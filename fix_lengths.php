<?php
// fix_lengths.php — run once: php fix_lengths.php, then delete.
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$bad = $pdo->query("
    SELECT stock_id, unique_key, length_mm
    FROM cutting_master_stock
    WHERE length_mm IS NULL OR length_mm < 10 OR length_mm > 50000
")->fetchAll();

if (!$bad) { echo "No suspicious rows.\n"; exit(0); }

echo "Found " . count($bad) . " bad row(s). Setting to ON_HOLD...\n";
$upd = $pdo->prepare("
    UPDATE cutting_master_stock
    SET status = 'ON_HOLD', updated_at = datetime('now')
    WHERE stock_id = ?
");
foreach ($bad as $r) {
    $upd->execute([$r['stock_id']]);
    printf("  #%d  %s  length=%s\n",
        $r['stock_id'], $r['unique_key'], $r['length_mm'] ?? 'NULL');
}
echo "Done. Re-import the source Excel to fix lengths.\n";