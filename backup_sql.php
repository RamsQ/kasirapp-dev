<?php

use Illuminate\Support\Facades\DB;

/**
 * Backup SQL seluruh database.
 *
 * Dipakai sebelum operasi destruktif. `mysqldump` tidak tersedia di mesin ini,
 * jadi dump dibuat manual lewat PDO: CREATE TABLE per tabel, lalu INSERT
 * batched per tabel.
 *
 * Pemakaian: php backup_sql.php <path-file>
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$target = $argv[1] ?? null;
if (! $target) {
    fwrite(STDERR, "Pemakaian: php backup_sql.php <path-file>\n");
    exit(1);
}

$dir = dirname($target);
if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
    fwrite(STDERR, "Gagal membuat folder: {$dir}\n");
    exit(1);
}

$dbName = config('database.connections.mysql.database');
$handle = fopen($target, 'wb');
if (! $handle) {
    fwrite(STDERR, "Gagal membuka file: {$target}\n");
    exit(1);
}

$written = 0;
$rows = 0;

$write = function (string $sql) use ($handle, &$written): void {
    fwrite($handle, $sql."\n");
    $written++;
};

$write('-- Backup LakuPOS');
$write('-- Database: '.$dbName);
$write('-- Waktu   : '.now()->format('Y-m-d H:i:s'));
$write('-- Driver  : '.config('database.default'));
$write('');
$write('SET FOREIGN_KEY_CHECKS=0;');
$write('SET NAMES utf8mb4;');
$write('');

$tables = array_column(
    DB::select(
        'SELECT TABLE_NAME FROM information_schema.TABLES '
        .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE" ORDER BY TABLE_NAME'
    ),
    'TABLE_NAME'
);

echo "Tabel ditemukan: ".count($tables)."\n";

foreach ($tables as $table) {
    $create = DB::select('SHOW CREATE TABLE `'.$table.'`');
    $createSql = $create[0]->{'Create Table'} ?? null;

    if (! $createSql) {
        echo "  ! gagal membaca struktur {$table}\n";
        continue;
    }

    $write('-- ----------------------------------------------------------');
    $write('-- Tabel: '.$table);
    $write('-- ----------------------------------------------------------');
    $write('DROP TABLE IF EXISTS `'.$table.'`;');
    $write($createSql.';');
    $write('');

    $data = DB::table($table)->get();
    $count = $data->count();
    $rows += $count;

    if ($count > 0) {
        $columns = implode(', ', array_map(
            fn ($c) => '`'.$c->Field.'`',
            DB::select('SHOW COLUMNS FROM `'.$table.'`')
        ));

        // Batched supaya file tidak monstrous untuk tabel besar.
        foreach (array_chunk($data->all(), 200) as $chunk) {
            $tuples = [];
            foreach ($chunk as $row) {
                $values = [];
                foreach ((array) $row as $value) {
                    $values[] = $value === null
                        ? 'NULL'
                        : DB::getPdo()->quote((string) $value);
                }
                $tuples[] = '('.implode(', ', $values).')';
            }
            $write('INSERT INTO `'.$table.'` ('.$columns.') VALUES');
            $write('  '.implode(",\n  ", $tuples).';');
        }
    }

    $write('');
    echo sprintf("  %-34s %6d baris\n", $table, $count);
}

$write('SET FOREIGN_KEY_CHECKS=1;');
$write('');
$write('-- Selesai. Baris total: '.$rows);

fclose($handle);

$size = filesize($target);
echo "\nFile  : {$target}\n";
echo 'Ukuran: '.number_format($size / 1024, 1)." KB\n";
echo 'Perintah: '.$written."\n";
echo 'Baris  : '.$rows."\n";

if ($size < 1024) {
    fwrite(STDERR, "\nPERINGATAN: file backup terlalu kecil. Jangan lanjut.\n");
    exit(1);
}

echo "\nBackup tersimpan.\n";
