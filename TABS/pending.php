<?php
// tabs/pending.php
$pending = $pdo->query("
    SELECT * FROM wire_conversions
    WHERE status = 'PENDING_LOT'
    ORDER BY conversion_id DESC
")->fetchAll();

$completed = $pdo->query("
    SELECT * FROM wire_conversions
    WHERE status = 'COMPLETED'
    ORDER BY conversion_id DESC
    LIMIT 100
")->fetchAll();

$pendingCount   = count($pending);
$completedCount = (int)$pdo->query("
    SELECT COUNT(*) FROM wire_conversions WHERE status = 'COMPLETED'
")->fetchColumn();
?>

<section class="panel">
  <h2>Pending Lot Entry</h2>
  <div class="grid-3">
    <div class="metric big"><label>Pending Lot</label>
      <span><?= $pendingCount ?></span></div>
    <div class="metric big"><label>Completed</label>
      <span><?= $completedCount ?></span></div>
    <div class="metric big"><label>Total</label>
      <span><?= $pendingCount + $completedCount ?></span></div>
  </div>
</section>

<section class="panel">
  <h3>Awaiting New Lot Number (<?= $pendingCount ?>)</h3>

  <?php if ($pendingCount > 0): ?>
    <div class="table-scroll">
    <table class="data">
      <thead><tr>
        <th>Conv #</th>
        <th>Material</th>
        <th>Scanned (mm)</th>
        <th>Target Part No</th>
        <th>Circuit</th>
        <th>Target (mm)</th>
        <th>Surplus Use</th>
        <th>Scrap (mm)</th>
        <th>By</th>
        <th>When</th>
        <th>New Lot Number</th>
      </tr></thead>
      <tbody>
      <?php foreach ($pending as $c): ?>
        <tr>
          <td>#<?= (int)$c['conversion_id'] ?></td>
          <td><code><?= h($c['material_code'] ?: '—') ?></code></td>
          <td><?= number_format((float)$c['scanned_length_mm'], 0) ?></td>
          <td><b><?= h($c['target_part_no'] ?: '—') ?></b></td>
          <td><code><?= h($c['target_circuit'] ?: '—') ?></code></td>
          <td><?= number_format((float)$c['target_length_mm'], 0) ?></td>
          <td class="surplus-cell">
            <?php if (!empty($c['surplus_reuse_part_no'])): ?>
              <div class="surplus-reuse">
                <span class="surplus-arrow">→</span>
                <span><b><?= h($c['surplus_reuse_part_no']) ?></b>
                  / <code><?= h($c['surplus_reuse_circuit']) ?></code>
                  (<?= number_format((float)$c['surplus_reuse_length_mm'], 0) ?> mm)</span>
              </div>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ((float)($c['scrap_length_mm'] ?? 0) > 0): ?>
              <span class="scrap-tag"><?= number_format((float)$c['scrap_length_mm'], 0) ?></span>
            <?php else: ?>
              <span class="muted">0</span>
            <?php endif; ?>
          </td>
          <td><?= h($c['converted_by'] ?: '—') ?></td>
          <td><?= h($c['converted_at'] ?: '—') ?></td>
          <td>
            <form method="post" action="actions.php" class="lot-form">
              <input type="hidden" name="action" value="record_lot">
              <input type="hidden" name="conversion_id"
                     value="<?= (int)$c['conversion_id'] ?>">
              <input type="hidden" name="return" value="index.php?tab=pending">
              <input type="text" name="new_lot_no"
                     placeholder="e.g. VQA01099"
                     required>
              <button class="btn approve small" type="submit">Save</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php else: ?>
    <p class="muted">No conversions awaiting a lot number.</p>
  <?php endif; ?>
</section>

<section class="panel">
  <h3>Completed Conversions (latest 100)</h3>

  <?php if ($completed): ?>
    <div class="table-scroll">
    <table class="data">
      <thead><tr>
        <th>Conv #</th>
        <th>Material</th>
        <th>Target Part No</th>
        <th>Circuit</th>
        <th>Target (mm)</th>
        <th>Surplus Use</th>
        <th>Scrap (mm)</th>
        <th>Converted At</th>
        <th>New Lot</th>
        <th>Lot Recorded At</th>
        <th>Lot By</th>
      </tr></thead>
      <tbody>
      <?php foreach ($completed as $c): ?>
        <tr>
          <td>#<?= (int)$c['conversion_id'] ?></td>
          <td><code><?= h($c['material_code'] ?: '—') ?></code></td>
          <td><b><?= h($c['target_part_no'] ?: '—') ?></b></td>
          <td><code><?= h($c['target_circuit'] ?: '—') ?></code></td>
          <td><?= number_format((float)$c['target_length_mm'], 0) ?></td>
          <td class="surplus-cell">
            <?php if (!empty($c['surplus_reuse_part_no'])): ?>
              <div class="surplus-reuse">
                <span class="surplus-arrow">→</span>
                <span><b><?= h($c['surplus_reuse_part_no']) ?></b>
                  / <code><?= h($c['surplus_reuse_circuit']) ?></code>
                  (<?= number_format((float)$c['surplus_reuse_length_mm'], 0) ?> mm)</span>
              </div>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ((float)($c['scrap_length_mm'] ?? 0) > 0): ?>
              <span class="scrap-tag"><?= number_format((float)$c['scrap_length_mm'], 0) ?></span>
            <?php else: ?>
              <span class="muted">0</span>
            <?php endif; ?>
          </td>
          <td><?= h($c['converted_at'] ?: '—') ?></td>
          <td><span class="badge available">
              <b><?= h($c['new_lot_no'] ?: '—') ?></b></span></td>
          <td><?= h($c['lot_recorded_at'] ?: '—') ?></td>
          <td><?= h($c['lot_recorded_by'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php else: ?>
    <p class="muted">No completed conversions yet.</p>
  <?php endif; ?>
</section>