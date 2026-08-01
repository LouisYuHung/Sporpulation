<?php

namespace App\Exceptions;

/**
 * Another request carrying this idempotency key is still running. The client
 * should wait and retry with the same key rather than treat this as a failure.
 */
class RequestInProgressException extends ConflictException
{
    public function __construct()
    {
        parent::__construct(__('messages.idempotency.in_progress'));
    }

    public function errorCode(): string
    {
        return 'request_in_progress';
    }
}
