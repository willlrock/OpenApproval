<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use Revolution\Google\Sheets\Facades\Sheets;

class GoogleSheetsService
{
    public static function append(ApprovalRequest $request)
    {
        $spreadsheetId = config('google_sheets.spreadsheet_id');
        $sheetName = config('google_sheets.sheet_name', 'Лист1');

        if (! $spreadsheetId) {
            return;
        }

        $row = [
            $request->id,
            $request->created_at->format('Y-m-d H:i:s'),
            $request->project ? $request->project->name : 'N/A',
            $request->total_price,
            $request->currency,
            $request->applicant ? ($request->applicant->full_name ?? 'Unknown') : 'Unknown',
            $request->status,
            $request->pdf_url && ! $request->isManagementDocument()
                ? route('requests.protocol', $request->id)
                : '',
        ];

        Sheets::spreadsheet($spreadsheetId)->sheet($sheetName)->append([$row]);

    }
}
