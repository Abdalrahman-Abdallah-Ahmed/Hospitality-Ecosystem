<?php

namespace App\Ai\Tools;

use App\Models\Guest;
use App\Services\GuestContactPreferenceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Lets the guest stop, or resume, proactive messages and unsolicited offers in
 * their own words ("please stop sending me offers"). A message that is just
 * a keyword such as STOP never reaches the Concierge: the WhatsApp job
 * handles it in code.
 */
class SetContactPreferenceTool implements Tool
{
    public function __construct(
        private readonly Guest $guest,
    ) {}

    public function description(): Stringable|string
    {
        return 'Stop, or resume, messages and suggestions the hotel sends this guest without being asked. Use it only when the guest clearly asks for that. Their questions are always answered either way.';
    }

    public function handle(Request $request): Stringable|string
    {
        $preference = $request->string('preference')->toString();

        if (! in_array($preference, ['opt_out', 'resume'], true)) {
            return 'Set preference to opt_out or resume.';
        }

        $preferences = app(GuestContactPreferenceService::class);
        $changed = $preference === 'opt_out'
            ? $preferences->optOut($this->guest, GuestContactPreferenceService::SOURCE_GUEST)
            : $preferences->optIn($this->guest, GuestContactPreferenceService::SOURCE_GUEST);

        return match (true) {
            $preference === 'opt_out' && $changed => 'Done: the guest will get no more messages or suggestions they did not ask for. Confirm this to them in one short sentence.',
            $preference === 'opt_out' => 'The guest had already asked for this; nothing changed. Confirm it to them in one short sentence.',
            $changed => 'Done: the guest may receive occasional messages and suggestions again. Confirm this to them in one short sentence.',
            default => 'The guest was already receiving them; nothing changed.',
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'preference' => $schema->string()->enum(['opt_out', 'resume'])->required(),
            'guest_words' => $schema->string()->description('The guest\'s own words asking for this.')->required(),
        ];
    }
}
