<?php

namespace App\Enums;

/**
 * Backed by tinyint on activity_registrations.status.
 *
 * Cancelling flips the status rather than deleting the row, so a user who
 * rejoins reuses their existing registration. Values are reserved rather than
 * reused: a future waitlist adds a case here without touching stored data.
 */
enum RegistrationStatus: int
{
    case Confirmed = 1;
    case Cancelled = 2;
    // 3 is reserved for Waitlisted.

    /**
     * Display name in the current locale (set by the SetLocale middleware).
     */
    public function label(): string
    {
        return __('enums.registration_status.'.strtolower($this->name));
    }
}
