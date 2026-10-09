<?php

use App\Ai\Tools\Admin\AdminToolset;
use App\Ai\Tools\Admin\GuardedTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * SPEC-055 FR-003, SC-003: an Admin AI tool given another hotel's reservation
 * code, guest, task, booking, activity or article treats it as unknown. Its
 * answer carries nothing of the other hotel, and nothing of the other hotel
 * changes. Every tool in the registry is run with the other hotel's
 * identifiers (aatArgs against the other hotel's seed).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
    Queue::fake();
    knFakeEmbeddings();
});

it('never shows or changes another hotel\'s records, whatever identifiers the AI passes', function () {
    $ours = aatSeed('Alpha');
    $theirs = aatSeed('Bravoleak');
    $theirsBefore = aatFingerprint($theirs['hotel']);
    $this->travel(5)->minutes();

    foreach (AdminToolset::for($ours['admin']) as $tool) {
        $result = aatCall($tool, aatArgs($tool->name(), $theirs));
        $text = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE);

        expect($text)->not->toContain('Bravoleak', $tool->name());
    }

    expect(aatFingerprint($theirs['hotel']))->toBe($theirsBefore);
});

it('answers "not found" for another hotel\'s reservation, guest, task, booking and article', function (string $name) {
    $ours = aatSeed('Alpha');
    $theirs = aatSeed('Bravoleak');

    $result = aatCall(aatTool($ours['admin'], $name), aatArgs($name, $theirs));

    expect($result)->toBeString()
        ->and(strtolower($result))->toContain('has no');
})->with([
    'GetReservationTool', 'GetGuestTool', 'UpdateGuestTool', 'UpdateReservationTool', 'CancelReservationTool',
    'AssignRoomsTool', 'CheckInTool', 'CheckOutTool', 'UpdateTaskTool', 'ReportTaskIssueTool',
    'UpdateBookingStatusTool', 'DecideBookingCancellationTool', 'UpdateKnowledgeArticleTool',
]);

it('runs every tool inside the acting admin\'s hotel', function () {
    $ours = aatSeed('Alpha');

    foreach (AdminToolset::for($ours['admin']) as $tool) {
        expect($tool)->toBeInstanceOf(GuardedTool::class);
    }

    // A read with no hotel filter of its own still only sees this hotel.
    aatSeed('Bravoleak');
    $rooms = aatCall(aatTool($ours['admin'], 'GetRoomsTool'), []);

    expect($rooms['total'])->toBe(3);
});
