<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Http\Requests\ImportTransactionsRequest;
use App\Http\Requests\ReverseTransactionRequest;
use App\Imports\TransactionsImport;
use App\Models\Transaction;
use App\Services\TransactionService;
use App\Support\Audit\EventLogger;
use App\Support\RequestRules\GenericQuery;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class TransactionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GenericIndexRequest $request)
    {
        $this->authorize('viewAny', Transaction::class);

        $query = Transaction::with(['guest', 'stay', 'room', 'activity']);

        $transactions = GenericQuery::apply($query, $request);

        return apiResponse('Transactions fetched successfully.', 200, $transactions);
    }

    /**
     * Display the specified resource.
     */
    public function show(Transaction $transaction)
    {
        $this->authorize('view', $transaction);

        return apiResponse('Transaction fetched successfully.', 200, $transaction->load(['guest', 'stay', 'room', 'activity', 'reverses', 'reversals']));
    }

    /**
     * Bulk-import transactions from an uploaded spreadsheet. Always scoped to
     * the uploader's own hotel, never a hotel_id from the file — same as
     * ReservationController::import().
     */
    public function import(ImportTransactionsRequest $request): JsonResponse
    {
        $this->authorize('create', Transaction::class);

        $hotel = $request->user()->hotel;
        if (! $hotel) {
            return apiResponse('You do not belong to any hotel.', 403);
        }

        $import = new TransactionsImport($hotel);

        // Suppress the per-row created events (a large upload would otherwise
        // write thousands of audit rows) and record one summary instead.
        EventLogger::withoutRecording(fn () => Excel::import($import, $request->file('file')));

        $summary = [
            'read' => $import->read,
            'imported' => $import->imported,
            'duplicates' => $import->duplicates,
            'rejected' => $import->rejected,
            'attributed' => $import->attributed,
            'unattributed' => $import->unattributed,
            'booking_links' => $import->booking_links,
            'unknown_booking_references' => $import->unknown_booking_references,
        ];

        EventLogger::record($hotel, 'transactions_imported', changes: $summary);

        return apiResponse('Transactions imported successfully.', 200, $summary);
    }

    /**
     * Correct a transaction by appending its reversal. The ledger has no
     * update or delete path; this is the only sanctioned correction.
     */
    public function reverse(ReverseTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('reverse', $transaction);

        try {
            $reversal = app(TransactionService::class)->reverse($transaction, $request->validated('reason'));
        } catch (RuntimeException $e) {
            return apiResponse($e->getMessage(), 422);
        }

        return apiResponse('Transaction reversed successfully.', 201, $reversal);
    }
}
