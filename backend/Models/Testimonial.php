<?php

namespace Theme\Backend\Models;

use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * A quote from a candidate, reviewed and published by staff.
 *
 * Content, not correspondence: the message it was curated from stays in Leads, and this row is
 * what the storefront renders. See the migration for why the two are kept apart.
 */
class Testimonial extends Model
{
    use SoftDeletes;
    use HasTranslations;
    use LogsSystemActivity;

    protected $table = 'lumen_testimonials';

    public $translatable = ['quote'];

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'author',
        'role',
        'quote',
        'rating',
        'status',
        'orders',
        'lead_id',
    ];

    protected $casts = [
        'rating'  => 'integer',
        'orders'  => 'integer',
        'lead_id' => 'integer',
    ];

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('orders')->orderByDesc('id');
    }
}
