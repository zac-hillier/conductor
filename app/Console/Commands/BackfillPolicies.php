<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Support\PolicyDefaults;
use Illuminate\Console\Command;

class BackfillPolicies extends Command
{
    protected $signature = 'conductor:backfill-policies';

    protected $description = 'Create a default policy for any profile that does not have one';

    public function handle(): int
    {
        $created = 0;

        Profile::query()->whereDoesntHave('policy')->each(function (Profile $profile) use (&$created) {
            $profile->policy()->create([
                'rules' => PolicyDefaults::for($profile->kind),
            ]);

            $this->line("Seeded policy for [{$profile->slug}] ({$profile->kind->value})");
            $created++;
        });

        $this->info("Done. {$created} polic".($created === 1 ? 'y' : 'ies').' created.');

        return self::SUCCESS;
    }
}
