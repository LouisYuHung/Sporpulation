<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A 404 raised deliberately by the application, carrying a message that is
 * already localised.
 *
 * Framework-generated NotFoundHttpExceptions (unmatched routes, failed route
 * model binding) carry internal English text, so the exception handler
 * replaces those with a generic localised message. Throwing this subclass
 * instead marks the message as ours and safe to show.
 */
class ResourceNotFoundException extends NotFoundHttpException
{
}
