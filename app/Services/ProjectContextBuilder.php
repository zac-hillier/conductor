<?php

namespace App\Services;

use App\Models\Task;

/**
 * Produces the standard "project context" block injected into every worker /
 * scoping / scoring prompt. Curated + budget-bounded: it injects the living
 * state (entry doc, latest changelog, roadmap snapshot, open P0 blockers) and
 * the root README/CLAUDE docs in full, but only *indexes* reference/docs for
 * on-demand reading — and stamps each role's edit policy.
 *
 * Reads fresh file content every call (discovery persists paths, not bodies),
 * so nothing goes stale.
 */
class ProjectContextBuilder
{
    private const ROOT_DOCS = ['README.md', 'CONDUCTOR.md', 'CLAUDE.md'];

    private const ENTRY_LIMIT = 2500;

    private const CHANGELOG_LIMIT = 1500;

    private const ROADMAP_LIMIT = 1500;

    private const PINNED_LIMIT = 2500;

    private const MAX_BLOCKERS = 12;

    private const MAX_INDEX_PER_ROLE = 25;

    public function __construct(private readonly ContextFileReader $reader = new ContextFileReader) {}

    /**
     * A compact project "pulse" for the UI: the latest CHANGELOG headline, the
     * open-P0 blocker count, and the in-progress roadmap line. All best-effort.
     *
     * @param  array<string, mixed>|null  $map
     * @return array{changelog: ?string, open_p0: int, roadmap: ?string}
     */
    public function pulse(?array $map): array
    {
        $pulse = ['changelog' => null, 'open_p0' => 0, 'roadmap' => null];

        $dirs = $map['roles']['living_state'] ?? [];
        if ($dirs === []) {
            return $pulse;
        }

        $changelog = $this->firstFile($dirs, 'CHANGELOG.md');
        if ($changelog !== null) {
            $section = $this->firstSection((string) file_get_contents($changelog));
            if ($section !== null) {
                $pulse['changelog'] = trim(ltrim(explode("\n", $section)[0], '# '));
            }
        }

        $blockers = $this->firstFile($dirs, 'BLOCKERS.md');
        if ($blockers !== null) {
            $pulse['open_p0'] = count($this->openP0Blockers((string) file_get_contents($blockers)));
        }

        $roadmap = $this->firstFile($dirs, 'ROADMAP.md');
        if ($roadmap !== null) {
            foreach (preg_split('/\R/', (string) file_get_contents($roadmap)) ?: [] as $line) {
                if (stripos($line, 'in progress') !== false) {
                    $pulse['roadmap'] = trim(ltrim($line, '-*# '));
                    break;
                }
            }
        }

        return $pulse;
    }

    /**
     * @return array<int, string> Prompt lines (no surrounding blank lines).
     */
    public function block(Task $task): array
    {
        $lines = [];

        $profile = $task->profile;

        if ($profile !== null) {
            $lines[] = 'Profile context:';
            $lines[] = '- Name: '.$profile->name;
            $lines[] = '- Kind: '.$profile->kind->label();

            if ($task->project !== null) {
                $lines[] = '- Project: '.$task->project->name;
            }

            $workdir = $task->resolvedWorkdir();
            if (! empty($workdir)) {
                $lines[] = '- Working directory: '.$workdir;
            }

            if (! empty($profile->repo_url)) {
                $lines[] = '- Repository: '.$profile->repo_url;
            }
        }

        $workdir = $task->resolvedWorkdir();
        $map = $task->project?->context_map ?? [];

        $lines = array_merge($lines, $this->rootDocs($workdir));
        $lines = array_merge($lines, $this->livingState($map));
        $lines = array_merge($lines, $this->referenceIndex($map));
        $lines = array_merge($lines, $this->manifestSummary($map));
        $lines = array_merge($lines, $this->pinnedDocs($task, $workdir));

        return $lines;
    }

    /**
     * README/CONDUCTOR/CLAUDE at the working directory root, in full (capped).
     *
     * @return array<int, string>
     */
    private function rootDocs(?string $workdir): array
    {
        $found = [];

        if (! empty($workdir) && is_dir($workdir)) {
            foreach (self::ROOT_DOCS as $name) {
                $body = $this->reader->read(rtrim($workdir, '/').'/'.$name);
                if ($body !== null && $body !== '') {
                    $found[$name] = $body;
                }
            }
        }

        if ($found === []) {
            return ['', 'Read README.md, CONDUCTOR.md and CLAUDE.md in the working directory (if present) for project context.'];
        }

        $lines = ['', 'Project context files (read from the working directory):'];
        foreach ($found as $name => $body) {
            $lines[] = '';
            $lines[] = '--- '.$name.' ---';
            $lines[] = $body;
        }

        return $lines;
    }

