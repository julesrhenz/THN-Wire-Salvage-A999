<?php
// upload.php — library (defines import_files)
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

const COL_LOT       = 2;
const COL_PART      = 3;
const COL_CIRCUIT   = 6; // Legacy exports: Sub No (not Ind No)
const COL_WIRE_KIND = 10;
const COL_MATERIAL  = 11;
const COL_SIZE      = 12;
const COL_COLOR     = 13;
const COL_LENGTH    = 14;
const COL_MACHINE   = 26;
const COL_SEQ       = 27;
const MAX_IMPORT_ERROR_MESSAGES = 25;

const LEGACY_REFERENCE_COLUMNS = [
    'lot'       => COL_LOT,
    'part'      => COL_PART,
    'circuit'   => COL_CIRCUIT,
    'wire_kind' => COL_WIRE_KIND,
    'material'  => COL_MATERIAL,
    'size'      => COL_SIZE,
    'color'     => COL_COLOR,
    'length'    => COL_LENGTH,
    'machine'   => COL_MACHINE,
    'seq'       => COL_SEQ,
];

function build_unique_key(string $part, string $circuit, string $lot,
                          string $seq, string $fileTag, int $rowNum): string {
    $p = trim($part); $c = trim($circuit); $l = trim($lot);
    if ($p !== '' && $c !== '' && $l !== '') return "{$p}_{$c}_{$l}";
    if ($p !== '' && $c !== '')              return "{$p}_{$c}";
    return sprintf('ROW_%s_%d_%s_%s',
        $fileTag,
        $rowNum,
        $l !== '' ? $l : 'NOLOT',
        trim($seq) !== '' ? trim($seq) : 'NOSEQ'
    );
}

function append_import_error(array &$errors, int &$suppressedErrors, string $message): void {
    if (count($errors) < MAX_IMPORT_ERROR_MESSAGES) {
        $errors[] = $message;
        return;
    }
    $suppressedErrors++;
}

function normalize_import_header(mixed $value): string {
    $label = preg_replace('/^\xEF\xBB\xBF/', '', trim((string)$value)) ?? '';
    return strtolower((string)(preg_replace('/[^a-z0-9]+/i', '', $label) ?? ''));
}

/**
 * Sequence numbers are encoded as four digits in the scanner QR format.
 * CSV exports commonly serialize them as numbers (for example, 128), which
 * removes the leading zeroes required by the scanner (0128).
 */
function format_reference_sequence(string $sequence): string {
    $sequence = trim($sequence);
    if ($sequence === '' || !ctype_digit($sequence) || strlen($sequence) >= 4) {
        return $sequence;
    }

    return str_pad($sequence, 4, '0', STR_PAD_LEFT);
}

/**
 * Keep a supplied QR string as the source of truth. When the source export
 * leaves its raw_QR_string column blank, build the canonical scanner value
 * from the lot and its four-digit sequence instead.
 *
 * @return array{lot: string, seq: string, raw_qr: string}
 */
function reference_identifiers_from_qr(string $lot, string $sequence, string $sourceRawQr): array {
    $lot         = trim($lot);
    $sequence    = trim($sequence);
    $sourceRawQr = trim($sourceRawQr);

    if ($sourceRawQr !== '') {
        $parsed = parse_qr_string($sourceRawQr);
        $qrLot  = trim((string)($parsed['lot_no'] ?? ''));
        $qrSeq  = trim((string)($parsed['seq_no'] ?? ''));

        // Standard scanner payloads are "lot sequence". Use these positions
        // directly so a numeric lot is not confused with a sequence token.
        $tokens = preg_split('/[\s,;|\/\\-]+/', $sourceRawQr, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) === 2 && ctype_digit($tokens[1])) {
            $qrLot = $tokens[0];
            $qrSeq = $tokens[1];
        }

        if ($qrLot !== '') $lot = $qrLot;
        if ($qrSeq !== '') $sequence = $qrSeq;

        return ['lot' => $lot, 'seq' => $sequence, 'raw_qr' => $sourceRawQr];
    }

    $sequence = format_reference_sequence($sequence);
    return [
        'lot'    => $lot,
        'seq'    => $sequence,
        'raw_qr' => trim("{$lot} {$sequence}"),
    ];
}

/**
 * Return a source-column map when a row looks like a reference-file header.
 * Existing exports keep their fixed positions; newer exports are mapped by name.
 */
