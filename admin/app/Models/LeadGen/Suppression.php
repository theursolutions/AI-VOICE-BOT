<?php

namespace App\Models\LeadGen;

/** An address that must not be contacted again (opt-out, bounce, manual). */
class Suppression extends LeadGenModel
{
    public const UPDATED_AT = null;

    public const KINDS   = ['email', 'phone', 'domain'];
    public const REASONS = ['opt_out', 'bounce', 'complaint', 'manual', 'deleted'];

    protected $table = 'lg_suppressions';
}
