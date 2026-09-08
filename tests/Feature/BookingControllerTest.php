<?php

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\ChargeModel;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function bookingApi(User $user, string $method, string $uri, array $payload = [])
{
    return test()->withHeaders(wp5Headers())->actingAs($user, 'sanctum')
        ->json($method, $uri, $payload);
}

function employeeOf($hotel): User
{
    return User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id]);
}

it('lets staff take a booking at the desk', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [, $guest, $activity] = wp5Recommendation($hotel);

    bookingApi($admin, 'POST', '/api/booking', [
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'charge_model' => ChargeModel::PAY_ON_SITE->value,
        'pax' => 2,
    ])->assertCreated()->assertJsonPath('body.origin', BookingOrigin::STAFF->value);

    $booking = Booking::where('hotel_id', $hotel->id)->first();

    expect($booking->pax)->toBe(2)
        ->and($booking->created_by_user_id)->toBe($admin->id)
        // Priced from the catalogue rather than trusted from the request.
        ->and((float) $booking->expected_value)->toBe(60.0);
});

it('lets an employee take a booking and move it through its lifecycle', function () {
    [, $hotel] = wp5AdminWithHotel();
    [, $guest, $activity] = wp5Recommendation($hotel);
    $seller = employeeOf($hotel);

    $id = bookingApi($seller, 'POST', '/api/booking', [
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'charge_model' => ChargeModel::INCLUDED->value,
    ])->assertCreated()->json('body.id');

    bookingApi($seller, 'POST', "/api/booking/{$id}/status", ['status' => 'confirmed'])->assertOk();

    // The desk is the only place that knows whether the guest turned up.
    bookingApi($seller, 'POST', "/api/booking/{$id}/status", ['status' => 'realised'])
        ->assertOk()
        ->assertJsonPath('body.status', BookingStatus::REALISED->value);

    expect(Booking::withoutGlobalScope('hotel')->find($id)->realised_at)->not->toBeNull();
});

it('credits the recommendation when a staff booking carries one', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel);

    bookingApi($admin, 'POST', '/api/booking', [
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'recommendation_id' => $recommendation->id,
        'charge_model' => ChargeModel::PAY_ON_SITE->value,
    ])->assertCreated()->assertJsonPath('body.origin', BookingOrigin::RECOMMENDATION->value);

    expect(wp5Outcome($recommendation)->outcome->value)->toBe('booked');
});

it('will not let a booking claim recommendation origin without the link', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [, $guest, $activity] = wp5Recommendation($hotel);

    // 'recommendation' is not an accepted origin value — it is derived.
    bookingApi($admin, 'POST', '/api/booking', [
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'charge_model' => ChargeModel::PAY_ON_SITE->value,
        'origin' => 'recommendation',
    ])->assertStatus(422)->assertJsonValidationErrors('origin');
});

it('requires a reason to cancel', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    $booking = wp5Booking($hotel, ['guest_id' => wp5Recommendation($hotel)[1]->id]);

    bookingApi($admin, 'POST', "/api/booking/{$booking->id}/status", ['status' => 'cancelled'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    bookingApi($admin, 'POST', "/api/booking/{$booking->id}/status", [
        'status' => 'cancelled', 'reason' => 'weather',
    ])->assertOk()->assertJsonPath('body.cancellation_reason', 'weather');
});

it('refuses to reopen a cancelled booking through the endpoint', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    $booking = wp5Booking($hotel, ['guest_id' => wp5Recommendation($hotel)[1]->id]);

    bookingApi($admin, 'POST', "/api/booking/{$booking->id}/status", ['status' => 'cancelled', 'reason' => 'x'])->assertOk();
    bookingApi($admin, 'POST', "/api/booking/{$booking->id}/status", ['status' => 'realised'])->assertStatus(422);
});

it('rejects a guest from another hotel', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [, $otherHotel] = wp5AdminWithHotel();
    [, $otherGuest] = wp5Recommendation($otherHotel);

    bookingApi($admin, 'POST', '/api/booking', [
        'guest_id' => $otherGuest->id,
        'item_name' => 'Sunset dive',
        'charge_model' => ChargeModel::PAY_ON_SITE->value,
    ])->assertStatus(422);
});

it('lists only the caller hotel bookings', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [, $otherHotel] = wp5AdminWithHotel();

    wp5Booking($hotel, ['guest_id' => wp5Recommendation($hotel)[1]->id]);
    wp5Booking($otherHotel, ['guest_id' => wp5Recommendation($otherHotel)[1]->id]);

    bookingApi($admin, 'GET', '/api/booking')->assertOk()->assertJsonPath('body.meta.total', 1);
});

it('forbids touching another hotel booking', function () {
    [$admin] = wp5AdminWithHotel();
    [, $otherHotel] = wp5AdminWithHotel();
    $theirs = wp5Booking($otherHotel, ['guest_id' => wp5Recommendation($otherHotel)[1]->id]);

    bookingApi($admin, 'GET', "/api/booking/{$theirs->id}")->assertStatus(403);
    bookingApi($admin, 'POST', "/api/booking/{$theirs->id}/status", ['status' => 'confirmed'])->assertStatus(403);
});

it('has no update or delete route', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    $booking = wp5Booking($hotel, ['guest_id' => wp5Recommendation($hotel)[1]->id]);

    // A booking is cancelled, never edited in place or removed.
    bookingApi($admin, 'PUT', "/api/booking/{$booking->id}", ['item_name' => 'x'])->assertStatus(405);
    bookingApi($admin, 'DELETE', "/api/booking/{$booking->id}")->assertStatus(405);
});
