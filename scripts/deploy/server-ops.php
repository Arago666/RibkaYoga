<?php

// Operator-only helpers. No command prints a database password or an application key.
declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

umask(0077);
try {
    $operation = $argv[1] ?? '';
    $project = realpath($argv[2] ?? '') ?: throw new RibkaOperatorFailure('Project directory is missing.');
    $backup = realpath($argv[3] ?? '') ?: throw new RibkaOperatorFailure('Private backup directory is missing.');
    $newDatabase = $argv[4] ?? '';
    if (! preg_match('/^[a-z][a-z0-9_]{1,62}$/D', $newDatabase)) {
        throw new RibkaOperatorFailure('Invalid new database name.');
    }
    if ($operation === 'restore-env') {
        $context = json_decode(file_get_contents($backup.'/server-context.json'), true, 512, JSON_THROW_ON_ERROR);
        replaceEnvironment($project.'/.env', file_get_contents($backup.'/environment.env'), $context['environment']);
        echo "Original environment restored.\n";
        exit(0);
    }
    require $project.'/vendor/autoload.php';
    $app = require $project.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (config('database.default') !== 'pgsql') {
        throw new RibkaOperatorFailure('This deployment requires the existing PostgreSQL installation.');
    }
    $cfg = config('database.connections.pgsql');
    if (! empty($cfg['url'])) {
        throw new RibkaOperatorFailure('DB_URL is configured. Prepare its database switch separately before deployment.');
    }
    if (! in_array($cfg['host'], ['127.0.0.1', 'localhost', '::1'], true) && ! str_starts_with($cfg['host'], '/')) {
        throw new RibkaOperatorFailure('PostgreSQL is remote; the local postgres creation procedure does not apply.');
    }
    $environment = $project.'/.env';
    if (! is_file($environment) || is_link($environment)) {
        throw new RibkaOperatorFailure('Expected a regular .env file.');
    }
    if ($newDatabase === $cfg['database']) {
        throw new RibkaOperatorFailure('New and active databases must be different.');
    }
    $port = (string) ($cfg['port'] ?? 5432);
    $localSocket = str_starts_with($cfg['host'], '/') ? $cfg['host'] : '/var/run/postgresql';
    if (! ctype_digit($port)) {
        throw new RibkaOperatorFailure('Invalid PostgreSQL port.');
    }
    if ($operation === 'preflight') {
        $plainEnvironment = Dotenv\Dotenv::parse(file_get_contents($environment));
        if (($plainEnvironment['DB_CONNECTION'] ?? null) !== 'pgsql' || ($plainEnvironment['DB_DATABASE'] ?? null) !== $cfg['database'] || ! empty($plainEnvironment['DB_URL'])) {
            throw new RibkaOperatorFailure('The cached connection and .env differ, or DB_URL is configured. Reconcile them before deployment.');
        }
        foreach (['imagecreatetruecolor', 'imagettftext', 'imagettfbbox'] as $function) {
            if (! function_exists($function)) {
                throw new RibkaOperatorFailure('PHP GD with FreeType is required: '.$function);
            }
        }
        if (! is_file($project.'/vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf')) {
            throw new RibkaOperatorFailure('Dompdf DejaVuSans font is missing.');
        }
        $connection = DB::connection('pgsql');
        $actual = $connection->selectOne('select current_database() as name, oid from pg_database where datname = current_database()');
        if ($actual->name !== $cfg['database']) {
            throw new RibkaOperatorFailure('Configured and connected PostgreSQL databases differ.');
        }
        // Check that the OS postgres account addresses the same local cluster.
        $clusterOid = trim(runCommand(['runuser', '-u', 'postgres', '--', 'psql', '--host='.$localSocket, '--username=postgres', '--port='.$port, '--dbname=postgres', '--no-psqlrc', '--tuples-only', '--no-align', '--set=ON_ERROR_STOP=1', '--command=SELECT oid FROM pg_database WHERE datname = '.quoteLiteral($cfg['database'])], $project, $backup.'/postgres-preflight.log'));
        if ($clusterOid !== (string) $actual->oid) {
            throw new RibkaOperatorFailure('The local postgres account resolves a different cluster.');
        }
        $stat = stat($environment);
        $context = ['project' => $project, 'old_database' => $cfg['database'], 'new_database' => $newDatabase, 'database_owner' => $cfg['username'], 'database_port' => $port, 'app_url' => config('app.url'), 'environment' => ['uid' => $stat['uid'], 'gid' => $stat['gid'], 'mode' => $stat['mode'] & 0777]];
        saveJson($backup.'/server-context.json', $context);
        echo "Preflight complete: local PostgreSQL and GD FreeType available.\n";
    } elseif ($operation === 'backup') {
        if (file_exists($backup.'/database.dump') || file_exists($backup.'/environment.env')) {
            throw new RibkaOperatorFailure('Refusing to overwrite a backup.');
        }
        $env = array_merge(getenv(), ['PGPASSWORD' => (string) $cfg['password'], 'PGSSLMODE' => (string) ($cfg['sslmode'] ?? 'prefer')]);
        runCommand(['pg_dump', '--format=custom', '--no-owner', '--no-privileges', '--file='.$backup.'/database.dump', '--host='.$cfg['host'], '--port='.$port, '--username='.$cfg['username'], $cfg['database']], $project, $backup.'/database-dump.log', $env);
        if (! is_file($backup.'/database.dump') || filesize($backup.'/database.dump') === 0) {
            throw new RibkaOperatorFailure('Database backup is empty.');
        }
        if (! copy($environment, $backup.'/environment.env')) {
            throw new RibkaOperatorFailure('Cannot save the original .env.');
        }
        chmod($backup.'/environment.env', 0600);
        echo "Database and environment backup saved.\n";
    } elseif ($operation === 'create-database') {
        runCommand(['runuser', '-u', 'postgres', '--', 'createdb', '--host='.$localSocket, '--username=postgres', '--port='.$port, '--template=template0', '--encoding=UTF8', '--owner='.$cfg['username'], '--', $newDatabase], $project, $backup.'/create-database.log');
        echo 'Separate empty database created: '.$newDatabase."\n";
    } elseif ($operation === 'switch-database') {
        if (! is_file($backup.'/import-report.json')) {
            throw new RibkaOperatorFailure('Import report is missing; database switch is refused.');
        }
        $context = json_decode(file_get_contents($backup.'/server-context.json'), true, 512, JSON_THROW_ON_ERROR);
        if ($context['new_database'] !== $newDatabase || $context['old_database'] !== $cfg['database']) {
            throw new RibkaOperatorFailure('Active database no longer matches the release baseline.');
        }
        if (hash_file('sha256', $environment) !== hash_file('sha256', $backup.'/environment.env')) {
            throw new RibkaOperatorFailure('.env changed during deployment; database switch is refused.');
        }
        $text = file_get_contents($environment);
        $updated = preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE='.$newDatabase, $text, -1, $replacements);
        if ($replacements !== 1) {
            throw new RibkaOperatorFailure('Expected exactly one DB_DATABASE assignment in .env.');
        }
        replaceEnvironment($environment, $updated, $context['environment']);
        echo "Environment now points to the imported database.\n";
    } else {
        throw new RibkaOperatorFailure('Unknown operator action.');
    }
} catch (Throwable $error) {
    // Native PDO/command errors may contain credentials; private logs retain command stderr.
    fwrite(STDERR, 'Operator step failed: '.($error instanceof RibkaOperatorFailure ? $error->getMessage() : get_class($error)).PHP_EOL);
    exit(1);
}

function runCommand(array $arguments, string $directory, string $log, ?array $environment = null): string
{
    $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'a']], $pipes, $directory, $environment);
    if (! is_resource($process)) {
        throw new RibkaOperatorFailure('Cannot start operator command; see private log.');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        throw new RibkaOperatorFailure('Operator command failed; inspect '.$log);
    }

    return $output;
}

function quoteLiteral(string $value): string
{
    return "'".str_replace("'", "''", $value)."'";
}

function saveJson(string $file, array $value): void
{
    if (file_put_contents($file, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX) === false) {
        throw new RibkaOperatorFailure('Cannot save private operator metadata.');
    }
    chmod($file, 0600);
}

function replaceEnvironment(string $file, string $content, array $stat): void
{
    $temporary = tempnam(dirname($file), '.env.release-');
    if ($temporary === false) {
        throw new RibkaOperatorFailure('Cannot create environment temporary file.');
    }
    try {
        if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content) || ! chmod($temporary, (int) $stat['mode']) || ! chown($temporary, (int) $stat['uid']) || ! chgrp($temporary, (int) $stat['gid']) || ! rename($temporary, $file)) {
            throw new RibkaOperatorFailure('Cannot atomically replace .env.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

final class RibkaOperatorFailure extends RuntimeException {}
