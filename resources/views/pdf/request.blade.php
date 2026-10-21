<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Заявка на расход №{{ $request->id }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10pt;
            color: #1a1a1a;
            background: #fff;
            padding: 18mm 15mm 15mm 15mm;
        }

        /* ── HEADER ── */
        .header {
            display: table;
            width: 100%;
            border-bottom: 2.5pt solid #1a2744;
            padding-bottom: 8pt;
            margin-bottom: 14pt;
        }
        .header-left  { display: table-cell; width: 60%; vertical-align: middle; }
        .header-right { display: table-cell; width: 40%; vertical-align: middle; text-align: right; }

        .company-name {
            font-size: 18pt;
            font-weight: bold;
            letter-spacing: 3pt;
            color: #1a2744;
            line-height: 1;
        }
        .company-sub  { font-size: 7.5pt; color: #6b7280; margin-top: 2pt; letter-spacing: 0.5pt; }
        .doc-title    { font-size: 10pt; font-weight: bold; color: #1a2744; text-transform: uppercase; letter-spacing: 0.5pt; }
        .doc-number   { font-size: 13pt; font-weight: bold; color: #1a2744; margin-top: 2pt; }
        .doc-date     { font-size: 8.5pt; color: #6b7280; margin-top: 3pt; }

        /* ── STATUS STAMP ── */
        .stamp-wrap { text-align: center; margin-bottom: 12pt; }
        .stamp {
            display: inline-block;
            padding: 4pt 18pt;
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 2pt;
            text-transform: uppercase;
            border-radius: 3pt;
        }
        .stamp-approved { border: 2.5pt solid #16a34a; color: #16a34a; }
        .stamp-rejected { border: 2.5pt solid #dc2626; color: #dc2626; }
        .stamp-pending  { border: 2.5pt solid #d97706; color: #d97706; }
        .stamp-paid     { border: 2.5pt solid #2563eb; color: #2563eb; }

        /* ── SECTION TITLE ── */
        .section-title {
            font-size: 8.5pt;
            font-weight: bold;
            letter-spacing: 1pt;
            text-transform: uppercase;
            color: #6b7280;
            border-bottom: 0.75pt solid #d1d5db;
            padding-bottom: 3pt;
            margin-bottom: 8pt;
            margin-top: 14pt;
        }

        /* ── INFO TABLE ── */
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 2pt; }
        .info-table tr td { padding: 4.5pt 8pt; font-size: 9.5pt; border: 0.5pt solid #e5e7eb; }
        .info-table tr td:first-child {
            width: 35%;
            background: #f8f9fb;
            color: #374151;
            font-weight: bold;
            font-size: 8.5pt;
        }

        /* ── ITEMS TABLE ── */
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 2pt; }
        .items-table thead tr th {
            background: #1a2744;
            color: #fff;
            font-size: 8pt;
            font-weight: bold;
            padding: 5pt 6pt;
            text-align: center;
            border: 0.5pt solid #1a2744;
        }
        .items-table tbody tr td {
            font-size: 8.5pt;
            padding: 4.5pt 6pt;
            border: 0.5pt solid #e5e7eb;
            vertical-align: top;
        }
        .items-table tbody tr td.td-center {
            vertical-align: top;
            text-align: center;
        }
        .items-table tbody tr td.td-right {
            vertical-align: top;
            text-align: right;
            white-space: nowrap;
        }
        .items-table tbody tr td.td-num {
            vertical-align: top;
            text-align: center;
            width: 4%;
        }
        .items-row-alt td { background: #f9fafb; }
        .items-total-row td {
            background: #eff6ff;
            border-top: 1.5pt solid #1a2744;
            padding: 6pt 6pt;
            vertical-align: middle;
            white-space: nowrap;
        }

        /* ── APPROVAL TABLE ── */
        .approval-table { width: 100%; border-collapse: collapse; }
        .approval-table th {
            background: #1a2744;
            color: #fff;
            font-size: 8pt;
            font-weight: bold;
            padding: 5pt 7pt;
            text-align: center;
            border: 0.5pt solid #1a2744;
        }
        .approval-table td {
            font-size: 8.5pt;
            padding: 5pt 7pt;
            border: 0.5pt solid #e5e7eb;
            vertical-align: middle;
            text-align: center;
        }
        .approval-table td.name-cell { text-align: left; }
        .approval-table tr:nth-child(even) td { background: #f9fafb; }

        .badge { display: inline-block; padding: 1.5pt 6pt; border-radius: 10pt; font-size: 7.5pt; font-weight: bold; }
        .badge-approved { background: #dcfce7; color: #15803d; }
        .badge-rejected { background: #fee2e2; color: #b91c1c; }
        .badge-pending  { background: #fef9c3; color: #a16207; }

        .sig-cell { width: 90pt; height: 36pt; }
        .sig-img  { max-width: 85pt; max-height: 34pt; object-fit: contain; }

        /* ── APPLICANT BLOCK ── */
        .applicant-block        { display: table; width: 100%; margin-top: 14pt; border: 0.5pt solid #e5e7eb; border-radius: 3pt; }
        .applicant-block-left  { display: table-cell; width: 50%; padding: 8pt 10pt; vertical-align: middle; }
        .applicant-block-right { display: table-cell; width: 50%; padding: 8pt 10pt; vertical-align: middle; border-left: 0.5pt solid #e5e7eb; }
        .applicant-label { font-size: 7.5pt; color: #9ca3af; margin-bottom: 2pt; }
        .applicant-name  { font-size: 9.5pt; font-weight: bold; color: #111827; }

        /* ── FOOTER ── */
        .footer-wrap  { display: table; width: 100%; margin-top: 18pt; padding-top: 8pt; border-top: 0.5pt solid #e5e7eb; }
        .footer-left  { display: table-cell; vertical-align: bottom; font-size: 7pt; color: #9ca3af; }
        .footer-right { display: table-cell; width: 80pt; text-align: right; vertical-align: bottom; }
        .qr-img   { width: 60pt; height: 60pt; }
        .qr-label { font-size: 6.5pt; color: #9ca3af; margin-top: 2pt; text-align: center; }
    </style>
</head>
<body>

{{-- ── HEADER ── --}}
<div class="header">
    <div class="header-left">
        <div class="company-name">TEG HOLDING</div>
        <div class="company-sub">Система согласования расходов</div>
    </div>
    <div class="header-right">
        <div class="doc-title">Заявка на расход</div>
        <div class="doc-number">№ {{ str_pad($request->id, 5, '0', STR_PAD_LEFT) }}</div>
        <div class="doc-date">от {{ $request->created_at->translatedFormat('d F Y') }} г.</div>
    </div>
</div>

{{-- ── STAMP ── --}}
<div class="stamp-wrap">
    @if($request->status === 'paid')
        <span class="stamp stamp-paid">✓ Оплачено</span>
    @elseif($request->status === 'approved')
        <span class="stamp stamp-approved">✓ Согласовано</span>
    @elseif($request->status === 'rejected')
        <span class="stamp stamp-rejected">✗ Отклонено</span>
    @else
        <span class="stamp stamp-pending">⏳ На согласовании</span>
    @endif
</div>

{{-- ── REQUEST INFO ── --}}
<div class="section-title">Сведения о заявке</div>
<table class="info-table">
    <tr><td>Проект</td><td>{{ $request->project->name ?? '—' }}</td></tr>
    <tr><td>Желаемый срок</td><td><b>{{ $request->deadline_date ? $request->deadline_date->format('d.m.Y') : '—' }}</b></td></tr>
    @if($request->factual_delivery_date)
    <tr><td>Фактическая дата поставки</td><td><b style="color:#1d4ed8">{{ $request->factual_delivery_date->format('d.m.Y') }}</b></td></tr>
    @endif
    @if($request->payment_commitment_date)
    <tr><td>Обещанная дата выплаты</td><td><b style="color:#16a34a">{{ $request->payment_commitment_date->format('d.m.Y') }}</b></td></tr>
    @endif
    <tr>
        <td>Поставщик</td>
        <td>
            {{ $request->supplier_name ?? '—' }}
            @if($request->supplier_inn)<span style="font-size:8pt;color:#6b7280;margin-left:5pt">(ИНН: {{ $request->supplier_inn }})</span>@endif
        </td>
    </tr>
    <tr><td>Способ оплаты</td><td>{{ $request->transaction_type === 'cash' ? 'Наличные' : 'Банковское перечисление' }}</td></tr>
    <tr><td>Валюта</td><td>{{ $request->currency }}</td></tr>
</table>

{{-- ── ITEMS TABLE ── --}}
<div class="section-title">Перечень товаров / услуг</div>

@php
    $items      = $request->items ?? collect();
    $hasItems   = $items->isNotEmpty();
    $grandTotal = $hasItems ? $items->sum('total_price') : $request->total_price;
@endphp

@if($hasItems)
<table class="items-table">
    <thead>
        <tr>
            <th style="width:4%">№</th>
            <th style="width:35%;text-align:left">Наименование</th>
            <th style="width:23%;text-align:left">Описание</th>
            <th style="width:10%">Кол-во</th>
            <th style="width:14%">Цена за ед.</th>
            <th style="width:14%">Сумма</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $i => $item)
        <tr @if($i % 2 === 1) class="items-row-alt" @endif>
            <td class="td-num">{{ $i + 1 }}</td>
            <td style="text-align:left"><b>{{ $item->name }}</b></td>
            <td style="text-align:left;color:#6b7280;font-size:8pt">{{ $item->description ?? '—' }}</td>
            <td class="td-center">{{ $item->quantity }} шт.</td>
            <td class="td-right">
                {{ $item->price_per_unit ? number_format($item->price_per_unit, 2, '.', ' ') : '—' }}
            </td>
            <td class="td-right">
                <b>{{ $item->total_price ? number_format($item->total_price, 2, '.', ' ') : '—' }}</b>
            </td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="items-total-row">
            <td colspan="5" style="text-align:right;font-weight:bold;font-size:9.5pt">ИТОГО К ОПЛАТЕ:</td>
            <td style="text-align:right;font-weight:bold;font-size:11pt;color:#1d4ed8">
                {{ number_format($grandTotal, 2, '.', ' ') }} {{ $request->currency }}
            </td>
        </tr>
    </tfoot>
</table>
@else
{{-- Fallback: старый формат с одним товаром --}}
<table class="items-table">
    <thead>
        <tr>
            <th style="width:5%">№</th>
            <th style="width:45%;text-align:left">Наименование</th>
            <th style="width:10%">Кол-во</th>
            <th style="width:20%">Цена за ед.</th>
            <th style="width:20%">Сумма</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td class="td-num">1</td>
            <td style="text-align:left">
                <b>{{ $request->name }}</b>
                @if($request->description)
                    <br><span style="font-size:8pt;color:#6b7280">{{ $request->description }}</span>
                @endif
            </td>
            <td class="td-center">{{ $request->quantity ?? 1 }} шт.</td>
            <td class="td-right">{{ number_format($request->price_per_unit ?? $request->total_price, 2, '.', ' ') }}</td>
            <td class="td-right"><b>{{ number_format($request->total_price, 2, '.', ' ') }}</b></td>
        </tr>
    </tbody>
    <tfoot>
        <tr class="items-total-row">
            <td colspan="4" style="text-align:right;font-weight:bold;font-size:9.5pt">ИТОГО К ОПЛАТЕ:</td>
            <td style="text-align:right;font-weight:bold;font-size:11pt;color:#1d4ed8">
                {{ number_format($grandTotal, 2, '.', ' ') }} {{ $request->currency }}
            </td>
        </tr>
    </tfoot>
</table>
@endif

{{-- ── TRANCHES TABLE ── --}}
@if($request->tranches && $request->tranches->isNotEmpty())
<div class="section-title">График платежей (Транши)</div>
<table class="items-table">
    <thead>
        <tr>
            <th style="width:5%">№</th>
            <th style="width:20%;text-align:left">Дата оплаты</th>
            <th style="width:25%;text-align:left">Сумма</th>
            <th style="width:35%;text-align:left">Характеристика / Условие</th>
            <th style="width:15%">Статус</th>
        </tr>
    </thead>
    <tbody>
        @foreach($request->tranches as $i => $tranche)
        <tr @if($i % 2 === 1) class="items-row-alt" @endif>
            <td class="td-num">{{ $i + 1 }}</td>
            <td style="text-align:left">{{ $tranche->due_date ? \Carbon\Carbon::parse($tranche->due_date)->format('d.m.Y') : '—' }}</td>
            <td style="text-align:left">
                <b>{{ number_format($tranche->amount, 2, '.', ' ') }} {{ $request->currency }}</b>
                @if($tranche->percent)
                    <span style="font-size:7.5pt;color:#6b7280;margin-left:4pt">({{ $tranche->percent }}%)</span>
                @endif
            </td>
            <td style="text-align:left;font-size:8.5pt">{{ $tranche->comment ?? '—' }}</td>
            <td style="text-align:center">
                @if($tranche->status === 'paid')
                    <span style="color:#16a34a;font-weight:bold">Оплачен</span>
                @else
                    <span style="color:#d97706">Ожидает</span>
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- ── APPROVAL SHEET ── --}}
<div class="section-title">Лист согласования</div>
<table class="approval-table">
    <thead>
        <tr>
            <th style="width:5%">№</th>
            <th style="width:25%;text-align:left">ФИО / Должность</th>
            <th style="width:13%">Решение</th>
            <th style="width:22%;text-align:left">Комментарий</th>
            <th style="width:14%">Дата решения</th>
            <th style="width:21%">Подпись</th>
        </tr>
    </thead>
    <tbody>
        @foreach($request->approvals->sortBy('level') as $approval)
        <tr>
            <td>{{ $approval->level }}</td>
            <td class="name-cell">
                <strong>{{ $approval->approver->full_name ?? '' }}</strong>
                @if($approval->approver->title)
                    <br><span style="font-size:7.5pt;color:#6b7280">{{ $approval->approver->title }}</span>
                @endif
            </td>
            <td>
                @if($approval->status === 'approved')
                    <span class="badge badge-approved">Одобрено</span>
                @elseif($approval->status === 'rejected')
                    <span class="badge badge-rejected">Отклонено</span>
                @else
                    <span class="badge badge-pending">Ожидает</span>
                @endif
            </td>
            <td class="name-cell" style="font-size:8pt;color:#374151">{{ $approval->comment ?? '—' }}</td>
            <td style="font-size:8pt">
                {{ $approval->action_date
                    ? \Carbon\Carbon::parse($approval->action_date)->translatedFormat('d.m.Y H:i')
                    : '—' }}
            </td>
            <td class="sig-cell">
                @if(isset($signatures[$approval->id]) && $signatures[$approval->id])
                    <img src="{{ $signatures[$approval->id] }}" class="sig-img" alt="">
                @else
                    <span style="font-size:7.5pt;color:#d1d5db">—</span>
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

{{-- ── APPLICANT BLOCK ── --}}
<div class="applicant-block">
    <div class="applicant-block-left">
        <div class="applicant-label">Заявитель</div>
        <div class="applicant-name">
            {{ $request->applicant->full_name ?? '' }}
        </div>
        @if($request->applicant->title)
            <div style="font-size:8pt;color:#6b7280;margin-top:1pt">{{ $request->applicant->title }}</div>
        @endif
        @if($request->applicant->department)
            <div style="font-size:8pt;color:#6b7280">{{ $request->applicant->department }}</div>
        @endif
    </div>
    <div class="applicant-block-right">
        <div class="applicant-label">Подпись заявителя</div>
        @php
            $applicantSig = app(\App\Services\PdfSignatureRenderer::class)->dataUri(
                $request->applicant_signature_snapshot_path,
                $request->applicant_signature_sha256,
                $request->applicant?->signature_path,
                'Подпись заявителя'
            );
        @endphp
        @if($applicantSig)
            <img src="{{ $applicantSig }}" style="max-height:32pt;max-width:120pt;object-fit:contain" alt="">
        @else
            <div style="border-bottom:0.75pt solid #9ca3af;width:120pt;height:28pt"></div>
        @endif
    </div>
</div>

{{-- ── FOOTER ── --}}
<div class="footer-wrap">
    <div class="footer-left">
        TEG Holding — Автоматизированная система согласования<br>
        Документ сформирован: {{ now()->format('d.m.Y H:i:s') }}<br>
        ID заявки: #{{ $request->id }} | Статус: {{ [
            'paid'             => 'Оплачена',
            'approved'         => 'Согласована',
            'rejected'         => 'Отклонена',
            'pending_approval' => 'На согласовании',
        ][$request->status] ?? $request->status }}
    </div>
    <div class="footer-right">
        @if(isset($qrData) && $qrData)
            <img src="{{ $qrData }}" class="qr-img" alt="QR">
            <div class="qr-label">Проверка подлинности</div>
        @endif
    </div>
</div>

</body>
</html>
