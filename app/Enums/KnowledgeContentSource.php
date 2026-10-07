<?php

namespace App\Enums;

/**
 * Where a document's indexed text came from: the stored file, or a staff
 * correction of it. Corrections survive re-indexing and rebuilds and are
 * dropped only when the file is replaced or staff discard them.
 */
enum KnowledgeContentSource: string
{
    case EXTRACTED = 'extracted';
    case CORRECTED = 'corrected';
}
