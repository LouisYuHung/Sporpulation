<?php

namespace Database\Seeders;

use App\Models\Sport;
use Illuminate\Database\Seeder;

class SportSeeder extends Seeder
{
    /**
     * @var array<string, string> zh-TW => en
     */
    public const SPORTS = [
        '籃球' => 'Basketball',
        '排球' => 'Volleyball',
        '足球' => 'Soccer',
        '棒球' => 'Baseball',
        '壘球' => 'Softball',
        '羽球' => 'Badminton',
        '網球' => 'Tennis',
        '桌球' => 'Table Tennis',
        '撞球' => 'Billiards',
        '保齡球' => 'Bowling',
        '高爾夫' => 'Golf',
        '飛盤' => 'Frisbee',
        '躲避球' => 'Dodgeball',
        '手球' => 'Handball',
        '橄欖球' => 'Rugby',
        '游泳' => 'Swimming',
        '衝浪' => 'Surfing',
        '潛水' => 'Diving',
        '跑步' => 'Running',
        '田徑' => 'Track and Field',
        '自行車' => 'Cycling',
        '登山' => 'Hiking',
        '攀岩' => 'Rock Climbing',
        '重訓' => 'Weight Training',
        '瑜伽' => 'Yoga',
        '拳擊' => 'Boxing',
        '柔道' => 'Judo',
        '跆拳道' => 'Taekwondo',
        '劍道' => 'Kendo',
        '滑板' => 'Skateboarding',
        '直排輪' => 'Inline Skating',
        '溜冰' => 'Ice Skating',
        '滑雪' => 'Skiing',
        '舞蹈' => 'Dance',
    ];

    /**
     * 執行資料填充。
     *
     * 以中文名稱當作識別鍵，重複執行只會更新既有資料，不會產生重複的運動。
     */
    public function run(): void
    {
        $existing = Sport::all()->keyBy(
            fn (Sport $sport) => $sport->getTranslation('name', 'zh-TW')
        );

        foreach (self::SPORTS as $zh => $en) {
            $sport = $existing->get($zh) ?? new Sport;
            $sport->setTranslations('name', ['zh-TW' => $zh, 'en' => $en]);
            $sport->save();
        }
    }
}
