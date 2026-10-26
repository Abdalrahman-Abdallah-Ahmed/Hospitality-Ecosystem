<?php

namespace App\Providers;

use App\Listeners\RecordAiUsage;
use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use App\Models\PitchDecision;
use App\Models\ProactiveMessage;
use App\Models\RecommendationOutcome;
use App\Models\WhatsAppDevice;
use App\Observers\HotelPolicyObserver;
use App\Observers\KnowledgeBaseArticleObserver;
use App\Observers\PitchDecisionObserver;
use App\Observers\ProactiveMessageObserver;
use App\Observers\RecommendationOutcomeObserver;
use App\Policies\ConversationPolicy;
use App\Support\Audit\EventLogger;
use App\Support\Observability\TagsSentryScope;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\Reranked;
use Laravel\Ai\Models\Conversation;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per process: the listener holds the start time of each
        // in-flight call between the "prompting" and "prompted" events, and a
        // fresh instance per event would have nothing to measure against.
        $this->app->singleton(RecordAiUsage::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        KnowledgeBaseArticle::observe(KnowledgeBaseArticleObserver::class);
        HotelPolicy::observe(HotelPolicyObserver::class);

        // What the Concierge offered and sent guests, in the AI activity list
        // (SPEC-083).
        PitchDecision::observe(PitchDecisionObserver::class);
        RecommendationOutcome::observe(RecommendationOutcomeObserver::class);
        ProactiveMessage::observe(ProactiveMessageObserver::class);

        // The conversation model is the laravel/ai package's, so policy
        // discovery cannot find ours by name (SPEC-082).
        Gate::policy(Conversation::class, ConversationPolicy::class);

        // Lets the AI action recorder tell a write that changed something
        // from one that changed nothing (SPEC-083). A no-op unless a capture
        // is open.
        foreach (['created', 'updated', 'deleted'] as $verb) {
            Event::listen("eloquent.{$verb}: *", function (string $event, array $payload) use ($verb): void {
                EventLogger::noticeModelEvent($payload[0], $verb);
            });
        }

        // Error tags for Sentry (spec 017 FR-027). The repeated-failure
        // alert, App\Listeners\AlertOnRepeatedJobFailures, is discovered.
        TagsSentryScope::register();

        $this->refuseDebugOutsideLocal();
        $this->recordAiCost();
        $this->refusePairingCodesAsApiTokens();
        $this->throttleAuthentication();
    }

    /**
     * Debug mode renders stack traces with server paths into API error
     * responses. A deployed .env left with APP_DEBUG=true would publish them,
     * so it is only honoured where nobody else can reach the server.
     */
    private function refuseDebugOutsideLocal(): void
    {
        if (! $this->app->environment('local', 'testing')) {
            config(['app.debug' => false]);
        }
    }

    /**
     * The API key is shipped in the frontend bundle, so it does not stop
     * anyone reaching these routes. Login is limited per email and per IP so a
     * password can't be guessed against one account or sprayed across many.
     */
    private function throttleAuthentication(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // Pairing codes are long random tokens, so this is not what stops a
        // guess; it just stops the endpoint being hammered for free.
        RateLimiter::for('pair', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Every authenticated route. Generous enough for a dashboard polling
        // several widgets, tight enough that one token cannot flood the API.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }

    /**
     * Attribute the cost of every AI call to the account that caused it.
     *
     * Wired here, against the AI package's own events, rather than inside the
     * agents. Centralising it is the requirement, not a preference: an agent
     * written next year is costed without anyone remembering to instrument
     * it. AgentStreamed extends AgentPrompted, so streamed responses are
     * covered by the same listener.
     */
    private function recordAiCost(): void
    {
        Event::listen(PromptingAgent::class, [RecordAiUsage::class, 'promptingAgent']);
        Event::listen(AgentPrompted::class, [RecordAiUsage::class, 'agentPrompted']);
        Event::listen(AgentFailedOver::class, [RecordAiUsage::class, 'agentFailedOver']);
        Event::listen(GeneratingEmbeddings::class, [RecordAiUsage::class, 'generatingEmbeddings']);
        Event::listen(EmbeddingsGenerated::class, [RecordAiUsage::class, 'embeddingsGenerated']);
        Event::listen(Reranked::class, [RecordAiUsage::class, 'reranked']);
    }

    /**
     * A WhatsApp pairing code is stored as a Sanctum token so it can be looked
     * up and expired the standard way, but it is not an API credential. It is
     * shown on a dashboard and typed into a chat; whoever saw it could
     * otherwise call the whole API as its owner.
     */
    private function refusePairingCodesAsApiTokens(): void
    {
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid): bool => $isValid
                && $token->name !== WhatsAppDevice::PAIRING_TOKEN_NAME,
        );
    }
}
