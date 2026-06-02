<?php

namespace App\Services;

/**
 * Classifies a project's filesystem into context roles by folder-name
 * convention — a tolerant discovery pass, NOT a required schema. Records paths
 * only; fresh content is read at injection time so nothing goes stale.
 *
 * Roles (mutability spectrum):
 *   - reference     : frozen ground truth (never edited without explicit command)
 *   - docs          : fluid understanding (propose edits on change)
 *   - living_state  : active project memory (actively maintained)
 */
class ProjectContextDiscovery
{
    private const MANIFEST_FILES = ['.workspace-config.json', '.ws-config.json'];

    /** How many parent levels to search for a manifest / cc directory. */
    private const MAX_ANCESTOR_DEPTH = 3;

    private const ENTRY_DOCS = ['INDEX.md', 'PRIMER.md'];

    /**
     * @return array{
     *   discovered_at: ?string,
     *   manifest: ?array{path: string, project_name: ?string, repos: array<int, array{name: ?string, url: ?string, default_branch: ?string}>},
     *   roles: array{reference: array<int, string>, docs: array<int, string>, living_state: array<int, string>},
     *   entry_doc: ?string
     * }
     */
    public function discover(?string $workdir): array
    {
        $map = [
            'discovered_at' => now()->toIso8601String(),
            'manifest' => null,
            'roles' => ['reference' => [], 'docs' => [], 'living_state' => []],
            'entry_doc' => null,
        ];

        if (empty($workdir) || ! is_dir($workdir)) {
            return $map;
        }

        $workdir = rtrim($workdir, '/');

        $map['manifest'] = $this->findManifest($workdir);
        $map['roles'] = $this->classifyRoles($workdir);
        $map['entry_doc'] = $this->findEntryDoc($map['roles']['living_state']);

        return $map;
    }

    /**
     * @return array{path: string, project_name: ?string, repos: array<int, array{name: ?string, url: ?string, default_branch: ?string}>}|null
     */
    private function findManifest(string $workdir): ?array
    {
        foreach ($this->ancestors($workdir) as $dir) {
            foreach (self::MANIFEST_FILES as $file) {
                $path = $dir.'/'.$file;

                if (! is_file($path) || ! is_readable($path)) {
                    continue;
                }

                $data = json_decode((string) file_get_contents($path), true);

                if (! is_array($data)) {
                    continue;
                }

                $repos = [];
                foreach (($data['repos'] ?? []) as $repo) {
                    if (! is_array($repo)) {
                        continue;
                    }
                    $repos[] = [
                        'name' => $repo['name'] ?? null,
                        'url' => $repo['url'] ?? null,
                        'default_branch' => $repo['default_branch'] ?? null,
                    ];
                }

                return [
                    'path' => $path,
                    'project_name' => $data['project_name'] ?? null,
                    'repos' => $repos,
                ];
            }
        }

        return null;
    }

    /**
     * @return array{reference: array<int, string>, docs: array<int, string>, living_state: array<int, string>}
     */
    private function classifyRoles(string $workdir): array
    {
        $roles = ['reference' => [], 'docs' => [], 'living_state' => []];

        // Candidate directories, by convention. cc/ may sit at the workdir or
        // at a worktree-project ancestor, so search a few levels up for it.
        $candidates = [
            $workdir.'/reference',
            $workdir.'/docs',
            $workdir.'/docs/reference',
            $workdir.'/agent_docs',
        ];

        foreach ($this->ancestors($workdir) as $dir) {
            $candidates[] = $dir.'/cc/reference';
            $candidates[] = $dir.'/cc/docs';
            $candidates[] = $dir.'/cc/agent_docs';
        }

        foreach ($candidates as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $role = $this->roleForBasename(basename($dir));

            if ($role !== null && ! in_array($dir, $roles[$role], true)) {
                $roles[$role][] = $dir;
            }
        }

        return $roles;
    }

    private function roleForBasename(string $basename): ?string
    {
        return match ($basename) {
            'reference' => 'reference',
            'agent_docs' => 'living_state',
            'docs' => 'docs',
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $livingStateDirs
     */
    private function findEntryDoc(array $livingStateDirs): ?string
    {
        foreach ($livingStateDirs as $dir) {
            foreach (self::ENTRY_DOCS as $name) {
                $path = $dir.'/'.$name;

                if (is_file($path) && is_readable($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * The workdir and its parents, up to MAX_ANCESTOR_DEPTH levels.
     *
     * @return array<int, string>
     */
    private function ancestors(string $workdir): array
    {
        $dirs = [$workdir];
        $current = $workdir;

        for ($i = 0; $i < self::MAX_ANCESTOR_DEPTH; $i++) {
            $parent = dirname($current);

            if ($parent === $current) {
                break;
            }

            $dirs[] = $parent;
            $current = $parent;
        }

        return $dirs;
    }
}
