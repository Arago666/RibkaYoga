#!/usr/bin/env php
<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/** Private operator import into a separate, empty PostgreSQL database. */
final class OperatorFailure extends RuntimeException {}

const RUNTIME_TABLES = ['migrations', 'cache', 'cache_locks', 'sessions', 'password_reset_tokens', 'jobs', 'job_batches', 'failed_jobs'];

function fail(string $message): never
{
    throw new OperatorFailure($message);
}

function identifier(string $name): string
{
    if (! preg_match('/^[a-z][a-z0-9_]*$/D', $name)) {
        fail('Unexpected database identifier.');
    }

    return '"'.$name.'"';
}

function canonical(mixed $value): mixed
{
    if ($value instanceof stdClass) {
        $properties = get_object_vars($value);
        ksort($properties, SORT_STRING);
        $object = new stdClass;
        foreach ($properties as $key => $child) {
            $object->{$key} = canonical($child);
        }

        return $object;
    }
    if (is_array($value)) {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(canonical(...), $value);
    }

    return $value;
}

function jsonBytes(mixed $value): string
{
    return json_encode(canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
}

function rowHash(array $rows): string
{
    $encoded = array_map(jsonBytes(...), $rows);
    sort($encoded, SORT_STRING);

    return hash('sha256', implode("\n", $encoded));
}

function securePath(string $root, string $relative, bool $mustExist = true): string
{
    if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\') || str_starts_with($relative, '/')) {
        fail('Unsafe package/private asset path.');
    }
    $parts = explode('/', $relative);
    $current = rtrim($root, '/');
    foreach ($parts as $part) {
        if (in_array($part, ['', '.', '..'], true)) {
            fail('Unsafe package/private asset path.');
        }
        $current .= '/'.$part;
        if (is_link($current)) {
            fail('Symlink in package/private asset path.');
        }
    }
    if ($mustExist && (! is_file($current) || ! is_readable($current))) {
        fail('Required package/private asset file is missing.');
    }

    return $current;
}

