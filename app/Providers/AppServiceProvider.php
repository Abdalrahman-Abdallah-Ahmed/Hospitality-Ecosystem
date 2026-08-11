<?php

namespace App\Providers;

use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use App\Observers\HotelPolicyObserver;
use App\Observers\KnowledgeBaseArticleObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        KnowledgeBaseArticle::observe(KnowledgeBaseArticleObserver::class);
        HotelPolicy::observe(HotelPolicyObserver::class);
    }
}
