<?php // tabs/kpi.php
$kpi   = kpi_summary();
$logsPerPage  = 50;
$logCount     = (int)$pdo->query("SELECT COUNT(*) FROM scan_audit_logs")->fetchColumn();
$logTotalPages = max(1, (int)ceil($logCount / $logsPerPage));
$logsPage     = max(1, (int)($_GET['logs_page'] ?? 1));
$logsPage     = min($logsPage, $logTotalPages);
$logsOffset   = ($logsPage - 1) * $logsPerPage;

$logsStmt = $pdo->prepare("\n    SELECT * FROM scan_audit_logs\n    ORDER BY scan_id DESC\n    LIMIT :limit OFFSET :offset\n");
$logsStmt->bindValue(':limit', $logsPerPage, PDO::PARAM_INT);
$logsStmt->bindValue(':offset', $logsOffset, PDO::PARAM_INT);
$logsStmt->execute();
$logs = $logsStmt->fetchAll();

$logRangeStart = $logCount > 0 ? $logsOffset + 1 : 0;
$logRangeEnd   = min($logsOffset + count($logs), $logCount);
$logsPageUrl = static function (int $pageNumber): string {
    $params = ['tab' => 'kpi'];
    if ($pageNumber > 1) $params['logs_page'] = $pageNumber;
    return '?' . http_build_query($params);
};

