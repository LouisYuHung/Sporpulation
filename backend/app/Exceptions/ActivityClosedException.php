<?php

namespace App\Exceptions;

/**
 * 活動已經開始，因此不再接受報名。
 */
class ActivityClosedException extends ConflictException
{
    public function __construct()
    {
        parent::__construct(__('messages.activities.closed'));
    }

    public function errorCode(): string
    {
        return 'activity_closed';
    }
}
