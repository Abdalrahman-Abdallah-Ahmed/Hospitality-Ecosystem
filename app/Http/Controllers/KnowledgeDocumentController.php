<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesKnowledgeDocuments;
use App\Models\Hotel;
use App\Models\KnowledgeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A hotel's own knowledge documents: always the hotel the request works in.
 * Global documents never appear here — they are managed from
 * routes/superadmin.php.
 */
class KnowledgeDocumentController extends Controller
{
    use HandlesKnowledgeDocuments;

    protected function knowledgeScope(Request $request): Hotel|JsonResponse|null
    {
        return resolveHotel($request->user()) ?? apiResponse('You do not belong to any hotel.', 403);
    }

    protected function authorizeKnowledge(string $ability, KnowledgeDocument|string $subject): void
    {
        $this->authorize($ability, $subject);
    }
}
