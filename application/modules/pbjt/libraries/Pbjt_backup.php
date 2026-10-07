<?php
defined('BASEPATH') OR (defined('PBJT_CLI') OR exit('No direct script access allowed'));

/**
 * Pbjt_backup — backup & restore per-tenant (silo).
 * Tiap tenant = satu DB, sehingga bisa dibackup/restore terpisah tanpa
 * mengganggu tenant lain. Dump berbasis PHP/mysqli (portabel, tanpa mysqldump).
 */
class Pbjt_backup
{
    /** @var mysqli koneksi level server. */
    private $db;
    private $control = 'pbjt_control';
    private $batch = 200; // baris per INSERT

    public function __construct($db) { $this->db = $db; }

    /** [ wp_id => db_name ] tenant aktif dari registry. */
    public function tenants()
    {
        $out = [];
        $r = $this->db->query("SELECT wp_id, db_name FROM `{$this->control}`.tenants WHERE status=1 ORDER BY wp_id");
        while ($x = $r->fetch_assoc()) $out[$x['wp_id']] = $x['db_name'];
        return $out;
    }

    private function dbExists($db)
    {
        $q = $this->db->query("SELECT 1 FROM information_schema.schemata WHERE schema_name='" . $this->db->real_escape_string($db) . "'");
        return $q && $q->num_rows;
    }

    /** Dump satu DB ke file .sql. Mengembalikan [path, bytes, tables, rows]. */
    public function dump($dbName, $outDir)
    {
        if (!$this->dbExists($dbName)) throw new \Exception("DB `$dbName` tidak ada");
        if (!is_dir($outDir) && !@mkdir($outDir, 0775, true)) throw new \Exception("Gagal membuat folder $outDir");

        $file = rtrim($outDir, '/') . '/' . $dbName . '_' . date('Ymd_His') . '.sql';
        $fh = fopen($file, 'w');
        if (!$fh) throw new \Exception("Gagal menulis $file");

        fwrite($fh, "-- Backup silo tenant: $dbName\n-- " . date('c') . "\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $this->db->select_db($dbName);
        $tables = [];
        $r = $this->db->query("SHOW TABLES");
        while ($x = $r->fetch_row()) $tables[] = $x[0];

        $totalRows = 0;
        foreach ($tables as $t) {
            $create = $this->db->query("SHOW CREATE TABLE `$t`")->fetch_row()[1];
            fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n$create;\n");

            $res = $this->db->query("SELECT * FROM `$t`", MYSQLI_USE_RESULT);
            if ($res) {
                $nfields = $res->field_count;
                $buf = []; $n = 0;
                while ($row = $res->fetch_row()) {
                    $vals = [];
                    for ($i = 0; $i < $nfields; $i++) {
                        $v = $row[$i];
                        $vals[] = ($v === null) ? 'NULL' : "'" . $this->db->real_escape_string($v) . "'";
                    }
                    $buf[] = '(' . implode(',', $vals) . ')';
                    $n++; $totalRows++;
                    if (count($buf) >= $this->batch) {
                        fwrite($fh, "INSERT INTO `$t` VALUES\n" . implode(",\n", $buf) . ";\n");
                        $buf = [];
                    }
                }
                if ($buf) fwrite($fh, "INSERT INTO `$t` VALUES\n" . implode(",\n", $buf) . ";\n");
                $res->free();
            }
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        return ['path' => $file, 'bytes' => filesize($file), 'tables' => count($tables), 'rows' => $totalRows];
    }

    /** Restore satu DB dari file .sql (DB dibuat bila belum ada). */
    public function restore($dbName, $file)
    {
        if (!is_file($file)) throw new \Exception("File $file tidak ada");
        $sql = file_get_contents($file);

        // Kompatibilitas antar-versi: collation uca1400 (MariaDB >= 10.10) tidak dikenal
        // server lama (mis. MariaDB 10.6 di Ubuntu 22.04) -> pakai padanan *_unicode_ci.
        if (!$this->supportsCollation('utf8mb4_uca1400_ai_ci')) {
            $sql = preg_replace('/\b(utf8mb[34])_uca1400_ai_ci\b/', '$1_unicode_ci', $sql);
        }
        $expectedTables = preg_match_all('/^CREATE TABLE /m', $sql);

        if (!$this->db->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
            throw new \Exception("Gagal membuat DB `$dbName`: " . $this->db->error);
        }
        if (!$this->db->select_db($dbName)) {
            throw new \Exception("Gagal memilih DB `$dbName`: " . $this->db->error);
        }
        // Jalankan semua pernyataan; error di pernyataan mana pun WAJIB menggagalkan restore
        // (next_result() mengembalikan false saat pernyataan berikutnya error).
        if (!$this->db->multi_query($sql)) {
            throw new \Exception("Restore `$dbName` gagal pada pernyataan #1: " . $this->db->error);
        }
        $n = 1;
        while (true) {
            if ($res = $this->db->store_result()) $res->free();
            if (!$this->db->more_results()) break;
            $n++;
            if (!$this->db->next_result()) {
                throw new \Exception("Restore `$dbName` gagal pada pernyataan #$n: " . $this->db->error);
            }
        }

        // Verifikasi: jumlah tabel hasil restore minimal sama dengan jumlah tabel di dump.
        $got = (int) $this->db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='"
            . $this->db->real_escape_string($dbName) . "'")->fetch_row()[0];
        if ($got < $expectedTables) {
            throw new \Exception("Restore `$dbName` tidak lengkap: $got dari $expectedTables tabel.");
        }
        return $got;
    }

    private function supportsCollation($name)
    {
        $r = $this->db->query("SHOW COLLATION LIKE '" . $this->db->real_escape_string($name) . "'");
        return $r && $r->num_rows > 0;
    }
}
