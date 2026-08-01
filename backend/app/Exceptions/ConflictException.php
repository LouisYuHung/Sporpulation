<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A 409 raised deliberately by the application: the request was well formed
 * but lost a race against the current state of the resource.
 *
 * Subclasses carry an already-localised message plus a stable machine code, so
 * the client can branch on the outcome without parsing display text.
 */
abstract class ConflictException extends ConflictHttpException
{
    /**
     * Stable identifier for this outcome, returned as `code` in the response.
     */
    abstract public function errorCode(): string;
}
