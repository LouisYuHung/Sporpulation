<?php

namespace App\Exceptions;

/**
 * The key was already used for a different request. Replaying the stored
 * response would answer a question the client did not ask, so this is refused
 * outright - it always means a bug in how the client generates keys.
 */
class IdempotencyKeyReusedException extends ConflictException
{
    public function __construct()
    {
        parent::__construct(__('messages.idempotency.reused'));
    }

    public function errorCode(): string
    {
        return 'idempotency_key_reused';
    }
}
