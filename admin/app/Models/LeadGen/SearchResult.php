<?php

namespace App\Models\LeadGen;

/** Link between a search and a prospect it produced, with the dedup outcome. */
class SearchResult extends LeadGenModel
{
    public const UPDATED_AT = null;

    public const NEW          = 'new';
    public const DUPLICATE    = 'duplicate';
    public const EXISTING_CRM = 'existing_crm';
    public const EXCLUDED     = 'excluded';

    protected $table = 'lg_search_results';

    public function prospect()
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }
}