function readPackage(string $directory): array
{
    $root = realpath($directory);
    if ($root === false || ! is_dir($root) || is_link($directory)) {
        fail('Package directory is missing or is a symlink.');
    }
    $manifest = json_decode(file_get_contents(securePath($root, 'manifest.json')), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['format'] ?? null) !== 'ribka-content-manifest-v1') {
        fail('Unsupported package manifest.');
    }
    foreach (['dataset.json.gz' => 'dataset_sha256', 'snapshot.sqlite' => 'sqlite_sha256'] as $file => $key) {
        if (! isset($manifest[$key]) || ! hash_equals($manifest[$key], hash_file('sha256', securePath($root, $file)))) {
            fail('Package data/snapshot checksum mismatch.');
        }
    }
    $compressed = file_get_contents(securePath($root, 'dataset.json.gz'));
    $plain = gzdecode($compressed, 536870912);
    if ($plain === false) {
        fail('Cannot decode package dataset.');
    }
    $data = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    if (($data['format'] ?? null) !== 'ribka-content-export-v1' || ! is_array($data['tables'] ?? null) || ! is_array($manifest['tables'] ?? null)) {
        fail('Unsupported package dataset.');
    }
    $tableNames = array_keys($data['tables']);
    $manifestNames = array_keys($manifest['tables']);
    sort($tableNames);
    sort($manifestNames);
    if ($tableNames !== $manifestNames || array_intersect($tableNames, RUNTIME_TABLES)) {
        fail('Unexpected package table list.');
    }
    foreach (['users', 'workspaces', 'media_assets', 'catalog_asanas', 'practice_documents', 'practice_cells'] as $required) {
        if (! isset($data['tables'][$required])) {
            fail('Required application table is missing from package.');
        }
    }
    foreach ($data['tables'] as $name => $table) {
        identifier($name);
        if (! is_array($table['columns'] ?? null) || ! is_array($table['rows'] ?? null)) {
            fail('Invalid package table structure.');
        }
        $columns = array_column($table['columns'], 'name');
        foreach ($columns as $column) {
            identifier($column);
        }
        $sortedColumns = $columns;
        sort($sortedColumns);
        foreach ($table['rows'] as $row) {
            $rowColumns = array_keys($row);
            sort($rowColumns);
            if ($rowColumns !== $sortedColumns) {
                fail('Package row/column mismatch.');
            }
        }
        if (count($table['rows']) !== ($manifest['tables'][$name]['count'] ?? null)
            || ! hash_equals($manifest['tables'][$name]['raw_sha256'] ?? '', rowHash($table['rows']))) {
            fail('Package table count/checksum mismatch.');
        }
    }
    foreach ($data['tables']['users']['rows'] as $row) {
        if (! empty($row['two_factor_secret']) || ! empty($row['two_factor_recovery_codes'])) {
            fail('Key-bound 2FA data needs an explicit key migration plan.');
        }
    }
    $files = [];
    foreach ($manifest['files'] ?? [] as $file) {
        $relative = $file['path'] ?? '';
        if (isset($files[$relative])) {
            fail('Duplicate package asset path.');
        }
        $source = securePath($root, 'files/'.$relative);
        if (filesize($source) !== ($file['bytes'] ?? null) || ! hash_equals($file['sha256'] ?? '', hash_file('sha256', $source))) {
            fail('Package asset checksum mismatch.');
        }
        $files[$relative] = $file;
    }
    $references = [];
    foreach ($data['tables']['media_assets']['rows'] as $row) {
        if (($row['disk'] ?? null) !== 'local') {
            fail('Only local private media is supported.');
        }
        foreach (['original_path', 'thumbnail_path'] as $column) {
            if (! empty($row[$column])) {
                $references[$row[$column]] = true;
            }
        }
    }
    foreach ($data['tables']['media_audio_assets']['rows'] ?? [] as $row) {
        if (($row['disk'] ?? null) !== 'local') {
            fail('Only local private audio is supported.');
        }
        if (! empty($row['path'])) {
            $references[$row['path']] = true;
        }
    }
    foreach ($data['tables']['users']['rows'] as $row) {
        if (! empty($row['avatar_path'])) {
            $references[$row['avatar_path']] = true;
        }
    }
    $referenced = array_keys($references);
    $packaged = array_keys($files);
    sort($referenced);
    sort($packaged);
    if ($referenced !== $packaged) {
        fail('Package asset list differs from all database file references.');
    }

    return [$root, $manifest, $data];
}

function setEnvironment(string $name, string $value): void
{
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

function targetUrl(string $url, string $database): string
{
    $parts = parse_url($url);
    if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['pgsql', 'postgres', 'postgresql'], true) || empty($parts['host'])) {
        fail('Unsupported DB_URL; use the existing PostgreSQL connection configuration.');
    }
    $credentials = isset($parts['user']) ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '';
    $host = str_contains($parts['host'], ':') ? '['.trim($parts['host'], '[]').']' : $parts['host'];

    return $parts['scheme'].'://'.$credentials.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').'/'.rawurlencode($database).(isset($parts['query']) ? '?'.$parts['query'] : '');
}

