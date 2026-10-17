<?php
// db.php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Bump this when a deployment needs to run the schema setup again.
const DB_SCHEMA_VERSION = 3;

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    return $pdo;
}

function table_has_column(PDO $pdo, string $table, string $column): bool {
    foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll() as $c) {
        if (($c['name'] ?? '') === $column) return true;
    }
    return false;
}

function add_column_if_missing(PDO $pdo, string $t, string $c, string $def): void {
    if (!table_has_column($pdo, $t, $c)) {
        $pdo->exec("ALTER TABLE {$t} ADD COLUMN {$c} {$def}");
    }
}

function migrate_salvage_recommendations(PDO $pdo): void {
    if (!table_has_column($pdo, 'salvage_recommendations', 'suggested_qty')) return;
    $cols = $pdo->query("PRAGMA table_info(salvage_recommendations)")->fetchAll();
    $needsRebuild = false;
    foreach ($cols as $c) {
        $name    = $c['name'] ?? '';
        $notnull = (int)($c['notnull'] ?? 0);
        $dflt    = $c['dflt_value'] ?? null;
        if (in_array($name, ['suggested_qty', 'match_score'], true)) {
            if ($notnull === 1 && $dflt === null) { $needsRebuild = true; break; }
        }
    }
    if (!$needsRebuild) return;

    $pdo->exec("BEGIN TRANSACTION");
    $pdo->exec("
        CREATE TABLE salvage_recommendations_new (
            rec_id             INTEGER PRIMARY KEY AUTOINCREMENT,
            stock_id           INTEGER,
            bom_id             INTEGER,
            matched_stock_id   INTEGER,
            suggested_qty      INTEGER NOT NULL DEFAULT 1,
            match_score        REAL    NOT NULL DEFAULT 0,
            material_code      TEXT,
            scanned_length_mm  REAL,
            target_length_mm   REAL,
            decision_status    TEXT DEFAULT 'PENDING',
            planner_id         TEXT,
            decision_timestamp TEXT,
            override_reason    TEXT,
            raw_scanned        TEXT
        )
    ");
    $pdo->exec("
        INSERT INTO salvage_recommendations_new
          (rec_id, stock_id, bom_id, matched_stock_id,
           suggested_qty, match_score, material_code,
           scanned_length_mm, target_length_mm,
           decision_status, planner_id, decision_timestamp,
           override_reason, raw_scanned)
        SELECT rec_id, stock_id, bom_id,
           " . (table_has_column($pdo, 'salvage_recommendations', 'matched_stock_id') ? 'matched_stock_id' : 'NULL') . ",
           COALESCE(suggested_qty, 1),
           COALESCE(match_score, 0),
           " . (table_has_column($pdo, 'salvage_recommendations', 'material_code') ? 'material_code' : 'NULL') . ",
           " . (table_has_column($pdo, 'salvage_recommendations', 'scanned_length_mm') ? 'scanned_length_mm' : 'NULL') . ",
           " . (table_has_column($pdo, 'salvage_recommendations', 'target_length_mm') ? 'target_length_mm' : 'NULL') . ",
           decision_status, planner_id, decision_timestamp,
           override_reason,
           " . (table_has_column($pdo, 'salvage_recommendations', 'raw_scanned') ? 'raw_scanned' : 'NULL') . "
        FROM salvage_recommendations
    ");
    $pdo->exec("DROP TABLE salvage_recommendations");
    $pdo->exec("ALTER TABLE salvage_recommendations_new RENAME TO salvage_recommendations");
    $pdo->exec("COMMIT");
}

function init_db(): void {
    $pdo = db();
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version >= DB_SCHEMA_VERSION) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cutting_master_stock (
            stock_id       INTEGER PRIMARY KEY AUTOINCREMENT,
            unique_key     TEXT UNIQUE NOT NULL,
            raw_qr_string  TEXT,
            lot_no         TEXT NOT NULL,
            seq_no         TEXT NOT NULL,
            part_no        TEXT NOT NULL,
            model_code     TEXT,
            circuit_number TEXT NOT NULL,
            wire_kind      TEXT,
            material_code  TEXT,
            size           TEXT,
            color          TEXT,
            length_mm      REAL,
            machine_number TEXT,
            status         TEXT DEFAULT 'AVAILABLE',
            qty_available  INTEGER DEFAULT 1,
            updated_at     TEXT
        )");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS active_bom_requirements (
            bom_id             INTEGER PRIMARY KEY AUTOINCREMENT,
            model_code         TEXT NOT NULL,
            part_number        TEXT NOT NULL,
            wire_gauge         TEXT,
            color              TEXT,
            material_code      TEXT,
            required_length_mm REAL NOT NULL,
            active_status      INTEGER DEFAULT 1
        )");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS salvage_recommendations (
            rec_id             INTEGER PRIMARY KEY AUTOINCREMENT,
            stock_id           INTEGER,
            bom_id             INTEGER,
            matched_stock_id   INTEGER,
            suggested_qty      INTEGER NOT NULL DEFAULT 1,
            match_score        REAL    NOT NULL DEFAULT 0,
            material_code      TEXT,
            scanned_length_mm  REAL,
            target_length_mm   REAL,
            decision_status    TEXT DEFAULT 'PENDING',
            planner_id         TEXT,
            decision_timestamp TEXT,
            override_reason    TEXT,
            raw_scanned        TEXT
        )");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scan_audit_logs (
            scan_id           INTEGER PRIMARY KEY AUTOINCREMENT,
            raw_scanned       TEXT NOT NULL,
            unique_key        TEXT,
            lot_no            TEXT NOT NULL,
            seq_no            TEXT NOT NULL,
            stock_id          INTEGER,
            matched_stock_id  INTEGER,
            scanned_by        TEXT,
            action_taken      TEXT,
            scan_timestamp    TEXT DEFAULT CURRENT_TIMESTAMP
        )");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wire_conversions (
            conversion_id          INTEGER PRIMARY KEY AUTOINCREMENT,
            scanned_stock_id       INTEGER NOT NULL,
            target_stock_id        INTEGER NOT NULL,
            material_code          TEXT,
            scanned_lot_no         TEXT,
            target_part_no         TEXT,
            target_circuit         TEXT,
            scanned_length_mm      REAL,
            target_length_mm       REAL,
            converted_by           TEXT,
            converted_at           TEXT DEFAULT CURRENT_TIMESTAMP,
            new_lot_no             TEXT,
            lot_recorded_by        TEXT,
            lot_recorded_at        TEXT,
            status                 TEXT DEFAULT 'PENDING_LOT',
            surplus_reuse_stock_id INTEGER,
            surplus_reuse_part_no  TEXT,
            surplus_reuse_circuit  TEXT,
            surplus_reuse_length_mm REAL,
            scrap_length_mm        REAL
        )");

    add_column_if_missing($pdo, 'cutting_master_stock', 'model_code', 'TEXT');
    add_column_if_missing($pdo, 'active_bom_requirements', 'wire_kind', 'TEXT');

    add_column_if_missing($pdo, 'salvage_recommendations', 'matched_stock_id',  'INTEGER');
    add_column_if_missing($pdo, 'salvage_recommendations', 'material_code',     'TEXT');
    add_column_if_missing($pdo, 'salvage_recommendations', 'scanned_length_mm', 'REAL');
    add_column_if_missing($pdo, 'salvage_recommendations', 'target_length_mm',  'REAL');
    add_column_if_missing($pdo, 'salvage_recommendations', 'raw_scanned',       'TEXT');

    add_column_if_missing($pdo, 'scan_audit_logs', 'matched_stock_id', 'INTEGER');

    add_column_if_missing($pdo, 'wire_conversions', 'surplus_reuse_stock_id',  'INTEGER');
    add_column_if_missing($pdo, 'wire_conversions', 'surplus_reuse_part_no',   'TEXT');
    add_column_if_missing($pdo, 'wire_conversions', 'surplus_reuse_circuit',   'TEXT');
    add_column_if_missing($pdo, 'wire_conversions', 'surplus_reuse_length_mm', 'REAL');
    add_column_if_missing($pdo, 'wire_conversions', 'scrap_length_mm',         'REAL');

    migrate_salvage_recommendations($pdo);

    // All application writes normalize this field. Normalize legacy rows once
    // so indexed equality lookups remain correct as well.
    $pdo->exec("UPDATE cutting_master_stock
                SET material_code = UPPER(TRIM(material_code))
                WHERE material_code <> UPPER(TRIM(material_code))");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_lot_seq   ON cutting_master_stock(lot_no, seq_no)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_mc        ON cutting_master_stock(material_code)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_mc_len    ON cutting_master_stock(material_code, length_mm DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stock_status    ON cutting_master_stock(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bom_active_len  ON active_bom_requirements(active_status, required_length_mm)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logs_timestamp  ON scan_audit_logs(scan_timestamp)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_conv_status     ON wire_conversions(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_conv_lot        ON wire_conversions(new_lot_no)");

    $pdo->exec('PRAGMA user_version = ' . DB_SCHEMA_VERSION);
}

init_db();
