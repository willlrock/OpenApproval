<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ApprovalItemsImport implements ToArray, WithHeadingRow
{
    public static function readerType(?string $name): string
    {
        return match (strtolower(pathinfo($name ?? '', PATHINFO_EXTENSION))) {
            'xlsx', 'xlsm', 'xltx', 'xltm' => \Maatwebsite\Excel\Excel::XLSX,
            'xls', 'xlt' => \Maatwebsite\Excel\Excel::XLS,
            'csv' => \Maatwebsite\Excel\Excel::CSV,
            'ods' => \Maatwebsite\Excel\Excel::ODS,
            default => throw new \InvalidArgumentException('Неподдерживаемый формат таблицы.'),
        };
    }

    public function array(array $array)
    {
        return $array;
    }
}
