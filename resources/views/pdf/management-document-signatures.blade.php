<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 14mm 15mm 18mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; font-size: 8.6pt; color: #182235; }
        .top { width: 100%; border-collapse: collapse; }
        .top td { padding: 0; vertical-align: middle; }
        .brand { width: 44%; color: #172a4a; }
        .brand-main { font-size: 18pt; font-weight: 700; letter-spacing: -.5pt; }
        .brand-main span { font-size: 10pt; }
        .brand-sub { margin-top: -1mm; font-size: 5.8pt; font-weight: 700; color: #687386; letter-spacing: .35pt; }
        .doc-head { width: 56%; text-align: right; color: #172a4a; }
        .doc-kind { font-size: 10pt; font-weight: 700; text-transform: uppercase; }
        .doc-number { margin-top: 1.5mm; font-size: 8.2pt; }
        .navy-rule { margin: 2.5mm 0 5mm; height: 1.4mm; background: #172a4a; }
        .approved { width: 43mm; margin: 0 auto 5mm; border: 1.2px solid #172a4a; padding: 2.2mm 2mm 2mm; text-align: center; color: #172a4a; }
        .approved-title { font-size: 12pt; font-weight: 700; letter-spacing: .6pt; }
        .approved-date { margin-top: 1mm; font-size: 7.4pt; color: #536072; }
        .section-title { margin: 4mm 0 2mm; color: #172a4a; font-size: 9.2pt; font-weight: 700; text-transform: uppercase; letter-spacing: .25pt; }
        .meta { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .meta td { border: 1px solid #c8d2e0; padding: 2mm 2.2mm; vertical-align: top; }
        .meta .label { display: block; margin-bottom: .6mm; color: #687386; font-size: 6.3pt; font-weight: 700; text-transform: uppercase; }
        .meta .value { color: #172a4a; font-size: 8.4pt; font-weight: 700; }
        .meta .shade { background: #eff3f8; }
        .approval-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .approval-table thead { display: table-header-group; }
        .approval-table th { border: 1px solid #172a4a; background: #172a4a; color: #fff; padding: 1.7mm 1mm; font-size: 6.4pt; line-height: 1.15; text-align: center; text-transform: uppercase; }
        .approval-table td { border: 1px solid #c8d2e0; padding: 1.8mm 1.2mm; vertical-align: middle; line-height: 1.2; }
        .approval-table tr { page-break-inside: avoid; }
        .c-level { width: 5%; text-align: center; }
        .c-person { width: 26%; }
        .c-decision { width: 14%; text-align: center; }
        .c-comment { width: 21%; }
        .c-date { width: 15%; text-align: center; font-size: 7.2pt; }
        .c-signature { width: 19%; text-align: center; }
        .person-name { font-weight: 700; color: #172a4a; }
        .person-title { margin-top: .7mm; font-size: 6.8pt; color: #687386; }
        .decision { display: inline-block; border-radius: 8px; padding: 1mm 1.5mm; background: #e8f3ed; color: #24633d; font-size: 6.7pt; font-weight: 700; }
        .final-row td { background: #f5f8fb; }
        .signature img { max-width: 30mm; max-height: 11mm; }
        .initiator { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .initiator td { border: 1px solid #c8d2e0; padding: 2mm 2.2mm; vertical-align: middle; }
        .initiator .label-cell { width: 21%; background: #eff3f8; color: #687386; font-size: 6.6pt; font-weight: 700; text-transform: uppercase; }
        .initiator .person-cell { width: 44%; }
        .initiator .date-cell { width: 17%; text-align: center; font-size: 7.3pt; }
        .initiator .sign-cell { width: 18%; text-align: center; }
        .initiator img { max-width: 30mm; max-height: 11mm; }
        .verify { width: 100%; margin-top: 4mm; border-collapse: collapse; table-layout: fixed; }
        .verify td { vertical-align: middle; padding: 0; }
        .verify .qr { width: 22mm; }
        .verify .qr img { width: 19mm; height: 19mm; }
        .verify .text { padding-left: 2.5mm; color: #536072; font-size: 7.2pt; line-height: 1.35; }
        .verify .hash { margin-top: 1.2mm; font-size: 6.1pt; color: #7a8494; word-break: break-all; }
        .footer { position: fixed; left: 0; right: 0; bottom: -11mm; border-top: 1px solid #c8d2e0; padding-top: 1.5mm; color: #7a8494; font-size: 6.4pt; }
        .footer .right { float: right; }
    </style>
</head>
<body>
    <table class="top">
        <tr>
            <td class="brand">
                <div class="brand-main">TEG <span>HOLDING</span></div>
                <div class="brand-sub">СИСТЕМА УПРАВЛЕНИЯ ДОКУМЕНТАМИ</div>
            </td>
            <td class="doc-head">
                <div class="doc-kind">{{ app(\App\Services\ManagementDocumentService::class)->kindLabel($request->document_kind) }}</div>
                <div class="doc-number">№ {{ $documentNumber }} от {{ $finalizedAt->format('d.m.Y') }}</div>
            </td>
        </tr>
    </table>
    <div class="navy-rule"></div>

    <div class="approved">
        <div class="approved-title">УТВЕРЖДЕНО</div>
        <div class="approved-date">{{ $finalizedAt->format('d.m.Y H:i') }}</div>
    </div>

    <div class="section-title">Сведения о документе</div>
    <table class="meta">
        <tr>
            <td class="shade" style="width:52%">
                <span class="label">Наименование</span>
                <span class="value">{{ $request->name }}</span>
            </td>
            <td class="shade" style="width:48%">
                <span class="label">Проект / подразделение</span>
                <span class="value">{{ $request->project?->name ?? '—' }}</span>
            </td>
        </tr>
        <tr>
            <td>
                <span class="label">Вступает в силу</span>
                <span class="value">{{ app(\App\Services\ManagementDocumentService::class)->effectiveDateLabel($request->effective_date_mode, $request->effective_date, $finalizedAt) }}</span>
            </td>
            <td>
                <span class="label">Версия / идентификатор</span>
                <span class="value">Версия {{ $request->document_version }} · Документ #{{ $request->id }}</span>
            </td>
        </tr>
    </table>

    <div class="section-title">Лист согласования</div>
    <table class="approval-table">
        <thead>
            <tr>
                <th class="c-level">№</th>
                <th class="c-person">ФИО / должность</th>
                <th class="c-decision">Решение</th>
                <th class="c-comment">Комментарий</th>
                <th class="c-date">Дата решения</th>
                <th class="c-signature">Подпись</th>
            </tr>
        </thead>
        <tbody>
        @foreach($request->approvals->sortBy('level') as $approval)
            @php($isFinal = $approval->level === $request->approvals->max('level'))
            <tr class="{{ $isFinal ? 'final-row' : '' }}">
                <td class="c-level">{{ $approval->level }}</td>
                <td class="c-person">
                    <div class="person-name">{{ $approval->approver?->full_name ?? '—' }}</div>
                    <div class="person-title">{{ $approval->approver?->title ?: 'Должность не указана' }}</div>
                </td>
                <td class="c-decision"><span class="decision">{{ $isFinal ? 'УТВЕРДИЛ' : 'СОГЛАСОВАЛ' }}</span></td>
                <td class="c-comment">{{ $approval->comment ?: 'Без комментария' }}</td>
                <td class="c-date">{{ $approval->action_date?->format('d.m.Y H:i') ?? '—' }}</td>
                <td class="c-signature signature">
                    @if(isset($signatures[$approval->id]))
                        <img src="{{ $signatures[$approval->id] }}" alt="Подпись">
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="section-title">Инициатор</div>
    <table class="initiator">
        <tr>
            <td class="label-cell">Документ направил</td>
            <td class="person-cell">
                <div class="person-name">{{ $request->applicant?->full_name ?? '—' }}</div>
                <div class="person-title">{{ $request->applicant?->title ?: 'Должность не указана' }}</div>
            </td>
            <td class="date-cell">{{ $request->created_at?->format('d.m.Y H:i') ?? '—' }}</td>
            <td class="sign-cell"><img src="{{ $applicantSignature }}" alt="Подпись инициатора"></td>
        </tr>
    </table>

    <table class="verify">
        <tr>
            <td class="qr"><img src="{{ $qrData }}" alt="QR"></td>
            <td class="text">
                QR-код открывает страницу проверки статуса документа и последовательности его согласования.
                <div class="hash"><strong>SHA-256 исходного DOCX:</strong> {{ $request->source_sha256 }}</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        TEG Holding · электронный документ
        <span class="right">Сформирован {{ $finalizedAt->format('d.m.Y H:i') }}</span>
    </div>
</body>
</html>
