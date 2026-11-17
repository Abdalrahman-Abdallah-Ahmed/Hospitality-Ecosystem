<?php

namespace Tests;

use App\Support\Ai\AiRun;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\ParallelTesting;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // TenantContext is process-static; without this, tenant scoping set by
        // one test's authenticated request would leak into the next test.
        TenantContext::reset();

        // So is the AI run in progress (SPEC-083): a test that throws inside
        // one must not leave the next test's writes attributed to it.
        AiRun::reset();

        // No test may reach a real service. With real keys in a local .env an
        // unfaked call succeeds (and costs money); on CI it fails with a 403.
        // Failing it everywhere keeps the two in step.
        Http::preventStrayRequests();
    }

    protected function refreshApplication()
    {
        parent::refreshApplication();

        // In a parallel run, Laravel checks the worker database before every
        // test and, if that check fails for any reason (even a slow
        // connection), drops it and creates an empty one. The worker still
        // believes it has migrated, so every later test on it fails with
        // "relation does not exist". Make the next refresh migrate again.
        ParallelTesting::setUpTestDatabase(function () {
            RefreshDatabaseState::$migrated = false;
        });
    }
}