function typeValue(mixed $value, array $column, bool $forHash): mixed
{
    if ($value === null) {
        return null;
    }
    $type = $column['type_name'];
    if (in_array($type, ['bool', 'boolean'], true)) {
        $normalized = match ($value) {
            true, 1, '1', 't', 'true' => true,
            false, 0, '0', 'f', 'false' => false,
            default => fail('Invalid boolean in transferred data.'),
        };

        return $forHash ? $normalized : ($normalized ? 'true' : 'false');
    }
    if (in_array($type, ['json', 'jsonb'], true)) {
        $decoded = is_string($value) ? json_decode($value, false, 512, JSON_THROW_ON_ERROR) : $value;

        return $forHash ? canonical($decoded) : jsonBytes($decoded);
    }
    if (in_array($type, ['int2', 'int4', 'int8', 'smallint', 'integer', 'bigint'], true)) {
        if (! is_int($value) && (! is_string($value) || ! preg_match('/^-?\d+$/D', $value))) {
            fail('Invalid integer in transferred data.');
        }

        return (int) $value;
    }
    if (in_array($type, ['timestamp', 'timestamptz', 'timestamp without time zone', 'timestamp with time zone'], true)) {
        $date = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
    if ($type === 'date') {
        return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->format('Y-m-d');
    }
    if (in_array($type, ['float4', 'float8', 'real', 'double precision'], true)) {
        return (float) $value;
    }

    return (string) $value;
}

function normalizedRows(array $rows, array $columns): array
{
    return array_map(function ($row) use ($columns) {
        $result = [];
        foreach ($columns as $name => $column) {
            $result[$name] = typeValue($row[$name], $column, true);
        }

        return $result;
    }, $rows);
}

function prepareFiles(string $project, string $package, array $manifest, bool $install, bool $verify): array
{
    $storage = realpath($project.'/storage/app/private');
    if ($storage === false || ! is_dir($storage)) {
        fail('Project private storage directory does not exist.');
    }
    $missing = [];
    foreach ($manifest['files'] as $file) {
        $destination = securePath($storage, $file['path'], false);
        if (file_exists($destination)) {
            if (! is_file($destination) || ! hash_equals($file['sha256'], hash_file('sha256', $destination))) {
                fail('Existing private file differs; refusing to overwrite immutable content.');
            }
        } else {
            $missing[] = $file;
        }
    }
    if ($missing && (! $install || $verify)) {
        fail('Referenced private files are missing; use --install-media during import.');
    }
    foreach ($missing as $file) {
        $destination = securePath($storage, $file['path'], false);
        if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0750, true) && ! is_dir(dirname($destination))) {
            fail('Cannot create private storage directories.');
        }
        securePath($storage, $file['path'], false);
        $temporary = dirname($destination).'/.release-'.bin2hex(random_bytes(12));
        if (! copy(securePath($package, 'files/'.$file['path']), $temporary)) {
            fail('Cannot copy private asset.');
        }
        chmod($temporary, 0640);
        if (! hash_equals($file['sha256'], hash_file('sha256', $temporary)) || ! link($temporary, $destination)) {
            @unlink($temporary);
            fail('Cannot install private asset without replacement.');
        }
        unlink($temporary);
    }

    return ['verified' => count($manifest['files']), 'installed' => count($missing)];
}

function inspectSchema($schema, $connection, array $tables): array
{
    $available = [];
    foreach ($schema->getTables() as $table) {
        if (($table['schema'] ?? 'public') === 'public') {
            $available[] = $table['name'];
        }
    }
    $expected = array_keys($tables);
    $domain = array_values(array_diff($available, RUNTIME_TABLES));
    sort($expected);
    sort($domain);
    if ($expected !== $domain) {
        fail('Target application table list differs from the package; use matching code and migrations.');
    }
    $metadata = [];
    foreach ($expected as $name) {
        $columns = [];
        foreach ($schema->getColumns($name) as $column) {
            $columns[$column['name']] = $column;
        }
        $sourceColumns = array_column($tables[$name]['columns'], 'name');
        $actualColumns = array_keys($columns);
        sort($sourceColumns);
        sort($actualColumns);
        if ($sourceColumns !== $actualColumns) {
            fail('Target columns differ from the package: '.$name);
        }
        $primary = [];
        foreach ($schema->getIndexes($name) as $index) {
            if ($index['primary']) {
                $primary = $index['columns'];
                break;
            }
        }
        $metadata[$name] = ['columns' => $columns, 'primary' => $primary, 'foreign' => $schema->getForeignKeys($name)];
    }

    return $metadata;
}

