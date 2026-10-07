<?php
/**
 * SNAP BI — penyimpanan di DB kontrol (pbjt_control).
 *
 * Registry VA ditaruh di DB kontrol, BUKAN di DB tenant, karena callback bank
 * masuk ke satu URL tanpa konteks tenant. Tiap baris mencatat purpose (pbjt|booking)
 * dan tenant_db asal sehingga callback bisa diteruskan ke tempat yang benar.
 */
class Snap_store
{
    /** @var mysqli */
    public $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->db->set_charset('utf8mb4');
    }

    /** Koneksi ke DB kontrol memakai kredensial terpusat pbjt_db.php. */
    public static function connect($dbName = null)
    {
        $c = require dirname(__DIR__, 3) . '/config/pbjt_db.php';
        $db = @new mysqli($c['host'], $c['user'], $c['pass'], $dbName ?: $c['control_db']);
        if ($db->connect_errno) {
            throw new RuntimeException('Koneksi DB SNAP gagal: ' . $db->connect_error);
        }
        return new self($db);
    }

    public function migrate()
    {
        $sql = [
            "CREATE TABLE IF NOT EXISTS snap_va (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                profile VARCHAR(32) NOT NULL,
                purpose VARCHAR(32) NOT NULL,
                tenant_db VARCHAR(64) NULL,
                ref VARCHAR(64) NOT NULL,
                partner_service_id VARCHAR(8) NOT NULL,
                customer_no VARCHAR(20) NOT NULL,
                va_no VARCHAR(28) NOT NULL,
                va_name VARCHAR(255) NOT NULL,
                trx_id VARCHAR(64) NOT NULL,
                amount DECIMAL(16,2) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'IDR',
                status ENUM('draft','pending','paid','expired','deleted','failed') NOT NULL DEFAULT 'draft',
                expired_at DATETIME NULL,
                paid_at DATETIME NULL,
                paid_amount DECIMAL(16,2) NULL,
                payment_request_id VARCHAR(128) NULL,
                dispatched_at DATETIME NULL,
                dispatch_error VARCHAR(255) NULL,
                bank_response TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_trx (trx_id),
                KEY ix_va (customer_no, partner_service_id),
                KEY ix_ref (purpose, ref)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // Token B2B yang KITA terbitkan untuk bank (inbound). Disimpan hash-nya saja.
            "CREATE TABLE IF NOT EXISTS snap_token_in (
                token_hash CHAR(64) PRIMARY KEY,
                profile VARCHAR(32) NOT NULL,
                expires_at DATETIME NOT NULL
            ) ENGINE=InnoDB",
            // Token B2B yang kita TERIMA dari bank (outbound), di-cache per profil.
            "CREATE TABLE IF NOT EXISTS snap_token_out (
                profile VARCHAR(32) PRIMARY KEY,
                token TEXT NOT NULL,
                expires_at DATETIME NOT NULL
            ) ENGINE=InnoDB",
            // X-EXTERNAL-ID inbound yang sudah dipakai (unik per hari per profil).
            "CREATE TABLE IF NOT EXISTS snap_seen (
                profile VARCHAR(32) NOT NULL,
                external_id VARCHAR(36) NOT NULL,
                seen_date DATE NOT NULL,
                PRIMARY KEY (profile, external_id, seen_date)
            ) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS snap_log (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL,
                direction ENUM('in','out') NOT NULL,
                profile VARCHAR(32) NULL,
                endpoint VARCHAR(128) NOT NULL,
                external_id VARCHAR(36) NULL,
                http_code SMALLINT NULL,
                response_code VARCHAR(7) NULL,
                request MEDIUMTEXT NULL,
                response MEDIUMTEXT NULL,
                KEY ix_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($sql as $q) {
            if (!$this->db->query($q)) {
                throw new RuntimeException('Migrasi SNAP gagal: ' . $this->db->error);
            }
        }
    }

    private function now()
    {
        return date('Y-m-d H:i:s');
    }

    private function exec($sql, $types = '', array $params = [])
    {
        $st = $this->db->prepare($sql);
        if (!$st) {
            throw new RuntimeException('SQL SNAP gagal: ' . $this->db->error);
        }
        if ($types !== '') {
            $st->bind_param($types, ...$params);
        }
        if (!$st->execute()) {
            $err = $st->error;
            $errno = $st->errno;
            $st->close();
            throw new RuntimeException('SQL SNAP gagal: ' . $err, $errno);
        }
        return $st;
    }

    private function fetchOne($sql, $types, array $params)
    {
        $st = $this->exec($sql, $types, $params);
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row ?: null;
    }

    // ---------------------------------------------------------------- VA

    /** Sisipkan VA draft; customer_no diturunkan dari id agar unik. */
    public function insertVa(array $v, $customerNoLength)
    {
        $now = $this->now();
        $tmp = 'TMP' . bin2hex(random_bytes(8));
        $st = $this->exec(
            "INSERT INTO snap_va (profile,purpose,tenant_db,ref,partner_service_id,customer_no,va_no,va_name,trx_id,amount,currency,status,expired_at,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,'draft',?,?,?)",
            'sssssssssdssss',
            [$v['profile'], $v['purpose'], $v['tenant_db'], $v['ref'], $v['partner_service_id'], $tmp, $tmp, $v['va_name'], $tmp,
             $v['amount'], $v['currency'], $v['expired_at'], $now, $now]
        );
        $id = $st->insert_id;
        $st->close();

        $customerNo = str_pad((string) $id, $customerNoLength, '0', STR_PAD_LEFT);
        $vaNo = str_pad($v['partner_service_id'], 8, ' ', STR_PAD_LEFT) . $customerNo;
        $trxId = $v['trx_prefix'] . $customerNo;
        $this->exec("UPDATE snap_va SET customer_no=?, va_no=?, trx_id=? WHERE id=?", 'sssi', [$customerNo, $vaNo, $trxId, $id])->close();
        return $this->getVa($id);
    }

    public function getVa($id)
    {
        return $this->fetchOne("SELECT * FROM snap_va WHERE id=?", 'i', [(int) $id]);
    }

    /** VA terbaru untuk nomor yang dikirim bank (partnerServiceId dibandingkan tanpa spasi). */
    public function findVa($partnerServiceId, $customerNo)
    {
        return $this->fetchOne(
            "SELECT * FROM snap_va WHERE customer_no=? AND TRIM(partner_service_id)=? ORDER BY id DESC LIMIT 1",
            'ss', [trim((string) $customerNo), trim((string) $partnerServiceId)]
        );
    }

    /** VA terbaru untuk ref dalam satu tenant (ref seperti nomor booking hanya unik per tenant). */
    public function findByRef($purpose, $ref, $tenantDb = null)
    {
        return $this->fetchOne("SELECT * FROM snap_va WHERE purpose=? AND ref=? AND tenant_db <=> ? ORDER BY id DESC LIMIT 1",
            'sss', [$purpose, (string) $ref, $tenantDb]);
    }

    public function setStatus($id, $status, $bankResponse = null)
    {
        $this->exec("UPDATE snap_va SET status=?, bank_response=COALESCE(?, bank_response), updated_at=? WHERE id=?",
            'sssi', [$status, $bankResponse, $this->now(), (int) $id])->close();
    }

    /**
     * Tandai lunas secara atomik: hanya berhasil bila status masih pending.
     * Mengembalikan true bila baris ini yang menandai lunas.
     */
    public function markPaid($id, $paidAmount, $paymentRequestId)
    {
        $st = $this->exec(
            "UPDATE snap_va SET status='paid', paid_at=?, paid_amount=?, payment_request_id=?, updated_at=? WHERE id=? AND status='pending'",
            'sdssi', [$this->now(), (float) $paidAmount, $paymentRequestId, $this->now(), (int) $id]
        );
        $ok = $st->affected_rows === 1;
        $st->close();
        return $ok;
    }

    public function markDispatched($id, $error = null)
    {
        if ($error === null) {
            $this->exec("UPDATE snap_va SET dispatched_at=?, dispatch_error=NULL WHERE id=?", 'si', [$this->now(), (int) $id])->close();
        } else {
            $this->exec("UPDATE snap_va SET dispatch_error=? WHERE id=?", 'si', [mb_substr($error, 0, 255), (int) $id])->close();
        }
    }

    /** VA lunas yang belum berhasil diteruskan ke sistem asal (untuk diulang). */
    public function undispatched()
    {
        return $this->db->query("SELECT * FROM snap_va WHERE status='paid' AND dispatched_at IS NULL ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    }

    // ------------------------------------------------------------- token

    public function issueInboundToken($profile, $ttl)
    {
        $token = bin2hex(random_bytes(32));
        $exp = date('Y-m-d H:i:s', time() + $ttl);
        $this->exec("DELETE FROM snap_token_in WHERE expires_at < ?", 's', [$this->now()])->close();
        $this->exec("INSERT INTO snap_token_in (token_hash,profile,expires_at) VALUES (?,?,?)", 'sss', [hash('sha256', $token), $profile, $exp])->close();
        return $token;
    }

    /** Profil pemilik token inbound yang masih berlaku, atau null. */
    public function inboundTokenProfile($token)
    {
        if (!is_string($token) || $token === '') return null;
        $row = $this->fetchOne("SELECT profile FROM snap_token_in WHERE token_hash=? AND expires_at >= ?", 'ss', [hash('sha256', $token), $this->now()]);
        return $row ? $row['profile'] : null;
    }

    public function getOutboundToken($profile)
    {
        $row = $this->fetchOne("SELECT token FROM snap_token_out WHERE profile=? AND expires_at > ?", 'ss', [$profile, date('Y-m-d H:i:s', time() + 30)]);
        return $row ? $row['token'] : null;
    }

    public function saveOutboundToken($profile, $token, $ttl)
    {
        $this->exec("REPLACE INTO snap_token_out (profile,token,expires_at) VALUES (?,?,?)", 'sss', [$profile, $token, date('Y-m-d H:i:s', time() + (int) $ttl)])->close();
    }

    public function clearOutboundToken($profile)
    {
        $this->exec("DELETE FROM snap_token_out WHERE profile=?", 's', [$profile])->close();
    }

    // ------------------------------------------------------- external id

    /** Catat X-EXTERNAL-ID inbound. false bila sudah dipakai hari ini (duplikat). */
    public function claimExternalId($profile, $externalId)
    {
        try {
            $this->exec("INSERT INTO snap_seen (profile,external_id,seen_date) VALUES (?,?,?)", 'sss', [$profile, $externalId, date('Y-m-d')])->close();
            return true;
        } catch (RuntimeException $e) {
            if ($e->getCode() === 1062) return false;
            throw $e;
        }
    }

    // --------------------------------------------------------------- log

    public function log($direction, $profile, $endpoint, $externalId, $httpCode, $responseCode, $request, $response)
    {
        try {
            $this->exec(
                "INSERT INTO snap_log (created_at,direction,profile,endpoint,external_id,http_code,response_code,request,response) VALUES (?,?,?,?,?,?,?,?,?)",
                'sssssisss',
                [$this->now(), $direction, $profile, $endpoint, $externalId, $httpCode, $responseCode, $request, $response]
            )->close();
        } catch (Throwable $e) {
            // Log tidak boleh menggagalkan transaksi.
        }
    }
}
