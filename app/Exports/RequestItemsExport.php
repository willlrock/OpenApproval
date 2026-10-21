<?php

namespace App\Exports;

use App\Models\ApprovalRequest;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class RequestItemsExport implements FromCollection, WithHeadings, WithMapping
{
    protected ApprovalRequest $request;

    public function __construct(ApprovalRequest $request)
    {
        $this->request = $request;
    }

    public function collection()
    {
        return $this->request->items;
    }

    public function headings(): array
    {
        return [
            '№',
            'Наименование',
            'Описание',
            'Количество',
            'Цена за ед.',
            'Сумма',
        ];
    }

    public function map($item): array
    {
        static $index = 0;
        $index++;

        return [
            $index,
            $item->name,
            $item->description ?? '',
            $item->quantity,
            $item->price_per_unit ? number_format($item->price_per_unit, 2, '.', '') : '',
            $item->total_price ? number_format($item->total_price, 2, '.', '') : '',
        ];
    }
}