function reference_column_map_from_header(array $row): ?array {
    $labels = [];
    foreach ($row as $index => $value) {
        $label = normalize_import_header($value);
        if ($label !== '' && !isset($labels[$label])) $labels[$label] = (int)$index;
    }

    $aliases = [
        'lot'       => ['lotno', 'lotnumber'],
        'raw_qr'    => ['rawqrstring', 'rawqr', 'qrstring', 'qrcode', 'barcode'],
        'part'      => ['partno', 'partnumber'],
        'model'     => ['model', 'modelcode', 'modelname'],
        // The scanner circuit is the Sub No value. Keep Ind No only as a
        // fallback for older exports that do not include a Sub No column.
        'circuit'   => ['subno', 'subnumber', 'sub', 'circuitno', 'circuitnumber', 'indno'],
        'wire_kind' => ['wire', 'wirekind'],
        'material'  => ['witmat', 'material', 'materialcode', 'wirematerial'],
        'size'      => ['size', 'sizegauge', 'gauge'],
        'color'     => ['color', 'colour'],
        'length'    => ['length', 'lengthmm', 'cuttinglength', 'cuttinglengthmm'],
        'machine'   => ['machine', 'machineno', 'machinenumber'],
        'seq'       => ['seq', 'seqno', 'seqnumber', 'sequence', 'sequenceno'],
    ];

    $map = [];
    foreach ($aliases as $field => $names) {
        foreach ($names as $name) {
            if (isset($labels[$name])) {
                $map[$field] = $labels[$name];
                break;
            }
        }
    }

    // Require enough distinctive labels to avoid interpreting normal data as a header.
    $coreCount = 0;
    foreach (['lot', 'part', 'wire_kind', 'material', 'length'] as $field) {
        if (isset($map[$field])) $coreCount++;
    }
    return (isset($map['lot'], $map['part']) && $coreCount >= 4) ? $map : null;
}

