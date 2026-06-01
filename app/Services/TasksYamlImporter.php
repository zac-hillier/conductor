<?php

namespace App\Services;

use App\Enums\ProfileKind;
use App\Enums\TaskStatus;
use App\Models\Profile;
use App\Models\Task;
use App\Models\TaskComment;
use App\Support\PolicyDefaults;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class TasksYamlImporter
{
    /**
     * Legacy status list => Conductor target status.
     *
     * @var array<string, TaskStatus>
     */
    private const STATUS_MAP = [
        'open' => TaskStatus::Ready,
        'in_progress' => TaskStatus::Ready,
        'blocked' => TaskStatus::Blocked,
        'done' => TaskStatus::Complete,
    ];

    /**
     * Import a legacy tasks.yaml into Conductor.
     *
     * Read-only on the source file. Idempotent: matched refs are updated,
     * never duplicated. A dry run reports the plan without persisting.
     *
     * @return array{
     *     profile: string,
     *     dry_run: bool,
     *     created: int,
     *     updated: int,
     *     by_status: array<string, int>,
     * }
     */
    public function import(string $customerSlug, string $path, bool $dryRun = false): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("tasks.yaml not found or unreadable at [{$path}].");
        }

        $data = Yaml::parseFile($path);

        if (! is_array($data)) {
            throw new RuntimeException("tasks.yaml at [{$path}] did not parse to a mapping.");
        }

        $summary = [
            'profile' => $customerSlug,
            'dry_run' => $dryRun,
            'created' => 0,
            'updated' => 0,
            'by_status' => [],
        ];

        $profile = $this->upsertProfile($customerSlug, $data, $dryRun);

        foreach (self::STATUS_MAP as $list => $status) {
            $entries = $data[$list] ?? [];

            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $outcome = $this->upsertTask($profile, $entry, $status, $dryRun);

                $summary[$outcome]++;
                $summary['by_status'][$status->value] = ($summary['by_status'][$status->value] ?? 0) + 1;
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertProfile(string $slug, array $data, bool $dryRun): Profile
    {
        $name = $this->nonEmptyString($data['client'] ?? null) ?? $slug;
        $workdir = $this->nonEmptyString($data['repo'] ?? null);

        $existing = Profile::query()->where('slug', $slug)->first();

        if ($dryRun) {
            return $existing ?? new Profile([
                'slug' => $slug,
                'name' => $name,
                'kind' => ProfileKind::Customer,
                'workdir' => $workdir,
            ]);
        }

        if ($existing !== null) {
            $existing->update([
                'name' => $name,
                'kind' => ProfileKind::Customer,
                'workdir' => $workdir,
            ]);

            $this->ensurePolicy($existing);

            return $existing;
        }

        // The ProfileObserver seeds a default policy on create.
        return Profile::create([
            'slug' => $slug,
            'name' => $name,
            'kind' => ProfileKind::Customer,
            'workdir' => $workdir,
        ]);
    }

    private function ensurePolicy(Profile $profile): void
    {
        if ($profile->policy()->exists()) {
            return;
        }

        $profile->policy()->create([
            'rules' => PolicyDefaults::for($profile->kind),
        ]);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return 'created'|'updated'
     */
    private function upsertTask(Profile $profile, array $entry, TaskStatus $status, bool $dryRun): string
    {
        $ref = $this->nonEmptyString($entry['id'] ?? null);
        $summary = $this->nonEmptyString($entry['summary'] ?? null);
        $title = $this->firstLine($summary) ?? $ref ?? 'Untitled task';

        $attributes = [
            'title' => $title,
            'summary' => $summary,
            'definition_of_done' => $this->stringList($entry['definition_of_done'] ?? []),
            'constraints' => $this->stringList($entry['constraints'] ?? []),
            'target_paths' => $this->stringList($entry['target_paths'] ?? []),
            'priority' => 50,
            'status' => $status,
        ];

        $createdAt = $this->parseDate($entry['created'] ?? null);

        $existing = $ref !== null
            ? Task::query()
                ->where('profile_id', $profile->id ?? 0)
                ->where('ref', $ref)
                ->first()
            : null;

        if ($dryRun) {
            return $existing !== null ? 'updated' : 'created';
        }

        if ($existing !== null) {
            $existing->update($attributes);
            $this->carryQuestions($existing, $status, $entry);

            return 'updated';
        }

        $task = $profile->tasks()->create(array_merge($attributes, [
            'ref' => $ref,
            'created_at' => $createdAt,
        ]));

        $this->carryQuestions($task, $status, $entry);

        return 'created';
    }

    /**
     * Carry legacy blocked-task questions into a single agent comment.
     *
     * @param  array<string, mixed>  $entry
     */
    private function carryQuestions(Task $task, TaskStatus $status, array $entry): void
    {
        if ($status !== TaskStatus::Blocked) {
            return;
        }

        $questions = $this->stringList($entry['questions'] ?? []);

        if ($questions === []) {
            return;
        }

        $body = "Imported open questions from the previous task list:\n\n".implode("\n", array_map(
            fn (string $q) => '- '.$q,
            $questions,
        ));

        // Idempotent: do not duplicate the same imported questions comment.
        $exists = $task->comments()
            ->where('author', TaskComment::AUTHOR_AGENT)
            ->where('body', $body)
            ->exists();

        if ($exists) {
            return;
        }

        $task->comments()->create([
            'author' => TaskComment::AUTHOR_AGENT,
            'body' => $body,
        ]);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        $string = $this->nonEmptyString(is_scalar($value) ? (string) $value : null);

        if ($string === null) {
            return null;
        }

        try {
            return Carbon::parse($string);
        } catch (\Throwable) {
            return null;
        }
    }

    private function firstLine(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $line = trim((string) strtok($text, "\n"));

        return $line !== '' ? $line : null;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            $string = $this->nonEmptyString(is_scalar($item) ? (string) $item : null);

            if ($string !== null) {
                $items[] = $string;
            }
        }

        return $items;
    }
}
