<?php

namespace App\Enums;

/**
 * The ordered stages every import row passes through (FR-001). A row's stage
 * is the one it reached, or the one that stopped it.
 */
enum ImportStage: string
{
    case PARSE = 'parse';
    case NORMALIZE = 'normalize';
    case VALIDATE = 'validate';
    case MAP = 'map';
    case IMPORT = 'import';
    case REPORT = 'report';
}
