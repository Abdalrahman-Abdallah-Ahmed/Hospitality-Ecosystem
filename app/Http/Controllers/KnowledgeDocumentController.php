<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesKnowledgeDocuments;
use App\Models\Hotel;
use App\Models\KnowledgeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A hotel's own knowledge documents. Always one hotel: a scoped user's own,
 * or the one a super admin names. Global documents never appear here — they
 * are managed from routes/admin.php.
 */
class KnowledgeDocumentController extends Controller
{
    use HandlesKnowledgeDocuments;

    protected function knowledgeScope(Request $request): Hotel|JsonResponse|null
    {
        $user = $request->user();

        if ($user->isSuperAdmin() && ! $request->filled('hotel_id')) {
            return apiResponse('A hotel_id is required.', 422);
        }

        $hotel = resolveHotel($user, $request->input('hotel_id'));

        if ($hotel === null) {
            return $user->isSuperAdmin()
                ? apiResponse('Hotel not found.', 404)
                : apiResponse('You do not belong to any hotel.', 403);
        }

        return $hotel;
    }

    protected function authorizeKnowledge(string $ability, KnowledgeDocument|string $subject): void
    {
        $this->authorize($ability, $subject);
    }
}
