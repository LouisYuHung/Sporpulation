<?php

namespace App\Enums;

/**
 * Backed by tinyint on users.sex. A null column means "not specified",
 * so there is deliberately no case for it.
 */
enum Sex: int
{
    case Male = 1;
    case Female = 2;
    case Other = 3;

    /**
     * Display name in the current locale (set by the SetLocale middleware).
     */
    public function label(): string
    {
        return __('enums.sex.'.strtolower($this->name));
    }

    /**
     * Value/label pairs for a form dropdown.
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
