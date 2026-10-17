<?php
// functions.php
declare(strict_types=1);

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function normalize_mc(?string $mc): string   { return strtoupper(trim((string)$mc)); }
function normalize_wk(?string $wk): string   { return strtoupper(trim((string)$wk)); }
function normalize_size(?string $s): string  { return strtoupper(trim((string)$s)); }
function normalize_part_no(?string $partNo): string { return strtoupper(trim((string)$partNo)); }

function high_runner_file_path(): string {
    return __DIR__ . '/high_runner.txt';
}

/** @return array{0: string, 1: string}|null */
function parse_high_runner_reference_line(string $line): ?array {
    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? '';
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) return null;

    // Source rows normally use tabs (or a wide whitespace gap) between
    // the part number and human-readable model label.
    $columns = preg_split('/(?:\t+|\s{2,})/u', $line, 2);
    if (!is_array($columns) || count($columns) < 2) {
        $columns = preg_split('/\s+/', $line, 2) ?: [];
    }

    $partNo = normalize_part_no($columns[0] ?? '');
    if ($partNo === '') return null;
    return [$partNo, trim((string)($columns[1] ?? ''))];
}

/** @return array<string, string> */
function read_high_runner_catalog_from_handle($fh): array {
    $catalog = [];
    rewind($fh);
    while (($line = fgets($fh)) !== false) {
        $entry = parse_high_runner_reference_line($line);
        if ($entry === null) continue;
        [$partNo, $modelLabel] = $entry;
        $catalog[$partNo] = $modelLabel;
    }
    return $catalog;
}

/**
 * Read the user-maintained high-runner list once per request.
 * The first column is the reference part number; the rest of the line is its label.
 *
 * @return array<string, string> Normalized part number => display label.
 */
function high_runner_catalog(): array {
    static $catalog = null;
    if ($catalog !== null) return $catalog;

    $catalog = [];
    $path = high_runner_file_path();
    if (!is_readable($path)) return $catalog;

    $fh = fopen($path, 'rb');
    if ($fh === false) return $catalog;

    try {
        if (!flock($fh, LOCK_SH)) return $catalog;
        try {
            $catalog = read_high_runner_catalog_from_handle($fh);
        } finally {
            flock($fh, LOCK_UN);
        }
    } finally {
        fclose($fh);
    }

    return $catalog;
}

/**
 * Normalize a model label for comparisons while retaining the original label
 * for display in the UI.
 */
function normalize_high_runner_model_key(?string $modelLabel): string {
    $modelLabel = preg_replace('/\s+/u', ' ', trim((string)$modelLabel)) ?? '';
    return strtoupper($modelLabel);
}

/**
 * Return unique high-runner model labels, optionally limited to the supplied
 * part numbers. Supplying an empty array intentionally returns no options.
 *
 * @param list<string>|null $partNumbers
 * @return array<string, string> Normalized label => display label.
 */
function high_runner_model_options(?array $partNumbers = null): array {
    static $allOptions = null;
    if ($partNumbers === null && $allOptions !== null) return $allOptions;

    $catalog = high_runner_catalog();
    $modelLabels = [];
    if ($partNumbers === null) {
        $modelLabels = array_values($catalog);
    } else {
        foreach ($partNumbers as $partNo) {
            $partNo = normalize_part_no($partNo);
            if (isset($catalog[$partNo])) $modelLabels[] = $catalog[$partNo];
        }
    }

    $options = [];
    foreach ($modelLabels as $modelLabel) {
        $modelLabel = preg_replace('/\s+/u', ' ', trim($modelLabel)) ?? '';
        $key = normalize_high_runner_model_key($modelLabel);
        if ($key !== '' && !isset($options[$key])) $options[$key] = $modelLabel;
    }
    ksort($options, SORT_NATURAL | SORT_FLAG_CASE);
    if ($partNumbers === null) $allOptions = $options;
    return $options;
}

function validate_high_runner_part_no(?string $partNo): string {
    $partNo = normalize_part_no($partNo);
    if ($partNo === '' || preg_match('/\s/', $partNo)) {
        throw new InvalidArgumentException('Enter a valid high-runner part number.');
    }
    return $partNo;
}

function normalize_high_runner_model_label(?string $modelLabel): string {
    $modelLabel = trim((string)$modelLabel);
    if (preg_match('/[\r\n]/', $modelLabel)) {
        throw new InvalidArgumentException('The model label must be a single line.');
    }
    if ($modelLabel === '') {
        throw new InvalidArgumentException('Enter a model label for the high-runner part.');
    }
    return $modelLabel;
}

