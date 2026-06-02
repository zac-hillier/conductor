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
    })
    ->in('Feature', 'Unit');
