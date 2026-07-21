<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'sport_id', 'level'])]
class UserSport extends Model
{
    use SoftDeletes;

    /**
     * The table only tracks created_at, so updated_at is disabled.
     */
    public const UPDATED_AT = null;

    protected $table = 'user_sports';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }
}