function insertionPlan(array $metadata): array
{
    $pending = array_fill_keys(array_keys($metadata), true);
    $dependencies = [];
    $deferred = [];
    foreach ($metadata as $name => $table) {
        $dependencies[$name] = [];
        foreach ($table['foreign'] as $foreign) {
            $target = $foreign['foreign_table'];
            if (! isset($metadata[$target])) {
                fail('Domain table references a non-exported table.');
            }
            if ($target === $name) {
                if (! $table['primary']) {
                    fail('Self-reference restoration needs a primary key.');
                }
                foreach ($foreign['columns'] as $column) {
                    if (! $table['columns'][$column]['nullable'] || in_array($column, $table['primary'], true)) {
                        fail('Non-nullable self-reference needs an explicit import plan.');
                    }
                    $deferred[$name][$column] = true;
                }
            } else {
                $dependencies[$name][$target] = true;
            }
        }
    }
    $order = [];
    while ($pending) {
        $ready = [];
        foreach (array_keys($pending) as $name) {
            if (! array_intersect_key($dependencies[$name], $pending)) {
                $ready[] = $name;
            }
        }
        if (! $ready) {
            fail('Unexpected inter-table foreign-key cycle; refusing to disable constraints.');
        }
        sort($ready);
        foreach ($ready as $name) {
            $order[] = $name;
            unset($pending[$name]);
        }
    }

    return [$order, $deferred];
}

function compareData($connection, array $tables, array $metadata): array
{
    $receipt = [];
    foreach ($tables as $name => $table) {
        $actual = array_map(fn ($row) => (array) $row, $connection->select('SELECT * FROM '.identifier($name)));
        $expectedHash = rowHash(normalizedRows($table['rows'], $metadata[$name]['columns']));
        $actualHash = rowHash(normalizedRows($actual, $metadata[$name]['columns']));
        if (count($actual) !== count($table['rows']) || ! hash_equals($expectedHash, $actualHash)) {
            fail('Transferred table count/content verification failed: '.$name);
        }
        $receipt[$name] = ['count' => count($actual), 'sha256' => $actualHash];
    }
    ksort($receipt);

    return $receipt;
}

function readOptions(): array
{
    $valued = ['project', 'package', 'database', 'report'];
    $flags = ['migrate', 'force', 'install-media', 'verify-only', 'package-only'];
    $arguments = array_slice($_SERVER['argv'] ?? [], 1);
    $options = [];
    for ($index = 0; $index < count($arguments); $index++) {
        if (! str_starts_with($arguments[$index], '--')) {
            fail('Unexpected positional CLI argument.');
        }
        $parts = explode('=', substr($arguments[$index], 2), 2);
        $name = $parts[0];
        if (array_key_exists($name, $options)) {
            fail('Duplicate CLI option.');
        }
        if (in_array($name, $valued, true)) {
            $value = $parts[1] ?? ($arguments[++$index] ?? '');
            if ($value === '' || str_starts_with($value, '--')) {
                fail('Required CLI option value is missing.');
            }
            $options[$name] = $value;
        } elseif (in_array($name, $flags, true) && count($parts) === 1) {
            $options[$name] = true;
        } else {
            fail('Unknown CLI option.');
        }
    }

    return $options;
}

