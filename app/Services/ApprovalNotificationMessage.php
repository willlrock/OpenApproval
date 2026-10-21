<?php

namespace App\Services;

use App\Models\ApprovalRequest;

class ApprovalNotificationMessage
{
    /** Build complete cards, splitting long requests without cutting HTML entities. */
    public function pages(ApprovalRequest $request, string $lang, bool $first = false): array
    {
        $request->loadMissing(['project', 'applicant', 'items', 'tranches']);
        $money = fn ($value) => number_format((float) $value, 2, '.', ' ');
        $lines = [];
        $add = function (string $key, array $params = []) use (&$lines, $lang) {
            // Translate labels before inserting user content; escape each chunk below.
            $text = strip_tags(__($key, [], $lang));
            foreach ($params as $name => $value) {
                $text = str_replace(':'.$name, (string) $value, $text);
            }
            $lines[] = $text;
        };
        $add($first ? 'bot.notify_new_request' : 'bot.notify_passed', ['id' => $request->id, 'level' => $request->expected_approval_level]);
        $lines[] = '';
        $add('bot.notify_project', ['name' => $request->project?->name ?? '—']);
        $add('bot.notify_applicant', ['name' => $request->applicant?->full_name ?? '—']);
        $add('bot.notify_phone', ['phone' => $request->applicant?->phone ?: __('bot.notify_phone_not_set', [], $lang)]);
        $add('bot.notify_name', ['name' => $request->name]);
        if ($request->supplier_name) {
            $add('bot.notify_supplier', ['name' => $request->supplier_name]);
        }
        if ($request->transaction_type) {
            $add('bot.notify_method', ['method' => __('bot.app_method_'.$request->transaction_type, [], $lang)]);
        }
        if ($request->currency) {
            $add('bot.notify_amount', ['total' => $money($request->total_price), 'currency' => $request->currency]);
        }
        if ($request->deadline_date) {
            $add('bot.notify_deadline', ['date' => $request->deadline_date->format('d.m.Y')]);
        }
        if ($request->items->isNotEmpty()) {
            $add('bot.notify_items');
        }
        foreach ($request->items as $index => $item) {
            $lines[] = ($index + 1).'. '.$item->name;
            if ($item->description) {
                $lines[] = '💬 '.$item->description;
            }
            $lines[] = $item->price_per_unit !== null
                ? $money($item->price_per_unit).' × '.$item->quantity.' = '.$money($item->total_price ?? $item->price_per_unit * $item->quantity).' '.$request->currency
                : '× '.$item->quantity;
        }
        if ($request->tranches->isNotEmpty()) {
            $add('bot.notify_tranches');
            foreach ($request->tranches as $index => $tranche) {
                $lines[] = ($index + 1).'. '.$money($tranche->amount).' '.$request->currency.' — '.($tranche->due_date?->format('d.m.Y') ?? '—');
                if ($tranche->comment) {
                    $lines[] = $tranche->comment;
                }
            }
        }
        if ($request->currency) {
            $add('bot.notify_total', ['total' => $money($request->total_price), 'currency' => $request->currency]);
        }
        $add('bot.notify_please_decide');

        return app(TelegramNotificationMessage::class)->pages(implode("\n", $lines));
    }
}
