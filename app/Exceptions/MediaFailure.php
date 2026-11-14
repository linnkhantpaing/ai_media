<?php

namespace App\Exceptions;

use RuntimeException;

class MediaFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $retryable = false, public readonly ?int $retryAfter = null)
    {
        parent::__construct($reason);
    }
}
