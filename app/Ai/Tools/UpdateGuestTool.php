<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Models\Guest;
use App\Models\Hotel;
use App\Services\Guests\GuestRegistrar;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Corrects a guest's profile through GuestRegistrar, like the guest screen.
 * Only the fields the admin actually gave are written: a field left out is
 * left alone, never blanked.
 */
class UpdateGuestTool implements Tool
{
    use AdminToolSupport;

    private const FIELDS = ['first_name', 'last_name', 'email', 'phone_number', 'preferred_language', 'nationality'];

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Update a guest\'s profile: name, email, phone number, preferred language, nationality, preferences or VIP '
            .'flag. Find the guest id first. Send only the fields the admin asked to change; anything left out stays as it is.';
    }

    public function handle(Request $request): Stringable|string
    {
        $guest = $this->findGuest($request->string('guest_id')->toString());

        if (! $guest instanceof Guest) {
            return 'This hotel has no guest with that id.';
        }

        $changes = [];

        foreach (self::FIELDS as $field) {
            if ($request->filled($field)) {
                $changes[$field] = trim($request->string($field)->toString());
            }
        }

        if ($request->has('is_vip')) {
            $changes['is_vip'] = $request->boolean('is_vip');
        }

        if ($request->filled('preferences')) {
            $changes['preferences'] = (array) $request['preferences'];
        }

        if ($changes === []) {
            return 'Nothing to change: say which fields to update.';
        }

        $result = $this->attempt(fn () => app(GuestRegistrar::class)->update($guest, $changes));

        if (is_string($result)) {
            return $result;
        }

        return $this->done(['guest_id' => $guest->id], $changes);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'guest_id' => $schema->string()->description("The guest's id. Look it up first.")->required(),
            'first_name' => $schema->string(),
            'last_name' => $schema->string(),
            'email' => $schema->string(),
            'phone_number' => $schema->string()->description('International format.'),
            'preferred_language' => $schema->string()->description('Language code, e.g. "en", "ar".'),
            'nationality' => $schema->string(),
            'preferences' => $schema->array()->items($schema->string())->description('The guest\'s preferences, replacing the current list.'),
            'is_vip' => $schema->boolean(),
        ];
    }
}
