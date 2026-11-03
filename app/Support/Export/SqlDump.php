<?php

namespace App\Support\Export;

use App\Enums\ExportFormat;
use App\Models\Hotel;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One module's records as PostgreSQL INSERT statements (spec 021 FR-012),
 * streamed straight to the client. Every stored column is kept as stored,
 * ids and foreign keys included, so separately exported tables can be
 * linked again; only internal columns are left out (FR-013). The statements
 * sit in one transaction, so a failed load leaves nothing half-done.
 */
class SqlDump
{
    private const NUMERIC_TYPES = ['int2', 'int4', 'int8', 'numeric', 'float4', 'float8'];

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $excluded
     */
    public function __construct(
        private readonly Builder $query,
        private readonly string $module,
        private readonly Hotel $hotel,
        private readonly int $rowCount,
        private readonly array $filters,
        private readonly array $excluded = [],
    ) {}

    public function stream(string $filename): StreamedResponse
    {
        // The body is written after the tenant middleware has cleared the
        // hotel context, so the hotel is set again for the read.
        return response()->streamDownload(
            fn () => TenantContext::runForHotel($this->hotel->id, fn () => $this->write()),
            $filename,
            ['Content-Type' => ExportFormat::SQL->contentType()],
        );
    }

    /**
     * Writes the dump and returns the number of INSERTs written.
     */
    public function write(): int
    {
        $model = $this->query->getModel();
        $table = $model->getTable();
        $types = $this->columnTypes($table);
        $columns = array_values(array_diff(array_keys($types), $this->excluded));
        $prefix = 'INSERT INTO '.$this->identifier($table).' ('
            .implode(', ', array_map($this->identifier(...), $columns)).') VALUES (';

        echo $this->comment($this->module.' export');
        echo $this->comment('Hotel: '.$this->hotel->name.' ('.$this->hotel->id.')');
        echo $this->comment('Exported at: '.now()->toIso8601String());
        echo $this->comment('Matching when the export started: '.$this->rowCount);
        echo $this->comment('Filters: '.json_encode($this->filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        echo "BEGIN;\n";

        $records = (clone $this->query)
            ->setEagerLoads([])
            ->orderBy($model->qualifyColumn('id'))
            ->cursor();

        $written = 0;

        foreach ($records as $record) {
            echo $prefix.implode(', ', array_map(
                fn (string $column) => $this->literal($record, $column, $types[$column]),
                $columns,
            )).");\n";
            $written++;
        }

        echo "COMMIT;\n";
        echo $this->comment('Rows written: '.$written);

        return $written;
    }

    /**
     * One `--` comment line. Line breaks in the text (a hotel name, say) are
     * flattened, otherwise the rest of the text would run as SQL.
     */
    private function comment(string $text): string
    {
        return '-- '.preg_replace('/[\r\n\x{2028}\x{2029}\x0B\x0C\x85]+/u', ' ', $text)."\n";
    }

    /**
     * Column name => Postgres type name, in table order.
     *
     * @return array<string, string>
     */
    private function columnTypes(string $table): array
    {
        return collect(Schema::getColumns($table))
            ->mapWithKeys(fn (array $column) => [$column['name'] => $column['type_name']])
            ->all();
    }

    private function literal(Model $record, string $column, string $type): string
    {
        $value = $record->getRawOriginal($column);

        if ($value === null) {
            return 'NULL';
        }

        if ($type === 'bool') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'TRUE' : 'FALSE';
        }

        if (in_array($type, self::NUMERIC_TYPES, true) && is_numeric($value)) {
            return (string) $value;
        }

        return DB::connection()->getPdo()->quote((string) $value);
    }

    private function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
