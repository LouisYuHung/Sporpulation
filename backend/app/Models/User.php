<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Sex;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'nickname', 'avatar', 'sex', 'email', 'password', 'google_id', 'line_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Districts the user has tagged as areas they frequent.
     *
     * Only the district is stored; the city comes from districts.city_id
     * (eager load with `areas.city`).
     */
    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(District::class, 'user_area_tags')
            ->withPivot(['created_at', 'deleted_at'])
            ->wherePivotNull('deleted_at');
    }

    /**
     * Tag a district as an area the user frequents.
     *
     * Restores a previously removed tag instead of inserting, because the
     * unique(user_id, district_id) index also covers soft-deleted rows.
     */
    public function addArea(int $districtId): UserAreaTag
    {
        $tag = UserAreaTag::withTrashed()
            ->where('user_id', $this->id)
            ->where('district_id', $districtId)
            ->first();

        if ($tag === null) {
            return UserAreaTag::create([
                'user_id' => $this->id,
                'district_id' => $districtId,
            ]);
        }

        if ($tag->trashed()) {
            $tag->restore();
        }

        return $tag;
    }

    /**
     * Untag an area. Soft deletes so the row can be restored on re-add.
     */
    public function removeArea(int $districtId): void
    {
        UserAreaTag::where('user_id', $this->id)
            ->where('district_id', $districtId)
            ->delete();
    }

    /**
     * Sports the user plays.
     */
    public function sports(): BelongsToMany
    {
        return $this->belongsToMany(Sport::class, 'user_sports')
            ->withPivot(['level', 'created_at', 'deleted_at'])
            ->wherePivotNull('deleted_at');
    }

    /**
     * Add a sport the user plays, optionally with a self-rated level (1-10).
     *
     * Restores a previously removed row instead of inserting, because the
     * unique(user_id, sport_id) index also covers soft-deleted rows. A null
     * $level leaves any existing rating untouched.
     */
    public function addSport(int $sportId, ?int $level = null): UserSport
    {
        $userSport = UserSport::withTrashed()
            ->where('user_id', $this->id)
            ->where('sport_id', $sportId)
            ->first();

        if ($userSport === null) {
            return UserSport::create([
                'user_id' => $this->id,
                'sport_id' => $sportId,
                'level' => $level,
            ]);
        }

        if ($userSport->trashed()) {
            $userSport->restore();
        }

        if ($level !== null) {
            $userSport->update(['level' => $level]);
        }

        return $userSport;
    }

    /**
     * Set (or clear, with null) the self-rated level for a sport the user
     * already plays. Returns null when the sport is not tagged.
     */
    public function setSportLevel(int $sportId, ?int $level): ?UserSport
    {
        $userSport = UserSport::where('user_id', $this->id)
            ->where('sport_id', $sportId)
            ->first();

        $userSport?->update(['level' => $level]);

        return $userSport;
    }

    /**
     * Remove a sport. Soft deletes so the row can be restored on re-add.
     */
    public function removeSport(int $sportId): void
    {
        UserSport::where('user_id', $this->id)
            ->where('sport_id', $sportId)
            ->delete();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'sex' => Sex::class,
        ];
    }
}
