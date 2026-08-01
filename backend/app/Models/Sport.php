<?php

namespace App\Models;

use Database\Factories\SportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Translatable\HasTranslations;

#[Fillable(['name'])]
class Sport extends Model
{
    /** @use HasFactory<SportFactory> */
    use HasFactory, HasTranslations;

    public $timestamps = false;

    public array $translatable = ['name'];

    /**
     * Users who play this sport.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_sports')
            ->wherePivotNull('deleted_at');
    }
}
