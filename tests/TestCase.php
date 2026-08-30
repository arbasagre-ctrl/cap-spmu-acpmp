<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The test environment's array cache driver is process-lifetime,
         * not per-test. RefreshDatabase rolls back auto-increment state
         * between tests, so two unrelated tests can legitimately produce
         * the exact same user/route/record-id/body combination.
         * PreventDuplicateSubmission's cache-based dedup would otherwise
         * treat the second test's first, genuine submission as a leftover
         * "already completed" duplicate from an earlier, unrelated test.
         * Flushing here isolates tests from each other without touching
         * the real (unflushed, request-lifetime) production behavior.
         */
        Cache::flush();
    }
}
