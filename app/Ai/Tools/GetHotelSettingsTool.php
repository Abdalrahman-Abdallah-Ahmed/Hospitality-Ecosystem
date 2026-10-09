<?php

namespace App\Ai\Tools;

use App\Models\Hotel;
use App\Models\TaskCategory;
use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The hotel's settings, read-only (D17), from an allow-list. Platform
 * secrets (API key, WhatsApp app secret, provider keys) live in config and
 * are never read here; any AI preference whose name suggests a secret is
 * dropped as well (FR-020). There is no write counterpart (FR-018).
 */
class GetHotelSettingsTool implements Tool
{
    private const SECRET_KEY = '/secret|token|key|password|credential/i';

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Read-only: this hotel\'s settings: name, timezone, currency, location, contact details, WhatsApp number, '
            .'whether inspection after cleaning is required, the default Housekeeping and Maintenance teams and task '
            .'categories, and AI preferences. You cannot change settings; the admin does that on the settings screens.';
    }

    public function handle(Request $request): Stringable|string
    {
        $hotel = Hotel::find($this->hotel->id);

        return json_encode([
            'name' => $hotel->name,
            'timezone' => $hotel->timezone,
            'currency' => $hotel->currency,
            'country_code' => $hotel->country_code,
            'city' => $hotel->city,
            'address' => $hotel->address,
            'email' => $hotel->email,
            'phone' => $hotel->phone,
            'whatsapp_number' => $hotel->whatsapp_number,
            'inspection_required' => (bool) $hotel->inspection_required,
            'housekeeping_team' => $this->teamName($hotel->housekeeping_team_id),
            'cleaning_task_category' => $this->categoryName($hotel->cleaning_task_category_id),
            'inspection_task_category' => $this->categoryName($hotel->inspection_task_category_id),
            'maintenance_team' => $this->teamName($hotel->maintenance_team_id),
            'maintenance_task_category' => $this->categoryName($hotel->maintenance_task_category_id),
            'ai_preferences' => $this->withoutSecrets((array) ($hotel->ai_preferences ?? [])),
        ], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    private function teamName(?string $id): ?string
    {
        return $id ? Team::withoutGlobalScope('hotel')->whereKey($id)->value('name') : null;
    }

    private function categoryName(?string $id): ?string
    {
        return $id ? TaskCategory::withoutGlobalScope('hotel')->whereKey($id)->value('name') : null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withoutSecrets(array $values): array
    {
        $kept = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key)) {
                continue;
            }

            $kept[$key] = is_array($value) ? $this->withoutSecrets($value) : $value;
        }

        return $kept;
    }
}
