<?php

namespace Database\Seeders;

use App\Enums\KnowledgeBaseCategory;
use App\Models\Hotel;
use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use Illuminate\Database\Seeder;

class KnowledgeBaseSeeder extends Seeder
{
    public function run(): void
    {
        $hotel = Hotel::where('slug', 'grand-harbor-hotel')->first();

        foreach ($this->articles() as $article) {
            KnowledgeBaseArticle::create([
                ...$article,
                'hotel_id'=>$hotel->id,
                'status' => 'published',
            ]);
        }

        if ($hotel) {
            foreach ($this->policies() as $policy) {
                HotelPolicy::create([
                    ...$policy,
                    'hotel_id' => $hotel->id,
                    'is_active' => true,
                ]);
            }
        }

        $this->command?->info('Knowledge base seeded. Run `php artisan knowledge:sync` to generate embeddings for it.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function articles(): array
    {
        return [
            [
                'category' => KnowledgeBaseCategory::HOSPITALITY_BEST_PRACTICES,
                'title' => 'The Art of the Warm Welcome',
                'tags' => ['welcome', 'first-impressions', 'service-recovery'],
                'content' => <<<'TEXT'
                    First impressions are formed within the first ninety seconds of a guest's arrival, and they set the emotional tone for the entire stay. Greet every guest by name whenever possible, make eye contact, and acknowledge them within a few seconds of them approaching the desk — even a brief "I'll be right with you" while finishing another task goes a long way. A rushed or distracted greeting is remembered far longer than a busy lobby ever is.

                    Personalization beats efficiency when the two are in tension. A guest who mentioned it's their anniversary in a pre-arrival message should be greeted with a reference to that, not just a room key. Small, specific gestures — a handwritten note, a room upgrade when available, remembering a returning guest's coffee order — cost little but are what guests actually write reviews about.

                    Service recovery matters more than flawless service. Every hotel has failures: a late housekeeping visit, a noisy room, a booking mix-up. What guests remember is not that something went wrong, but how quickly and graciously it was made right. The standard response to a complaint should be to acknowledge it without defensiveness, offer a concrete remedy immediately rather than "escalating" it, and follow up afterward to confirm the guest is satisfied.

                    Staff should never make a guest feel like a problem is their fault, even when it technically is (a missed cutoff time, a misunderstood policy). Reframe the conversation around the solution: "Let's see what we can do" lands very differently than "I'm afraid our policy doesn't allow that."
                    TEXT,
            ],
            [
                'category' => KnowledgeBaseCategory::GUEST_PERSONAS,
                'title' => 'Understanding Your Guest Personas',
                'tags' => ['personas', 'segmentation', 'personalization'],
                'content' => <<<'TEXT'
                    Business travelers value speed, reliability, and connectivity above all else. They are often booking last-minute, staying one or two nights, and working from the room. Fast Wi-Fi, an efficient check-in, a quiet desk area, and an early breakfast option matter more to this guest than resort-style amenities. Avoid over-scripted small talk — they usually want to get to their room and get to work.

                    Families traveling with children prioritize space, safety, and predictability. Connecting rooms, cribs, kid-friendly menu options, and clear information about pool hours and children's activities reduce a huge amount of pre-trip anxiety for parents. Staff should proactively mention family-relevant amenities during check-in rather than waiting to be asked, since parents are often juggling too much to ask themselves.

                    Couples on a romantic getaway or honeymoon respond well to ambiance and quiet, thoughtful gestures — room placement away from elevators and ice machines, a bottle of something on arrival, dinner reservation assistance. These guests are usually not price-sensitive on add-ons if the offer feels genuinely tailored rather than upsold.

                    Digital nomads and long-stay guests care about consistency more than luxury: reliable high-speed internet, a workable desk setup, laundry access, and a kitchenette or nearby grocery options. They are effectively living at the property temporarily, so staff familiarity and light-touch relationship building (remembering their name and routine) drives loyalty far more than daily turndown service.
                    TEXT,
            ],
            [
                'category' => KnowledgeBaseCategory::COMMUNICATION_GUIDELINES,
                'title' => 'Guest Communication Guidelines',
                'tags' => ['whatsapp', 'tone', 'response-time'],
                'content' => <<<'TEXT'
                    Guests messaging over WhatsApp expect a response speed closer to texting a friend than emailing a business — aim to acknowledge every message within a few minutes during staffed hours, even if the full answer takes longer to prepare. A quick "checking on that now" is far better than silence.

                    Tone should be warm and conversational but not overly casual: use the guest's name, keep messages short, and avoid corporate-sounding phrasing like "as per our policy" or "please be advised." Emoji can be used sparingly to soften a message, but should never replace clear information — a guest asking about check-in time needs the actual time, not just a friendly tone.

                    When a request cannot be fulfilled, lead with what *can* be done rather than what can't. Instead of "We don't have any rooms with a sea view available," try "Our sea view rooms are booked for those dates, but I can offer you our top-floor room with a great city view, or check the waitlist for you." Guests remember being told "no" much more negatively than being offered an alternative, even if the alternative is modest.

                    For sensitive topics — billing disputes, complaints, cancellations — move the conversation to a call or in-person conversation once the guest has expressed frustration in writing. Text is easy to misread as curt, and a short call can resolve in two minutes what could become a drawn-out, escalating chat thread.
                    TEXT,
            ],
            [
                'category' => KnowledgeBaseCategory::REVENUE_STRATEGIES,
                'title' => 'Revenue Management Fundamentals',
                'tags' => ['pricing', 'upselling', 'occupancy'],
                'content' => <<<'TEXT'
                    Dynamic pricing should respond to both demand signals (occupancy pace, day-of-week, local events) and booking window (how far in advance the reservation is being made). Rooms booked with less than 48 hours' notice can typically bear a rate premium since the guest has fewer remaining alternatives, while early-bird bookings reward a discount in exchange for locking in demand months ahead.

                    Upselling works best when it is offered as a specific, concrete improvement rather than a generic "would you like to upgrade?" A front desk agent should frame it around what the guest gains: "For an additional amount, I can move you to a room with a balcony and a partial sea view" converts far better than "we have upgrades available."

                    Length-of-stay incentives (discounting the nightly rate for stays of four nights or more) increase both occupancy and operational efficiency, since longer stays reduce the housekeeping and check-in/check-out labor per guest-night. This is especially effective in shoulder season when the goal is filling rooms rather than maximizing rate.

                    Ancillary revenue — spa, dining, excursions, late checkout — should be proposed at the moments guests are most receptive: at booking confirmation, at check-in, and the evening before checkout. A well-timed, relevant offer (proposing the spa to a couple, or a kids' activity to a family) converts far better than the same offer sent to every guest regardless of profile.
                    TEXT,
            ],
            [
                'category' => KnowledgeBaseCategory::DESTINATION_KNOWLEDGE,
                'title' => 'Cairo & the Nile: Local Destination Guide',
                'tags' => ['cairo', 'egypt', 'local-guide'],
                'content' => <<<'TEXT'
                    The Pyramids of Giza and the Sphinx are the single most requested excursion and are best visited early in the morning, both to avoid the midday heat and the largest tour-bus crowds. Recommend guests depart the hotel by 7:30 AM for the best experience, and mention that a private guide is well worth arranging in advance rather than relying on guides at the site.

                    A Nile dinner cruise is one of the most popular evening recommendations, particularly for couples and first-time visitors, combining a scenic view of the city skyline with live entertainment. Book at least a day ahead during high season, since the well-reviewed operators sell out.

                    Khan el-Khalili bazaar is the best recommendation for guests wanting an authentic shopping and local-food experience, but advise guests to agree on prices before purchasing (bargaining is expected) and to visit in the late afternoon or evening when it's liveliest but before the heaviest crowds of a Friday or holiday.

                    Traffic in Cairo is heavy and largely unpredictable, so any excursion involving a fixed appointment (a flight, a tour, a reservation) should build in significantly more transit buffer than guests would expect from a Western city — as a rule of thumb, double the estimated travel time shown by a map app during daytime hours.
                    TEXT,
            ],
            [
                'category' => KnowledgeBaseCategory::SEASONAL_KNOWLEDGE,
                'title' => 'Seasonal Trends and Planning',
                'tags' => ['seasonality', 'ramadan', 'peak-season'],
                'content' => <<<'TEXT'
                    Peak season runs from October through April, when cooler daytime temperatures make sightseeing comfortable; this is when rates should be at their highest and advance-booking requirements can be firmer. Summer (June through August) sees a steep drop in leisure demand due to extreme heat, and should be treated as an opportunity for length-of-stay and local-market promotions rather than held at peak pricing.

                    During Ramadan, guest behavior shifts significantly: expect quieter days, a spike in demand around the Iftar (sunset) meal, and reduced operating hours at many local attractions and restaurants. Staff should proactively inform guests of Iftar dining times and any adjusted excursion schedules rather than waiting to be asked, since this catches many international travelers by surprise.

                    Major local and religious holidays (Eid al-Fitr, Eid al-Adha, Coptic Christmas and Easter) cause short but sharp spikes in domestic travel demand and should be flagged for rate and availability review well in advance, since domestic booking patterns tend to materialize later than international bookings.

                    Weather-related planning matters most for outdoor excursions: the Pyramids and most open-air sites are best avoided in the early afternoon during summer months, and guests should be proactively advised to carry water and sun protection regardless of season, since Cairo's dry heat is easy for visitors to underestimate.
                    TEXT,
            ],
            [
                'category' => KnowledgeBaseCategory::RECOMMENDATION_RULES,
                'title' => 'Guest Recommendation Rules',
                'tags' => ['recommendations', 'rules-of-thumb'],
                'content' => <<<'TEXT'
                    Match the recommendation to the occasion, not just the guest type. A couple celebrating an anniversary and a couple on a routine business-adjacent trip are both "couples," but only one should be offered the romantic Nile dinner cruise as a first suggestion — check the booking notes and any messages for context before defaulting to a generic couples' offer.

                    Never recommend an activity that conflicts with a guest's stated constraints. A family with young children should not be steered toward a late-evening excursion; a guest who mentioned a tight schedule should not be offered anything requiring a half-day commitment without flagging the time cost clearly upfront.

                    When uncertain between two relevant recommendations, offer the one with the more flexible cancellation terms first — guests are more likely to commit to something they don't feel locked into, and it reduces the odds of a frustrated cancellation conversation later.

                    Always disclose price before the guest asks. Recommending an experience without mentioning cost, and having the guest discover the price later, reads as a sales tactic even when unintentional — stating the price naturally as part of the recommendation builds more trust than omitting it.
                    TEXT,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function policies(): array
    {
        return [
            [
                'title' => 'Cancellation Policy',
                'category' => KnowledgeBaseCategory::RECOMMENDATION_RULES,
                'keywords' => ['cancellation', 'refund', 'no-show'],
                'content' => <<<'TEXT'
                    Reservations may be cancelled free of charge up to 48 hours before the scheduled arrival date. Cancellations made within 48 hours of arrival are charged one night's rate plus applicable taxes. No-shows are charged the full stay value for the first night and the reservation is released the following day.

                    Guests booking non-refundable rates are not eligible for any cancellation refund regardless of notice given, but may request a one-time date change up to 7 days before arrival, subject to rate availability for the new dates.

                    Group bookings of five rooms or more follow a separate cancellation schedule with a 14-day notice requirement, communicated at the time of booking.
                    TEXT,
            ],
            [
                'title' => 'Check-in & Check-out Policy',
                'category' => KnowledgeBaseCategory::RECOMMENDATION_RULES,
                'keywords' => ['check-in', 'check-out', 'early-arrival', 'late-checkout'],
                'content' => <<<'TEXT'
                    Standard check-in time is 3:00 PM and check-out is 12:00 PM. Early check-in and late check-out are offered subject to availability; when the room is ready ahead of schedule, it is offered at no charge, otherwise a fee applies based on how early or late the request is relative to standard times.

                    Guests arriving before check-in time are welcome to leave luggage with the concierge and use hotel facilities while waiting. All guests must present a valid government-issued photo ID at check-in matching the name on the reservation.
                    TEXT,
            ],
            [
                'title' => 'Pet Policy',
                'category' => KnowledgeBaseCategory::RECOMMENDATION_RULES,
                'keywords' => ['pets', 'dogs', 'cats', 'service-animals'],
                'content' => <<<'TEXT'
                    Pets under 15 kg are welcome in designated pet-friendly rooms for an additional nightly fee, with a maximum of two pets per room. Pets must not be left unattended in the room and are not permitted in dining areas or the pool deck. Guests should notify the hotel of a travelling pet at the time of booking so a suitable room can be assigned.

                    Trained service animals assisting guests with disabilities are exempt from all pet fees and room restrictions and are welcome throughout the property.
                    TEXT,
            ],
            [
                'title' => 'Children & Extra Bed Policy',
                'category' => KnowledgeBaseCategory::RECOMMENDATION_RULES,
                'keywords' => ['children', 'extra-bed', 'crib', 'family'],
                'content' => <<<'TEXT'
                    Children under 6 years old stay free of charge when using existing bedding. Children aged 6 to 12 can be accommodated with an extra bed for an additional fee, subject to room capacity limits. Cribs are available free of charge on request and should be requested at least 24 hours before arrival to guarantee availability.

                    Maximum room occupancy, including children, is strictly enforced for fire-safety reasons; families exceeding standard room capacity will be offered a connecting room or suite instead.
                    TEXT,
            ],
        ];
    }
}