    /**
     * The living-state digest: entry doc + latest changelog + roadmap snapshot
     * + open P0 blockers, from the discovered agent_docs dirs.
     *
     * @param  array<string, mixed>  $map
     * @return array<int, string>
     */
    private function livingState(array $map): array
    {
        $dirs = $map['roles']['living_state'] ?? [];

        if ($dirs === []) {
            return [];
        }

        $lines = ['', 'Project state (living docs — keep these maintained as you work):'];

        $entryPath = $map['entry_doc'] ?? null;
        if (is_string($entryPath)) {
            $body = $this->reader->read($entryPath, self::ENTRY_LIMIT);
            if ($body !== null) {
                $lines[] = '';
                $lines[] = 'Entry doc ('.basename($entryPath).'):';
                $lines[] = $body;
            }
        }

        $changelog = $this->firstFile($dirs, 'CHANGELOG.md');
        if ($changelog !== null) {
            $entry = $this->firstSection((string) file_get_contents($changelog));
            if ($entry !== null) {
                $lines[] = '';
                $lines[] = 'Latest CHANGELOG entry:';
                $lines[] = $this->truncate($entry, self::CHANGELOG_LIMIT);
            }
        }

        $roadmap = $this->firstFile($dirs, 'ROADMAP.md');
        if ($roadmap !== null) {
            $snapshot = $this->roadmapSnapshot((string) file_get_contents($roadmap));
            if ($snapshot !== null) {
                $lines[] = '';
                $lines[] = 'Roadmap snapshot:';
                $lines[] = $this->truncate($snapshot, self::ROADMAP_LIMIT);
            }
        }

        $blockers = $this->firstFile($dirs, 'BLOCKERS.md');
        if ($blockers !== null) {
            $p0 = $this->openP0Blockers((string) file_get_contents($blockers));
            if ($p0 !== []) {
                $lines[] = '';
                $lines[] = 'Open P0 blockers:';
                foreach ($p0 as $line) {
                    $lines[] = '- '.$line;
                }
            }
        }

        return $lines;
    }

    /**
     * Index (not bodies) of reference + docs, with each role's edit policy.
     *
     * @param  array<string, mixed>  $map
     * @return array<int, string>
     */
    private function referenceIndex(array $map): array
    {
        $reference = $this->listDocs($map['roles']['reference'] ?? []);
        $docs = $this->listDocs($map['roles']['docs'] ?? []);

        if ($reference === [] && $docs === []) {
            return [];
        }

        $lines = ['', 'Reference & docs available to Read on demand (do not load blindly):'];

        foreach ($reference as $path => $heading) {
            $lines[] = '- [reference — do NOT edit unless explicitly told] '.$path.($heading !== '' ? ' — '.$heading : '');
        }

        foreach ($docs as $path => $heading) {
            $lines[] = '- [docs — propose an edit if your work makes it stale] '.$path.($heading !== '' ? ' — '.$heading : '');
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<int, string>
     */
    private function manifestSummary(array $map): array
    {
        $manifest = $map['manifest'] ?? null;

        if (! is_array($manifest)) {
            return [];
        }

        $repos = array_filter(array_map(
            fn ($r) => is_array($r) ? ($r['name'] ?? null) : null,
            $manifest['repos'] ?? [],
        ));

        if ($repos === []) {
            return [];
        }

        return ['', 'Workspace manifest: '.($manifest['project_name'] ?? 'project').' — repos: '.implode(', ', $repos)];
    }

    /**
     * @return array<int, string>
     */
    private function pinnedDocs(Task $task, ?string $workdir): array
    {
        $pinned = $task->pinned_docs ?? [];

        if ($pinned === [] || empty($workdir)) {
            return [];
        }

        $lines = ['', 'Pinned context (the human marked these essential for this task):'];

        foreach ($pinned as $relative) {
            $path = str_starts_with((string) $relative, '/')
                ? (string) $relative
                : rtrim($workdir, '/').'/'.ltrim((string) $relative, '/');

            $body = $this->reader->read($path, self::PINNED_LIMIT);
            if ($body !== null) {
                $lines[] = '';
                $lines[] = '--- '.$relative.' ---';
                $lines[] = $body;
            }
        }

        return $lines;
    }

    /**
     * @param  array<int, string>  $dirs
     */
    private function firstFile(array $dirs, string $name): ?string
    {
        foreach ($dirs as $dir) {
            $path = rtrim((string) $dir, '/').'/'.$name;
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Top-level .md files in the given dirs, mapped path => first heading.
     *
     * @param  array<int, string>  $dirs
     * @return array<string, string>
     */
    private function listDocs(array $dirs): array
    {
        $docs = [];

        foreach ($dirs as $dir) {
            $matches = glob(rtrim((string) $dir, '/').'/*.md') ?: [];

            foreach ($matches as $path) {
                if (count($docs) >= self::MAX_INDEX_PER_ROLE) {
                    break 2;
                }
                $docs[$path] = $this->firstHeading($path);
            }
        }

        return $docs;
    }

    private function firstHeading(string $path): string
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return '';
        }

        $heading = '';
        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '#')) {
                $heading = trim(ltrim($trimmed, '# '));
                break;
            }
        }
        fclose($handle);

        return $heading;
    }

    /**
     * The first `## ` section of a markdown doc (heading through to the next
     * `## ` or end) — used for the newest CHANGELOG entry.
     */
    private function firstSection(string $body): ?string
    {
        if (preg_match('/^##[^#].*?(?=\n##[^#]|\z)/sm', $body, $m) === 1) {
            return trim($m[0]);
        }

        return null;
    }

    /**
     * The phase-status section of a ROADMAP, or its head if none is labelled.
     */
    private function roadmapSnapshot(string $body): ?string
    {
        if (preg_match('/^#{2,3}\s.*(snapshot|status).*$.*?(?=\n#{1,3}\s|\z)/smi', $body, $m) === 1) {
            return trim($m[0]);
        }

        $trimmed = trim($body);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Lines in the "Open" section of a BLOCKERS doc that mention P0.
     *
     * @return array<int, string>
     */
    private function openP0Blockers(string $body): array
    {
        $open = $body;

        // Prefer just the "Open" section if the doc separates open/resolved.
        if (preg_match('/^##\s*Open.*?(?=\n##\s|\z)/smi', $body, $m) === 1) {
            $open = $m[0];
        }

        $found = [];
        foreach (preg_split('/\R/', $open) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && stripos($trimmed, 'P0') !== false) {
                $found[] = ltrim($trimmed, '-* ');
                if (count($found) >= self::MAX_BLOCKERS) {
                    break;
                }
            }
        }

        return $found;
    }

    private function truncate(string $text, int $limit): string
    {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text;
    }
}