$recs  = $pdo->query("SELECT * FROM salvage_recommendations ORDER BY rec_id DESC LIMIT 100")->fetchAll();
$ref   = $pdo->query("
    SELECT * FROM cutting_master_stock
    ORDER BY stock_id DESC LIMIT 200
")->fetchAll();

$counts = [
    'reference'       => (int)$pdo->query("SELECT COUNT(*) FROM cutting_master_stock")->fetchColumn(),
    'recommendations' => (int)$pdo->query("SELECT COUNT(*) FROM salvage_recommendations")->fetchColumn(),
    'logs'            => $logCount,
];
$totalRows = array_sum($counts);
?>

<section class="panel">
  <h2>History</h2>
  <div class="grid-3">
    <div class="metric big"><label>Total Decided</label>
      <span><?= (int)$kpi['total'] ?></span></div>
    <div class="metric big"><label>Used</label>
      <span><?= (int)$kpi['approved'] ?></span></div>
    <div class="metric big"><label>Usage Rate</label>
      <span><?= number_format($kpi['rate'], 1) ?>%</span></div>
  </div>
</section>

<section class="panel danger-zone">
  <h2>Danger Zone</h2>

  <div class="clear-grid">
    <?php
    $targets = [
        'reference' => ['title' => 'Wire Reference DB',
                        'desc'  => 'Every uploaded wire spec.',
                        'count' => $counts['reference']],
        'recommendations' => ['title' => 'Usage Decisions',
                        'desc'  => 'Mark-used records.',
                        'count' => $counts['recommendations']],
        'logs' => ['title' => 'Scan Audit Logs',
                        'desc'  => 'Every scan + action history.',
                        'count' => $counts['logs']],
    ];
    foreach ($targets as $key => $t): ?>
      <div class="clear-card">
        <h3><?= h($t['title']) ?></h3>
        <p class="muted"><?= h($t['desc']) ?></p>
        <p class="clear-count"><b><?= (int)$t['count'] ?></b> row(s)</p>
        <form method="post" action="actions.php"
              onsubmit="return confirmClear('<?= h($key) ?>');">
          <input type="hidden" name="action" value="clear_data">
          <input type="hidden" name="target" value="<?= h($key) ?>">
          <input type="hidden" name="return" value="index.php?tab=kpi">
          <input type="text" name="confirm" class="confirm-input"
                 placeholder="Type DELETE to confirm" required>
          <button class="btn reject" type="submit">Clear</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="clear-all-box">
    <h3>Clear EVERYTHING</h3>
    <p class="muted">Deletes <b><?= (int)$totalRows ?></b> row(s) across all tables.</p>
    <form method="post" action="actions.php"
          onsubmit="return confirmClear('all');">
      <input type="hidden" name="action" value="clear_data">
      <input type="hidden" name="target" value="all">
      <input type="hidden" name="return" value="index.php?tab=kpi">
      <input type="text" name="confirm" class="confirm-input"
             placeholder="Type DELETE to confirm" required>
      <button class="btn reject" type="submit">Clear ALL Data</button>
    </form>
  </div>
</section>

<script>
function confirmClear(target) {
  var labels = {
    reference: 'the ENTIRE wire reference DB',
    recommendations: 'ALL usage decisions',
    logs: 'ALL scan audit logs',
    all: 'EVERYTHING (reference DB, decisions, logs)'
  };
  return window.confirm(
    'Are you absolutely sure?\n\n' +
    'This will permanently delete ' + (labels[target] || target) + '.\n\n' +
    'Click OK to proceed, or Cancel to abort.'
  );
}
</script>

<section class="panel">
  <h3>Usage Decisions</h3>
  <div class="table-scroll">
  <table class="data">
    <thead><tr>
      <th>ID</th><th>Scanned</th><th>Target</th><th>Material</th>
      <th>Scanned L (mm)</th><th>Target L (mm)</th>
      <th>Status</th><th>Planner</th><th>Time</th>
    </tr></thead>
    <tbody>
    <?php foreach ($recs as $r): ?>
      <tr>
        <td>#<?= (int)$r['rec_id'] ?></td>
        <td>#<?= (int)$r['stock_id'] ?></td>
        <td>#<?= (int)($r['matched_stock_id'] ?? 0) ?></td>
        <td><code><?= h($r['material_code'] ?? '—') ?></code></td>
        <td><?= number_format((float)($r['scanned_length_mm'] ?? 0), 0) ?></td>
        <td><?= number_format((float)($r['target_length_mm'] ?? 0), 0) ?></td>
        <td><span class="badge approved"><?= h($r['decision_status']) ?></span></td>
        <td><?= h($r['planner_id']) ?></td>
        <td><?= h($r['decision_timestamp']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="panel">
  <h3>Scan Audit Logs</h3>
  <p class="muted">Showing <?= $logRangeStart ?>–<?= $logRangeEnd ?> of <?= $logCount ?> audit logs.</p>
  <?php if ($logTotalPages > 1): ?>
    <nav class="scan-pager scan-pager-top" aria-label="Scan audit log pages">
      <span class="scan-pager-side">
        <?php if ($logsPage > 1): ?>
          <a class="btn small" href="<?= h($logsPageUrl(1)) ?>">First</a>
          <a class="btn small" href="<?= h($logsPageUrl($logsPage - 1)) ?>">Previous</a>
        <?php endif; ?>
      </span>
      <span class="muted">Page <?= $logsPage ?> of <?= $logTotalPages ?></span>
      <span class="scan-pager-side scan-pager-side-right">
        <?php if ($logsPage < $logTotalPages): ?>
          <a class="btn small" href="<?= h($logsPageUrl($logsPage + 1)) ?>">Next</a>
          <a class="btn small" href="<?= h($logsPageUrl($logTotalPages)) ?>">Last</a>
        <?php endif; ?>
      </span>
    </nav>
  <?php endif; ?>
  <div class="table-scroll">
  <table class="data">
    <thead><tr>
      <th>ID</th><th>Raw Scan</th><th>Unique Key</th><th>Lot</th>
      <th>Seq</th><th>Action</th><th>By</th><th>Time</th>
    </tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
      <tr>
        <td><?= (int)$l['scan_id'] ?></td>
        <td><code><?= h($l['raw_scanned']) ?></code></td>
        <td><?= h($l['unique_key']) ?></td>
        <td><?= h($l['lot_no']) ?></td>
        <td><?= h($l['seq_no']) ?></td>
        <td><?= h($l['action_taken']) ?></td>
        <td><?= h($l['scanned_by']) ?></td>
        <td><?= h($l['scan_timestamp']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="panel">
  <h3>Reference DB Snapshot (latest 200)</h3>
  <div class="table-scroll">
  <table class="data">
    <thead>
      <tr>
        <th>ID</th><th>Lot</th><th>Seq</th><th>Part No</th>
        <th>Circuit</th><th>Material Code</th><th>Wire Kind</th>
        <th>Size</th><th>Color</th><th>Length (mm)</th>
        <th>Machine</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($ref as $s):
      $len    = $s['length_mm'];
      $hasLen = $len !== null && $len !== '' && (float)$len > 0;
    ?>
      <tr>
        <td><?= (int)$s['stock_id'] ?></td>
        <td><?= h($s['lot_no']) ?></td>
        <td><?= h($s['seq_no']) ?></td>
        <td><?= h($s['part_no']) ?></td>
        <td><?= h($s['circuit_number']) ?></td>
        <td><code><?= h($s['material_code'] ?: '—') ?></code></td>
        <td><?= h($s['wire_kind'] ?: '—') ?></td>
        <td><?= h($s['size'] ?: '—') ?></td>
        <td><?= h($s['color'] ?: '—') ?></td>
        <td>
          <?php if ($hasLen): ?>
            <b><?= number_format((float)$len, 0) ?></b>
          <?php else: ?>
            <span class="badge scrap"><?= h((string)$len) ?></span>
          <?php endif; ?>
        </td>
        <td><?= h($s['machine_number'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
