<?php

namespace App\Support;

use Closure;

final class AutoDownloadDeadline
{
    private readonly Closure $clock;

    private readonly float $deadline;

    public function __construct(int $budgetSeconds, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $this->deadline = ($this->clock)() + max(0, $budgetSeconds);
    }

    public function expired(): bool
    {
        return ($this->clock)() >= $this->deadline;
    }

    public function remainingSeconds(): float
    {
        return max(0.0, $this->deadline - ($this->clock)());
    }
}
