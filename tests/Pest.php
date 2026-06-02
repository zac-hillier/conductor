<?php

use App\Services\Claude\ClaudeRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeClaudeRunner;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Never invoke the real claude binary from a test. Any job that runs
        // synchronously (sync queue) resolves this fake unless a test binds
        // its own instance. Tests asserting on the bus use Bus::fake() and
        // never reach this.
        app()->instance(ClaudeRunner::class, new FakeClaudeRunner);

        // Ensure the canonical test working directories exist so the profile
        // workdir gate (Profile::hasValidWorkdir) passes for tests that set one.
        foreach (['/tmp/conductor-work', '/tmp/conductor-scope'] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }
    })
    ->in('Feature', 'Unit');
