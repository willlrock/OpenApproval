<?php

namespace App\Services;

use App\Models\ApprovalRequest;

class RequestDescriptionMessage
{
    public function pages(ApprovalRequest $request, string $lang): array
    {
        $request->loadMissing('items');
        $lines = [];
        if (filled($request->description)) {
            $lines[] = $request->description;
        }
        foreach ($request->items as $item) {
            if (filled($item->description)) {
                $lines[] = $request->items->count() > 1
                    ? $item->name.': '.$item->description
                    : $item->description;
            }
        }

        $header = __('bot.my_detail_desc', ['desc' => ''], $lang);
        $pages = [];
        $page = $header;
        foreach ($lines ?: ['—'] as $line) {
            foreach (mb_str_split($line, 400) as $chunk) {
                $escaped = htmlspecialchars($chunk, ENT_QUOTES, 'UTF-8');
                if (mb_strlen($page) + mb_strlen($escaped) + 1 > 3000) {
                    $pages[] = $page;
                    $page = $header;
                }
                $page .= ($page === $header ? '' : "\n").$escaped;
            }
        }
        $pages[] = $page;

        return $pages;
    }
}
