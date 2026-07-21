<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

#[Fillable(['name'])]
class City extends Model
{
    use HasTranslations;

    public $timestamps = false;

    public array $translatable = ['name'];

    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }
}
