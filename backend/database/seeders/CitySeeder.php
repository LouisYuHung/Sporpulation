<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

class CitySeeder extends Seeder
{
    /**
     * The 22 Taiwan cities/counties.
     *
     * English names use the MOI "international customary" column
     * (國際慣用名), e.g. Taipei / Kaohsiung / Hsinchu / Keelung.
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
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::CITIES as $zh => $en) {
            City::create([
                'name' => ['zh-TW' => $zh, 'en' => $en],
            ]);
        }
    }
}
