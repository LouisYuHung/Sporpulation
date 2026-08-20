<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

class CitySeeder extends Seeder
{
    /**
     * 臺灣 22 個縣市。
     *
     * 英文名稱採用內政部的「國際慣用名」欄位，例如 Taipei / Kaohsiung /
     * Hsinchu / Keelung。
     *
     * @var array<string, string> zh-TW => en
     */
    public const CITIES = [
        '臺北市' => 'Taipei City',
        '新北市' => 'New Taipei City',
        '桃園市' => 'Taoyuan City',
        '臺中市' => 'Taichung City',
        '臺南市' => 'Tainan City',
        '高雄市' => 'Kaohsiung City',
        '基隆市' => 'Keelung City',
        '新竹市' => 'Hsinchu City',
        '嘉義市' => 'Chiayi City',
        '新竹縣' => 'Hsinchu County',
        '苗栗縣' => 'Miaoli County',
        '彰化縣' => 'Changhua County',
        '南投縣' => 'Nantou County',
        '雲林縣' => 'Yunlin County',
        '嘉義縣' => 'Chiayi County',
        '屏東縣' => 'Pingtung County',
        '宜蘭縣' => 'Yilan County',
        '花蓮縣' => 'Hualien County',
        '臺東縣' => 'Taitung County',
        '澎湖縣' => 'Penghu County',
        '金門縣' => 'Kinmen County',
        '連江縣' => 'Lienchiang County',
    ];

    /**
     * 執行資料填充。
     *
     * 以中文名稱當作識別鍵，重複執行只會更新既有資料，不會產生重複的縣市。
     */
    public function run(): void
    {
        $existing = City::all()->keyBy(
            fn (City $city) => $city->getTranslation('name', 'zh-TW')
        );

        foreach (self::CITIES as $zh => $en) {
            $city = $existing->get($zh) ?? new City;
            $city->setTranslations('name', ['zh-TW' => $zh, 'en' => $en]);
            $city->save();
        }
    }
}
