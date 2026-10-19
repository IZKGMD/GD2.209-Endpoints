<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use PDO;

final class SourceDetector
{
    /**
     * Detect a source database by schema, not by product name.
     * GDPS-Maker commonly provisions a Cvolton-compatible database, so the
     * schema family remains the source of truth instead of the product name.
     * The source is read only; this class performs metadata/SELECT queries only.
     *
     * @return array{
     *   engine:string,
     *   label:string,
     *   confidence:string,
     *   required_tables:string[],
     *   optional_tables:string[],
     *   present_tables:string[],
     *   datasets:array<int,array<string,mixed>>
     * }
     */
    public function inspect(PDO $source): array
    {
        $tables = $this->tables($source);

        $hasAccounts = in_array('accounts', $tables, true);
        $hasUsers = in_array('users', $tables, true);
        $hasLevels = in_array('levels', $tables, true);

        $engine = 'unknown';
        $label = 'Unknown GDPS schema';
        $confidence = 'none';
        $required = [];
        $optional = [];

        if ($hasAccounts && $hasUsers && $hasLevels) {
            $engine = 'cvolton';
            $label = 'Cvolton-compatible GDPS schema';
            $confidence = 'high';
            $required = ['accounts', 'users', 'levels'];

            foreach (['levelscores', 'platscores'] as $table) {
                if (in_array($table, $tables, true)) {
                    $optional[] = $table;
                }
            }

            $megaSignature = [
                'acccomments',
                'friendships',
                'friendreqs',
                'messages',
                'roles',
                'roleassign',
                'songs',
            ];

            $matchedSignature = count(array_intersect($megaSignature, $tables));
            if ($matchedSignature >= 6) {
                $label = 'MegaSa1nt-style / FHGDPS-compatible Cvolton schema';
            }
        }

        return [
            'engine' => $engine,
            'label' => $label,
            'confidence' => $confidence,
            'required_tables' => $required,
            'optional_tables' => $optional,
            'present_tables' => $tables,
            'datasets' => $this->datasets($tables),
        ];
    }

    /**
     * Keep the original small detection contract for callers that only need
     * the engine/family decision.
     *
     * @return array<string,mixed>
     */
    public function detect(PDO $source): array
    {
        $inspection = $this->inspect($source);

        return [
            'engine' => $inspection['engine'],
            'label' => $inspection['label'],
            'confidence' => $inspection['confidence'],
            'required_tables' => $inspection['required_tables'],
            'optional_tables' => $inspection['optional_tables'],
        ];
    }

    /**
     * @param string[] $tables
     * @return array<int,array<string,mixed>>
     */
    private function datasets(array $tables): array
    {
        $definitions = [
            [
                'key' => 'accounts',
                'label' => 'Accounts & profiles',
                'tables' => ['accounts', 'users'],
                'status' => 'imported_now',
                'where' => 'accounts stores login/account data; users stores the player profile and progress.',
                'notes' => 'Account IDs are mapped to MuchoCore IDs so repeated migrations do not blindly duplicate accounts.',
            ],
            [
                'key' => 'levels',
                'label' => 'Levels',
                'tables' => ['levels'],
                'status' => 'imported_now',
                'where' => 'levels contains level metadata and the full levelString.',
                'notes' => 'The source level ID is preserved when it is still free in MuchoCore; otherwise a new target ID is allocated.',
            ],
            [
                'key' => 'scores',
                'label' => 'Scores',
                'tables' => ['levelscores', 'platscores'],
                'status' => 'imported_now',
                'where' => 'levelscores contains classic-level progress; platscores contains Platformer scores.',
                'notes' => 'Scores are matched through the imported account and level mappings.',
            ],
            [
                'key' => 'comments',
                'label' => 'Comments',
                'tables' => ['comments', 'acccomments'],
                'status' => 'detected_only',
                'where' => 'comments are level comments; acccomments are profile/account comments.',
                'notes' => 'Detected and counted by Migration Center, but not yet written into MuchoCore automatically.',
            ],
            [
                'key' => 'social',
                'label' => 'Friends, requests, blocks & messages',
                'tables' => ['friendships', 'friendreqs', 'blocks', 'messages', 'links'],
                'status' => 'detected_only',
                'where' => 'friendships, friendreqs, blocks, messages and links hold the social graph and private messaging state.',
                'notes' => 'Reported explicitly so the migration never pretends these datasets were moved when they were not.',
            ],
            [
                'key' => 'collections',
                'label' => 'Lists, Map Packs, Gauntlets & Daily',
                'tables' => ['lists', 'mappacks', 'gauntlets', 'dailyfeatures'],
                'status' => 'detected_only',
                'where' => 'lists, mappacks, gauntlets and dailyfeatures contain curated level collections and daily selections.',
                'notes' => 'Detected and counted, but not yet automatically mapped to MuchoCore structures.',
            ],
            [
                'key' => 'moderation',
                'label' => 'Moderation & legacy admin state',
                'tables' => ['roles', 'roleassign', 'modips', 'bannedips', 'reports', 'modactions', 'actions', 'suggest', 'modipperms'],
                'status' => 'detected_only',
                'where' => 'Legacy moderator permissions, IP lists, reports, actions and rating suggestions live in these tables.',
                'notes' => 'Never copied automatically into MuchoCore RBAC. A separate role/moderation migration is safer because the permission models differ.',
            ],
            [
                'key' => 'audio',
                'label' => 'Songs & SFX',
                'tables' => ['songs'],
                'status' => 'filesystem',
                'where' => 'songs contains metadata; MegaSa1nt/Cvolton also stores binary music under the old server music/ directory and SFX under sfx/.',
                'notes' => 'Database-only migration can inspect song metadata, but the actual audio files require access to the old server filesystem or an archive.',
            ],
            [
                'key' => 'analytics',
                'label' => 'Download/like analytics',
                'tables' => ['actions_downloads', 'actions_likes', 'cpshares'],
                'status' => 'detected_only',
                'where' => 'actions_downloads and actions_likes store analytics; cpshares stores creator-point share relations.',
                'notes' => 'These datasets depend on legacy analytics/identity semantics and are not silently converted to MuchoCore counters.',
            ],
        ];

        foreach ($definitions as &$dataset) {
            $present = [];
            foreach ($dataset['tables'] as $table) {
                if (in_array($table, $tables, true)) {
                    $present[] = $table;
                }
            }

            $dataset['present_tables'] = $present;
            $dataset['available'] = $present !== [];
            $dataset['complete'] = count($present) === count($dataset['tables']);
        }
        unset($dataset);

        return $definitions;
    }

    /**
     * @return string[]
     */
    private function tables(PDO $source): array
    {
        $rows = $source->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
        $tables = [];

        foreach ($rows as $row) {
            if (isset($row[0]) && is_string($row[0])) {
                $tables[] = $row[0];
            }
        }

        sort($tables, SORT_STRING);
        return $tables;
    }
}
