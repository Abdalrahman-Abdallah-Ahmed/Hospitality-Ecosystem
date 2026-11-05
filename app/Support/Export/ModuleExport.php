<?php

namespace App\Support\Export;

use App\Enums\ExportFormat;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * One module's list as a spreadsheet (spec 021): a fixed, readable column set
 * read from the same query the list uses. Excel goes through PhpSpreadsheet:
 * dates are real dates, amounts are numbers and text cells are stored as
 * text, so nothing typed into the system runs as a formula. CSV is streamed
 * by streamCsv(): dates as text, risky text prefixed (SpreadsheetSafe).
 */
abstract class ModuleExport extends DefaultValueBinder implements FromQuery, WithColumnFormatting, WithCustomValueBinder, WithHeadings, WithMapping, WithStrictNullComparison, WithTitle
{
    /** Date-times are stored in UTC and written in the hotel's own time. */
    private string $timezone = 'UTC';

    public function __construct(
        protected readonly Builder $query,
        protected readonly ExportFormat $format,
    ) {}

    public function inTimezone(?string $timezone): static
    {
        $this->timezone = $timezone ?: 'UTC';

        return $this;
    }

    /**
     * Writes the CSV straight to the response, a chunk at a time, so memory
     * stays flat however many rows there are. Returns the rows written.
     */
    public function streamCsv(): int
    {
        $out = fopen('php://output', 'w');
        // The UTF-8 BOM is what makes Excel open non-Latin text correctly.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $this->headings(), escape: '');

        $written = 0;

        foreach ($this->query()->lazy(500) as $row) {
            fputcsv($out, $this->map($row), escape: '');
            $written++;
        }

        fclose($out);

        return $written;
    }

    /**
     * Header => value for one record, in column order.
     *
     * @return array<string, Closure(Model): mixed>
     */
    abstract protected function columns(): array;

    /**
     * What the columns read, eager loaded per chunk.
     *
     * @return array<int|string, mixed>
     */
    abstract protected function relations(): array;

    /**
     * Headers holding a date without a time.
     *
     * @return list<string>
     */
    protected function dateColumns(): array
    {
        return [];
    }

    /**
     * Headers holding a date and time.
     *
     * @return list<string>
     */
    protected function dateTimeColumns(): array
    {
        return [];
    }

    /**
     * The list's own sort, then the id: the file is read in chunks of
     * LIMIT/OFFSET, and rows with equal sort keys would otherwise repeat or
     * go missing between chunks. Cloned so repeated calls never stack it.
     */
    public function query(): Builder
    {
        return (clone $this->query)
            ->with($this->relations())
            ->orderBy($this->query->getModel()->qualifyColumn('id'));
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return array_keys($this->columns());
    }

    /**
     * @param  Model  $row
     * @return list<mixed>
     */
    public function map($row): array
    {
        $dates = $this->dateColumns();
        $values = [];

        foreach ($this->columns() as $header => $value) {
            $values[] = $this->cell($value($row), in_array($header, $dates, true));
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        if ($this->format !== ExportFormat::XLSX) {
            return [];
        }

        $headers = $this->headings();
        $formats = [];

        foreach ($this->dateColumns() as $header) {
            $formats[$this->letter($headers, $header)] = NumberFormat::FORMAT_DATE_YYYYMMDD;
        }

        foreach ($this->dateTimeColumns() as $header) {
            $formats[$this->letter($headers, $header)] = 'yyyy-mm-dd hh:mm';
        }

        return $formats;
    }

    /**
     * Text is always stored as text, never read as a formula (FR-011).
     */
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    private function cell(mixed $value, bool $dateOnly): mixed
    {
        if ($value instanceof DateTimeInterface) {
            if (! $dateOnly) {
                $value = Carbon::instance($value)->setTimezone($this->timezone);
            }

            if ($this->format === ExportFormat::XLSX) {
                return Date::dateTimeToExcel($value);
            }

            return $value->format($dateOnly ? 'Y-m-d' : 'Y-m-d H:i');
        }

        if (is_string($value) && $this->format === ExportFormat::CSV) {
            return SpreadsheetSafe::text($value);
        }

        return $value;
    }

    /**
     * @param  list<string>  $headers
     */
    private function letter(array $headers, string $header): string
    {
        return Coordinate::stringFromColumnIndex(array_search($header, $headers, true) + 1);
    }

    /**
     * A readable label for a coded value: the enum's own label when it has
     * one, otherwise its value in words. A stored string that is not one of
     * the enum's values is shown as stored.
     *
     * @param  class-string<BackedEnum>|null  $enum
     */
    protected function label(BackedEnum|string|null $value, ?string $enum = null): ?string
    {
        if (is_string($value) && $enum !== null) {
            $value = $enum::tryFrom($value) ?? $value;
        }

        if (! $value instanceof BackedEnum) {
            return $value === '' ? null : $value;
        }

        return method_exists($value, 'label') ? $value->label() : Str::headline((string) $value->value);
    }

    protected function yesNo(?bool $value): ?string
    {
        return $value === null ? null : ($value ? 'Yes' : 'No');
    }

    protected function fullName(?Model $person): ?string
    {
        if ($person === null) {
            return null;
        }

        $name = trim($person->first_name.' '.$person->last_name);

        return $name === '' ? null : $name;
    }

    /**
     * @param  iterable<mixed>  $values
     */
    protected function joined(iterable $values): ?string
    {
        $joined = collect($values)->filter(fn ($value) => $value !== null && $value !== '')->unique()->implode(', ');

        return $joined === '' ? null : $joined;
    }

    protected function amount(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
