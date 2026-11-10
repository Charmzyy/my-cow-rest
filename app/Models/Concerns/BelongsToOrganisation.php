<?php

namespace App\Models\Concerns;

use App\Models\Organisation;
use App\Support\CurrentOrganisation;
use Illuminate\Database\Eloquent\Builder;

// Rows that live inside one organisation. While a request has a workspace chosen, queries (and
// route-model binding) only see that organisation's rows, so another tenant's farm is a 404.
trait BelongsToOrganisation
{
    protected static function bootBelongsToOrganisation(): void
    {
        static::addGlobalScope('organisation', function (Builder $query) {
            if ($id = app(CurrentOrganisation::class)->id()) {
                $query->where($query->getModel()->qualifyColumn('organisation_id'), $id);
            }
        });
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }
}
