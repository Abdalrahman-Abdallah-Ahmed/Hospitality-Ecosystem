<?php

namespace App\Ai\Tools;

use App\Models\Guest;
use App\Models\Hotel;
use App\Services\GuestIdentityService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Records a guest, without creating a second copy of one that already exists.
 *
 * Duplicate guests are the expensive mistake here. A guest's stays, bookings,
 * recommendations, and conversation history all hang off their record, so a
 * second row for the same person silently splits their history in two — and
 * nothing downstream reports an error, it just quietly knows less about them.
 *
 * So this looks the guest up through GuestIdentityService first, exactly as
 * the reservation path does, and refuses to overwrite an existing record. An
 * agent correcting a spelling is fine; an agent blanking a nationality
 * because the admin did not repeat it is not.
 */
class CreateGuestTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Record a guest for this hotel, matched by phone number. If a guest with that number already exists, their existing record is returned and left untouched rather than duplicated.';
    }

    public function handle(Request $request): Stringable|string
    {
        $phone = $request->string('phone_number')->trim()->toString();

        if ($phone === '') {
            return 'A phone number is required: it is what identifies the guest and prevents a duplicate record.';
        }

        $existing = app(GuestIdentityService::class)->findExistingGuest($this->hotel->id, null, $phone);

        if ($existing) {
            $name = trim($existing->first_name.' '.$existing->last_name) ?: 'unnamed';

            return "A guest with that phone number already exists: {$name} (guest id: {$existing->id}). Nothing was created or changed. Tell the admin the guest is already on file.";
        }

        $attributes = [
            'hotel_id' => $this->hotel->id,
            'phone_number' => $phone,
            'first_name' => $request->string('first_name')->toString() ?: null,
            'last_name' => $request->string('last_name')->toString() ?: null,
            'email' => $request->string('email')->toString() ?: null,
            'nationality' => $request->string('nationality')->toString() ?: null,
            'marketing_consent' => $request->boolean('marketing_consent', false),
        ];

        // `preferred_language` is NOT NULL with a database default. Passing an
        // explicit null overrides the default and fails the insert, so the key
        // is omitted entirely when the admin did not give one — "unset" and
        // "set to nothing" are different instructions to a database.
        if ($language = $request->string('preferred_language')->trim()->toString()) {
            $attributes['preferred_language'] = $language;
        }

        $guest = Guest::create($attributes);

        $name = trim($guest->first_name.' '.$guest->last_name) ?: 'unnamed guest';

        return "Guest {$name} created (guest id: {$guest->id}).";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'phone_number' => $schema->string()
                ->description("The guest's phone number in international format. Required — it is how the guest is identified and how duplicates are avoided.")
                ->required(),
            'first_name' => $schema->string()->description("The guest's first name."),
            'last_name' => $schema->string()->description("The guest's last name."),
            'email' => $schema->string()->description("The guest's email address, if given."),
            'preferred_language' => $schema->string()->description('Preferred language, if mentioned (e.g. "en", "it").'),
            'nationality' => $schema->string()->description('Nationality, if mentioned.'),
            'marketing_consent' => $schema->boolean()
                ->description('Only true if the admin explicitly says the guest agreed to marketing contact. Never assume consent.')
                ->default(false),
        ];
    }
}
