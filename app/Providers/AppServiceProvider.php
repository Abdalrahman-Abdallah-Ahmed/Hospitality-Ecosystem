<?php

namespace App\Providers;

use App\Listeners\RecordAiUsage;
use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use App\Observers\HotelPolicyObserver;
use App\Observers\KnowledgeBaseArticleObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\Reranked;

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

        $this->recordAiCost();
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
}
