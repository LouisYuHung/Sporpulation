<?php

namespace App\Exceptions;

/**
 * 在這個請求來得及佔位之前，所有名額都已被取走。
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
