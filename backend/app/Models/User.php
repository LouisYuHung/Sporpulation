<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\RegistrationStatus;
use App\Enums\Sex;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     * 使用者標記為常出沒地區的行政區。
     *
     * 只會儲存行政區；縣市來自 districts.city_id（以 `areas.city` 預先載入）。
     */
    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(District::class, 'user_area_tags')
            ->withPivot(['created_at', 'deleted_at'])
            ->wherePivotNull('deleted_at');
    }

    /**
     * 將一個行政區標記為使用者的常用地區。
     *
     * 若先前曾移除過，會改為還原該筆標記而非新增，因為
     * unique(user_id, district_id) 索引同樣涵蓋軟刪除的資料列。
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
     * 取消一個常用地區的標記。採軟刪除，以便重新加入時可以還原該資料列。
     */
    public function removeArea(int $districtId): void
    {
        UserAreaTag::where('user_id', $this->id)
            ->where('district_id', $districtId)
            ->delete();
    }

    /**
     * 使用者從事的運動。
     */
    public function sports(): BelongsToMany
    {
        return $this->belongsToMany(Sport::class, 'user_sports')
            ->withPivot(['level', 'created_at', 'deleted_at'])
            ->wherePivotNull('deleted_at');
    }

    /**
     * 新增一項使用者從事的運動，可一併帶入自評等級（1-10）。
     *
     * 若先前曾移除過，會改為還原該筆資料列而非新增，因為
     * unique(user_id, sport_id) 索引同樣涵蓋軟刪除的資料列。$level 為 null 時，
     * 既有的自評等級維持不變。
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
     * 設定使用者已標記運動的自評等級（傳入 null 則清除）。若該運動未被標記，
     * 回傳 null。
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
     * 移除一項運動。採軟刪除，以便重新加入時可以還原該資料列。
     */
    public function removeSport(int $sportId): void
    {
        UserSport::where('user_id', $this->id)
            ->where('sport_id', $sportId)
            ->delete();
    }

    /**
     * 使用者主辦的活動。
     */
    public function hostedActivities(): HasMany
    {
        return $this->hasMany(Activity::class, 'host_id');
    }

    /**
     * 使用者所有的報名紀錄，包含已取消的。
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(ActivityRegistration::class);
    }

    /**
     * 使用者目前持有名額的活動。
     */
    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(Activity::class, 'activity_registrations')
            ->withPivot(['status', 'joined_at'])
            ->wherePivot('status', RegistrationStatus::Confirmed->value);
    }

    /**
     * 取得需要進行型別轉換的屬性。
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
