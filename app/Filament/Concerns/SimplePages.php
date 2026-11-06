<?php

namespace App\Filament\Concerns;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * A long list (calls, API requests) pages with Previous and Next, without counting every row
 * first: one page of rows is read, so the page opens as fast with millions of rows as with ten.
 */
trait SimplePages
{
    protected function paginateTableQuery(Builder $query): Paginator|CursorPaginator
    {
        $perPage = $this->getTableRecordsPerPage();

        return $query->simplePaginate(
            perPage: $perPage === 'all' ? 100 : (int) $perPage,
            columns: ['*'],
            pageName: $this->getTablePaginationPageName(),
        );
    }
}
