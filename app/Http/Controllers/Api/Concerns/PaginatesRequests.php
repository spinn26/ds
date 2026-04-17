<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;

/**
 * Shared helpers for request-driven pagination.
 *
 * Front-end data tables send `page` and `per_page` query params.
 * Historically controllers hard-coded `limit(25)` + `(page-1)*25` and
 * ignored per_page — so increasing Vuetify's "Items per page" did
 * nothing. These helpers replace that pattern:
 *
 *   $rows = $query
 *       ->offset($this->paginationOffset($request))
 *       ->limit($this->paginationPerPage($request))
 *       ->get();
 */
trait PaginatesRequests
{
    protected int $defaultPerPage = 25;
    protected int $maxPerPage = 100;

    protected function paginationPerPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', $this->defaultPerPage);
        return max(1, min($this->maxPerPage, $perPage));
    }

    protected function paginationPage(Request $request): int
    {
        return max(1, (int) $request->input('page', 1));
    }

    protected function paginationOffset(Request $request): int
    {
        return ($this->paginationPage($request) - 1) * $this->paginationPerPage($request);
    }
}
