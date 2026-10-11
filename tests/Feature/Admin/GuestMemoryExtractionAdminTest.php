<?php

use App\Enums\UserRole;
use App\Jobs\ExtractGuestMemoriesJob;
use App\Models\GuestMemoryExtraction;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/*
 * SPEC-084 FR-036: a super admin can find extractions that ended for good
 * and re-run a failed or ceiling-stopped one, by hand, once.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Queue::fake();
});

function adminExtraction(Hotel $hotel, string $status): GuestMemoryExtraction
{
    [$guest, $reservation] = rapInHouseStay($hotel);

    return GuestMemoryExtraction::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => $reservation->id,
        'status' => $status,
        'attempts' => 2,
        'last_error' => $status === 'failed' ? 'RuntimeException' : null,
    ]);
}

function superAdminUser(): User
{
    return User::factory()->role(UserRole::SUPER_ADMIN)->create();
}

it('lists extractions for a super admin, by hotel and status', function () {
    $hotelA = cmaHotel();
    $hotelB = cmaHotel();
    $failed = adminExtraction($hotelA, 'failed');
    adminExtraction($hotelA, 'extracted');
    adminExtraction($hotelB, 'failed');

    $response = cmaRequest($this, superAdminUser(), 'GET', "/api/admin/guest-memory-extractions?hotel_id={$hotelA->id}&status=failed")->assertOk();

    expect(collect($response->json('body.data'))->pluck('id')->all())->toBe([$failed->id])
        ->and($response->json('body.data.0.last_error'))->toBe('RuntimeException');
});

it('re-runs a failed or ceiling-stopped extraction once', function (string $status) {
    $extraction = adminExtraction(cmaHotel(), $status);

    cmaRequest($this, superAdminUser(), 'POST', "/api/admin/guest-memory-extractions/{$extraction->id}/rerun")
        ->assertStatus(202)
        ->assertJsonPath('body.status', 'pending_retry');

    expect($extraction->fresh())
        ->status->value->toBe('pending_retry')
        ->attempts->toBe(1);
    Queue::assertPushed(ExtractGuestMemoriesJob::class, fn ($job) => $job->guestId === $extraction->guest_id && $job->reservationId === $extraction->reservation_id);
})->with(['failed', 'skipped_spend_ceiling']);

it('refuses to re-run an extraction that ended any other way', function (string $status) {
    $extraction = adminExtraction(cmaHotel(), $status);

    cmaRequest($this, superAdminUser(), 'POST', "/api/admin/guest-memory-extractions/{$extraction->id}/rerun")->assertUnprocessable();

    expect($extraction->fresh()->status->value)->toBe($status);
    Queue::assertNothingPushed();
})->with(['extracted', 'skipped_no_messages', 'skipped_disabled']);

it('keeps the extraction routes from hotel admins', function () {
    $hotel = cmaHotel();
    $extraction = adminExtraction($hotel, 'failed');

    cmaRequest($this, cmaAdmin($hotel), 'GET', '/api/admin/guest-memory-extractions')->assertForbidden();
    cmaRequest($this, cmaAdmin($hotel), 'POST', "/api/admin/guest-memory-extractions/{$extraction->id}/rerun")->assertForbidden();
});