function import_files(array $files): array {
    $pdo      = db();
    $inserted = 0;
    $updated  = 0;
    $skipped  = 0;
    $errors   = [];
    $suppressedErrors = 0;

    $hasSpreadsheet = class_exists('\PhpOffice\PhpSpreadsheet\IOFactory');

    $insert = $pdo->prepare("
        INSERT INTO cutting_master_stock
          (unique_key, raw_qr_string, lot_no, seq_no, part_no, model_code, circuit_number,
           wire_kind, material_code, size, color, length_mm, machine_number,
           status, qty_available, updated_at)
        VALUES (:uk, :rq, :lot, :seq, :part, :model, :circ, :wk, :mc, :size, :col, :len, :mn,
                'REFERENCE', 1, datetime('now'))
    ");

    $exists = $pdo->prepare("SELECT 1 FROM cutting_master_stock WHERE unique_key = ?");

    /**
     * Process either a CSV stream or a spreadsheet row list without retaining
     * the source file in PHP memory.
     */
    $processRows = function (iterable $rows, string $name) use (
        $pdo,
        $insert,
        $exists,
        &$errors,
        &$suppressedErrors
    ): array {
        $fileTag  = substr(md5($name), 0, 6);
        $firstRow = true;
        $rowNum   = 0;
        $fileIns  = 0;
        $fileDup  = 0;
        $sawRows  = false;
        $inTransaction = false;
        $columnMap = LEGACY_REFERENCE_COLUMNS;

        try {
            foreach ($rows as $row) {
                $sawRows = true;
                if (!$inTransaction) {
                    $pdo->beginTransaction();
                    $inTransaction = true;
                }

                $rowNum++;
                if (!is_array($row)) {
                    append_import_error($errors, $suppressedErrors,
                        "Row {$rowNum} in {$name}: invalid row format.");
                    continue;
                }

                $headerMap = reference_column_map_from_header($row);
                if ($headerMap !== null) {
                    // A second process-header row can occur in newer CSV exports.
                    $columnMap = $headerMap + $columnMap;
                    $firstRow = false;
                    continue;
                }
                if ($firstRow) {
                    // Preserve compatibility with legacy files whose first row is a header.
                    $firstRow = false;
                    continue;
                }

                $nonEmpty = false;
                foreach ($row as $cell) {
                    if ($cell !== null && trim((string)$cell) !== '') { $nonEmpty = true; break; }
                }
                if (!$nonEmpty) continue;

                $col = function (string $field) use ($row, $columnMap): string {
                    $idx = $columnMap[$field] ?? -1;
                    $v = $idx >= 0 ? ($row[$idx] ?? null) : null;
                    if ($v === null) return '';
                    $v = trim((string)$v);
                    return ($v === '' || strtolower($v) === 'nan') ? '' : $v;
                };

                $lot     = $col('lot');
                $seq     = $col('seq');
                $sourceRawQr = $col('raw_qr');
                $part    = $col('part');
                $model   = $col('model');
                $circuit = $col('circuit');
                $wk      = normalize_wk($col('wire_kind'));
                $mc      = normalize_mc($col('material'));
                $size    = normalize_size($col('size'));
                $color   = $col('color');
                $machine = $col('machine');

                $lengthIndex = $columnMap['length'] ?? -1;
                $rawLen = $lengthIndex >= 0 ? ($row[$lengthIndex] ?? null) : null;
                if ($rawLen === null || $rawLen === '' || strtolower((string)$rawLen) === 'nan') {
                    $len = 0.0;
                } elseif (is_numeric($rawLen)) {
                    $len = (float)$rawLen;
                } else {
                    $clean = preg_replace('/[^0-9.\-]/', '', (string)$rawLen);
                    $len   = ($clean === '' || !is_numeric($clean)) ? 0.0 : (float)$clean;
                }

                $identifiers = reference_identifiers_from_qr($lot, $seq, $sourceRawQr);
                $lot          = $identifiers['lot'];
                $seq          = $identifiers['seq'];
                $uniqueKey = build_unique_key($part, $circuit, $lot, $seq, $fileTag, $rowNum);
                $rawQr     = $identifiers['raw_qr'];

                try {
                    $exists->execute([$uniqueKey]);
                    if ($exists->fetchColumn()) { $fileDup++; continue; }

                    $insert->execute([
                        ':uk'   => $uniqueKey,
                        ':rq'   => $rawQr,
                        ':lot'  => $lot,
                        ':seq'  => $seq,
                        ':part' => $part,
                        ':model'=> $model,
                        ':circ' => $circuit,
                        ':wk'   => $wk,
                        ':mc'   => $mc,
                        ':size' => $size,
                        ':col'  => $color,
                        ':len'  => $len,
                        ':mn'   => $machine,
                    ]);
                    $fileIns++;
                } catch (Throwable $e) {
                    append_import_error($errors, $suppressedErrors,
                        "Row {$rowNum} in {$name}: " . $e->getMessage());
                }
            }

            if (!$sawRows) return ['empty' => true, 'failed' => false, 'inserted' => 0, 'duplicates' => 0];

            $pdo->commit();
            return ['empty' => false, 'failed' => false, 'inserted' => $fileIns, 'duplicates' => $fileDup];
        } catch (Throwable $e) {
            if ($inTransaction && $pdo->inTransaction()) $pdo->rollBack();
            append_import_error($errors, $suppressedErrors, "Failed {$name}: " . $e->getMessage());
            return ['empty' => false, 'failed' => true, 'inserted' => 0, 'duplicates' => 0];
        }
    };

    $fileCount = count($files['tmp_name'] ?? []);
    for ($i = 0; $i < $fileCount; $i++) {
        $tmp  = $files['tmp_name'][$i] ?? '';
        $name = $files['name'][$i]     ?? "file_$i";
        $err  = $files['error'][$i]    ?? UPLOAD_ERR_NO_FILE;

        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            append_import_error($errors, $suppressedErrors,
                "Skipped {$name}: upload error (code {$err}).");
            $skipped++;
            continue;
        }

        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            $fh = fopen($tmp, 'r');
            if (!$fh) {
                append_import_error($errors, $suppressedErrors, "Skipped {$name}: cannot open.");
                $skipped++;
                continue;
            }

            try {
                $header = fgetcsv($fh);
                if ($header === false) {
                    append_import_error($errors, $suppressedErrors, "Skipped {$name}: no rows.");
                    $skipped++;
                    continue;
                }

                $csvRows = (function () use ($fh, $header): Generator {
                    yield $header;
                    while (($row = fgetcsv($fh)) !== false) yield $row;
                })();
                $result = $processRows($csvRows, $name);
            } finally {
                fclose($fh);
            }
        } elseif (in_array($ext, ['xls','xlsx'], true)) {
            if (!$hasSpreadsheet) {
                append_import_error($errors, $suppressedErrors,
                    "Skipped {$name}: PhpSpreadsheet not installed.");
                $skipped++;
                continue;
            }
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
                $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
            } catch (Throwable $e) {
                append_import_error($errors, $suppressedErrors, "Failed {$name}: " . $e->getMessage());
                $skipped++;
                continue;
            }
            $result = $processRows($rows, $name);
        } else {
            append_import_error($errors, $suppressedErrors,
                "Skipped {$name}: unsupported type '{$ext}'.");
            $skipped++;
            continue;
        }

        if ($result['empty']) {
            append_import_error($errors, $suppressedErrors, "Skipped {$name}: no rows.");
            $skipped++;
            continue;
        }
        if ($result['failed']) {
            $skipped++;
            continue;
        }

        $inserted += $result['inserted'];
        $skipped  += $result['duplicates'];
        $errors[] = "{$name}: {$result['inserted']} inserted, "
            . "{$result['duplicates']} already existed (kept old).";
    }

    if ($suppressedErrors > 0) {
        $errors[] = "{$suppressedErrors} additional row error(s) were not shown.";
    }

    return [
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'errors'   => $errors,
    ];
}
