<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\District;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 示範用的活動資料，讓全新安裝時列表與報名畫面有東西可以顯示。會在地區與運動
 * seeder 之後執行。
 *
 * 僅供本地開發使用，不會在正式環境執行，詳見 DatabaseSeeder。
 */
class ActivitySeeder extends Seeder
{
    /**
     * @var list<array{title: string, sport: string, location: string, capacity: int, in_days: int, hour: int}>
     */
    private const ACTIVITIES = [
        ['title' => '週三晚間鬥牛', 'sport' => '籃球', 'location' => '大安運動中心', 'capacity' => 10, 'in_days' => 2, 'hour' => 19],
        ['title' => '新手羽球團', 'sport' => '羽球', 'location' => '中正運動中心', 'capacity' => 8, 'in_days' => 3, 'hour' => 20],
        ['title' => '河濱晨跑 5K', 'sport' => '跑步', 'location' => '大佳河濱公園', 'capacity' => 20, 'in_days' => 4, 'hour' => 6],
        ['title' => '週末排球約打', 'sport' => '排球', 'location' => '信義運動中心', 'capacity' => 12, 'in_days' => 5, 'hour' => 14],
        ['title' => '桌球單打練習', 'sport' => '桌球', 'location' => '松山運動中心', 'capacity' => 4, 'in_days' => 6, 'hour' => 18],
        ['title' => '登山健行 - 象山', 'sport' => '登山', 'location' => '象山登山口', 'capacity' => 15, 'in_days' => 9, 'hour' => 8],
        ['title' => '五人制足球', 'sport' => '足球', 'location' => '青年公園', 'capacity' => 10, 'in_days' => 11, 'hour' => 16],
        ['title' => '週日網球雙打', 'sport' => '網球', 'location' => '網球中心', 'capacity' => 4, 'in_days' => 12, 'hour' => 10],
    ];

    public function run(): void
    {
        // 這裡不用 factory 補使用者：factory 依賴 require-dev 的 faker，正式
        // 映像檔裡並不存在。主辦人由 DemoDataSeeder 事先建好。
        $host = User::first();

        if ($host === null) {
            $this->command?->warn('No users seeded; skipping activities.');

            return;
        }

        $districts = District::inRandomOrder()->limit(count(self::ACTIVITIES))->get();

        if ($districts->isEmpty()) {
            $this->command?->warn('No districts seeded; skipping activities.');

            return;
        }

        foreach (self::ACTIVITIES as $index => $activity) {
            $sport = Sport::whereJsonContains('name->zh-TW', $activity['sport'])->first();

            if ($sport === null) {
                continue;
            }

            $startsAt = now()->addDays($activity['in_days'])->setTime($activity['hour'], 0);

            // 以「主辦人 + 標題」當識別鍵，重跑只會把時間往後推，不會多出一批重複活動。
            Activity::updateOrCreate(
                [
                    'host_id' => $host->id,
                    'title' => $activity['title'],
                ],
                [
                    'sport_id' => $sport->id,
                    'district_id' => $districts[$index % $districts->count()]->id,
                    'location' => $activity['location'],
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->copy()->addHours(2),
                    'capacity' => $activity['capacity'],
                ],
            );
        }
    }
}
