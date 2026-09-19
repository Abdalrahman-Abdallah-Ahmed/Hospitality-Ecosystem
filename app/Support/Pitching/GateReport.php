<?php

namespace App\Support\Pitching;

use App\Enums\PitchGate;

/**
 * Every gate evaluated for one turn, in evaluation order. Failures are kept
 * alongside passes: knowing that five of six passed tells you which rule is
 * doing the blocking.
 */
final readonly class GateReport
{
    /**
     * @param  list<GateResult>  $results
     */
    public function __construct(
        public array $results = [],
    ) {}

    public function with(GateResult ...$results): self
    {
        return new self([...$this->results, ...$results]);
    }

    public function passed(): bool
    {
        foreach ($this->results as $result) {
            if (! $result->passed) {
                return false;
            }
        }

        return true;
    }

    public function blocked(PitchGate $gate): bool
    {
        foreach ($this->results as $result) {
            if ($result->gate === $gate && ! $result->passed) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{gate: string, passed: bool, detail: ?string}>
     */
    public function toArray(): array
    {
        return array_map(fn (GateResult $result) => $result->toArray(), $this->results);
    }
}
