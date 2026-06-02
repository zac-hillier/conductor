<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give every existing profile a default project (carrying its workdir) and
     * point every existing task at it, so the new Project layer is transparent
     * to current data.
     */
    public function up(): void
    {
        $now = now();

        DB::table('profiles')->orderBy('id')->each(function (object $profile) use ($now): void {
            // workdir stays null so the default project inherits the profile's
            // home (the single source of truth set in Settings); per-project
            // workdir is only set when a profile gains additional projects.
            $projectId = DB::table('projects')->insertGetId([
                'profile_id' => $profile->id,
                'slug' => 'default',
                'name' => $profile->name,
                'workdir' => null,
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('tasks')
                ->where('profile_id', $profile->id)
                ->update(['project_id' => $projectId]);
        });
    }

    public function down(): void
    {
        DB::table('tasks')->update(['project_id' => null]);
        DB::table('projects')->where('is_default', true)->delete();
    }
};
