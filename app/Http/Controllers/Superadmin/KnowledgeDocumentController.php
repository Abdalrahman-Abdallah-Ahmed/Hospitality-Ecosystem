<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HandlesKnowledgeDocuments;
use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\KnowledgeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global knowledge documents: platform knowledge every hotel's assistant
 * reads. Only ever hotel_id IS NULL rows; a hotel document id is a 404 here.
 *
 * There is no policy check because there is no per-hotel permission to check:
 * routes/admin.php is registered behind the super_admin middleware as a
 * whole, and nothing else may change what every tenant's assistant says.
 */
class KnowledgeDocumentController extends Controller
{
    use HandlesKnowledgeDocuments;

    protected function knowledgeScope(Request $request): Hotel|JsonResponse|null
    {
        return null;
    }

    protected function authorizeKnowledge(string $ability, KnowledgeDocument|string $subject): void
    {
        // Guarded by the super_admin middleware on the whole admin route file.
    }
}