/** @return list<string> */
function read_high_runner_file_lines($fh): array {
    rewind($fh);
    $lines = [];
    while (($line = fgets($fh)) !== false) {
        $lines[] = rtrim($line, "\r\n");
    }
    return $lines;
}

/** @param list<string> $lines */
function write_high_runner_file_lines($fh, array $lines): void {
    $contents = implode(PHP_EOL, $lines);
    if ($contents !== '') $contents .= PHP_EOL;

    rewind($fh);
    if (!ftruncate($fh, 0)) {
        throw new RuntimeException('Could not update the high-runner reference file.');
    }
    $written = fwrite($fh, $contents);
    if ($written === false || $written !== strlen($contents) || !fflush($fh)) {
        throw new RuntimeException('Could not save the high-runner reference file.');
    }
}

function save_high_runner_reference(string $partNo, string $modelLabel): void {
    $partNo = validate_high_runner_part_no($partNo);
    $modelLabel = normalize_high_runner_model_label($modelLabel);
    $fh = fopen(high_runner_file_path(), 'c+');
    if ($fh === false) throw new RuntimeException('Could not open the high-runner reference file.');

    try {
        if (!flock($fh, LOCK_EX)) {
            throw new RuntimeException('Could not lock the high-runner reference file.');
        }
        try {
            $updated = false;
            $lines = [];
            foreach (read_high_runner_file_lines($fh) as $line) {
                $entry = parse_high_runner_reference_line($line);
                if ($entry !== null && $entry[0] === $partNo) {
                    if (!$updated) $lines[] = "{$partNo}\t{$modelLabel}";
                    $updated = true;
                    continue;
                }
                $lines[] = $line;
            }
            if (!$updated) $lines[] = "{$partNo}\t{$modelLabel}";
            write_high_runner_file_lines($fh, $lines);
        } finally {
            flock($fh, LOCK_UN);
        }
    } finally {
        fclose($fh);
    }
}

function remove_high_runner_reference(string $partNo): bool {
    $partNo = validate_high_runner_part_no($partNo);
    $fh = fopen(high_runner_file_path(), 'c+');
    if ($fh === false) throw new RuntimeException('Could not open the high-runner reference file.');

    try {
        if (!flock($fh, LOCK_EX)) {
            throw new RuntimeException('Could not lock the high-runner reference file.');
        }
        try {
            $removed = false;
            $lines = [];
            foreach (read_high_runner_file_lines($fh) as $line) {
                $entry = parse_high_runner_reference_line($line);
                if ($entry !== null && $entry[0] === $partNo) {
                    $removed = true;
                    continue;
                }
                $lines[] = $line;
            }
            if ($removed) write_high_runner_file_lines($fh, $lines);
            return $removed;
        } finally {
            flock($fh, LOCK_UN);
        }
    } finally {
        fclose($fh);
    }
}

function high_runner_label(?string $partNo): ?string {
    $partNo = normalize_part_no($partNo);
    if ($partNo === '') return null;
    $catalog = high_runner_catalog();
    return array_key_exists($partNo, $catalog) ? $catalog[$partNo] : null;
}

function is_high_runner_part(?string $partNo): bool {
    return high_runner_label($partNo) !== null;
}

/**
 * Prefer the model saved with a reference row. Older imports did not retain
 * that column, so use the high-runner catalog label when one is available.
 */
function reference_model_label(?string $modelCode, ?string $partNo): string {
    $modelCode = trim((string)$modelCode);
    return $modelCode !== '' ? $modelCode : (high_runner_label($partNo) ?? '');
}

/**
 * SQL filter for listed high-runner part numbers. The text-file values remain
 * bound parameters rather than being inserted into the query string.
 */
function high_runner_part_filter_sql(array &$bindings, string $prefix): ?string {
    $parts = array_keys(high_runner_catalog());
    if (empty($parts)) return null;

    $placeholders = [];
    foreach ($parts as $index => $partNo) {
        $placeholder = ':' . $prefix . $index;
        $placeholders[] = $placeholder;
        $bindings[$placeholder] = $partNo;
    }

    return 'UPPER(TRIM(part_no)) IN (' . implode(', ', $placeholders) . ')';
}

