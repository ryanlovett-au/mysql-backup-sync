<?php

namespace Tests\Support;

use App\Database\Backup;

/** A Backup whose server estimate we decide, so the apportioning maths can be tested on its own. */
class EstimatingBackup extends Backup
{
    public ?int $estimate = null;

    public function __construct() {}

    protected function estimated_rows(): ?int
    {
        return $this->estimate;
    }

    public function estimateAbove(string $column, $value): ?int
    {
        return $this->estimated_rows_above($column, $value);
    }
}
