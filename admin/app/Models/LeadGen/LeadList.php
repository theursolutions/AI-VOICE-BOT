<?php

namespace App\Models\LeadGen;

use App\Models\Concerns\IntSoftDeletes;

/** A saved, hand-curated set of prospects — what a campaign is built from. */
class LeadList extends LeadGenModel
{
    use IntSoftDeletes;

    protected $table = 'lg_lists';

    public function prospects()
    {
        return $this->belongsToMany(Prospect::class, 'lg_list_items', 'list_id', 'prospect_id');
    }

    public function items()
    {
        return $this->hasMany(LeadListItem::class, 'list_id');
    }
}
