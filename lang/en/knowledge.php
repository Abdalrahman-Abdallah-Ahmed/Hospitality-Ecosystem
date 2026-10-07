<?php

return [

    'duplicate' => 'This file was already uploaded as “:title”.',

    'failures' => [
        'unsupported_type' => 'This file type is not supported. Upload a PDF, Word (DOCX), Excel (XLSX), CSV, text, Markdown, JPEG, PNG or WebP file.',
        'encrypted' => 'The file is password-protected. Remove the password and upload it again.',
        'corrupt' => 'The file could not be read. It may be damaged; save it again and replace it.',
        'duplicate' => 'The new file is identical to another document that is already uploaded.',
        'no_text' => 'No readable text was found in this file.',
        'too_many_scanned_pages' => 'This document has more than :max scanned pages, which is the limit for reading scans. Split it into smaller files.',
        'too_many_rows' => 'This spreadsheet has too many rows to index. Split it into smaller files.',
        'too_large_for_vision' => 'This file is too large for its scanned pages to be read. Upload a smaller file.',
        'file_missing' => 'The stored file could not be found. Replace the file to index it again.',
        'usage_limit_reached' => 'The AI usage limit was reached. Try indexing again later.',
        'extraction_unavailable' => 'The text could not be read right now. Try indexing again later.',
        'embedding_unavailable' => 'The search index could not be updated right now. Try indexing again later.',
    ],

];
