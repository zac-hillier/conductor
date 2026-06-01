<?php

namespace App\Observers;

use App\Models\Profile;
use App\Support\PolicyDefaults;

class ProfileObserver
{
    public function created(Profile $profile): void
    {
        $profile->policy()->create([
            'rules' => PolicyDefaults::for($profile->kind),
        ]);
    }
}
