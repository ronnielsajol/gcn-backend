<?php

namespace App\Http\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait FilterSortTrait
{
    /**
     * Apply search and filters to the query
     */
    protected function applyFilters(Builder $query, Request $request, array $searchableFields = [], array $filterableFields = []): void
    {
        // Apply search functionality
        if ($request->filled('search') && !empty($searchableFields)) {
            $search = $request->search;
            $query->where(function ($q) use ($search, $searchableFields) {
                foreach ($searchableFields as $field) {
                    $q->orWhere($field, 'like', "%{$search}%");
                }
            });
        }

        // Apply filters
        foreach ($filterableFields as $filter) {
            if ($request->filled($filter) && $request->$filter !== 'all') {
                $query->where($filter, $request->$filter);
            }
        }

        // Apply status filter (common pattern)
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('is_active', $request->status === 'active');
        }
    }

    /**
     * Apply sorting to the query
     */
    protected function applySorting(Builder $query, Request $request, array $sortableFields = [], string $defaultSort = 'created_at', string $defaultDirection = 'desc'): void
    {
        if ($request->filled('sort') && in_array($request->sort, $sortableFields)) {
            $query->orderBy($request->sort, $this->getSortDirection($request->direction));
        } else {
            $query->orderBy($defaultSort, $defaultDirection);
        }
    }

    /**
     * Get valid sort direction
     */
    protected function getSortDirection(?string $direction): string
    {
        return in_array(strtolower($direction ?? ''), ['asc', 'desc']) ? strtolower($direction) : 'asc';
    }

    /**
     * Get pagination limit with bounds checking
     */
    protected function getPerPageLimit(Request $request, int $default = 15, int $max = 100): int
    {
        return min(max((int) ($request->per_page ?? $default), 1), $max);
    }
}
