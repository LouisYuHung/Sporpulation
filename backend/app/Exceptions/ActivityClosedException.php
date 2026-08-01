<?php

namespace App\Exceptions;

/**
 * The activity has already started, so it no longer takes registrations.
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
