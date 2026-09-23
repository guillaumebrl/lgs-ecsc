<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$backupDirectory = rtrim((string) env('BACKUP_DIR', ''), DIRECTORY_SEPARATOR);
if ($backupDirectory === '') {
    fwrite(STDERR, "BACKUP_DIR doit être défini dans .env et pointer hors de public_html.\n");
    exit(1);
}

$publicRoot = realpath(dirname(__DIR__));
if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    fwrite(STDERR, "Impossible de créer le dossier de sauvegarde.\n");
    exit(1);
}
$resolvedBackupDirectory = realpath($backupDirectory);
if ($publicRoot && $resolvedBackupDirectory && str_starts_with($resolvedBackupDirectory, $publicRoot . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "BACKUP_DIR ne doit pas se trouver dans le dossier public de l’application.\n");
    exit(1);
}

$pdo = db();
$retentionDays = max(1, (int) env('BACKUP_RETENTION_DAYS', '14'));
$filename = $backupDirectory . DIRECTORY_SEPARATOR . 'lgs-ecsc-' . date('Y-m-d_H-i-s') . '.sql.gz';
$stream = gzopen($filename, 'wb9');
if ($stream === false) {
    fwrite(STDERR, "Impossible d’ouvrir le fichier de sauvegarde.\n");
    exit(1);
}

try {
    gzwrite($stream, "-- Sauvegarde lgs-ecsc du " . date(DATE_ATOM) . "\n");
    gzwrite($stream, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $tableRow) {
        $table = (string) $tableRow[0];
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $create = $pdo->query("SHOW CREATE TABLE $quotedTable")->fetch(PDO::FETCH_NUM);
        gzwrite($stream, "DROP TABLE IF EXISTS $quotedTable;\n" . $create[1] . ";\n\n");
        $rows = $pdo->query("SELECT * FROM $quotedTable");
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $columns = implode(',', array_map(static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`', array_keys($row)));
            $values = implode(',', array_map(static fn(mixed $value): string => $value === null ? 'NULL' : $pdo->quote((string) $value), array_values($row)));
            gzwrite($stream, "INSERT INTO $quotedTable ($columns) VALUES ($values);\n");
        }
        gzwrite($stream, "\n");
    }
    gzwrite($stream, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($stream);
} catch (Throwable $exception) {
    gzclose($stream);
    @unlink($filename);
    fwrite(STDERR, "Échec de la sauvegarde : " . $exception->getMessage() . "\n");
    exit(1);
}

$expiry = time() - ($retentionDays * 86400);
foreach (glob($backupDirectory . DIRECTORY_SEPARATOR . 'lgs-ecsc-*.sql.gz') ?: [] as $oldBackup) {
    if (is_file($oldBackup) && filemtime($oldBackup) < $expiry) unlink($oldBackup);
}

echo "Sauvegarde créée : $filename\n";