function parse_qr_string(string $scanned): array {
    $text = trim($scanned);

    $json = json_decode($text, true);
    if (is_array($json)) {
        return [
            'lot_no'         => (string)($json['lot_no']  ?? $json['lot'] ?? ''),
            'seq_no'         => (string)($json['seq_no']  ?? $json['seq'] ?? ''),
            'part_no'        => (string)($json['part_no'] ?? $json['part'] ?? ''),
            'circuit_number' => (string)($json['circuit_number'] ?? $json['circuit'] ?? ''),
            'material_code'  => (string)($json['material_code']  ?? $json['mc'] ?? ''),
            'length_mm'      => (float)($json['length_mm'] ?? $json['length'] ?? 0),
            'tokens'         => [$text],
        ];
    }

    $tokens = preg_split('/[\s,;|\/\-]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $lot = $seq = $part = $circuit = $mc = '';
    $len = 0.0;

    foreach ($tokens as $t) {
        if (!$lot && preg_match('/^[A-Z]{2,4}\d{5,8}$/i', $t)) { $lot = $t; continue; }
        if (!$seq && preg_match('/^\d{3,4}$/', $t))            { $seq = $t; continue; }
        if (!$part && preg_match('/^[A-Z0-9]+-[A-Z0-9]+-[A-Z0-9]+/i', $t)) { $part = $t; continue; }
        if (!$mc && preg_match('/^\d[A-Z0-9]{6,12}$/i', $t)
                && preg_match('/[A-Z]/i', $t) && preg_match('/\d/', $t)) { $mc = $t; continue; }
        if (!$circuit && preg_match('/^[A-Z]{1,4}\d{1,4}$/i', $t)) { $circuit = $t; continue; }
        if ($len === 0.0 && preg_match('/^(\d{3,5})\s*(mm)?$/i', $t, $m)) $len = (float)$m[1];
    }

    if (!$lot && count($tokens) > 0) $lot = $tokens[0];
    if (!$seq && count($tokens) > 1 && preg_match('/^\d+$/', $tokens[1])) $seq = $tokens[1];

    return [
        'lot_no'         => $lot,
        'seq_no'         => $seq,
        'part_no'        => $part,
        'circuit_number' => $circuit,
        'material_code'  => $mc,
        'length_mm'      => $len,
        'tokens'         => $tokens,
    ];
}

/* ═══════════════════════════════════════════════════════════════
 * REFERENCE MATCH — same material code + shorter or equal length
 * ═══════════════════════════════════════════════════════════════ */
function find_reference_matches(
    string $materialCode,
    float  $referenceLength,
    int    $excludeStockId = 0,
    int    $limit = 1000
): array {
    $mc = normalize_mc($materialCode);
    if ($mc === '' || $referenceLength <= 0) return [];

    $pdo = db();
    $baseSql = "
        SELECT stock_id, lot_no, seq_no, part_no, model_code, circuit_number,
               material_code, size, color, length_mm, wire_kind, machine_number
        FROM cutting_master_stock
        WHERE material_code = :mc
          AND length_mm <= :len
          AND length_mm > 0
    ";
    if ($excludeStockId > 0) $baseSql .= " AND stock_id <> :self";

    $bindBase = static function (PDOStatement $stmt) use ($mc, $referenceLength, $excludeStockId): void {
        $stmt->bindValue(':mc', $mc);
        $stmt->bindValue(':len', $referenceLength);
        if ($excludeStockId > 0) $stmt->bindValue(':self', $excludeStockId, PDO::PARAM_INT);
    };

    $highRows = [];
    $highBindings = [];
    $highFilter = high_runner_part_filter_sql($highBindings, 'match_hr_');
    if ($highFilter !== null) {
        $highStmt = $pdo->prepare(
            $baseSql . " AND {$highFilter} ORDER BY length_mm DESC, stock_id ASC LIMIT :lim"
        );
        $bindBase($highStmt);
        foreach ($highBindings as $placeholder => $partNo) {
            $highStmt->bindValue($placeholder, $partNo, PDO::PARAM_STR);
        }
        $highStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $highStmt->execute();
        $highRows = $highStmt->fetchAll();
    }

    // Query the ordinary length order only when fewer than the requested number
    // of high-runner rows fit. This avoids a costly CASE sort across the full
    // material bucket while keeping priority in effect before the final limit.
    $rows = $highRows;
    if (count($rows) < $limit) {
        $fallbackStmt = $pdo->prepare(
            $baseSql . " ORDER BY length_mm DESC, stock_id ASC LIMIT :lim"
        );
        $bindBase($fallbackStmt);
        $fallbackStmt->bindValue(':lim', $limit + count($highRows), PDO::PARAM_INT);
        $fallbackStmt->execute();

        $catalog = high_runner_catalog();
        foreach ($fallbackStmt->fetchAll() as $row) {
            if (isset($catalog[normalize_part_no($row['part_no'] ?? '')])) continue;
            $rows[] = $row;
            if (count($rows) === $limit) break;
        }
    }

    foreach ($rows as &$r) {
        $r['surplus_mm'] = round($referenceLength - (float)$r['length_mm'], 1);
    }
    unset($r);
    return $rows;
}

/* ═══════════════════════════════════════════════════════════════
 * SURPLUS REUSE — find parts the surplus can become
 * ═══════════════════════════════════════════════════════════════ */
function find_surplus_reuses(
    string $materialCode,
    float  $surplusLen,
    array  $excludeStockIds = [],
    int    $limit = 3
): array {
    $mc = normalize_mc($materialCode);
    if ($mc === '' || $surplusLen <= 0 || $limit <= 0) return [];

    $pdo = db();
    $baseSql = "
        SELECT stock_id, lot_no, seq_no, part_no, circuit_number,
               material_code, size, color, length_mm, status
        FROM cutting_master_stock
        WHERE material_code = :mc
          AND length_mm <= :max
          AND length_mm > 0
          AND UPPER(COALESCE(status, 'REFERENCE')) <> 'CONVERTED'
    ";
    if (!empty($excludeStockIds)) {
        $ids = array_map('intval', $excludeStockIds);
        $baseSql .= " AND stock_id NOT IN (" . implode(',', $ids) . ")";
    }

    $bindBase = static function (PDOStatement $stmt) use ($mc, $surplusLen): void {
        $stmt->bindValue(':mc', $mc);
        $stmt->bindValue(':max', $surplusLen);
    };

    $highRows = [];
    $highBindings = [];
    $highFilter = high_runner_part_filter_sql($highBindings, 'reuse_hr_');
    if ($highFilter !== null) {
        $highStmt = $pdo->prepare(
            $baseSql . " AND {$highFilter} ORDER BY length_mm DESC, part_no ASC, "
            . "circuit_number ASC, stock_id ASC LIMIT :lim"
        );
        $bindBase($highStmt);
        foreach ($highBindings as $placeholder => $partNo) {
            $highStmt->bindValue($placeholder, $partNo, PDO::PARAM_STR);
        }
        $highStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $highStmt->execute();
        $highRows = $highStmt->fetchAll();
    }

    if (count($highRows) >= $limit) return $highRows;

    $fallbackStmt = $pdo->prepare(
        $baseSql . " ORDER BY length_mm DESC, part_no ASC, circuit_number ASC, stock_id ASC LIMIT :lim"
    );
    $bindBase($fallbackStmt);
    $fallbackStmt->bindValue(':lim', $limit + count($highRows), PDO::PARAM_INT);
    $fallbackStmt->execute();

    $catalog = high_runner_catalog();
    foreach ($fallbackStmt->fetchAll() as $row) {
        if (isset($catalog[normalize_part_no($row['part_no'] ?? '')])) continue;
        $highRows[] = $row;
        if (count($highRows) === $limit) break;
    }
    return $highRows;
}

function list_material_codes(): array {
    $pdo = db();
    return $pdo->query("
        SELECT UPPER(TRIM(material_code)) AS mc,
               COUNT(*)                    AS cnt,
               MIN(length_mm)              AS min_len,
               MAX(length_mm)              AS max_len
        FROM cutting_master_stock
        WHERE material_code IS NOT NULL
          AND TRIM(material_code) <> ''
        GROUP BY UPPER(TRIM(material_code))
        ORDER BY mc ASC
    ")->fetchAll();
}

function kpi_summary(): array {
    $pdo = db();
    $total    = (int)$pdo->query("SELECT COUNT(*) FROM salvage_recommendations")->fetchColumn();
    $approved = (int)$pdo->query("SELECT COUNT(*) FROM salvage_recommendations
                                  WHERE decision_status = 'USED'")->fetchColumn();
    $rate = $total > 0 ? ($approved / $total) * 100.0 : 0.0;
    return ['total' => $total, 'approved' => $approved, 'rate' => $rate];
}

function kpi_acceptance_rate(): array {
    return kpi_summary();
}
