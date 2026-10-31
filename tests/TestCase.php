<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Seed permissions, system roles and plans for every database test. */
    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        // SPA requests are stateful only when they come from a configured frontend origin.
        $this->withHeader('Referer', 'http://localhost');
    }
}
