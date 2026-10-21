<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Services\ApprovalRequestAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RequestRevisionController extends Controller
{
    public function requestChanges(Request $http, int $id)
    {
        $data = $http->validate(['reason' => 'required|string|max:2000', 'revision' => 'required|integer']);
        app(\App\Services\ApprovalWorkflow::class)->reject($id, $http->user(), $data['reason'], (int) $data['revision'], true);

        return back()->with('success', 'Заявка возвращена автору на доработку.');
    }

    public function history(Request $http, int $id)
    {
        $r = app(ApprovalRequestAccess::class)->scope(ApprovalRequest::query(), $http->user())->findOrFail($id);
        $versions = DB::table('request_revisions')->where('approval_request_id', $id)->orderBy('version')->get()->map(function ($v) {
            $s = json_decode($v->snapshot, true);

            return ['version' => $v->version, 'saved_at' => $v->created_at, 'data' => $this->present($s), 'files' => collect($s['attachments'] ?? [])->filter(fn ($f) => ! empty($f['file_path']) || ! empty($f['telegram_file_id']))->map(fn ($f) => ['id' => $f['id'], 'name' => \App\Models\RequestAttachment::safeName($f['original_name'] ?? null, (int) $f['id'], $f['file_type'] ?? 'bin'), 'url' => route('requests.history-file', [$v->approval_request_id, $v->version, $f['id']])])->values()];
        });

        return Inertia::render('RequestDrafts/History', ['id' => $id, 'versions' => $versions, 'current' => $this->present($r->load(['items', 'approvals.approver', 'tranches', 'project', 'attachments'])->toArray()), 'revision' => $r->revision]);
    }

    public function file(Request $http, int $id, int $version, int $fileId)
    {
        app(ApprovalRequestAccess::class)->scope(ApprovalRequest::query(), $http->user())->findOrFail($id);
        $record = DB::table('request_revisions')->where('approval_request_id', $id)->where('version', $version)->first();
        abort_unless($record, 404);
        $file = collect(json_decode($record->snapshot, true)['attachments'] ?? [])->firstWhere('id', $fileId);
        abort_unless($file, 404);

        return app(\App\Services\TelegramFileCache::class)->download($file['file_path'] ?? null, $file['telegram_file_id'] ?? null, \App\Models\RequestAttachment::safeName($file['original_name'] ?? null, $fileId, $file['file_type'] ?? 'bin'));
    }

    private function dateLabel(?string $value): ?string
    {
        return $value ? \Carbon\Carbon::parse($value)->setTimezone(config('app.timezone'))->format('d.m.Y') : null;
    }

    private function statusLabel(string $status): string
    {
        return ['approval_paused' => 'Согласование приостановлено', 'pending' => 'Ожидает', 'pending_approval' => 'На согласовании', 'approved' => 'Утверждено', 'paid' => 'Оплачено', 'rejected' => 'Отклонено', 'revision_requested' => 'На доработке', 'pending_supply' => 'В снабжении'][$status] ?? $status;
    }

    private function present(array $s): array
    {
        return [
            'Недоступные согласующие' => collect($s['route_unavailable'] ?? [])->pluck('name')->implode(', '),
            'Восстановление отправлено' => $s['route_draft']['saved_at'] ?? null,
            'Вложения' => count($s['attachments'] ?? []),
            'Название' => $s['name'], 'Статус' => $this->statusLabel($s['status']), 'Проект' => $s['project']['name'] ?? $s['project_id'],
            'Поставщик' => $s['supplier_name'] ?? null, 'ИНН' => $s['supplier_inn'] ?? null,
            'Валюта' => $s['currency'] ?? null, 'Способ оплаты' => ($s['transaction_type'] ?? null) === 'bank' ? 'Перечисление' : (($s['transaction_type'] ?? null) === 'cash' ? 'Наличные' : null),
            'Сумма' => $s['total_price'] ?? null, 'Срок' => $this->dateLabel($s['deadline_date'] ?? null),
            'Позиции' => collect($s['items'] ?? [])->map(fn ($i) => ['Название' => $i['name'], 'Описание' => $i['description'] ?? null, 'Количество' => $i['quantity'], 'Цена' => $i['price_per_unit'], 'Сумма' => $i['total_price']])->all(),
            'Согласование' => collect($s['approvals'] ?? [])->sortBy('level')->map(fn ($a) => ['Уровень' => $a['level'], 'Согласующий' => $a['approver']['full_name'] ?? $a['approver_id'], 'Решение' => $this->statusLabel($a['status']), 'Комментарий' => $a['comment'] ?? null])->values()->all(),
            'Платежи' => collect($s['tranches'] ?? [])->map(fn ($t) => ['Сумма' => $t['amount'], 'Срок' => $this->dateLabel($t['due_date'] ?? null), 'Статус' => $this->statusLabel($t['status'])])->all(),
        ];
    }
}
