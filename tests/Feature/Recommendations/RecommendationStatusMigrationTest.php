<?php

use App\Models\Recommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * SPEC-071 FR-011: recommendations still waiting to be offered were never
 * reviewed, so they become pending_approval. Postgres runs DDL inside the
 * test's transaction, so rolling the migration back and forward here is
 * undone afterwards.
 */
it('moves waiting recommendations to pending approval and keeps every other status', function () {
    $migration = require database_path('migrations/2026_10_10_000001_add_approval_to_recommendations_table.php');
    $hotel = rapHotel();
    [, $reservation] = rapInHouseStay($hotel);
    $activity = rapActivity($hotel);

    $migration->down();

    $insert = fn (string $status) => DB::table('recommendations')->insertGetId([
        'id' => (string) Str::uuid(),
        'hotel_id' => $hotel->id,
        'reservation_id' => $reservation->id,
        'activity_id' => $activity->id,
        'status' => $status,
        'recommended_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $waiting = $insert('pending');
    $sent = $insert('sent');
    $declined = $insert('rejected');

    $migration->up();

    $status = fn (string $id) => DB::table('recommendations')->where('id', $id)->value('status');

    expect($status($waiting))->toBe('pending_approval')
        ->and($status($sent))->toBe('sent')
        ->and($status($declined))->toBe('rejected')
        ->and(DB::table('recommendations')->whereIn('id', [$waiting, $sent, $declined])->pluck('source')->unique()->all())->toBe(['legacy']);
});

it('creates new recommendations as pending approval by default', function () {
    $hotel = rapHotel();
    [, $reservation] = rapInHouseStay($hotel);

    $recommendation = Recommendation::create([
        'hotel_id' => $hotel->id,
        'reservation_id' => $reservation->id,
        'activity_id' => rapActivity($hotel)->id,
        'recommended_at' => now(),
    ]);

    expect(DB::table('recommendations')->where('id', $recommendation->id)->value('status'))->toBe('pending_approval');
});
