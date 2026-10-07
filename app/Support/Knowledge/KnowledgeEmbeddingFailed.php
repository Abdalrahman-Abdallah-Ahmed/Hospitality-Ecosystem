<?php

namespace App\Support\Knowledge;

use RuntimeException;
use Throwable;

/**
 * The text was read, but turning it into searchable passages failed (the
 * embedding provider, or the swap). Thrown in place of the original error so
 * the queue retries it, and so the job's failed() handler — which runs on a
 * fresh copy of the job — can still tell which stage gave up.
 */
class KnowledgeEmbeddingFailed extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
