<?php

use App\Enums\SuspensionScope;
use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\User;
use App\Support\Suspension\HotelAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
| Spec 024: the one rule that decides what counts as suspended.
*/

uses(RefreshDatabase::class);

function suspensionModelHotel(string $slug): Hotel
{
    $account = HotelGroup::create(['name' => $slug, 'slug' => $slug]);

    return Hotel::create(['hotel_group_id' => $account->id, 'name' => $slug, 'slug' => $slug]);
}

it('applies one rule to a hotel and its account, in every form', function (bool $hotelSuspended, bool $accountSuspended, bool $expected) {
    $hotel = suspensionModelHotel('harbor');
    $account = $hotel->hotelGroup;

    if ($hotelSuspended) {
        $hotel->forceFill(['suspended_at' => now()])->save();
    }

    if ($accountSuspended) {
        $account->forceFill(['suspended_at' => now()])->save();
    }

    expect($hotel->fresh()->isSuspended())->toBe($expected)
        ->and(Hotel::withSuspension()->find($hotel->id)->isSuspended())->toBe($expected)
        ->and(Hotel::notSuspended()->whereKey($hotel->id)->exists())->toBe(! $expected)
        ->and(Hotel::suspended()->whereKey($hotel->id)->exists())->toBe($expected)
        ->and($account->fresh()->isSuspended())->toBe($accountSuspended);
})->with([
    'neither' => [false, false, false],
    'hotel only' => [true, false, true],
    'account only' => [false, true, true],
    'both' => [true, true, true],
]);

it('counts a soft-deleted account that was suspended, in every form', function () {
    $hotel = suspensionModelHotel('harbor');
    $hotel->hotelGroup->forceFill(['suspended_at' => now()])->save();
    $hotel->hotelGroup->delete();

    expect($hotel->fresh()->isSuspended())->toBeTrue()
        ->and(Hotel::withSuspension()->find($hotel->id)->isSuspended())->toBeTrue()
        ->and(Hotel::notSuspended()->whereKey($hotel->id)->exists())->toBeFalse()
        ->and(Hotel::suspended()->whereKey($hotel->id)->exists())->toBeTrue();
});

it('keeps the account state when columns were chosen before withSuspension', function () {
    $hotel = suspensionModelHotel('harbor');
    $hotel->hotelGroup->forceFill(['suspended_at' => now()])->save();

    $read = Hotel::select(['hotels.id', 'hotels.hotel_group_id', 'hotels.suspended_at'])->withSuspension()->find($hotel->id);

    expect($read->accountIsSuspended())->toBeTrue();
});

it('reads a user\'s hotels in one query, suspended or not', function (bool $suspended) {
    $hotel = suspensionModelHotel('harbor');
    $second = Hotel::create(['hotel_group_id' => $hotel->hotel_group_id, 'name' => 'Second', 'slug' => 'second']);
    $user = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $hotel->id]);

    if ($suspended) {
        $hotel->hotelGroup->forceFill(['suspended_at' => now()])->save();
    }

    DB::enableQueryLog();

    $access = HotelAccess::for([$hotel->id, $second->id], $user);

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($access->refusal())->toBe($suspended ? SuspensionScope::ACCOUNT : null);
})->with(['active' => false, 'suspended' => true]);

it('cannot write a suspension column through mass assignment', function () {
    $user = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $hotel = suspensionModelHotel('harbor');
    $account = $hotel->hotelGroup;
    $attributes = ['suspended_at' => now(), 'suspended_by' => $user->id, 'suspended_reason' => 'Nope'];

    $hotel->update($attributes);
    $account->update($attributes);

    foreach ([$hotel->fresh(), $account->fresh()] as $record) {
        expect($record->suspended_at)->toBeNull()
            ->and($record->suspended_by)->toBeNull()
            ->and($record->suspended_reason)->toBeNull();
    }
});
