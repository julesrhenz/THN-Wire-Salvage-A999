<?php
// actions.php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (!is_logged_in()) { http_response_code(403); exit('Not authenticated.'); }

$action  = $_POST['action'] ?? '';
$pdo     = db();
$planner = $_SESSION['user'] ?? 'PLANNER_USER';

$adminOnly = [
    'clear_data', 'add_reference', 'upload_files',
    'save_high_runner', 'remove_high_runner',
];
if (in_array($action, $adminOnly, true) && !is_admin()) {
    $_SESSION['flash'] = ['error', 'Admin access required.'];
    header('Location: index.php?tab=scan');
    exit;
}

function make_reference_key(string $part, string $circuit, string $lot): string {
    $p = trim($part); $c = trim($circuit); $l = trim($lot);
    if ($p !== '' && $c !== '' && $l !== '') return "{$p}_{$c}_{$l}";
    if ($p !== '' && $c !== '')              return "{$p}_{$c}";
    return 'ROW_MANUAL_' . substr(md5($p . $c . $l . microtime(true)), 0, 8);
}

/* ---------- MARK USED (Convert here) ---------- */
if ($action === 'mark_used') {
    $scannedId             = (int)($_POST['scanned_id'] ?? 0);
    $targetId              = (int)($_POST['target_id']  ?? 0);
    $requestedSurplusId    = (int)($_POST['surplus_target_id'] ?? 0);
    $rawScan               = (string)($_POST['raw_scanned'] ?? '');

    try {
        $pdo->beginTransaction();

        $s1 = $pdo->prepare("SELECT * FROM cutting_master_stock WHERE stock_id = ?");
        $s1->execute([$scannedId]);
        $scanned = $s1->fetch();

        $s2 = $pdo->prepare("SELECT * FROM cutting_master_stock WHERE stock_id = ?");
        $s2->execute([$targetId]);
        $target = $s2->fetch();

        if (!$scanned || !$target) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['error', 'Scanned wire or target row not found.'];
            header('Location: index.php?tab=scan');
            exit;
        }

        $scannedLen  = (float)$scanned['length_mm'];
        $targetLen   = (float)$target['length_mm'];
        $surplus     = max(0.0, $scannedLen - $targetLen);

        $surplusReuses = find_surplus_reuses(
            $scanned['material_code'],
            $surplus,
            [$scannedId, $targetId],
            3
        );
        $bestReuse = $surplusReuses[0] ?? null;

        // A surplus-option card can explicitly choose the second cut. Always
        // validate it again server-side; never trust a posted reference ID.
        if ($requestedSurplusId > 0) {
            $s3 = $pdo->prepare("SELECT * FROM cutting_master_stock WHERE stock_id = ?");
            $s3->execute([$requestedSurplusId]);
            $requestedReuse = $s3->fetch();
            $sameMaterial = $requestedReuse
                && normalize_mc($requestedReuse['material_code'] ?? '')
                    === normalize_mc($scanned['material_code'] ?? '');
            $validLength = $requestedReuse
                && (float)$requestedReuse['length_mm'] > 0
                && (float)$requestedReuse['length_mm'] <= $surplus;
            $available = $requestedReuse
                && strtoupper(trim((string)($requestedReuse['status'] ?? ''))) !== 'CONVERTED';

            if (!$requestedReuse || $requestedSurplusId === $scannedId
                || $requestedSurplusId === $targetId || !$sameMaterial
                || !$validLength || !$available) {
                throw new RuntimeException('The selected surplus conversion is no longer available.');
            }
            $bestReuse = $requestedReuse;
        }

        $surplusReuseId      = $bestReuse ? (int)$bestReuse['stock_id'] : null;
        $surplusReusePart    = $bestReuse ? (string)$bestReuse['part_no'] : null;
        $surplusReuseCircuit = $bestReuse ? (string)$bestReuse['circuit_number'] : null;
        $surplusReuseLen     = $bestReuse ? (float)$bestReuse['length_mm'] : 0.0;
        $scrapLen            = $surplus - $surplusReuseLen;

        $pdo->prepare("
            UPDATE cutting_master_stock
            SET status = 'CONVERTED', updated_at = datetime('now')
            WHERE stock_id = ?
        ")->execute([$scannedId]);

        $pdo->prepare("
            INSERT INTO wire_conversions
              (scanned_stock_id, target_stock_id, material_code, scanned_lot_no,
               target_part_no, target_circuit, scanned_length_mm,
               target_length_mm, converted_by, status,
               surplus_reuse_stock_id, surplus_reuse_part_no,
               surplus_reuse_circuit, surplus_reuse_length_mm,
               scrap_length_mm)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING_LOT',
                    ?, ?, ?, ?, ?)
        ")->execute([
            $scannedId, $targetId,
            $scanned['material_code'],
            $scanned['lot_no'],
            $target['part_no'],
            $target['circuit_number'],
            $scannedLen, $targetLen,
            $planner,
            $surplusReuseId,
            $surplusReusePart,
            $surplusReuseCircuit,
            $surplusReuseLen,
            $scrapLen,
        ]);

        $pdo->prepare("
            INSERT INTO salvage_recommendations
              (stock_id, matched_stock_id, material_code, scanned_length_mm,
               target_length_mm, suggested_qty, match_score,
               decision_status, planner_id, decision_timestamp, raw_scanned)
            VALUES (?, ?, ?, ?, ?, 1, 100, 'USED', ?, datetime('now'), ?)
        ")->execute([
            $scannedId, $targetId,
            $scanned['material_code'],
            $scannedLen, $targetLen,
            $planner, $rawScan,
        ]);

        $note = "CONVERTED (pending lot) → {$target['part_no']} / {$target['circuit_number']}";
        if ($bestReuse) {
            $note .= "; surplus → {$bestReuse['part_no']} / {$bestReuse['circuit_number']}"
                   . " (" . number_format($surplusReuseLen, 0) . "mm);"
                   . " scrap " . number_format($scrapLen, 0) . "mm";
        } else {
            $note .= "; surplus scrapped " . number_format($surplus, 0) . "mm";
        }

        $pdo->prepare("
            INSERT INTO scan_audit_logs
              (raw_scanned, unique_key, lot_no, seq_no, stock_id,
               matched_stock_id, scanned_by, action_taken)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $rawScan,
            $scanned['unique_key'],
            $scanned['lot_no'],
            $scanned['seq_no'],
            $scannedId,
            $targetId,
            $planner,
            $note,
        ]);

        $pdo->commit();
        $_SESSION['flash'] = ['success',
            "Wire {$scanned['lot_no']} converted to {$target['part_no']}. "
            . ($bestReuse
                ? "Surplus reused for {$bestReuse['part_no']}."
                : "Surplus scrapped.")];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash'] = ['error', 'Convert failed: ' . $e->getMessage()];
    }

    header('Location: index.php?tab=pending');
    exit;
}

