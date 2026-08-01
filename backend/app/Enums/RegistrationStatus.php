<?php

namespace App\Enums;

/**
 * 對應 activity_registrations.status 的 tinyint 欄位。
 *
 * 取消是翻轉狀態而非刪除資料列，因此重新報名的使用者會沿用既有的報名紀錄。數值只
 * 保留、不重複使用：未來要加候補名單時，只需在這裡新增一個 case，不必動到既有資料。
 */
enum RegistrationStatus: int
{
    case Confirmed = 1;
    case Cancelled = 2;
    // 3 保留給候補（Waitlisted）。

    /**
     * 依目前語系（由 SetLocale middleware 設定）取得顯示名稱。
     */
    public function label(): string
    {
        return __('enums.registration_status.'.strtolower($this->name));
    }
}
