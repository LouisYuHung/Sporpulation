<?php

namespace App\Models;

use App\Enums\RegistrationStatus;
use App\Exceptions\ActivityClosedException;
use App\Exceptions\ActivityFullException;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'host_id',
    'sport_id',
    'district_id',
    'title',
    'description',
    'location',
    'starts_at',
    'ends_at',
    'capacity',
])]
/**
 * 「名額（seat）」就是 `joined_count` 的一個單位，僅此而已。
 *
 * 這兩個詞是刻意區分、不可互換的：`joined_count` 是資料表欄位與 API 欄位的名稱，
 * 而「名額」是領域用語，用在方法名稱與說明文字上（claimSeat、releaseSeat、
 * remainingSeats）。凡是用戶端或查詢看得到的一律叫 joined_count；凡是描述取用或
 * 歸還這個動作的一律叫名額。
 */
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    /**
     * 名額異動一律先取得活動資料列的寫入鎖，因此彼此會排隊而不會死結。這裡是雙重
     * 保險，用來涵蓋順序還沒顧到的情況：當資料庫回報死結或鎖等待逾時，Laravel 會
     * 重跑這個 transaction。
     */
    private const TRANSACTION_ATTEMPTS = 3;

    protected $table = 'activities';

    /**
     * joined_count 在資料表結構中同樣預設為 0；在這裡重複一次，是為了讓剛建立的活動
     * 不必重新讀取資料庫就能回報已佔用的名額數。
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'joined_count' => 0,
    ];

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ActivityRegistration::class);
    }

    /**
     * 目前持有名額的使用者。
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'activity_registrations')
            ->withPivot(['status', 'joined_at'])
            ->wherePivot('status', RegistrationStatus::Confirmed->value);
    }

    /**
     * 報名一位使用者，佔用一個名額。
     *
     * 具冪等性：重送同一個請求會回傳既有的報名紀錄，不會再佔用第二個名額。最後一個
     * 名額已被取走時拋出 ActivityFullException，活動已開始則拋出
     * ActivityClosedException。
     *
     * joined_count 是在資料庫端變更，不會反映到這個實例上：讀取前請先 refresh()。
     */
    public function join(User $user): ActivityRegistration
    {
        if (! $this->isOpenForRegistration()) {
            throw new ActivityClosedException;
        }

        $registration = $this->registrationFor($user);

        // 已經持有名額：代表呼叫端正在重試，因此回報同樣的結果，不再動到計數器。
        if ($registration?->isConfirmed()) {
            return $registration;
        }

        return $registration === null
            ? $this->createRegistration($user)
            : $this->reconfirmRegistration($registration);
    }

    /**
     * 釋出名額。具冪等性：取消一筆已取消（或根本不存在）的報名不會釋出任何名額。
     *
     * 回傳已取消的報名紀錄；使用者從未報名時回傳 null。與 join() 一樣，讀取
     * joined_count 前請先 refresh()。
     */
    public function cancel(User $user): ?ActivityRegistration
    {
        $registration = $this->registrationFor($user);

        if ($registration === null) {
            return null;
        }

        return DB::transaction(function () use ($registration) {
            $this->lockSeats();

            // 以資料列仍為已確認為條件：重送的取消請求（或彼此競爭的兩個取消）
            // 會比對不到任何資料列，因此名額只會被歸還一次。
            $cancelled = ActivityRegistration::whereKey($registration->id)
                ->confirmed()
                ->update([
                    'status' => RegistrationStatus::Cancelled,
                    'cancelled_at' => now(),
                ]);

            if ($cancelled === 1) {
                $this->releaseSeat();
            }

            return $registration->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * 使用者對這個活動的報名紀錄，不限狀態。
     */
    public function registrationFor(User $user): ?ActivityRegistration
    {
        return $this->registrations()->where('user_id', $user->id)->first();
    }

    /**
     * 活動開始後即停止報名。
     */
    public function isOpenForRegistration(): bool
    {
        return $this->starts_at->isFuture();
    }

    public function isFull(): bool
    {
        return $this->joined_count >= $this->capacity;
    }

    public function remainingSeats(): int
    {
        return max(0, $this->capacity - $this->joined_count);
    }

    /**
     * 尚未開始的活動，依開始時間由近到遠排序。
     *
     * @param  Builder<self>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where('starts_at', '>', now())->orderBy('starts_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'joined_count' => 'integer',
        ];
    }

    /**
     * 第一次報名。
     *
     * 名額會在插入資料列之前先佔用，順序很重要：插入時為了檢查外鍵，會對上層的活動
     * 資料列取得共享鎖，而接著想把它升級成互斥鎖的 transaction，會與其他做同樣事情
     * 的報名者互相死結。先佔名額等於一開始就取得互斥鎖，讓報名者在活動資料列上被
     * 序列化 - 而這正是計數器本來就需要的。
     *
     * 冪等性仍然由插入動作把關：重複的請求會違反 unique(activity_id, user_id)，
     * 使整個 transaction 回滾，連同名額一起還原。
     */
    private function createRegistration(User $user): ActivityRegistration
    {
        try {
            return DB::transaction(function () use ($user) {
                $this->claimSeat();

                return ActivityRegistration::create([
                    'activity_id' => $this->id,
                    'user_id' => $user->id,
                    'status' => RegistrationStatus::Confirmed,
                    'joined_at' => now(),
                ]);
            }, self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            // 同一位使用者的另一個並行請求搶先完成了。由於兩者都在活動資料列上被
            // 序列化，它的結果已成定局：不是留下可回傳的資料列，就是因為活動額滿
            // 而回滾。
            return $this->registrationFor($user) ?? throw new ActivityFullException;
        }
    }

    /**
     * 曾取消的使用者再次報名，沿用原有的資料列。
     */
    private function reconfirmRegistration(ActivityRegistration $registration): ActivityRegistration
    {
        return DB::transaction(function () use ($registration) {
            $this->lockSeats();

            // 以資料列仍為已取消為條件，理由和 cancel() 以已確認為條件相同：
            // 只有真正翻轉狀態的那個請求才能動到計數器。
            $reconfirmed = ActivityRegistration::whereKey($registration->id)
                ->where('status', RegistrationStatus::Cancelled)
                ->update([
                    'status' => RegistrationStatus::Confirmed,
                    'joined_at' => now(),
                    'cancelled_at' => null,
                ]);

            // 沒有比對到任何資料列：代表同一位使用者的另一個並行重新報名已經翻轉
            // 狀態並佔走名額，這次呼叫只負責回報結果。
            if ($reconfirmed === 1) {
                $this->claimSeat();
            }

            return $registration->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * 取得活動資料列的寫入鎖，但不變更任何內容。
     *
     * 每一條會動到計數器的路徑，都會在碰 activity_registrations 之前先鎖住這一列，
     * 因此並行的報名與取消永遠依相同順序排隊，不會互相死結。claimSeat() 會隱含地
     * 取得同一把鎖；可能不會走到那裡的路徑則在這裡取得。
     */
    private function lockSeats(): void
    {
        // 這裡要的不是資料列本身 - 只要它發出的 SELECT ... FOR UPDATE，該鎖會由
        // 外層 transaction 一直持有到 commit 為止。捨棄查詢結果是刻意的，不是疏漏。
        static::whereKey($this->id)->lockForUpdate()->first();
    }

    /**
     * 佔用一個名額，若已無剩餘則失敗。
     *
     * 檢查條件放在 WHERE 子句裡，讓讀取與寫入成為單一的原子敘述：兩個爭搶最後一個
     * 名額的請求，不可能同時認定它還空著。
     */
    private function claimSeat(): void
    {
        $claimed = static::whereKey($this->id)
            ->whereColumn('joined_count', '<', 'capacity')
            ->increment('joined_count');

        if ($claimed === 0) {
            throw new ActivityFullException;
        }
    }

    /**
     * 歸還一個名額。`> 0` 這個條件是安全網：只要每次釋出都與一次佔用配對，它就不會
     * 被觸發，但可以避免程式錯誤讓這個無號欄位發生下溢。
     */
    private function releaseSeat(): void
    {
        static::whereKey($this->id)
            ->where('joined_count', '>', 0)
            ->decrement('joined_count');
    }
}
