<?php

namespace App\Enums;

/**
 * The file formats a list export can be downloaded in (spec 021).
 */
enum ExportFormat: string
{
    case XLSX = 'xlsx';
    case CSV = 'csv';
    case SQL = 'sql';

    public function extension(): string
    {
        return $this->value;
    }

    public function contentType(): string
    {
        return match ($this) {
            self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::CSV => 'text/csv; charset=UTF-8',
            self::SQL => 'application/sql; charset=UTF-8',
        };
    }
}
