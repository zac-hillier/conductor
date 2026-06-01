<?php

use App\Enums\TaskStatus;
use App\Models\Profile;

it('loads the board route and renders all ten status column labels', function () {
    $profile = Profile::factory()->create();

    $response = $this->get(route('profiles.board', $profile));

    $response->assertOk();

    foreach (TaskStatus::ordered() as $status) {
        $response->assertSee($status->label());
    }

    expect(TaskStatus::ordered())->toHaveCount(10);
});
