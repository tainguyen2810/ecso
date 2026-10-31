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

        // Installer markers must never land in the real storage/ folder during tests.
        $dir = sys_get_temp_dir().'/ecso-installer-'.getmypid();
        @mkdir($dir, 0o700, true);
        config([
            'ecso.installer.lock_path' => "{$dir}/installed.lock",
            'ecso.installer.token_path' => "{$dir}/install-token.txt",
        ]);
    }

    protected function tearDown(): void
    {
        $dir = sys_get_temp_dir().'/ecso-installer-'.getmypid();
        foreach (glob("{$dir}/{,.}*", GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }
}
