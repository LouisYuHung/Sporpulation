<?php

namespace App\Http\Middleware;

use App\Exceptions\IdempotencyKeyReusedException;
use App\Exceptions\RequestInProgressException;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Replays the original response when a write is retried with the same
 * Idempotency-Key.
 *
 * This is a general safety net, separate from the guarantees a specific
 * endpoint already makes. Joining an activity is idempotent on its own because
 * of unique(activity_id, user_id); a key protects writes that have no such
 * natural key, and stops a retry storm reaching the domain logic at all.
 *
 * Opt in per route: without the header, requests pass straight through.
 */
class EnsureIdempotentRequest
{
    /**
     * Responses that release the key instead of being stored, because they
     * mean nothing was done and a later attempt may succeed. See remember().
     */
    private const RELEASED_STATUSES = [
        Response::HTTP_CONFLICT,
        Response::HTTP_TOO_MANY_REQUESTS,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(config('idempotency.header'));
        $user = $request->user();

        // Nothing to do for reads, for clients that do not opt in, or on the
        // routes that allow guests - records are scoped to a user.
        if ($key === null || $user === null || $request->isMethodSafe()) {
            return $next($request);
        }

        $this->validateKey($key);

        $fingerprint = $this->fingerprint($request);
        $record = $this->claim($user->getAuthIdentifier(), $key, $fingerprint);

        // The claim went to someone else: either the first request is still
        // running, or it finished and its answer is on file.
        if ($record === null) {
            return $this->replay($user->getAuthIdentifier(), $key, $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // The routing pipeline normally turns exceptions into responses
            // before they reach here, so this covers only the ones that escape
            // it. Either way the claim must go, or the client's retry is met
            // with "still in progress" until the record expires.
            $record->delete();

            throw $e;
        }

        $this->remember($record, $response);

        return $response;
    }

    /**
     * Stake a claim on the key, or null when someone already holds it.
     *
     * The insert is the atomic part: unique(user_id, key_hash) means exactly
     * one concurrent request can win, so duplicates never both reach the
     * controller. Same mechanism the seat counter uses, for the same reason -
     * the database decides, not a read-then-write in PHP.
     */
    private function claim(int|string $userId, string $key, string $fingerprint): ?IdempotencyKey
    {
        try {
            return IdempotencyKey::create([
                'user_id' => $userId,
                'key_hash' => $this->hash($key),
                'fingerprint' => $fingerprint,
                'expires_at' => now()->addSeconds(config('idempotency.ttl')),
            ]);
        } catch (UniqueConstraintViolationException) {
            // An expired record still occupies the key until pruning runs, so
            // clear it out and try once more before giving up the claim.
            $stale = $this->find($userId, $key);

            if ($stale !== null && $stale->hasExpired()) {
                $stale->delete();

                return $this->claim($userId, $key, $fingerprint);
            }

            return null;
        }
    }

    /**
     * Answer from the record someone else already wrote.
     */
    private function replay(int|string $userId, string $key, string $fingerprint): Response
    {
        $record = $this->find($userId, $key);

        // Deleted between the failed claim and this read - whoever held it
        // gave it up, so the caller may as well retry from scratch.
        if ($record === null) {
            throw new RequestInProgressException;
        }

        // Same key, different request: always a client bug, never something to
        // paper over by replaying an unrelated response.
        if ($record->fingerprint !== $fingerprint) {
            throw new IdempotencyKeyReusedException;
        }

        if ($record->isInProgress()) {
            throw new RequestInProgressException;
        }

        return response($record->body, $record->status)
            ->withHeaders(array_filter([
                'Content-Type' => $record->content_type,

                // So the client can tell a replay from a fresh result - useful
                // when debugging, and harmless otherwise.
                'Idempotent-Replay' => 'true',
            ]));
    }

    /**
     * Store the outcome so a retry can be answered without running again.
     *
     * Only outcomes a retry must not repeat are worth storing. A 409 says the
     * request lost a race and changed nothing - the activity was full, say -
     * and a 429 says it was never let through at all. Storing either would pin
     * the client to a stale "no" for the whole TTL, even once a seat opens up,
     * so the record is dropped and the retry gets a fresh answer. Same for
     * 5xx, where the outcome is simply unknown.
     */
    private function remember(IdempotencyKey $record, Response $response): void
    {
        $status = $response->getStatusCode();

        if ($status >= 500 || in_array($status, self::RELEASED_STATUSES, true)) {
            $record->delete();

            return;
        }

        $record->update([
            'status' => $status,
            'body' => $response->getContent(),
            'content_type' => $response->headers->get('Content-Type'),
        ]);
    }

    private function find(int|string $userId, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::where('user_id', $userId)
            ->where('key_hash', $this->hash($key))
            ->first();
    }

    /**
     * Identifies what was asked, so reusing a key for a different call is
     * caught rather than answered with the wrong response.
     */
    private function fingerprint(Request $request): string
    {
        return $this->hash(implode('|', [
            $request->method(),
            $request->path(),
            $request->getContent(),
        ]));
    }

    private function validateKey(string $key): void
    {
        $length = strlen($key);

        if ($length < config('idempotency.min_length') || $length > config('idempotency.max_length')) {
            throw ValidationException::withMessages([
                config('idempotency.header') => __('messages.idempotency.invalid'),
            ]);
        }
    }

    private function hash(string $value): string
    {
        return hash('xxh128', $value);
    }
}