/* ---------- RECORD NEW LOT NUMBER ---------- */
if ($action === 'record_lot') {
    $conversionId = (int)($_POST['conversion_id'] ?? 0);
    $newLot       = strtoupper(trim((string)($_POST['new_lot_no'] ?? '')));
    $return       = (string)($_POST['return'] ?? 'index.php?tab=pending');

    if ($conversionId <= 0) {
        $_SESSION['flash'] = ['error', 'Invalid conversion ID.'];
        header('Location: ' . $return); exit;
    }
    if ($newLot === '') {
        $_SESSION['flash'] = ['error', 'New lot number cannot be empty.'];
        header('Location: ' . $return); exit;
    }

    try {
        $pdo->beginTransaction();

        $sel = $pdo->prepare("SELECT * FROM wire_conversions WHERE conversion_id = ?");
        $sel->execute([$conversionId]);
        $conv = $sel->fetch();

        if (!$conv) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['error', 'Conversion record not found.'];
            header('Location: ' . $return); exit;
        }
        if ($conv['status'] !== 'PENDING_LOT') {
            $pdo->rollBack();
            $_SESSION['flash'] = ['warning', 'This conversion already has a lot number recorded.'];
            header('Location: ' . $return); exit;
        }

        $pdo->prepare("
            UPDATE wire_conversions
            SET new_lot_no      = ?,
                lot_recorded_by = ?,
                lot_recorded_at = datetime('now'),
                status          = 'COMPLETED'
            WHERE conversion_id = ?
        ")->execute([$newLot, $planner, $conversionId]);

        $pdo->prepare("
            INSERT INTO scan_audit_logs
              (raw_scanned, unique_key, lot_no, seq_no, stock_id,
               matched_stock_id, scanned_by, action_taken)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            'lot-entry',
            "CONV#{$conversionId}",
            $newLot,
            '',
            (int)$conv['scanned_stock_id'],
            (int)$conv['target_stock_id'],
            $planner,
            "NEW LOT → {$newLot}",
        ]);

        $pdo->commit();
        $_SESSION['flash'] = ['success',
            "New lot number '{$newLot}' recorded for conversion #{$conversionId}."];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash'] = ['error', 'Save failed: ' . $e->getMessage()];
    }

    header('Location: ' . $return);
    exit;
}

