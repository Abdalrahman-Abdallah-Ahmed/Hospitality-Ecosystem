<?php

namespace App\Enums;

/**
 * Where a knowledge document is in the indexing pipeline.
 *
 * Status says nothing about whether the document is searchable: a document
 * being re-indexed (`extracting`) or whose replacement failed (`failed`)
 * keeps its previous passages live until a new set is swapped in.
 */
enum KnowledgeDocumentStatus: string
{
    case UPLOADED = 'uploaded';
    case EXTRACTING = 'extracting';
    case INDEXED = 'indexed';
    case FAILED = 'failed';
}
