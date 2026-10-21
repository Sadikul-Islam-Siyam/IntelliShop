<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface BranchScope
{
    /** Apply the authenticated user's branch scope; administrators may be unrestricted. */
    public function apply(Builder $query, ?int $branchId): Builder;
}
