<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Billing\PublicApi\SubscriptionAccess;
use App\Modules\Catalog\PublicApi\StarterLibrary;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ServerSnapshotSeeder extends Seeder
{
    public function run(): void
    {
        $root = database_path('seeders/snapshot');
        $archive = $root.'/dataset.json.gz';
        if (! is_file($archive)) {
            throw new RuntimeException('Snapshot is missing: database/seeders/snapshot/dataset.json.gz');
        }
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('This snapshot supports PostgreSQL or local SQLite.');
        }
        $data = json_decode(gzdecode(file_get_contents($archive)), true, 512, JSON_THROW_ON_ERROR);
        if (($data['format'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported snapshot format.');
        }
        $expected = array_column($data['tables']['migrations'], 'migration');
        $actual = DB::table('migrations')->pluck('migration')->all();
        sort($expected);
        sort($actual);
        if (array_diff($expected, $actual)) {
            throw new RuntimeException('Run the migrations shipped with this archive before seeding.');
        }
        foreach ($data['files'] as $relative => $checksum) {
            $this->validatePath($relative);
            $source = $root.'/files/'.$relative;
            $destination = storage_path('app/'.$relative);
            if (! is_file($source) || hash_file('sha256', $source) !== $checksum) {
                throw new RuntimeException('Missing or damaged snapshot image: '.$relative);
            }
            if (file_exists($destination) && (! is_file($destination) || hash_file('sha256', $destination) !== $checksum)) {
                throw new RuntimeException('Refusing to overwrite a different image: '.$relative);
            }
        }
        DB::transaction(function () use ($data, $root) {
            foreach ($data['tables'] as $table => $rows) {
                $quoted = $this->identifier($table);
                if ($table === 'migrations') {
                    continue;
                }
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('LOCK TABLE '.$quoted.' IN EXCLUSIVE MODE');
                }
                if (DB::table($table)->exists()) {
                    throw new RuntimeException('Restore requires an empty database. Non-empty table: '.$table);
                }
            }
            foreach ($data['files'] as $relative => $checksum) {
                $destination = storage_path('app/'.$relative);
                if (is_file($destination)) {
                    continue;
                }
                if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0755, true) && ! is_dir(dirname($destination))) {
                    throw new RuntimeException('Cannot create image directory.');
                }
                if (! copy($root.'/files/'.$relative, $destination) || hash_file('sha256', $destination) !== $checksum) {
                    throw new RuntimeException('Cannot restore image: '.$relative);
                }
                chmod($destination, 0644);
            }
            // Dataset tables are ordered by foreign-key dependencies; models/events are deliberately bypassed.
            foreach ($data['tables'] as $table => $rows) {
                if ($table === 'migrations') {
                    continue;
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
                $this->command?->info($table.': '.count($rows));
            }
            foreach ($data['sequences'] as $sequence) {
                if (DB::getDriverName() !== 'pgsql') {
                    break;
                }
                if (! $sequence['sequence'] || $sequence['table_name'] === 'migrations') {
                    continue;
                }
                $max = DB::table($sequence['table_name'])->max($sequence['column_name']);
                DB::select('SELECT setval(?::regclass, ?, ?)', [$sequence['sequence'], $max ?? 1, $max !== null]);
            }
        });
        $previousUser = Auth::user();
        try {
            foreach ($data['tables']['workspaces'] as $workspace) {
                app(SubscriptionAccess::class)->ensureLegacyGrant($workspace['id']);
                Auth::setUser(User::findOrFail($workspace['created_by']));
                app(StarterLibrary::class)->organizeExisting();
            }
        } finally {
            if ($previousUser) {
                Auth::setUser($previousUser);
            } else {
                Auth::forgetUser();
            }
        }
        $this->command?->info('Snapshot restored: '.$data['exported_at'].'; images: '.count($data['files']));
    }

    private function identifier(string $name): string
    {
        if (! preg_match('/^[a-z_]+$/D', $name)) {
            throw new RuntimeException('Invalid table name.');
        }

        return '"'.$name.'"';
    }

    private function validatePath(string $path): void
    {
        if (! preg_match('#^(private|public)/[a-zA-Z0-9_./-]+$#D', $path) || str_contains($path, '..')) {
            throw new RuntimeException('Invalid image path.');
        }
    }
}
