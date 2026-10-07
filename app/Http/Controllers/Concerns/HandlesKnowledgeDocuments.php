<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\Knowledge\CorrectKnowledgeDocumentTextRequest;
use App\Http\Requests\Knowledge\ReplaceKnowledgeDocumentFileRequest;
use App\Http\Requests\Knowledge\StoreKnowledgeDocumentRequest;
use App\Http\Requests\Knowledge\UpdateKnowledgeDocumentRequest;
use App\Http\Resources\KnowledgeDocumentResource;
use App\Http\Resources\KnowledgeDocumentTextResource;
use App\Models\Hotel;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\KnowledgeDocumentService;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The knowledge document endpoints, shared by the hotel routes and the
 * super-admin (global) routes. The two differ only in which scope they act
 * on and how they authorize; everything else is the same service call.
 */
trait HandlesKnowledgeDocuments
{
    /**
     * The scope this request acts on: a hotel, or null for global. Returns a
     * response instead when the request has no usable scope.
     */
    abstract protected function knowledgeScope(Request $request): Hotel|JsonResponse|null;

    /**
     * @param  KnowledgeDocument|class-string<KnowledgeDocument>  $subject
     */
    abstract protected function authorizeKnowledge(string $ability, KnowledgeDocument|string $subject): void;

    public function index(GenericIndexRequest $request, KnowledgeDocumentService $documents)
    {
        $this->authorizeKnowledge('viewAny', KnowledgeDocument::class);

        if (($scope = $this->knowledgeScope($request)) instanceof JsonResponse) {
            return $scope;
        }

        $page = GenericQuery::apply($documents->scopeQuery($scope)->with(['uploader', 'corrector']), $request);

        return apiResponse('Knowledge documents fetched successfully.', 200, KnowledgeDocumentResource::collection($page));
    }

    public function store(StoreKnowledgeDocumentRequest $request, KnowledgeDocumentService $documents)
    {
        $this->authorizeKnowledge('create', KnowledgeDocument::class);

        if (($scope = $this->knowledgeScope($request)) instanceof JsonResponse) {
            return $scope;
        }

        $document = $documents->upload(
            $request->file('file'),
            $request->knowledgeMimeType(),
            $request->safe()->only(['title', 'category', 'is_active']),
            $scope,
            $request->user(),
        );

        return apiResponse('Knowledge document uploaded.', 201, KnowledgeDocumentResource::make($document->load(['uploader', 'corrector'])));
    }

    public function show(Request $request, KnowledgeDocumentService $documents, string $id)
    {
        return $this->withDocument($request, $documents, $id, 'view', fn (KnowledgeDocument $document) => apiResponse(
            'Knowledge document fetched successfully.',
            200,
            KnowledgeDocumentResource::make($document->load(['uploader', 'corrector'])),
        ));
    }

    public function update(UpdateKnowledgeDocumentRequest $request, KnowledgeDocumentService $documents, string $id)
    {
        return $this->withDocument($request, $documents, $id, 'update', fn (KnowledgeDocument $document) => apiResponse(
            'Knowledge document updated successfully.',
            200,
            KnowledgeDocumentResource::make($documents->update($document, $request->validated())->load(['uploader', 'corrector'])),
        ));
    }

    public function replace(ReplaceKnowledgeDocumentFileRequest $request, KnowledgeDocumentService $documents, string $id)
    {
        return $this->withDocument($request, $documents, $id, 'replace', fn (KnowledgeDocument $document) => apiResponse(
            'Replacement file uploaded; the current version stays live until it is indexed.',
            202,
            KnowledgeDocumentResource::make($documents->replaceFile($document, $request->file('file'), $request->knowledgeMimeType())->load(['uploader', 'corrector'])),
        ));
    }

    public function download(Request $request, KnowledgeDocumentService $documents, string $id)
    {
        return $this->withDocument($request, $documents, $id, 'download', function (KnowledgeDocument $document) {
            $disk = Storage::disk($document->disk);

            if (! $disk->exists($document->path)) {
                return apiResponse('The stored file could not be found.', 404);
            }

            return $disk->download($document->path, $document->original_filename);
        });
    }

    public function showText(Request $request, KnowledgeDocumentService $documents, string $id)
    {
        return $this->withDocument($request, $documents, $id, 'viewText', fn (KnowledgeDocument $document) => apiResponse(
            'Knowledge document text fetched successfully.',
            200,
            KnowledgeDocumentTextResource::make($document->load('corrector')),
        ));
    }

    public function correctText(CorrectKnowledgeDocumentTextRequest $request, KnowledgeDocumentService $documents, string $id)
    {
        return $this->withDocument($request, $documents, $id, 'correctText', fn (KnowledgeDocument $document) => apiResponse(
            'Correction saved; the document is being re-indexed.',
            202,
            KnowledgeDocumentTextResource::make($documents->correctText($document, $request->validated('segments'), $request->user())->load('corrector')),
        ));
    }

    public function discardText(Request $request, KnowledgeDocumentService $documents, string $id)
    {
        return $this->withDocument($request, $documents, $id, 'discardText', fn (KnowledgeDocument $document) => apiResponse(
            'Corrections discarded; the text is being extracted again.',
            202,
            KnowledgeDocumentResource::make($documents->discardCorrections($document)->load(['uploader', 'corrector'])),
        ));
    }

    public function reindex(Request $request, KnowledgeDocumentService $documents, string $id)
    {
        return $this->withDocument($request, $documents, $id, 'reindex', fn (KnowledgeDocument $document) => apiResponse(
            'Re-indexing queued.',
            202,
            KnowledgeDocumentResource::make($documents->reindex($document)->load(['uploader', 'corrector'])),
        ));
    }

    public function destroy(Request $request, KnowledgeDocumentService $documents, string $id)
    {
        return $this->withDocument($request, $documents, $id, 'delete', function (KnowledgeDocument $document) use ($documents) {
            $documents->delete($document);

            return apiResponse('Knowledge document deleted. It can be restored for '.config('knowledge.purge_after_days').' days.', 200);
        });
    }

    public function deleted(GenericIndexRequest $request, KnowledgeDocumentService $documents)
    {
        $this->authorizeKnowledge('viewDeleted', KnowledgeDocument::class);

        if (($scope = $this->knowledgeScope($request)) instanceof JsonResponse) {
            return $scope;
        }

        $page = GenericQuery::apply($documents->deletedQuery($scope)->with(['uploader', 'corrector']), $request);

        return apiResponse('Deleted knowledge documents fetched successfully.', 200, KnowledgeDocumentResource::collection($page));
    }

    public function restore(Request $request, KnowledgeDocumentService $documents, string $id)
    {
        if (($scope = $this->knowledgeScope($request)) instanceof JsonResponse) {
            return $scope;
        }

        $document = $documents->deletedQuery($scope)->whereKey($id)->firstOrFail();
        $this->authorizeKnowledge('restore', $document);

        return apiResponse(
            'Knowledge document restored.',
            200,
            KnowledgeDocumentResource::make($documents->restore($document)->load(['uploader', 'corrector'])),
        );
    }

    /**
     * Resolve the scope, find the document in it (404 outside it), authorize,
     * then act.
     */
    private function withDocument(Request $request, KnowledgeDocumentService $documents, string $id, string $ability, callable $action): JsonResponse|Response
    {
        if (($scope = $this->knowledgeScope($request)) instanceof JsonResponse) {
            return $scope;
        }

        $document = $documents->findForScope($scope, $id);
        $this->authorizeKnowledge($ability, $document);

        return $action($document);
    }
}
