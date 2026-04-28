<?php

namespace App\Services\Reports;

abstract class AbstractReportType implements ReportTypeContract
{
    /** Хелпер: округлить и привести к скаляру для CSV. */
    protected function n($value, int $decimals = 2): float
    {
        return round((float) $value, $decimals);
    }
}
