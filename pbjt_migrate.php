<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // skrip CLI: tolak akses via web
$__db = require __DIR__ . '/application/config/pbjt_db.php'; // kredensial terpusat
/**
 * Migrasi & seed modul PBJT ke DALAM database aplikasi CI3 (hotelv3).
 * Tabel diberi prefix pbjt_ agar tidak bentrok dengan 116 tabel aplikasi.
 * Membuat penautan tenant: admin(id=1) = Bapenda; user WP = tenant per wp_id.
 *
 * Jalankan: php pbjt_migrate.php
 */
define('PBJT_CLI', true);
require __DIR__ . '/application/modules/pbjt/libraries/Pbjt_engine.php';

$masa = '2026-08';
$m = @new mysqli($__db['host'], $__db['user'], $__db['pass'], 'hotelv3');
if ($m->connect_errno) { fwrite(STDERR, "Koneksi gagal: " . $m->connect_error . "\n"); exit(1); }

// --- Skema (pbjt_*) ---
$ddl = "
CREATE TABLE IF NOT EXISTS pbjt_wp (
  wp_id VARCHAR(32) PRIMARY KEY, nama VARCHAR(150) NOT NULL,
  jenis_usaha ENUM('hotel','resto') NOT NULL, kecamatan VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pbjt_peta_mid (
  mid VARCHAR(40) PRIMARY KEY, wp_id VARCHAR(32) NOT NULL, device_id VARCHAR(40) NULL, KEY(wp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pbjt_omzet_harian (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, wp_id VARCHAR(32) NOT NULL, device_id VARCHAR(40) NULL,
  tanggal DATE NOT NULL, omzet_bruto BIGINT NOT NULL, pajak_terlapor BIGINT NOT NULL, KEY(wp_id,tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pbjt_settlement_harian (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, mid VARCHAR(40) NOT NULL, bank VARCHAR(30) NOT NULL,
  kanal ENUM('QRIS','EDC') NOT NULL, tanggal_transaksi DATE NOT NULL, nilai_settlement BIGINT NOT NULL,
  status ENUM('sukses','refund','void') NOT NULL DEFAULT 'sukses', KEY(mid,tanggal_transaksi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pbjt_temuan (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, masa CHAR(7) NOT NULL, wp_id VARCHAR(32) NOT NULL,
  nama VARCHAR(150) NOT NULL, jenis_usaha VARCHAR(20) NOT NULL, kecamatan VARCHAR(80) NOT NULL,
  omzet_terlapor BIGINT NOT NULL, qris_teragregasi BIGINT NOT NULL, selisih_lantai BIGINT NOT NULL,
  omzet_estimasi BIGINT NOT NULL, potensi_rp BIGINT NOT NULL, skor_risiko INT NOT NULL,
  cakupan_sinyal VARCHAR(40) NOT NULL, aturan_terpicu VARCHAR(255) NOT NULL,
  status_kasus VARCHAR(30) NOT NULL DEFAULT 'baru', dibuat_pada DATETIME NOT NULL, KEY(masa), KEY(wp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pbjt_mid_unmapped (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, masa CHAR(7) NOT NULL, mid VARCHAR(40) NOT NULL,
  bank VARCHAR(30) NOT NULL, nilai_settlement BIGINT NOT NULL, dibuat_pada DATETIME NOT NULL, KEY(masa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pbjt_tenant_user (
  user_id INT PRIMARY KEY, role ENUM('bapenda','wp') NOT NULL, wp_id VARCHAR(32) NULL, KEY(wp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pbjt_audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, waktu DATETIME NOT NULL, aktor VARCHAR(80) NOT NULL,
  aksi VARCHAR(120) NOT NULL, detail VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if (!$m->multi_query($ddl)) { fwrite(STDERR, "DDL gagal: " . $m->error . "\n"); exit(1); }
do { if ($r = $m->store_result()) $r->free(); } while ($m->more_results() && $m->next_result());

// Reset isi (idempoten) — hanya tabel pbjt.
foreach (['pbjt_omzet_harian','pbjt_settlement_harian','pbjt_peta_mid','pbjt_wp','pbjt_temuan','pbjt_mid_unmapped','pbjt_tenant_user'] as $t) {
    $m->query("TRUNCATE TABLE $t");
}

// --- Master WP + peta ---
$wps = [
    ['WP001','Hotel Melati Indah','hotel','Coblong'],
    ['WP002','Hotel Serena','hotel','Lengkong'],
    ['WP003','Guest House Kenanga','hotel','Coblong'],
    ['WP004','Resto Sunda Asli','resto','Cidadap'],
    ['WP005','Cafe Kopi Senja','resto','Coblong'],
    ['WP006','Warung Steak Jaya','resto','Lengkong'],
];
$st = $m->prepare("INSERT INTO pbjt_wp (wp_id,nama,jenis_usaha,kecamatan) VALUES (?,?,?,?)");
foreach ($wps as $w) { $st->bind_param('ssss',$w[0],$w[1],$w[2],$w[3]); $st->execute(); } $st->close();

$peta = [['HTL001A','WP001','DEV001'],['HTL002A','WP002','DEV002'],['GH003A','WP003','DEV003A'],
         ['GH003B','WP003','DEV003B'],['RST004A','WP004','DEV004'],['RST005A','WP005','DEV005'],['RST006A','WP006','DEV006']];
$st = $m->prepare("INSERT INTO pbjt_peta_mid (mid,wp_id,device_id) VALUES (?,?,?)");
foreach ($peta as $p) { $st->bind_param('sss',$p[0],$p[1],$p[2]); $st->execute(); } $st->close();

// --- Data harian ---
$first = $masa . '-01'; $days = (int) date('t', strtotime($first));
$tb_target = ['WP001'=>500000000,'WP002'=>200000000,'WP003'=>80000000,'WP004'=>150000000,'WP005'=>40000000,'WP006'=>30000000];
$qris_target = [
    'HTL001A'=>[300000000,'Bank A','QRIS'],'HTL002A'=>[265000000,'Bank A','QRIS'],
    'GH003A'=>[50000000,'Bank B','QRIS'],'GH003B'=>[45000000,'Bank B','QRIS'],
    'RST004A'=>[90000000,'Bank A','QRIS'],'RST005A'=>[55000000,'Bank C','QRIS'],
    'RST006A'=>[15000000,'Bank B','EDC'],'MID-XX-99'=>[70000000,'Bank C','QRIS'],
];
function spread($total,$days){ $per=intdiv($total,$days); $o=array_fill(1,$days,$per); $o[$days]+=$total-$per*$days; return $o; }

$st = $m->prepare("INSERT INTO pbjt_omzet_harian (wp_id,device_id,tanggal,omzet_bruto,pajak_terlapor) VALUES (?,?,?,?,?)");
foreach ($tb_target as $wp=>$total) {
    $dev='DEV'.substr($wp,2); $daily=spread($total,$days);
    for ($d=1;$d<=$days;$d++){ $tgl=sprintf('%s-%02d',$masa,$d); $om=$daily[$d]; $pj=(int)round($om*0.10);
        $st->bind_param('sssii',$wp,$dev,$tgl,$om,$pj); $st->execute(); }
} $st->close();

$st = $m->prepare("INSERT INTO pbjt_settlement_harian (mid,bank,kanal,tanggal_transaksi,nilai_settlement,status) VALUES (?,?,?,?,?,?)");
foreach ($qris_target as $mid=>$info) {
    list($total,$bank,$kanal)=$info; $daily=spread($total,$days);
    for ($d=1;$d<=$days;$d++){ $tgl=sprintf('%s-%02d',$masa,$d); $nl=$daily[$d]; $stt='sukses';
        $st->bind_param('ssssis',$mid,$bank,$kanal,$tgl,$nl,$stt); $st->execute(); }
    if ($mid==='HTL002A'){ $tgl=sprintf('%s-15',$masa); $nl=-5000000; $stt='refund';
        $st->bind_param('ssssis',$mid,$bank,$kanal,$tgl,$nl,$stt); $st->execute(); }
} $st->close();

// --- Tenant users ---
// Bapenda = admin aplikasi (id=1).
$m->query("INSERT INTO pbjt_tenant_user (user_id,role,wp_id) VALUES (1,'bapenda',NULL)");

// Buat user WP demo di tabel aplikasi (is_admin=0) lalu tautkan sebagai tenant.
$demoWp = ['WP001'=>'wp001@pbjt.local','WP002'=>'wp002@pbjt.local'];
$pass = md5('123456');
foreach ($demoWp as $wp=>$email) {
    $r = $m->query("SELECT id FROM user WHERE email='".$m->real_escape_string($email)."' LIMIT 1");
    if ($r && $r->num_rows) { $uid = (int) $r->fetch_row()[0]; }
    else {
        $fn = 'WP '.$wp;
        // usertype=1 wajib: Auth_model::checkUser mensyaratkannya agar bisa login. is_admin=0 = WP (bukan Bapenda).
        $ins = $m->prepare("INSERT INTO user (firstname,email,password,status,usertype,is_admin) VALUES (?,?,?,1,1,0)");
        $ins->bind_param('sss',$fn,$email,$pass); $ins->execute(); $uid = $ins->insert_id; $ins->close();
    }
    $ins = $m->prepare("INSERT INTO pbjt_tenant_user (user_id,role,wp_id) VALUES (?,'wp',?)
                        ON DUPLICATE KEY UPDATE role='wp', wp_id=VALUES(wp_id)");
    $ins->bind_param('is',$uid,$wp); $ins->execute(); $ins->close();
    echo "  Tenant WP: user#$uid ($email) -> $wp\n";
}

// --- Rekonsiliasi ---
$engine = new Pbjt_engine($m, ['porsi_nontunai_default'=>0.60,'tarif_pbjt'=>0.10,'ambang_skor'=>50]);
$dibuat = $engine->reconcile($masa);

echo "Migrasi PBJT selesai (DB hotelv3, masa $masa)\n";
echo "  WP: 6 | Peta MID: 7 | Temuan dibuat: $dibuat\n";
$m->close();
