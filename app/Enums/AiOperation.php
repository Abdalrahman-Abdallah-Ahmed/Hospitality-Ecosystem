<?php

namespace App\Enums;

/**
 * The kind of provider call being paid for. Chat and embeddings are priced
 * on completely different scales — a per-call average that mixes them
 * describes neither.
 */
enum AiOperation: string
{
    case CHAT = 'chat';
    case EMBEDDING = 'embedding';
    case RERANK = 'rerank';
}
