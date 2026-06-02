<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\Claude\ClaudeRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Cold discovery for projects whose folder names don't match the convention.
 * Runs a read-only agent survey of the tree and stores a PROPOSED role map in
 * the project's settings for the human to confirm — heuristics never auto-apply
 * an agent's guess.
 */
class DiscoverProjectJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Read-only tools available to the survey. */
    public const ALLOWED_TOOLS = ['Read', 'Grep', 'Glob'];

    public function __construct(public Project $project) {}

    public function handle(ClaudeRunner $runner): void
    {
        $project = $this->project->fresh();

        if ($project === null) {
            return;
        }

        $workdir = $project->resolvedWorkdir();

        if (empty($workdir) || ! is_dir($workdir)) {
            return;
        }

        try {
            $result = $runner->run($this->prompt($workdir), $workdir, [
                'allowed_tools' => self::ALLOWED_TOOLS,
            ]);

            $parsed = $this->extractJson($result->result);

            if ($parsed === null) {
                return;
            }

            $settings = $project->settings ?? [];
            $settings['context_proposal'] = [
                'roles' => [
                    'reference' => $this->stringList($parsed['reference'] ?? []),
                    'docs' => $this->stringList($parsed['docs'] ?? []),
                    'living_state' => $this->stringList($parsed['living_state'] ?? []),
                ],
                'entry_doc' => is_string($parsed['entry_doc'] ?? null) ? $parsed['entry_doc'] : null,
                'rationale' => is_string($parsed['rationale'] ?? null) ? $parsed['rationale'] : null,
                'proposed_at' => now()->toIso8601String(),
            ];

            $project->update(['settings' => $settings]);
        } catch (Throwable) {
            // Best-effort: a failed survey simply leaves no proposal.
        }
    }

    private function prompt(string $workdir): string
    {
        return implode("\n", [
            'You are surveying a project filesystem to classify its documentation directories into',
            'roles, so an orchestration tool knows how to treat them. You have read-only access',
            '(Read, Grep, Glob). Do not modify anything.',
            '',
            'Roles (a mutability spectrum):',
            '- reference: frozen, authoritative ground-truth docs an agent must NEVER edit.',
            '- docs: fluid documentation/understanding an agent may propose edits to.',
            '- living_state: active project state/memory maintained across sessions (roadmap, changelog,',
            '  blockers, an index/primer).',
            '',
            'Working directory: '.$workdir,
            'Inspect the top two or three levels. Identify directories that play each role, even when',
            'named unconventionally (e.g. a "knowledge" dir may be reference, a "journal" dir may be',
            'living_state). Return absolute directory paths.',
            '',
            'Respond with ONLY a single JSON object, no prose around it, of this exact shape:',
            '{"reference": string[], "docs": string[], "living_state": string[], "entry_doc": string|null, "rationale": string}',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(string $text): ?array
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $decoded = json_decode(substr($text, $start, $i - $start + 1), true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
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
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return $items;
    }
}