function main(): array
{
    $options = readOptions();
    if (empty($options['package'])) {
        fail('Usage: import.php --project=PROJECT --package=CONTENT --database=NEW_DB --migrate --force --install-media [--report=PATH]; or --verify-only; or --package-only.');
    }
    if (isset($options['report']) && (file_exists($options['report']) || is_link($options['report']) || ! is_writable(dirname($options['report'])))) {
        fail('Report exists or its parent directory is not writable; choose a new private report path.');
    }
    [$package, $manifest, $data] = readPackage($options['package']);
    $base = ['package_sha256' => $manifest['dataset_sha256'], 'files' => count($manifest['files']), 'table_counts' => array_map(fn ($table) => count($table['rows']), $data['tables'])];
    if (isset($options['package-only'])) {
        return ['mode' => 'package-only', 'verified' => true] + $base;
    }
    $project = realpath($options['project'] ?? '');
    $database = $options['database'] ?? '';
    $verify = isset($options['verify-only']);
    if (! $project || ! is_file($project.'/vendor/autoload.php') || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $database)) {
        fail('Project or explicit PostgreSQL database name is invalid.');
    }
    if (! $verify && ! isset($options['force'])) {
        fail('Writing requires --force and a separate empty database.');
    }
    if ($verify && (isset($options['migrate']) || isset($options['install-media']))) {
        fail('--verify-only does not permit migrations or file installation.');
    }
    chdir($project);
    require $project.'/vendor/autoload.php';
    Dotenv\Dotenv::createImmutable($project)->safeLoad();
    $originalDatabase = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: '';
    $originalUrl = $_ENV['DB_URL'] ?? getenv('DB_URL') ?: '';
    if ($originalUrl !== '') {
        $urlDatabase = trim((string) parse_url($originalUrl, PHP_URL_PATH), '/');
        if ($urlDatabase !== '') {
            $originalDatabase = rawurldecode($urlDatabase);
        }
    }
    if (! $verify && $originalDatabase === $database) {
        fail('Import target must differ from the active application database.');
    }
    // Prevent a cached configuration from reconnecting to the active database.
    // Set all overrides before Laravel is bootstrapped or makes any DB call.
    setEnvironment('APP_CONFIG_CACHE', $project.'/bootstrap/cache/.release-import-'.bin2hex(random_bytes(12)).'.php');
    setEnvironment('DB_CONNECTION', 'pgsql');
    setEnvironment('DB_DATABASE', $database);
    if ($originalUrl !== '') {
        setEnvironment('DB_URL', targetUrl($originalUrl, $database));
    }
    $application = require $project.'/bootstrap/app.php';
    $kernel = $application->make(Kernel::class);
    $kernel->bootstrap();
    $application['config']->set('database.default', 'pgsql');
    $application['config']->set('database.connections.pgsql.database', $database);
    $application['config']->set('database.connections.pgsql.url', $originalUrl !== '' ? targetUrl($originalUrl, $database) : null);
    DB::purge('pgsql');
    $connection = DB::connection('pgsql');
    if ($connection->getDriverName() !== 'pgsql' || $connection->getTablePrefix() !== ''
        || $connection->selectOne('SELECT current_database() AS name')->name !== $database) {
        fail('Connected database does not match the explicit PostgreSQL target.');
    }
    $connection->statement("SET TIME ZONE 'UTC'");
    $schema = $connection->getSchemaBuilder();
    if (! $verify) {
        foreach ($schema->getTables() as $table) {
            if (($table['schema'] ?? 'public') === 'public' && ! in_array($table['name'], RUNTIME_TABLES, true)
                && $connection->table($table['name'])->exists()) {
                fail('Target database contains application rows; refusing to overwrite them.');
            }
        }
        if (isset($options['migrate'])) {
            $status = $kernel->call('migrate', ['--database' => 'pgsql', '--force' => true]);
            if ($status !== 0) {
                fail('Target migrations failed; source/active database was not modified.');
            }
        }
    }
    $metadata = inspectSchema($schema, $connection, $data['tables']);
    $applied = $connection->table('migrations')->orderBy('migration')->pluck('migration')->all();
    $expectedMigrations = $manifest['migrations'];
    sort($expectedMigrations);
    if ($applied !== $expectedMigrations) {
        fail('Applied migration names differ from the source snapshot.');
    }
    [$order, $deferred] = insertionPlan($metadata);
    $fileReceipt = prepareFiles($project, $package, $manifest, isset($options['install-media']), $verify);
    if ($verify) {
        $tables = $connection->transaction(function () use ($connection, $data, $metadata) {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return compareData($connection, $data['tables'], $metadata);
        });
    } else {
        $tables = $connection->transaction(function () use ($connection, $data, $metadata, $order, $deferred) {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            foreach ($order as $name) {
                $columns = array_keys($metadata[$name]['columns']);
                if ($connection->table($name)->exists()) {
                    fail('Target received rows during staging; refusing to overwrite them.');
                }
                $sql = 'INSERT INTO '.identifier($name).' ('.implode(',', array_map(identifier(...), $columns)).') VALUES ('.implode(',', array_fill(0, count($columns), '?')).')';
                $statement = $connection->getPdo()->prepare($sql);
                foreach ($data['tables'][$name]['rows'] as $row) {
                    $bindings = [];
                    foreach ($columns as $column) {
                        $bindings[] = isset($deferred[$name][$column]) ? null : typeValue($row[$column], $metadata[$name]['columns'][$column], false);
                    }
                    $statement->execute($bindings);
                }
            }
            foreach ($deferred as $name => $columns) {
                $columns = array_keys($columns);
                $primary = $metadata[$name]['primary'];
                $sql = 'UPDATE '.identifier($name).' SET '.implode(',', array_map(fn ($column) => identifier($column).'=?', $columns)).' WHERE '.implode(' AND ', array_map(fn ($column) => identifier($column).'=?', $primary));
                $statement = $connection->getPdo()->prepare($sql);
                foreach ($data['tables'][$name]['rows'] as $row) {
                    $bindings = [];
                    foreach (array_merge($columns, $primary) as $column) {
                        $bindings[] = typeValue($row[$column], $metadata[$name]['columns'][$column], false);
                    }
                    $statement->execute($bindings);
                    if ($statement->rowCount() !== 1) {
                        fail('Deferred nullable link restoration failed.');
                    }
                }
            }
            $receipt = compareData($connection, $data['tables'], $metadata);
            foreach ($metadata as $name => $table) {
                foreach ($table['primary'] as $column) {
                    if (! in_array($table['columns'][$column]['type_name'], ['int2', 'int4', 'int8', 'smallint', 'integer', 'bigint'], true)) {
                        continue;
                    }
                    $sequence = $connection->selectOne('SELECT pg_get_serial_sequence(?, ?) AS sequence', [$name, $column])->sequence;
                    if ($sequence !== null) {
                        $maximum = $connection->table($name)->max($column);
                        $connection->selectOne('SELECT setval(CAST(? AS regclass), ?, CAST(? AS boolean))', [$sequence, $maximum ?? 1, $maximum === null ? 'false' : 'true']);
                    }
                }
            }

            return $receipt;
        });
    }
    $result = ['mode' => $verify ? 'verify-only' : 'import', 'verified' => true, 'database' => $database, 'dataset_sha256' => $manifest['dataset_sha256'], 'files' => $fileReceipt, 'tables' => $tables, 'nullable_links_restored' => array_map(array_keys(...), $deferred), 'content_sha256' => hash('sha256', jsonBytes($tables))];
    if (isset($options['report'])) {
        $report = $options['report'];
        if (file_exists($report) || is_link($report)) {
            fail('Report already exists; choose a new report path.');
        }
        if (file_put_contents($report, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n", LOCK_EX) === false) {
            fail('Cannot write verification report.');
        }
        chmod($report, 0600);
    }

    return $result;
}

umask(0077);
try {
    echo json_encode(main(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $exception) {
    // Do not print PDO errors or row values: packages contain credential hashes.
    fwrite(STDERR, ($exception instanceof OperatorFailure ? $exception->getMessage() : 'Operator import failed ('.get_class($exception).'); no row values were printed.')."\n");
    exit(1);
}
