<?php

namespace App\Services\Pitching;

use App\Ai\Agents\TurnSignalAgent;
use App\Enums\PitchOpening;
use App\Models\ActivityCategory;
use App\Models\Guest;
use App\Models\Hotel;
use App\Support\Pitching\TurnSignal;
use Illuminate\Support\Str;
use Laravel\Ai\Models\ConversationMessage;
use UnexpectedValueException;

/**
 * Asks TurnSignalAgent about the guest's current message, then checks the
 * answer before anything trusts it. The classifier is an LLM, so its output
 * is treated like any other untrusted input: an id it invented is dropped,
 * and a quote the guest never wrote means the whole answer is discarded.
 */
class TurnSignalClassifier
{
    /**
     * @throws \Throwable on any failure — the caller records CLASSIFIER_FAILED and does not pitch
     */
    public function classify(Guest $guest, Hotel $hotel, string $messageText): TurnSignal
    {
        $categories = ActivityCategory::where('hotel_id', $hotel->id)->pluck('name', 'id')->all();

        $response = TurnSignalAgent::make(categories: $categories)
            ->prompt($this->promptFor($guest, $messageText));

        return $this->validated($response->structured, $messageText, $categories);
    }

    private function promptFor(Guest $guest, string $messageText): string
    {
        $history = ConversationMessage::query()
            ->whereIn('conversation_id', $guest->conversations()->select('id'))
            ->whereIn('role', ['user', 'assistant'])
            ->latest()
            ->limit((int) config('pitching.complaint.classifier_history_messages'))
            ->get()
            ->reverse()
            ->map(fn (ConversationMessage $message) => ($message->role === 'user' ? 'Guest: ' : 'Concierge: ').$message->content)
            ->implode("\n");

        return "Earlier messages:\n".($history ?: '(none)')."\n\nLast guest message:\n{$messageText}";
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, string>  $categories
     */
    private function validated(array $output, string $messageText, array $categories): TurnSignal
    {
        if (! is_bool($output['complaint'] ?? null)) {
            throw new UnexpectedValueException('The classifier did not say whether this is a complaint.');
        }

        $opening = $output['opening'] ?? null;

        if ($opening !== null && ! PitchOpening::tryFrom($opening)) {
            throw new UnexpectedValueException("The classifier returned an unknown opening [{$opening}].");
        }

        $opening = $opening === null ? null : PitchOpening::from($opening);
        $quote = trim((string) ($output['evidence_quote'] ?? ''));
        $needsQuote = $output['complaint'] || $opening !== null;

        if ($needsQuote && ($quote === '' || ! str_contains($this->normalise($messageText), $this->normalise($quote)))) {
            throw new UnexpectedValueException('The classifier quoted words the guest did not write.');
        }

        $categoryId = $output['interest_category_id'] ?? null;

        return new TurnSignal(
            complaint: $output['complaint'],
            opening: $opening,
            interestCategoryId: array_key_exists((string) $categoryId, $categories) ? $categoryId : null,
            evidenceQuote: $quote,
        );
    }

    /**
     * Case-insensitive, whitespace-collapsed, so a quote survives the model
     * re-spacing or re-casing it — but not paraphrasing it.
     */
    private function normalise(string $text): string
    {
        return Str::of($text)->lower()->squish()->toString();
    }
}
