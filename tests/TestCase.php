<?php

namespace Tests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // TenantContext is process-static; without this, tenant scoping set by
        // one test's authenticated request would leak into the next test.
        TenantContext::reset();
    }
}
