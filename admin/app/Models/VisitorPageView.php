<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single page open by a visitor — the raw trail behind Visitor::page_views.
 *
 * Only `created_at` is kept: there is no update path, so an `updated_at`
 * column would be dead weight on the highest-volume table in the app.
 */
class VisitorPageView extends Model
{
    protected $connection = 'mysql';
    protected $table = 'visitor_page_views';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }
}
