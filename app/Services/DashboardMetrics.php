<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\User;

class DashboardMetrics
{
    public function forUser(User $user, array $filters = []): array
    {
        $visible = app(ApprovalRequestAccess::class)->scope(ApprovalRequest::query(), $user);
        $this->filter($visible, $filters);
        $counts = (clone $visible)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn ($n) => (int) $n)->all();
        $attention = [
            'overduePayment' => (clone $visible)->overduePayment()->count(),
            'overdueSupply' => (clone $visible)->overdueSupply()->count(),
            'stuckApproval' => (clone $visible)->stuckInApproval()->count(),
        ];
        $totals = [];
        $anomalies = 0;
        $requests = (clone $visible)->financial()
            ->withCount('tranches')
            ->withSum(['tranches as paid_amount' => fn ($q) => $q->where('status', 'paid')], 'amount')
            ->withSum(['tranches as overdue_amount' => fn ($q) => $q->overdue()], 'amount')
            ->withSum('tranches', 'amount')
            ->lazyById(500);

        foreach ($requests as $request) {
            $currency = $request->currency ?: 'Без валюты';
            $totals[$currency] ??= ['approved' => 0, 'paid' => 0, 'remaining' => 0, 'overdue' => 0];
            $approved = $this->cents($request->total_price);
            $paid = $this->cents($request->paid_amount);
            $remaining = max(0, $approved - $paid);
            // A tranche schedule takes precedence over the general deadline.
            $overdue = $request->tranches_count > 0
                ? $this->cents($request->overdue_amount)
                : ($request->status === 'approved' && $request->deadline_date?->isBefore(today()) ? $remaining : 0);
            if ($paid > $approved || ($request->status === 'paid' && $remaining > 0)
                || ($request->tranches_count > 0 && $this->cents($request->tranches_sum_amount) !== $approved)) {
                $anomalies++;
            }
            foreach (compact('approved', 'paid', 'remaining', 'overdue') as $key => $value) {
                $totals[$currency][$key] += $value;
            }
        }
        ksort($totals);
        foreach ($totals as &$row) {
            $row = array_map(fn ($cents) => $cents / 100, $row);
        }

        $paymentRequests = app(ApprovalRequestAccess::class)->scope(ApprovalRequest::query(), $user)->financial();
        $this->filter($paymentRequests, array_intersect_key($filters, ['project_id' => true]));
        $payments = \App\Models\RequestTranche::whereIn('approval_request_id', $paymentRequests->select('id'))->where('status', 'paid');
        $undated = (clone $payments)->whereNull('paid_at')->count();
        $flows = [];
        foreach ((clone $payments)->whereNotNull('paid_at')
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('paid_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('paid_at', '<=', $date))
            ->with('approvalRequest:id,currency')->lazyById(500) as $payment) {
            $currency = $payment->approvalRequest?->currency ?: 'Без валюты';
            $flows[$currency] = ($flows[$currency] ?? 0) + $this->cents($payment->amount);
        }
        ksort($flows);

        return ['counts' => $counts, 'paymentActivity' => array_map(fn ($cents) => $cents / 100, $flows), 'undatedPayments' => $undated, 'financialSummary' => $totals, 'attention' => $attention, 'paymentDataIssues' => $anomalies];
    }

    public function filter($query, array $filters)
    {
        return $query->when($filters['project_id'] ?? null, fn ($q, $id) => $q->where('project_id', $id))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('approval_requests.created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('approval_requests.created_at', '<=', $date));
    }

    private function cents($value): int
    {
        // Round database aggregates to cents once, then accumulate integer amounts.
        $value = number_format((float) ($value ?? 0), 2, '.', '');
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');

        return ((int) $whole * 100 + (int) substr(str_pad($fraction, 2, '0'), 0, 2)) * ($negative ? -1 : 1);
    }
}
