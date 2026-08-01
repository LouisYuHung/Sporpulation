<?php

namespace App\Models;

use Database\Factories\DistrictFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Translatable\HasTranslations;

#[Fillable(['city_id', 'name'])]
class District extends Model
{
    /** @use HasFactory<DistrictFactory> */
    use HasFactory, HasTranslations;

    protected $table = 'districts';

    public $timestamps = false;

    public array $translatable = ['name'];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function postalCode(): HasOne
    {
        return $this->hasOne(PostalCode::class);
    }

    /**
     * Users who have tagged this district as an area they frequent.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_area_tags')
            ->wherePivotNull('deleted_at');
    }
}
