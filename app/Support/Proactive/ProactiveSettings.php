<?php

namespace App\Support\Proactive;

use App\Enums\ProactiveTrigger;
use Carbon\CarbonInterface;

/**
 * A hotel's proactive messaging settings (SPEC-073, R15), stored in
 * hotels.proactive_settings. Every value the hotel never set takes its
 * default, and the default is off.
 *
 * Times are hotel-local "H:i" strings.
 */
final class ProactiveSettings
{
    /**
     * @param  array<string, bool>  $triggers  keyed by ProactiveTrigger value
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly array $triggers,
        public readonly string $quietStart,
        public readonly string $quietEnd,
        public readonly int $dailyCap,
        public readonly string $milestoneTime,
        public readonly string $reminderMorningCutoff,
        public readonly string $reminderEveningBeforeAt,
        public readonly int $reminderHoursBefore,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'triggers' => array_fill_keys(array_map(fn (ProactiveTrigger $t) => $t->value, ProactiveTrigger::cases()), true),
            'quiet_hours' => ['start' => '21:00', 'end' => '09:00'],
            'daily_cap' => 1,
            'milestone_time' => '10:00',
            'reminder' => ['morning_cutoff' => '12:00', 'evening_before_at' => '18:00', 'hours_before' => 4],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromArray(?array $stored): self
    {
        $settings = self::merge(self::defaults(), $stored ?? []);

        return new self(
            enabled: (bool) $settings['enabled'],
            triggers: array_map(fn ($on) => (bool) $on, $settings['triggers']),
            quietStart: $settings['quiet_hours']['start'],
            quietEnd: $settings['quiet_hours']['end'],
            dailyCap: (int) $settings['daily_cap'],
            milestoneTime: $settings['milestone_time'],
            reminderMorningCutoff: $settings['reminder']['morning_cutoff'],
            reminderEveningBeforeAt: $settings['reminder']['evening_before_at'],
            reminderHoursBefore: (int) $settings['reminder']['hours_before'],
        );
    }

    /**
     * Nested settings with `$changes` laid over `$base`; keys absent from
     * `$changes` keep their value.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $changes): array
    {
        foreach ($changes as $key => $value) {
            $base[$key] = is_array($value) && is_array($base[$key] ?? null)
                ? self::merge($base[$key], $value)
                : $value;
        }

        return $base;
    }

    /**
     * Validation for a full, merged settings array. Unknown keys are refused
     * by the caller (see unknownKeys()).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $rules = [
            'enabled' => ['required', 'boolean'],
            'triggers' => ['required', 'array'],
            'quiet_hours.start' => ['required', 'date_format:H:i'],
            'quiet_hours.end' => ['required', 'date_format:H:i'],
            'daily_cap' => ['required', 'integer', 'min:1', 'max:3'],
            'milestone_time' => ['required', 'date_format:H:i'],
            'reminder.morning_cutoff' => ['required', 'date_format:H:i'],
            'reminder.evening_before_at' => ['required', 'date_format:H:i'],
            'reminder.hours_before' => ['required', 'integer', 'min:1', 'max:24'],
        ];

        foreach (ProactiveTrigger::cases() as $trigger) {
            $rules["triggers.{$trigger->value}"] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * Keys in `$given` the settings do not have, as dotted paths.
     *
     * @param  array<string, mixed>  $given
     * @return list<string>
     */
    public static function unknownKeys(array $given, ?array $known = null, string $prefix = ''): array
    {
        $known ??= self::defaults();
        $unknown = [];

        foreach ($given as $key => $value) {
            if (! array_key_exists($key, $known)) {
                $unknown[] = $prefix.$key;

                continue;
            }

            if (is_array($value) && is_array($known[$key])) {
                array_push($unknown, ...self::unknownKeys($value, $known[$key], $prefix.$key.'.'));
            }
        }

        return $unknown;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'triggers' => $this->triggers,
            'quiet_hours' => ['start' => $this->quietStart, 'end' => $this->quietEnd],
            'daily_cap' => $this->dailyCap,
            'milestone_time' => $this->milestoneTime,
            'reminder' => [
                'morning_cutoff' => $this->reminderMorningCutoff,
                'evening_before_at' => $this->reminderEveningBeforeAt,
                'hours_before' => $this->reminderHoursBefore,
            ],
        ];
    }

    public function triggerEnabled(ProactiveTrigger $trigger): bool
    {
        return $this->triggers[$trigger->value] ?? false;
    }

    /**
     * Whether `$local` (already in the hotel's timezone) falls in quiet
     * hours. Handles a window that crosses midnight (21:00–09:00).
     */
    public function inQuietHours(CarbonInterface $local): bool
    {
        $now = $local->format('H:i');

        if ($this->quietStart === $this->quietEnd) {
            return false;
        }

        return $this->quietStart < $this->quietEnd
            ? $now >= $this->quietStart && $now < $this->quietEnd
            : $now >= $this->quietStart || $now < $this->quietEnd;
    }

    /**
     * The next moment quiet hours end, at or after `$local`, in the same
     * timezone.
     */
    public function quietHoursEnd(CarbonInterface $local): CarbonInterface
    {
        $end = $local->copy()->setTimeFromTimeString($this->quietEnd)->startOfMinute();

        return $end->lessThanOrEqualTo($local) ? $end->addDay() : $end;
    }
}
