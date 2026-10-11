<?php

namespace App\Services\Guests;

use App\Enums\GuestMemoryCategory;
use App\Enums\GuestMemoryConfidence;
use App\Models\Guest;
use App\Models\GuestMemory;
use App\Models\Hotel;
use App\Models\Stay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of guest memories (SPEC-084), and the place their rules
 * hold whatever the extraction model proposed:
 *
 * - a known category and language, confidence of at least medium, at most
 *   200 characters;
 * - nothing that looks like a card, identity document, email or phone number;
 * - refresh and replace only touch this guest's memories at this hotel, and a
 *   refresh never lowers confidence;
 * - at most 20 memories per guest per hotel, the oldest least confident going
 *   first.
 */
class GuestMemoryService
{
    public const LIMIT = 20;

    private const MAX_LENGTH = 200;

    /**
     * Apply the extraction's proposals. Returns how many memories were
     * created, refreshed or replaced.
     *
     * @param  list<array<string, mixed>>  $operations
     */
    public function apply(Guest $guest, Stay $sourceStay, array $operations): int
    {
        return DB::transaction(function () use ($guest, $sourceStay, $operations): int {
            $own = GuestMemory::query()
                ->where('hotel_id', $guest->hotel_id)
                ->where('guest_id', $guest->id)
                ->get()
                ->keyBy('id');
            $written = 0;

            foreach ($operations as $operation) {
                $op = $operation['op'] ?? null;
                $confidence = GuestMemoryConfidence::tryFrom((string) ($operation['confidence'] ?? ''));

                if ($confidence === null || $confidence->rank() < GuestMemoryConfidence::MEDIUM->rank()) {
                    continue;
                }

                if ($op === 'refresh') {
                    $memory = $own->get($operation['id'] ?? '');

                    if ($memory === null) {
                        continue;
                    }

                    $memory->update([
                        'confidence' => $confidence->rank() > $memory->confidence->rank() ? $confidence : $memory->confidence,
                        'source_stay_id' => $sourceStay->id,
                        'extracted_at' => now(),
                    ]);
                    $written++;

                    continue;
                }

                $fact = $this->fact($operation);

                if ($fact === null) {
                    continue;
                }

                if ($op === 'replace') {
                    $memory = $own->get($operation['id'] ?? '');

                    if ($memory === null) {
                        continue;
                    }

                    $memory->update([...$fact, 'confidence' => $confidence, 'source_stay_id' => $sourceStay->id, 'extracted_at' => now()]);
                    $written++;
                } elseif ($op === 'create') {
                    GuestMemory::create([
                        ...$fact,
                        'hotel_id' => $guest->hotel_id,
                        'guest_id' => $guest->id,
                        'confidence' => $confidence,
                        'source_stay_id' => $sourceStay->id,
                        'extracted_at' => now(),
                    ]);
                    $written++;
                }
            }

            $this->enforceLimit($guest);

            return $written;
        });
    }

    /**
     * What the Concierge may know about this guest at this hotel: nothing
     * while the hotel has memories switched off (FR-032).
     *
     * @return Collection<int, GuestMemory>
     */
    public function forConcierge(Guest $guest, Hotel $hotel): Collection
    {
        if (! $hotel->guest_memories_enabled || $guest->hotel_id !== $hotel->id) {
            return new Collection;
        }

        return GuestMemory::query()
            ->where('hotel_id', $hotel->id)
            ->where('guest_id', $guest->id)
            ->orderBy('category')
            ->orderByDesc('extracted_at')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * The category, content and language of a proposed fact, or null when
     * it may not be kept.
     *
     * @param  array<string, mixed>  $operation
     * @return array{category: GuestMemoryCategory, content: string, language: string}|null
     */
    private function fact(array $operation): ?array
    {
        $category = GuestMemoryCategory::tryFrom((string) ($operation['category'] ?? ''));
        $content = trim((string) ($operation['content'] ?? ''));
        $language = $operation['language'] ?? null;

        if ($category === null || ! in_array($language, ['en', 'ar'], true)) {
            return null;
        }

        if ($content === '' || mb_strlen($content) > self::MAX_LENGTH || $this->isSensitive($content)) {
            return null;
        }

        return ['category' => $category, 'content' => $content, 'language' => $language];
    }

    /**
     * Emails, identity-document numbers (one or two capitals then 6–9
     * digits), and any run of nine or more digits — card, phone and ID
     * numbers alike — are never kept, whatever the model said (FR-012). No
     * durable preference needs a long number.
     */
    private function isSensitive(string $content): bool
    {
        return preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/u', $content) === 1
            || preg_match('/\b[A-Z]{1,2}\d{6,9}\b/', $content) === 1
            || preg_match('/\d(?:[\s\-]?\d){8,}/', $content) === 1;
    }

    /**
     * Over the limit, the least confident go first, and among them the
     * oldest.
     */
    private function enforceLimit(Guest $guest): void
    {
        $memories = GuestMemory::query()
            ->where('hotel_id', $guest->hotel_id)
            ->where('guest_id', $guest->id)
            ->get();

        $memories
            ->sortBy([
                fn (GuestMemory $a, GuestMemory $b) => $a->confidence->rank() <=> $b->confidence->rank(),
                fn (GuestMemory $a, GuestMemory $b) => $a->extracted_at <=> $b->extracted_at,
            ])
            ->take(max(0, $memories->count() - self::LIMIT))
            ->each->delete();
    }
}
