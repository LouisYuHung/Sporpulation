<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Idempotency Keys
    |--------------------------------------------------------------------------
    |
    | A client that retries a write after a timeout has no way of knowing
    | whether the first attempt landed. Sending the same key on the retry lets
    | the server recognise it and replay the original response instead of
    | acting twice.
    |
    | Records live in the idempotency_keys table, not the cache: the cache gets
    | cleared as a matter of routine, and losing these would remove the
    | protection without anything failing loudly. Expired rows are pruned on a
    | schedule (see routes/console.php).
    |
    */

    'header' => 'Idempotency-Key',

    /*
    | How long a key is remembered. Long enough to cover any retry a client
    | would reasonably make, short enough that keys do not accumulate forever.
    */
    'ttl' => 24 * 60 * 60,

    /*
    | Bounds on the key itself, so a client cannot fill the table with one
    | enormous key or collide by sending something trivially short.
    */
    'min_length' => 8,
    'max_length' => 255,

];
