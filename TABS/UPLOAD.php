<?php
// tabs/upload.php
$hasSpreadsheet = class_exists('\PhpOffice\PhpSpreadsheet\IOFactory');
$acceptedTypes  = $hasSpreadsheet ? '.csv,.xls,.xlsx' : '.csv';

$stats = $pdo->query("
    SELECT COUNT(*) AS ref_count,
           SUM(CASE WHEN length_mm IS NULL OR length_mm = 0 THEN 1 ELSE 0 END) AS zero_len,
           SUM(CASE WHEN material_code IS NULL OR TRIM(material_code) = '' THEN 1 ELSE 0 END) AS missing_mc
    FROM cutting_master_stock
")->fetch();
$refCount  = (int)($stats['ref_count'] ?? 0);
$zeroLen   = (int)($stats['zero_len'] ?? 0);
$missingMc = (int)($stats['missing_mc'] ?? 0);
?>

<section class="panel">
  <h2>Upload Reference DB</h2>

  <form method="post" enctype="multipart/form-data" class="upload-form">
    <input type="hidden" name="action" value="upload_files">
    <input type="file" name="files[]" multiple
           accept="<?= h($acceptedTypes) ?>" required>
    <button class="btn approve" type="submit">Import Files</button>
  </form>

  <?php if (!$hasSpreadsheet): ?>
  <details class="warn-details">
    <summary>Excel uploads disabled — CSV only</summary>
    <pre><code>cd <?= h(__DIR__) ?>

composer require phpoffice/phpspreadsheet</code></pre>
  </details>
  <?php endif; ?>
</section>

<?php if ($refCount > 0 && ($zeroLen > 0 || $missingMc > 0)): ?>
<section class="panel">
  <div class="grid-3">
    <div class="metric"><label>Zero / empty length</label>
      <span><?= $zeroLen ?></span></div>
    <div class="metric"><label>Empty material code</label>
      <span><?= $missingMc ?></span></div>
    <div class="metric"><label>Total reference rows</label>
      <span><?= $refCount ?></span></div>
  </div>
</section>
<?php endif; ?>

<section class="panel">
  <h3>Reference DB: <?= $refCount ?> row(s)</h3>

  <?php
  $recent = $pdo->query("
      SELECT stock_id, lot_no, seq_no, part_no, circuit_number,
             material_code, size, color, length_mm, status
      FROM cutting_master_stock
      ORDER BY stock_id DESC LIMIT 20
  ")->fetchAll();
  ?>
  <?php if ($recent): ?>
    <div class="table-scroll">
    <table class="data">
      <thead>
        <tr>
          <th>ID</th><th>Lot</th><th>Seq</th><th>Part No</th>
          <th>Circuit</th><th>Material Code</th><th>Size</th>
          <th>Color</th><th>Length (mm)</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($recent as $r):
        $len    = $r['length_mm'];
        $hasLen = $len !== null && $len !== '' && (float)$len > 0;
      ?>
        <tr>
          <td><?= (int)$r['stock_id'] ?></td>
          <td><?= h($r['lot_no'] ?: '—') ?></td>
          <td><?= h($r['seq_no'] ?: '—') ?></td>
          <td><?= h($r['part_no'] ?: '—') ?></td>
          <td><?= h($r['circuit_number'] ?: '—') ?></td>
          <td><code><?= h($r['material_code'] ?: '—') ?></code></td>
          <td><?= h($r['size'] ?: '—') ?></td>
          <td><?= h($r['color'] ?: '—') ?></td>
          <td>
            <?php if ($hasLen): ?>
              <b><?= number_format((float)$len, 0) ?></b>
            <?php else: ?>
              <span class="badge scrap"><?= h((string)$len) ?></span>
            <?php endif; ?>
          </td>
          <td><span class="badge reference"><?= h($r['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php else: ?>
    <p class="muted">Reference DB is empty.</p>
  <?php endif; ?>
</section>
