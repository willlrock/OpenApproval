<?php

namespace App\Exports;

use App\Models\RequestTranche;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class TranchesExport implements FromQuery, WithHeadings, WithMapping
{
    protected $user;

    public function __construct($user)
    {
        $this->user = $user;
    }

    public function query()
    {
        $role = $this->user->role;

        return RequestTranche::query()
            ->with(['approvalRequest.project', 'approvalRequest.applicant'])
            ->whereHas('approvalRequest', function($q) use ($role) {
                $q->whereIn('status', ['approved', 'paid'])
                    ->where('type', '!=', 'management_document');
                // Бухгалтер — только перечисления. Казначей/admin/superadmin — все типы.
                if ($role === 'accountant') {
                    $q->where('transaction_type', 'bank');
                }
                if (!in_array($role, ['admin', 'superadmin'], true)) {
                    $q->whereIn('project_id', $this->user->projects()->pluck('projects.id'));
                }
            });
    }

    public function headings(): array
    {
        return [
            'ID Заявки',
            'Проект',
            'Заявитель',
            'Поставщик',
            'Сумма',
            'Валюта',
            'Дата дедлайна',
            'Описание транша',
            'Статус оплаты',
            'Дата создания',
        ];
    }

    public function map($tranche): array
    {
        return [
            $tranche->approval_request_id,
            $tranche->approvalRequest->project->name ?? 'N/A',
            $tranche->approvalRequest->applicant->full_name ?? 'N/A',
            $tranche->approvalRequest->supplier_name,
            number_format($tranche->amount, 2, '.', ''),
            $tranche->approvalRequest->currency,
            $tranche->due_date ? $tranche->due_date->format('d.m.Y') : '—',
            $tranche->comment,
            $tranche->status === 'paid' ? 'Оплачен' : 'Ожидает',
            $tranche->created_at->format('d.m.Y H:i'),
        ];
    }
}
