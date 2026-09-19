<?php

namespace App\Support\Pitching;

use App\Enums\PitchGate;

final readonly class GateResult
{
    public function __construct(
        public PitchGate $gate,
        public bool $passed,
        public ?string $detail = null,
    ) {}

    public static function pass(PitchGate $gate, ?string $detail = null): self
    {
        return new self($gate, true, $detail);
    }

    public static function block(PitchGate $gate, ?string $detail = null): self
    {
        return new self($gate, false, $detail);
    }

    /**
     * @return array{gate: string, passed: bool, detail: ?string}
     */
    public function toArray(): array
    {
        return ['gate' => $this->gate->value, 'passed' => $this->passed, 'detail' => $this->detail];
    }
}
