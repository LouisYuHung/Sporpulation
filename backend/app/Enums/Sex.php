<?php

namespace App\Enums;

/**
 * 對應 users.sex 的 tinyint 欄位。欄位為 null 代表「未指定」，因此刻意不為它
 * 定義任何 case。
 */
enum Sex: int
{
    case Male = 1;
    case Female = 2;
    case Other = 3;

    /**
     * 依目前語系（由 SetLocale middleware 設定）取得顯示名稱。
     */
    public function label(): string
    {
        return __('enums.sex.'.strtolower($this->name));
    }

    /**
     * 供表單下拉選單使用的 value/label 配對。
     *
     * @return list<array{value: int, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $sex) => ['value' => $sex->value, 'label' => $sex->label()],
            self::cases(),
        );
    }
}