/* ---------- MANUAL: ADD REFERENCE ROW (admin only) ---------- */
if ($action === 'add_reference') {
    $f = fn($k) => trim((string)($_POST[$k] ?? ''));
    $part    = $f('part_no');
    $circuit = $f('circuit_number');
    $lot     = $f('lot_no');
    $unique  = make_reference_key($part, $circuit, $lot);

    try {
        $exists = $pdo->prepare("SELECT 1 FROM cutting_master_stock WHERE unique_key = ?");
        $exists->execute([$unique]);
        if ($exists->fetchColumn()) {
            $_SESSION['flash'] = ['warning',
                "Row already exists with key '{$unique}'. Old data kept."];
            header('Location: index.php?tab=inventory'); exit;
        }

        $pdo->prepare("
            INSERT INTO cutting_master_stock
              (unique_key, raw_qr_string, lot_no, seq_no, part_no, model_code, circuit_number,
               wire_kind, material_code, size, color, length_mm, machine_number,
               status, qty_available, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'REFERENCE', 1, datetime('now'))
        ")->execute([
            $unique,
            $f('lot_no') . ' ' . $f('seq_no'),
            $lot, $f('seq_no'), $part, $f('model_code'), $circuit,
            normalize_wk($f('wire_kind')),
            normalize_mc($f('material_code')),
            normalize_size($f('size')),
            $f('color'), (float)$f('length_mm'), $f('machine_number'),
        ]);
        $_SESSION['flash'] = ['success', "Reference row appended: {$unique}"];
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['error', 'Save failed: ' . $e->getMessage()];
    }
    header('Location: index.php?tab=inventory');
    exit;
}

/* ---------- HIGH-RUNNER REFERENCE (admin only) ---------- */
if ($action === 'save_high_runner') {
    $partNo = (string)($_POST['high_runner_part_no'] ?? '');
    $modelLabel = (string)($_POST['high_runner_model_label'] ?? '');

    try {
        $partNo = validate_high_runner_part_no($partNo);
        save_high_runner_reference($partNo, $modelLabel);
        $_SESSION['flash'] = ['success', "High-runner reference saved: {$partNo}."];
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['error', 'High-runner reference was not saved: ' . $e->getMessage()];
    }
    header('Location: index.php?tab=inventory');
    exit;
}

if ($action === 'remove_high_runner') {
    $partNo = (string)($_POST['high_runner_part_no'] ?? '');

    try {
        $partNo = validate_high_runner_part_no($partNo);
        if (remove_high_runner_reference($partNo)) {
            $_SESSION['flash'] = ['success', "High-runner reference removed: {$partNo}."];
        } else {
            $_SESSION['flash'] = ['warning', "High-runner reference not found: {$partNo}."];
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['error', 'High-runner reference was not removed: ' . $e->getMessage()];
    }
    header('Location: index.php?tab=inventory');
    exit;
}

/* ---------- CLEAR DATA (admin only) ---------- */
if ($action === 'clear_data') {
    $target  = strtolower(trim((string)($_POST['target'] ?? '')));
    $confirm = trim((string)($_POST['confirm'] ?? ''));
    $return  = (string)($_POST['return'] ?? 'index.php?tab=kpi');

    if ($confirm !== 'DELETE') {
        $_SESSION['flash'] = ['error', 'Confirmation failed. Type DELETE exactly.'];
        header('Location: ' . $return); exit;
    }

    $allowed = ['reference', 'recommendations', 'logs', 'conversions', 'all'];
    if (!in_array($target, $allowed, true)) {
        $_SESSION['flash'] = ['error', "Unknown target '{$target}'."];
        header('Location: ' . $return); exit;
    }

    try {
        $pdo->beginTransaction();
        $tables = match ($target) {
            'reference'       => ['cutting_master_stock'],
            'recommendations' => ['salvage_recommendations'],
            'logs'            => ['scan_audit_logs'],
            'conversions'     => ['wire_conversions'],
            'all'             => [
                'salvage_recommendations',
                'scan_audit_logs',
                'wire_conversions',
                'cutting_master_stock',
            ],
        };

        $counts = [];
        foreach ($tables as $t) {
            $before = (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
            $pdo->exec("DELETE FROM {$t}");
            $pdo->exec("DELETE FROM sqlite_sequence WHERE name = '{$t}'");
            $counts[$t] = $before;
        }

        if ($target !== 'logs' && $target !== 'all') {
            $pdo->prepare("
                INSERT INTO scan_audit_logs
                  (raw_scanned, lot_no, seq_no, scanned_by, action_taken)
                VALUES ('admin-clear', '-', '-', ?, ?)
            ")->execute([$planner, 'CLEARED: ' . $target]);
        }

        $pdo->commit();
        $summary = [];
        foreach ($counts as $t => $n) $summary[] = "{$t} ({$n})";
        $_SESSION['flash'] = ['warning', 'Cleared ' . implode(', ', $summary) . '.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash'] = ['error', 'Clear failed: ' . $e->getMessage()];
    }

    header('Location: ' . $return);
    exit;
}
