<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Models\Hotel;
use App\Services\Guests\GuestRegistrar;
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
 * So it registers through GuestRegistrar, the same rules the guest endpoint
 * uses (match by phone or email), and never overwrites an existing record.
 * An agent correcting a spelling is fine; an agent blanking a nationality
 * because the admin did not repeat it is not.
 */
class CreateGuestTool implements Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Record a guest for this hotel, matched by phone number (and email, if given). If the guest already exists, their existing record is returned and left untouched rather than duplicated.';
    }

    public function handle(Request $request): Stringable|string
    {
        $phone = $request->string('phone_number')->trim()->toString();

        if ($phone === '') {
            return 'A phone number is required: it is what identifies the guest and prevents a duplicate record.';
        }

        $attributes = [
            'phone_number' => $phone,
            'first_name' => $request->string('first_name')->toString() ?: null,
            'last_name' => $request->string('last_name')->toString() ?: null,
            'email' => $request->string('email')->toString() ?: null,
            'nationality' => $request->string('nationality')->toString() ?: null,
        ];

        // `preferred_language` is NOT NULL with a database default. Passing an
        // explicit null overrides the default and fails the insert, so the key
        // is omitted entirely when the admin did not give one — "unset" and
        // "set to nothing" are different instructions to a database.
        if ($language = $request->string('preferred_language')->trim()->toString()) {
            $attributes['preferred_language'] = $language;
        }

        $result = $this->attempt(fn () => app(GuestRegistrar::class)->register($this->hotel, $attributes));

        if (is_string($result)) {
            return $result;
        }

        ['guest' => $guest, 'created' => $created] = $result;
        $name = $this->guestName($guest);

        if (! $created) {
            return "A guest with that phone number or email already exists: {$name} (guest id: {$guest->id}). Nothing was created or changed. Tell the admin the guest is already on file.";
        }

        return $this->done(['guest_id' => $guest->id], ['guest' => 'created'], "Guest {$name} created (guest id: {$guest->id}).");
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
        ];
    }
}
