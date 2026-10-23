<?php

namespace App\Http\Resources\Concerns;

use Illuminate\Http\Request;

trait DetectsAudience
{
    /** Internal (platform) fields - supplier cost, markup, profit - only on admin routes. */
    protected function isInternal(Request $request): bool
    {
        return $request->is('api/v1/admin/*');
    }
}
