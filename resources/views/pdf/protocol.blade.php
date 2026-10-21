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
        .company-sub {
            font-size: 7.5pt;
            color: #6b7280;
            margin-top: 2pt;
            letter-spacing: 0.5pt;
        }
        .doc-title {
            font-size: 10pt;
            font-weight: bold;
            color: #1a2744;
            text-transform: uppercase;
            letter-spacing: 0.5pt;
        }
        .doc-number {
            font-size: 13pt;
            font-weight: bold;
            color: #1a2744;
            margin-top: 2pt;
        }
        .doc-date {
            font-size: 8.5pt;
            color: #6b7280;
            margin-top: 3pt;
        }

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
        .info-table tr:last-child td { border-bottom: 0.5pt solid #e5e7eb; }

        .total-row td { background: #eff6ff !important; font-weight: bold; font-size: 10.5pt !important; }
        .total-row td:first-child { background: #dbeafe !important; color: #1d4ed8 !important; }

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

        .badge {
            display: inline-block;
            padding: 1.5pt 6pt;
            border-radius: 10pt;
            font-size: 7.5pt;
            font-weight: bold;
        }
        .badge-approved { background: #dcfce7; color: #15803d; }
        .badge-rejected { background: #fee2e2; color: #b91c1c; }
        .badge-pending  { background: #fef9c3; color: #a16207; }

        .sig-cell { width: 90pt; height: 36pt; }
        .sig-img  { max-width: 85pt; max-height: 34pt; object-fit: contain; }

        /* ── APPLICANT BLOCK ── */
        .applicant-block {
            display: table;
            width: 100%;
            margin-top: 14pt;
            border: 0.5pt solid #e5e7eb;
            border-radius: 3pt;
        }
        .applicant-block-left  { display: table-cell; width: 50%; padding: 8pt 10pt; vertical-align: middle; }
        .applicant-block-right { display: table-cell; width: 50%; padding: 8pt 10pt; vertical-align: middle; border-left: 0.5pt solid #e5e7eb; }
        .applicant-label { font-size: 7.5pt; color: #9ca3af; margin-bottom: 2pt; }
        .applicant-name  { font-size: 9.5pt; font-weight: bold; color: #111827; }

        /* ── FOOTER ── */
        .footer-wrap {
            display: table;
            width: 100%;
            margin-top: 18pt;
            padding-top: 8pt;
            border-top: 0.5pt solid #e5e7eb;
        }
        .footer-left  { display: table-cell; vertical-align: bottom; font-size: 7pt; color: #9ca3af; }
        .footer-right { display: table-cell; width: 80pt; text-align: right; vertical-align: bottom; }
        .qr-img { width: 60pt; height: 60pt; }
        .qr-label { font-size: 6.5pt; color: #9ca3af; margin-top: 2pt; text-align: center; }
    </style>
</head>
<body>

{{-- ── HEADER ─────────────────────────────────────────────────────── --}}
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

{{-- ── STAMP ───────────────────────────────────────────────────────── --}}
<div class="stamp-wrap">
    @if($request->status === 'approved')
        <span class="stamp stamp-approved">✓ Согласовано</span>
    @elseif($request->status === 'rejected')
        <span class="stamp stamp-rejected">✗ Отклонено</span>
    @else
        <span class="stamp stamp-pending">⏳ На согласовании</span>
    @endif
</div>

{{-- ── REQUEST INFO ────────────────────────────────────────────────── --}}
<div class="section-title">Сведения о заявке</div>
<table class="info-table">
    <tr>
        <td>Проект</td>
        <td>{{ $request->project->name ?? '—' }}</td>
    </tr>
    <tr>
        <td>Наименование</td>
        <td>{{ $request->name }}</td>
    </tr>
    @if($request->description)
    <tr>
        <td>Описание / Обоснование</td>
        <td>{{ $request->description }}</td>
    </tr>
    @endif
    <tr>
        <td>Поставщик</td>
        <td>{{ $request->supplier_name ?? '—' }}</td>
    </tr>
    <tr>
        <td>Способ оплаты</td>
        <td>{{ $request->transaction_type === 'cash' ? 'Наличные' : 'Банковское перечисление' }}</td>
    </tr>
</table>

{{-- ── FINANCIAL ───────────────────────────────────────────────────── --}}
<div class="section-title">Финансовая информация</div>
<table class="info-table">
    <tr>
        <td>Валюта</td>
        <td>{{ $request->currency }}</td>
    </tr>
    @if($request->items && $request->items->count() > 0)
        @foreach($request->items as $i => $item)
        <tr>
            <td>{{ $i + 1 }}. {{ $item->name }}</td>
            <td>{{ number_format($item->price_per_unit, 2, '.', ' ') }} × {{ $item->quantity }} = {{ number_format($item->total_price, 2, '.', ' ') }} {{ $request->currency }}</td>
        </tr>
        @endforeach
    @endif
    <tr class="total-row">
        <td>ИТОГО К ОПЛАТЕ</td>
        <td>{{ number_format($request->total_price, 2, '.', ' ') }} {{ $request->currency }}</td>
    </tr>
</table>

{{-- ── APPROVAL SHEET ──────────────────────────────────────────────── --}}
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
                    <br><span style="font-size:7.5pt;color:#6b7280;">{{ $approval->approver->title }}</span>
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
            <td class="name-cell" style="font-size:8pt;color:#374151;">{{ $approval->comment ?? '—' }}</td>
            <td style="font-size:8pt;">
                {{ $approval->action_date
                    ? \Carbon\Carbon::parse($approval->action_date)->translatedFormat('d.m.Y H:i')
                    : '—' }}
            </td>
            <td class="sig-cell">
                @if(isset($signatures[$approval->id]) && $signatures[$approval->id])
                    <img src="{{ $signatures[$approval->id] }}" class="sig-img" alt="">
                @else
                    <span style="font-size:7.5pt;color:#d1d5db;">—</span>
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

{{-- ── APPLICANT BLOCK ─────────────────────────────────────────────── --}}
<div class="applicant-block">
    <div class="applicant-block-left">
        <div class="applicant-label">Заявитель</div>
        <div class="applicant-name">
            {{ $request->applicant->full_name ?? '' }}
        </div>
        @if($request->applicant->title)
            <div style="font-size:8pt;color:#6b7280;margin-top:1pt;">{{ $request->applicant->title }}</div>
        @endif
        @if($request->applicant->department)
            <div style="font-size:8pt;color:#6b7280;">{{ $request->applicant->department }}</div>
        @endif
    </div>
    <div class="applicant-block-right">
        <div class="applicant-label">Подпись заявителя</div>
        @php
            $applicantSig = null;
            if ($request->applicant->signature_path) {
                $disk = \Illuminate\Support\Facades\Storage::disk('local');
                if ($disk->exists($request->applicant->signature_path)) {
                    $content = $disk->get($request->applicant->signature_path);
                    $mime = \Illuminate\Support\Facades\File::mimeType($disk->path($request->applicant->signature_path));
                    $applicantSig = 'data:' . $mime . ';base64,' . base64_encode($content);
                }
            }
        @endphp
        @if($applicantSig)
            <img src="{{ $applicantSig }}" style="max-height:32pt;max-width:120pt;object-fit:contain;" alt="">
        @else
            <div style="border-bottom:0.75pt solid #9ca3af;width:120pt;height:28pt;"></div>
        @endif
    </div>
</div>

{{-- ── FOOTER ──────────────────────────────────────────────────────── --}}
<div class="footer-wrap">
    <div class="footer-left">
        TEG Holding — Автоматизированная система согласования<br>
        Документ сформирован: {{ now()->format('d.m.Y H:i:s') }}<br>
        ID заявки: #{{ $request->id }} | Статус: {{ [
            'approved' => 'Согласована',
            'rejected' => 'Отклонена',
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
