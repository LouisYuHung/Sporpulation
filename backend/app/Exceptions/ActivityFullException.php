<?php

namespace App\Exceptions;

/**
 * Every seat was taken before this request could claim one.
 */
class ActivityFullException extends ConflictException
{
    public function __construct()
    {
        parent::__construct(__('messages.activities.full'));
    }

    public function errorCode(): string
    {
        return 'activity_full';
    }
}
