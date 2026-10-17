<?php
// tabs/scan.php
$rawScan   = trim((string)($_GET['q'] ?? ''));
$stockId   = (int)($_GET['stock_id'] ?? 0);
$manualMc  = trim((string)($_GET['manual_mc'] ?? ''));
$manualLen = (float)($_GET['manual_len'] ?? 0);
$browseLot = trim((string)($_GET['browse_lot'] ?? ''));
$pnFilter  = trim((string)($_GET['pn'] ?? ''));
$matchSort = trim((string)($_GET['sort'] ?? 'priority'));
$allowedMatchSorts = ['priority', 'model', 'circuit', 'part'];
if (!in_array($matchSort, $allowedMatchSorts, true)) $matchSort = 'priority';
$requestedHighRunnerModelKey = normalize_high_runner_model_key(
    (string)($_GET['high_runner_model'] ?? '')
);
$highRunnerModelOptions = [];
$highRunnerModelKey = '';
$highRunnerModel = '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$pageSize  = 10;

$parsed = $rawScan !== '' ? parse_qr_string($rawScan) : null;

$refRow    = null;
$refMc     = '';
$refLen    = 0.0;
$refSource = '';

if ($manualMc !== '' && $manualLen > 0) {
    $refMc     = normalize_mc($manualMc);
    $refLen    = $manualLen;
    $refSource = 'manual';
}

if ($refSource === '' && $parsed && $parsed['lot_no'] !== '' && $parsed['seq_no'] !== '') {
    $stmt = $pdo->prepare("
        SELECT * FROM cutting_master_stock
        WHERE lot_no = :lot AND seq_no = :seq
        ORDER BY stock_id DESC LIMIT 1
    ");
    $stmt->execute([':lot' => $parsed['lot_no'], ':seq' => $parsed['seq_no']]);
    $found = $stmt->fetch();
    if ($found) {
        $refRow    = $found;
        $stockId   = (int)$found['stock_id'];
        $refMc     = normalize_mc($found['material_code']);
        $refLen    = (float)$found['length_mm'];
        $refSource = 'db';
    }
}

if ($refSource === '' && $parsed && $parsed['material_code'] !== '' && $parsed['length_mm'] > 0) {
    $refMc     = normalize_mc($parsed['material_code']);
    $refLen    = (float)$parsed['length_mm'];
    $refSource = 'barcode';
}

if ($refSource === '' && $stockId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM cutting_master_stock WHERE stock_id = ?");
    $stmt->execute([$stockId]);
    $found = $stmt->fetch();
    if ($found) {
        $refRow    = $found;
        $refMc     = normalize_mc($found['material_code']);
        $refLen    = (float)$found['length_mm'];
        $refSource = 'db';
    }
}

$allMatches = [];
if ($refMc !== '' && $refLen > 0) {
    $exclude    = $refRow ? (int)$refRow['stock_id'] : 0;
    $allMatches = find_reference_matches($refMc, $refLen, $exclude);
}

// Offer only high-runner models that have an actual compatible conversion
// target for this scanned wire. The list therefore changes with each scan.
$highRunnerModelOptions = high_runner_model_options(
    array_column($allMatches, 'part_no')
);
if ($matchSort === 'model'
    && $requestedHighRunnerModelKey !== ''
    && isset($highRunnerModelOptions[$requestedHighRunnerModelKey])) {
    $highRunnerModelKey = $requestedHighRunnerModelKey;
    $highRunnerModel = $highRunnerModelOptions[$highRunnerModelKey];
}

$matches = $allMatches;
if ($pnFilter !== '') {
    $needle  = strtoupper($pnFilter);
    $matches = array_values(array_filter(
        $allMatches,
        fn($m) => strpos(strtoupper((string)($m['part_no'] ?? '')), $needle) !== false
    ));
}

if ($highRunnerModelKey !== '') {
    $matches = array_values(array_filter(
        $matches,
        static fn(array $match): bool => normalize_high_runner_model_key(
            high_runner_label($match['part_no'] ?? '')
        ) === $highRunnerModelKey
    ));
}

$modelForMatch = static fn(array $match): string => reference_model_label(
    $match['model_code'] ?? '',
    $match['part_no'] ?? ''
);
$compareMatchText = static function (?string $a, ?string $b): int {
    $a = strtoupper(trim((string)$a));
    $b = strtoupper(trim((string)$b));
    if ($a === '') return $b === '' ? 0 : 1;
    if ($b === '') return -1;
    return $a <=> $b;
};

usort($matches, static function (array $a, array $b) use (
    $matchSort,
    $modelForMatch,
    $compareMatchText
): int {
    $aHighRunner = is_high_runner_part($a['part_no'] ?? '');
    $bHighRunner = is_high_runner_part($b['part_no'] ?? '');
    if ($aHighRunner !== $bHighRunner) return $aHighRunner ? -1 : 1;

    // The user-selected order applies inside the high-runner and standard
    // groups, so established high-runner priority is never lost.
    if ($matchSort !== 'priority') {
        $selectedOrder = match ($matchSort) {
            'model'   => $compareMatchText($modelForMatch($a), $modelForMatch($b)),
            'circuit' => $compareMatchText($a['circuit_number'] ?? '', $b['circuit_number'] ?? ''),
            'part'    => $compareMatchText($a['part_no'] ?? '', $b['part_no'] ?? ''),
            default   => 0,
        };
        if ($selectedOrder !== 0) return $selectedOrder;
    }

    // The same source length applies to every row, so lower surplus means
    // less leftover wire after this conversion.
    $surplusOrder = (float)($a['surplus_mm'] ?? 0) <=> (float)($b['surplus_mm'] ?? 0);
    if ($surplusOrder !== 0) return $surplusOrder;

    $pa = strtoupper((string)($a['part_no'] ?? ''));
    $pb = strtoupper((string)($b['part_no'] ?? ''));
    if ($pa !== $pb) return $pa <=> $pb;
    $ca = strtoupper((string)($a['circuit_number'] ?? ''));
    $cb = strtoupper((string)($b['circuit_number'] ?? ''));
    if ($ca !== $cb) return $ca <=> $cb;
    return (int)($a['stock_id'] ?? 0) <=> (int)($b['stock_id'] ?? 0);
});

$highRunnerMatchCount = count(array_filter(
    $matches,
    static fn(array $match): bool => is_high_runner_part($match['part_no'] ?? '')
));

$matchCnt = count($matches);
$totalCnt = count($allMatches);
$totalPages = max(1, (int)ceil($matchCnt / $pageSize));
$page = min($page, $totalPages);
$pageOffset = ($page - 1) * $pageSize;
$pageMatches = array_slice($matches, $pageOffset, $pageSize);
$rangeStart = $matchCnt > 0 ? $pageOffset + 1 : 0;
$rangeEnd = min($pageOffset + count($pageMatches), $matchCnt);

$scanPageUrl = static function (
    int $pageNumber,
    bool $includePartFilter = true,
    bool $includeHighRunnerModel = true
) use (
    $rawScan, $stockId, $manualMc, $manualLen, $pnFilter, $matchSort,
    $highRunnerModel
): string {
    $params = ['tab' => 'scan'];
    if ($rawScan !== '') $params['q'] = $rawScan;
    if ($stockId > 0) $params['stock_id'] = $stockId;
    if ($manualMc !== '') $params['manual_mc'] = $manualMc;
    if ($manualLen > 0) $params['manual_len'] = $manualLen;
    if ($includePartFilter && $pnFilter !== '') $params['pn'] = $pnFilter;
    if ($matchSort !== 'priority') $params['sort'] = $matchSort;
    if ($includeHighRunnerModel && $highRunnerModel !== '') {
        $params['high_runner_model'] = $highRunnerModel;
    }
    if ($pageNumber > 1) $params['page'] = $pageNumber;
    return '?' . http_build_query($params);
};

// Reuse searches are identical for targets with the same leftover length.
// Fetch extra rows to account for excluded IDs and duplicate reference rows;
// the chooser should show distinct part/circuit/length conversion options.
$surplusReuseCache = [];
$surplusReusesFor = static function (float $surplus, array $excludeIds) use (
    &$surplusReuseCache, $refMc
): array {
    $key = sprintf('%.1F', $surplus);
    if (!isset($surplusReuseCache[$key])) {
        $surplusReuseCache[$key] = find_surplus_reuses($refMc, $surplus, [], 20);
    }

    $excluded = array_fill_keys(array_map('intval', $excludeIds), true);
    $usable = [];
    $seenTargets = [];
    foreach ($surplusReuseCache[$key] as $candidate) {
        if (isset($excluded[(int)$candidate['stock_id']])) continue;
        $targetKey = implode('|', [
            normalize_part_no($candidate['part_no'] ?? ''),
            strtoupper(trim((string)($candidate['circuit_number'] ?? ''))),
            sprintf('%.1F', (float)($candidate['length_mm'] ?? 0)),
        ]);
        if (isset($seenTargets[$targetKey])) continue;
        $seenTargets[$targetKey] = true;
        $usable[] = $candidate;
        if (count($usable) === 3) break;
    }
    return $usable;
};

$srcLot     = $refRow['lot_no']         ?? ($parsed['lot_no'] ?? '');
$srcSeq     = $refRow['seq_no']         ?? ($parsed['seq_no'] ?? '');
$srcPart    = $refRow['part_no']        ?? '';
$srcCircuit = $refRow['circuit_number'] ?? '';
$srcStatus  = $refRow['status']         ?? 'UNKNOWN';
$srcLen     = $refRow['length_mm']      ?? $refLen;
$hasResultPane = $browseLot !== '' || ($refMc !== '' && $refLen > 0);
?>

<div class="scan-workspace<?= $hasResultPane ? ' has-results' : '' ?>">
  <aside class="scan-input-pane" aria-label="Scanned wire details">
<div class="scan-hero scan-input-hero">
  <h2>Scan an Obsolete Wire</h2>
  <form method="get" class="scan-form-big">
    <input type="hidden" name="tab" value="scan">
    <input type="text" name="q"
           placeholder="Scan or paste barcode"
           value="<?= h($rawScan) ?>" autofocus>
    <button type="submit">Analyze</button>
  </form>
</div>

<?php if ($parsed): ?>
<section class="panel">
  <div class="grid-4">
    <div class="metric"><label>Lot No</label>
      <span><?= h($parsed['lot_no'] ?: '—') ?></span></div>
    <div class="metric"><label>Seq No</label>
      <span><?= h($parsed['seq_no'] ?: '—') ?></span></div>
    <div class="metric"><label>Material Code</label>
      <span><?= h($refMc ?: ($parsed['material_code'] ?: '—')) ?></span></div>
    <div class="metric"><label>Cutting Length (mm)</label>
      <span><?= $refLen > 0 ? h(number_format($refLen, 0)) : '—' ?></span></div>
  </div>
</section>
<?php endif; ?>

<?php if ($refSource === 'db' && $refRow): ?>
  <section class="panel status-panel status-ok">
    <div class="status-row">
      <span class="status-pill status-ok">Found in reference DB</span>
      <span class="muted">
        <?php if ($refRow['lot_no']): ?>
          Lot <b><?= h($refRow['lot_no']) ?></b> ·
        <?php endif; ?>
        Part <b><?= h($refRow['part_no']) ?></b> ·
        Circuit <code><?= h($refRow['circuit_number']) ?></code> ·
        <code><?= h($refRow['material_code']) ?></code> ·
        <?= number_format((float)$refRow['length_mm'], 0) ?> mm ·
        <span class="badge <?= strtolower($refRow['status']) ?>"><?= h($refRow['status']) ?></span>
      </span>
    </div>
  </section>
<?php elseif ($refSource === 'barcode'): ?>
  <section class="panel status-panel status-info">
    <div class="status-row">
      <span class="status-pill status-info">Barcode data</span>
      <span class="muted"><code><?= h($refMc) ?></code> · <?= number_format($refLen, 0) ?> mm</span>
    </div>
  </section>
<?php elseif ($refSource === 'manual'): ?>
  <section class="panel status-panel status-info">
    <div class="status-row">
      <span class="status-pill status-info">Manual reference</span>
      <span class="muted"><code><?= h($refMc) ?></code> · <?= number_format($refLen, 0) ?> mm</span>
    </div>
  </section>
<?php endif; ?>

<?php if ($parsed && $refSource === ''): ?>
  <section class="panel warn">
    <h3>Could not identify this wire</h3>
    <?php $mcs = list_material_codes(); ?>
    <form method="get" class="manual-mc-form">
      <input type="hidden" name="tab" value="scan">
      <input type="hidden" name="q" value="<?= h($rawScan) ?>">
      <label>Material code
        <select name="manual_mc" required>
          <option value="">— select —</option>
          <?php foreach ($mcs as $row): ?>
            <option value="<?= h($row['mc']) ?>">
              <?= h($row['mc']) ?>
              (<?= (int)$row['cnt'] ?> parts,
              <?= number_format((float)$row['min_len'], 0) ?>–<?= number_format((float)$row['max_len'], 0) ?> mm)
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Cutting length (mm)
        <input type="number" name="manual_len" step="0.1"
               value="<?= $parsed['length_mm'] > 0 ? h((string)$parsed['length_mm']) : '' ?>"
               required>
      </label>
      <button class="btn approve" type="submit">Search</button>
    </form>
  </section>
<?php endif; ?>

  </aside>

  <?php if ($hasResultPane): ?>
  <section class="scan-results-pane" aria-label="Conversion results">

<?php if ($browseLot !== ''): ?>
<?php
  $stmt = $pdo->prepare("
      SELECT * FROM cutting_master_stock
      WHERE lot_no = :lot
      ORDER BY seq_no ASC LIMIT 100
  ");
  $stmt->execute([':lot' => $browseLot]);
  $browseRows = $stmt->fetchAll();
?>
<section class="panel">
  <h3>Lot <?= h($browseLot) ?> (<?= count($browseRows) ?>)</h3>
  <div class="table-scroll">
  <table class="data">
    <thead><tr>
      <th>Lot</th><th>Part No</th><th>Circuit</th>
      <th>Material</th><th>Size</th><th>Color</th><th>Length</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($browseRows as $m): ?>
      <tr>
        <td><?= h($m['lot_no']) ?></td>
        <td><?= h($m['part_no']) ?></td>
        <td><?= h($m['circuit_number']) ?></td>
        <td><code><?= h($m['material_code']) ?></code></td>
        <td><?= h($m['size']) ?></td>
        <td><?= h($m['color']) ?></td>
        <td><?= h((string)$m['length_mm']) ?></td>
        <td>
          <a class="btn small"
             href="?tab=scan&q=<?= urlencode($browseLot . ' ' . $m['seq_no']) ?>&stock_id=<?= (int)$m['stock_id'] ?>">
            Select
          </a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<?php if ($refMc !== '' && $refLen > 0): ?>

<section class="panel scan-filter-panel">
  <h3>Search Within Results</h3>
  <form method="get" class="pn-filter-form">
    <input type="hidden" name="tab"      value="scan">
    <input type="hidden" name="q"        value="<?= h($rawScan) ?>">
    <input type="hidden" name="stock_id" value="<?= (int)$stockId ?>">
    <input type="hidden" name="sort"     value="<?= h($matchSort) ?>">
    <?php if ($highRunnerModel !== ''): ?>
      <input type="hidden" name="high_runner_model" value="<?= h($highRunnerModel) ?>">
    <?php endif; ?>
    <input type="text" name="pn"
           placeholder="Filter by part number"
           value="<?= h($pnFilter) ?>">
    <button class="btn approve" type="submit">Search</button>
    <?php if ($pnFilter !== ''): ?>
      <a class="btn small"
         href="<?= h($scanPageUrl(1, false)) ?>">
        Clear
      </a>
    <?php endif; ?>
  </form>
</section>

<?php if ($matchCnt > 0): ?>
<style>
/* Keep the title and result controls on one compact header row on desktop. */
.scan-results-heading-row {
  display: grid;
  grid-template-columns: max-content minmax(0, 1fr);
  align-items: center;
  column-gap: 20px;
  row-gap: 12px;
}
.scan-results-heading-row h3 {
  margin: 0;
  white-space: nowrap;
}
.scan-results-heading-row .scan-sort-form {
  display: flex;
  width: 100%;
  min-width: 0;
  margin: 0;
  flex-wrap: nowrap;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
}
.scan-results-heading-row .scan-sort-form select {
  min-width: 150px;
}
.scan-sort-form .model-picker-label {
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
  color: #53647d;
}
.scan-sort-form .model-picker {
  position: relative;
  width: min(210px, 100%);
  flex: 0 1 210px;
  min-width: 176px;
}
.scan-sort-form .model-picker-control {
  display: flex;
  align-items: center;
  height: 39px;
  border: 1px solid #d9e1ed;
  border-radius: 9px;
  background: #fff;
  color: #64748b;
  transition: border-color .12s ease, box-shadow .12s ease;
}
.scan-sort-form .model-picker-control:focus-within {
  border-color: #5288e3;
  box-shadow: 0 0 0 3px rgba(74, 130, 224, .12);
}
.scan-sort-form .model-picker-search-icon {
  flex: 0 0 auto;
  margin-left: 10px;
  pointer-events: none;
}
.scan-sort-form .model-picker-search {
  box-sizing: border-box;
  width: 100%;
  min-width: 0;
  height: 100%;
  padding: 0 5px 0 9px;
  border: 0;
  outline: none;
  box-shadow: none;
  background: transparent;
  color: #172333;
  font: inherit;
  font-size: 13px;
}
.scan-sort-form .model-picker-search::placeholder { color: #8492a6; }
.scan-sort-form .model-picker-toggle,
.scan-sort-form .model-picker-clear {
  display: inline-flex;
  justify-content: center;
  align-items: center;
  padding: 0;
  flex: 0 0 30px;
  height: 31px;
  border: 0;
  border-radius: 6px;
  color: #66788e;
  background: transparent;
  cursor: pointer;
}
.scan-sort-form .model-picker-toggle { margin-right: 3px; }
.scan-sort-form .model-picker-toggle:hover,
.scan-sort-form .model-picker-clear:hover { background: #f0f4f9; color: #263d58; }
.scan-sort-form .model-picker-clear { font-size: 20px; }
.scan-sort-form .model-picker-menu {
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  left: 0;
  z-index: 200;
  box-sizing: border-box;
  overflow: hidden;
  border: 1px solid #dce5ee;
  border-radius: 11px;
  background: #fff;
  box-shadow: 0 10px 30px rgba(20, 32, 54, .17);
}
.scan-sort-form .model-picker-menu-head {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  padding: 11px 12px 8px;
  color: #5b6c80;
  font-size: 11px;
  font-weight: 700;
}
.scan-sort-form .model-picker-menu-head span:last-child { color: #8190a3; font-weight: 500; }
.scan-sort-form .model-picker-items { max-height: 228px; overflow-y: auto; padding: 3px 5px 6px; overscroll-behavior: contain; }
.scan-sort-form .model-picker-option {
  box-sizing: border-box;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  width: 100%;
  min-height: 36px;
  padding: 8px 10px;
  border: 0;
  border-radius: 7px;
  background: transparent;
  color: #273548;
  text-align: left;
  font: inherit;
  font-size: 13px;
  line-height: 1.3;
  cursor: pointer;
}
.scan-sort-form .model-picker-option:hover,
.scan-sort-form .model-picker-option.is-highlighted { background: #f0f5fc; }
.scan-sort-form .model-picker-option[aria-selected="true"] { color: #174c90; font-weight: 700; }
.scan-sort-form .model-picker-option-check { visibility: hidden; color: #3279d5; }
.scan-sort-form .model-picker-option[aria-selected="true"] .model-picker-option-check { visibility: visible; }
.scan-sort-form .model-picker-empty { margin: 8px; padding: 10px; color: #66788e; font-size: 12px; }
.scan-sort-form .model-picker-menu-foot {
  border-top: 1px solid #edf0f5;
  padding: 9px 12px;
  font-size: 11px;
  color: #7e8a9a;
}
.scan-sort-form .model-picker-help { display: block; font-size: 11px; color: #b45309; }
.scan-sort-form .model-picker-help:empty { display: none; }
.scan-sort-form .model-picker[hidden],
.scan-sort-form .model-picker-fallback[hidden],
.scan-sort-form .model-picker-menu[hidden],
.scan-sort-form .model-picker-option[hidden],
.scan-sort-form .model-picker-empty[hidden],
.scan-sort-form .model-picker-clear[hidden] { display: none !important; }
@media (max-width: 960px) {
  .scan-results-heading-row {
    grid-template-columns: 1fr;
    align-items: stretch;
  }
  .scan-results-heading-row .scan-sort-form {
    justify-content: flex-start;
    flex-wrap: wrap;
  }
  .scan-sort-form .model-picker { flex: 1 1 220px; }
}
</style>
<section class="panel scan-results-card" aria-labelledby="scan-results-heading">
  <div class="scan-results-heading-row">
    <h3 id="scan-results-heading">Convert to Any of These (<?= $matchCnt ?>)</h3>
    <form method="get" class="scan-sort-form">
      <input type="hidden" name="tab" value="scan">
      <input type="hidden" name="q" value="<?= h($rawScan) ?>">
      <input type="hidden" name="stock_id" value="<?= (int)$stockId ?>">
      <?php if ($manualMc !== ''): ?>
        <input type="hidden" name="manual_mc" value="<?= h($manualMc) ?>">
      <?php endif; ?>
      <?php if ($manualLen > 0): ?>
        <input type="hidden" name="manual_len" value="<?= h((string)$manualLen) ?>">
      <?php endif; ?>
      <?php if ($pnFilter !== ''): ?>
        <input type="hidden" name="pn" value="<?= h($pnFilter) ?>">
      <?php endif; ?>
      <label for="match-sort" title="Sort matches">Sort</label>
      <select id="match-sort" name="sort" onchange="this.form.submit()">
        <option value="priority" <?= $matchSort === 'priority' ? 'selected' : '' ?>>Priority / least scrap</option>
        <option value="model" <?= $matchSort === 'model' ? 'selected' : '' ?>>Model</option>
        <option value="circuit" <?= $matchSort === 'circuit' ? 'selected' : '' ?>>Circuit</option>
        <option value="part" <?= $matchSort === 'part' ? 'selected' : '' ?>>Part number</option>
      </select>
      <?php if ($matchSort === 'model' && $highRunnerModelOptions): ?>
        <span class="model-picker-label" title="Compatible high-runner model">High-runner model</span>
        <!-- Native-select fallback works when JavaScript is disabled. -->
        <select id="high-runner-model-fallback" class="model-picker-fallback"
                aria-label="Compatible high-runner model" name="high_runner_model"
                onchange="this.form.submit()">
          <option value="">All compatible models</option>
          <?php foreach ($highRunnerModelOptions as $modelLabel): ?>
            <option value="<?= h($modelLabel) ?>" <?= $highRunnerModel === $modelLabel ? 'selected' : '' ?>>
              <?= h($modelLabel) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <div class="model-picker" data-model-picker hidden>
          <input type="hidden" name="high_runner_model"
                 value="<?= h($highRunnerModel) ?>" data-model-value disabled>
          <div class="model-picker-control">
            <svg class="model-picker-search-icon" viewBox="0 0 24 24" width="16" height="16"
                 fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <circle cx="10.8" cy="10.8" r="6.8"></circle>
              <path d="m16 16 5 5"></path>
            </svg>
            <input id="high-runner-model-search" type="text" class="model-picker-search"
                   data-model-search role="combobox" aria-label="Search compatible high-runner models"
                   aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false"
                   aria-controls="high-runner-model-list"
                   placeholder="All compatible models"
                   value="<?= h($highRunnerModel) ?>" autocomplete="off" spellcheck="false">
            <button type="button" class="model-picker-clear" data-model-clear
                    aria-label="Clear high-runner model filter" title="Clear filter" hidden>×</button>
            <button type="button" class="model-picker-toggle" data-model-toggle
                    aria-label="Toggle compatible models" aria-controls="high-runner-model-list"
                    aria-expanded="false">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="m6 9 6 6 6-6"></path>
              </svg>
            </button>
          </div>
          <div id="high-runner-model-list" class="model-picker-menu" role="listbox"
               aria-label="Compatible high-runner models" data-model-menu hidden>
            <div class="model-picker-menu-head">
              <span>Available models</span>
              <span data-model-count><?= count($highRunnerModelOptions) ?> models</span>
            </div>
            <div class="model-picker-items">
              <button type="button" id="high-runner-model-option-all" class="model-picker-option"
                      role="option" aria-selected="<?= $highRunnerModel === '' ? 'true' : 'false' ?>"
                      data-model-option data-value="" tabindex="-1">
                <span>All compatible models</span>
                <span class="model-picker-option-check" aria-hidden="true">✓</span>
              </button>
              <?php foreach ($highRunnerModelOptions as $optionIndex => $modelLabel): ?>
                <button type="button" id="high-runner-model-option-<?= md5($optionIndex) ?>"
                        class="model-picker-option" role="option" tabindex="-1"
                        aria-selected="<?= $highRunnerModel === $modelLabel ? 'true' : 'false' ?>"
                        data-model-option data-value="<?= h($modelLabel) ?>">
                  <span><?= h($modelLabel) ?></span>
                  <span class="model-picker-option-check" aria-hidden="true">✓</span>
                </button>
              <?php endforeach; ?>
              <p class="model-picker-empty" data-model-empty hidden>No matching models.</p>
            </div>
            <div class="model-picker-menu-foot">Select a model, then click Apply</div>
          </div>
          <span class="model-picker-help" data-model-feedback role="status" aria-live="polite"></span>
        </div>
        <button class="btn small high-runner-model-apply" type="submit">Apply</button>
      <?php endif; ?>
      <noscript><button class="btn small" type="submit">Apply</button></noscript>
    </form>
  </div>
  <?php if ($highRunnerMatchCount > 0): ?>
    <p class="muted">
      <?= $highRunnerMatchCount ?> high-runner target<?= $highRunnerMatchCount === 1 ? '' : 's' ?> listed first.
      The selected order applies within each priority group.
    </p>
  <?php endif; ?>
  <?php if ($totalPages > 1): ?>
    <p class="muted">Showing <?= $rangeStart ?>–<?= $rangeEnd ?> of <?= $matchCnt ?> matches.</p>
  <?php endif; ?>

  <?php if ($totalPages > 1): ?>
    <nav class="scan-pager scan-pager-top" aria-label="Match result pages">
      <span class="scan-pager-side">
        <?php if ($page > 1): ?>
          <a class="btn small" href="<?= h($scanPageUrl(1)) ?>">First</a>
          <a class="btn small" href="<?= h($scanPageUrl($page - 1)) ?>">Previous</a>
        <?php endif; ?>
      </span>
      <span class="muted">Page <?= $page ?> of <?= $totalPages ?></span>
      <span class="scan-pager-side scan-pager-side-right">
        <?php if ($page < $totalPages): ?>
          <a class="btn small" href="<?= h($scanPageUrl($page + 1)) ?>">Next</a>
          <a class="btn small" href="<?= h($scanPageUrl($totalPages)) ?>">Last</a>
        <?php endif; ?>
      </span>
    </nav>
  <?php endif; ?>

  <div class="table-scroll scan-results-table" role="region"
       aria-label="Conversion matches" tabindex="0">
  <table class="data scan-results-table-data">
    <thead><tr>
      <th>Lot</th>
      <th>Part No</th>
      <th>Circuit</th>
      <th>Material</th>
      <th>Size</th>
      <th>Color</th>
      <th>Required (mm)</th>
      <th>Surplus (mm)</th>
      <th>Surplus Use</th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($pageMatches as $r):
      $surplus     = (float)$r['surplus_mm'];
      $excludeIds  = $refRow ? [(int)$refRow['stock_id'], (int)$r['stock_id']]
                             : [(int)$r['stock_id']];
      $surplusReuses = $surplusReusesFor($surplus, $excludeIds);
      $bestReuse     = $surplusReuses[0] ?? null;
      $scrapLen      = $bestReuse
                        ? $surplus - (float)$bestReuse['length_mm']
                        : $surplus;
      $targetModel      = $modelForMatch($r);
      $targetHighRunner = high_runner_label($r['part_no'] ?? '');
      $conversionFormId = 'convert-form-' . (int)$r['stock_id'];
      $surplusDialogId = 'surplus-options-' . (int)$r['stock_id'];
      $surplusDialogTitleId = $surplusDialogId . '-title';
    ?>
      <tr>
        <td><b><?= h($r['lot_no'] ?: '—') ?></b></td>
        <td>
          <b><?= h($r['part_no'] ?: '—') ?></b>
          <?php if ($targetModel !== ''): ?>
            <div class="match-model"><?= h($targetModel) ?></div>
          <?php endif; ?>
          <?php if ($targetHighRunner !== null): ?>
            <div><span class="badge high-runner" title="Listed in high_runner.txt">
              High runner<?= $targetHighRunner !== '' ? ' · ' . h($targetHighRunner) : '' ?>
            </span></div>
          <?php endif; ?>
        </td>
        <td><code><?= h($r['circuit_number'] ?: '—') ?></code></td>
        <td><code><?= h($r['material_code'] ?: '—') ?></code></td>
        <td><?= h($r['size'] ?: '—') ?></td>
        <td><?= h($r['color'] ?: '—') ?></td>
        <td><b><?= number_format((float)$r['length_mm'], 0) ?></b></td>
        <td><?= number_format($surplus, 0) ?></td>
        <td class="surplus-cell">
          <?php if ($bestReuse && $refRow): ?>
            <button type="button" class="btn small surplus-convert-btn"
                    data-surplus-dialog-trigger="<?= h($surplusDialogId) ?>"
                    aria-controls="<?= h($surplusDialogId) ?>"
                    aria-expanded="false" aria-haspopup="dialog">
              Use surplus
            </button>

            <dialog id="<?= h($surplusDialogId) ?>" class="surplus-options-dialog"
                    aria-labelledby="<?= h($surplusDialogTitleId) ?>">
              <div class="surplus-options-card">
                <div class="surplus-options-heading">
                  <div>
                    <span class="surplus-options-eyebrow">Compatible surplus cuts</span>
                    <h3 id="<?= h($surplusDialogTitleId) ?>">
                      Use <?= number_format($surplus, 0) ?> mm surplus
                    </h3>
                    <p>
                      Choose what to make from the remaining wire after
                      <b><?= h($r['part_no']) ?></b> / <code><?= h($r['circuit_number']) ?></code>.
                    </p>
                  </div>
                  <button type="button" class="surplus-options-close"
                          data-surplus-dialog-close aria-label="Close surplus options">×</button>
                </div>

                <div class="surplus-options-list">
                  <?php foreach ($surplusReuses as $reuse):
                    $reuseLength = (float)$reuse['length_mm'];
                    $reuseScrap = max(0.0, $surplus - $reuseLength);
                    $reuseHighRunner = high_runner_label($reuse['part_no'] ?? '');
                    $reuseActionLabel = sprintf(
                        'Convert with surplus for %s / %s (%s mm); %s mm scrap remains.',
                        (string)$reuse['part_no'],
                        (string)$reuse['circuit_number'],
                        number_format($reuseLength, 0),
                        number_format($reuseScrap, 0)
                    );
                  ?>
                    <article class="surplus-option-card">
                      <div class="surplus-option-copy">
                        <b><?= h($reuse['part_no']) ?></b>
                        <code><?= h($reuse['circuit_number']) ?></code>
                        <span><?= number_format($reuseLength, 0) ?> mm</span>
                        <?php if ($reuseHighRunner !== null): ?>
                          <span class="badge high-runner" title="Listed in high_runner.txt">
                            High runner<?= $reuseHighRunner !== '' ? ' · ' . h($reuseHighRunner) : '' ?>
                          </span>
                        <?php endif; ?>
                      </div>
                      <p>
                        Uses <?= number_format($reuseLength, 0) ?> mm ·
                        Scrap after this cut: <b><?= number_format($reuseScrap, 0) ?> mm</b>
                      </p>
                      <button type="submit" form="<?= h($conversionFormId) ?>"
                              name="surplus_target_id" value="<?= (int)$reuse['stock_id'] ?>"
                              class="btn approve small surplus-option-convert"
                              title="<?= h($reuseActionLabel) ?>"
                              data-reuse-part="<?= h($reuse['part_no']) ?>"
                              data-reuse-circuit="<?= h($reuse['circuit_number']) ?>"
                              data-reuse-len="<?= h(number_format($reuseLength, 0)) ?>"
                              data-scrap-len="<?= h(number_format($reuseScrap, 0)) ?>">
                        Convert with this use
                      </button>
                    </article>
                  <?php endforeach; ?>
                </div>
              </div>
            </dialog>
          <?php elseif (!$bestReuse): ?>
            <span class="scrap-tag">SCRAP <?= number_format($surplus, 0) ?> mm</span>
          <?php endif; ?>
        </td>
        <td class="actions-cell">
          <?php if ($refRow): ?>
            <form id="<?= h($conversionFormId) ?>" method="post" action="actions.php" class="convert-form"
                  data-src-lot="<?= h($srcLot) ?>"
                  data-src-seq="<?= h($srcSeq) ?>"
                  data-src-part="<?= h($srcPart) ?>"
                  data-src-circuit="<?= h($srcCircuit) ?>"
                  data-src-status="<?= h($srcStatus) ?>"
                  data-src-len="<?= h(number_format((float)$srcLen, 0)) ?>"
                  data-tgt-part="<?= h($r['part_no']) ?>"
                  data-tgt-circuit="<?= h($r['circuit_number']) ?>"
                  data-tgt-len="<?= h(number_format((float)$r['length_mm'], 0)) ?>"
                  data-surplus="<?= h(number_format($surplus, 0)) ?>"
                  data-reuse-part="<?= $bestReuse ? h($bestReuse['part_no']) : '' ?>"
                  data-reuse-circuit="<?= $bestReuse ? h($bestReuse['circuit_number']) : '' ?>"
                  data-reuse-len="<?= $bestReuse ? h(number_format((float)$bestReuse['length_mm'], 0)) : '0' ?>"
                  data-scrap-len="<?= h(number_format($scrapLen, 0)) ?>">
              <input type="hidden" name="action" value="mark_used">
              <input type="hidden" name="scanned_id" value="<?= (int)$refRow['stock_id'] ?>">
              <input type="hidden" name="target_id"  value="<?= (int)$r['stock_id'] ?>">
              <input type="hidden" name="raw_scanned" value="<?= h($rawScan) ?>">
              <button class="btn approve small">Convert here</button>
            </form>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

</section>

<script>
// Progressive enhancement: PHP's native select remains usable without JavaScript.
document.addEventListener('DOMContentLoaded', function () {
  const picker = document.querySelector('[data-model-picker]');
  if (!picker) return;

  const form = picker.closest('form');
  const fallback = form.querySelector('.model-picker-fallback');
  const selectedField = picker.querySelector('[data-model-value]');
  const search = picker.querySelector('[data-model-search]');
  const menu = picker.querySelector('[data-model-menu]');
  const toggle = picker.querySelector('[data-model-toggle]');
  const clear = picker.querySelector('[data-model-clear]');
  const count = picker.querySelector('[data-model-count]');
  const empty = picker.querySelector('[data-model-empty]');
  const feedback = picker.querySelector('[data-model-feedback]');
  const options = Array.from(picker.querySelectorAll('[data-model-option]'));
  const total = options.length - 1;
  let active = -1;

  function visibleOptions() { return options.filter(option => !option.hidden); }
  function markActive(index) {
    const visible = visibleOptions();
    options.forEach(option => option.classList.remove('is-highlighted'));
    active = visible.length ? (index + visible.length) % visible.length : -1;
    if (active < 0) {
      search.removeAttribute('aria-activedescendant');
      return;
    }
    const item = visible[active];
    item.classList.add('is-highlighted');
    search.setAttribute('aria-activedescendant', item.id);
    item.scrollIntoView({ block: 'nearest' });
  }
  function filterModels(query) {
    const term = query.trim().toLocaleLowerCase();
    let visibleCount = 0;
    options.forEach(option => {
      const model = option.dataset.value || '';
      const show = term === '' || (model !== '' && model.toLocaleLowerCase().includes(term));
      option.hidden = !show;
      if (show && model !== '') visibleCount++;
    });
    empty.hidden = visibleCount !== 0 || term === '';
    count.textContent = term ? visibleCount + ' of ' + total : total + ' models';
    options.forEach(option => option.classList.remove('is-highlighted'));
    active = -1;
    search.removeAttribute('aria-activedescendant');
  }
  function syncSelection() {
    options.forEach(option => {
      option.setAttribute('aria-selected', String(option.dataset.value === selectedField.value));
    });
    clear.hidden = !selectedField.value;
  }
  function openMenu(showAll) {
    menu.hidden = false;
    search.setAttribute('aria-expanded', 'true');
    toggle.setAttribute('aria-expanded', 'true');
    filterModels(showAll ? '' : search.value);
    feedback.textContent = '';
  }
  function closeMenu(restoreInput) {
    menu.hidden = true;
    search.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-expanded', 'false');
    search.removeAttribute('aria-activedescendant');
    active = -1;
    if (restoreInput) search.value = selectedField.value;
  }
  function selectModel(model) {
    selectedField.value = model;
    search.value = model;
    syncSelection();
    closeMenu(false);
    feedback.textContent = '';
  }

  // Only enable the custom picker after all controls are initialized.
  fallback.disabled = true;
  fallback.hidden = true;
  selectedField.disabled = false;
  picker.hidden = false;
  syncSelection();

  search.addEventListener('focus', function () {
    if (menu.hidden) openMenu(true);
  });
  search.addEventListener('click', function () {
    if (menu.hidden) openMenu(true);
    search.select();
  });
  search.addEventListener('input', function () {
    openMenu(false);
  });
  search.addEventListener('keydown', function (event) {
    const visible = visibleOptions();
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (menu.hidden) openMenu(true);
      const choices = visibleOptions();
      if (choices.length) {
        const step = event.key === 'ArrowDown' ? 1 : -1;
        markActive(active < 0 ? (step === 1 ? 0 : choices.length - 1) : active + step);
      }
    } else if (event.key === 'Enter' && !menu.hidden) {
      event.preventDefault();
      if (active >= 0 && visible[active]) selectModel(visible[active].dataset.value || '');
      else if (visible.length === 1) selectModel(visible[0].dataset.value || '');
      else feedback.textContent = 'Choose a model from the list or click Apply.';
    } else if (event.key === 'Escape') {
      if (!menu.hidden) { event.preventDefault(); closeMenu(true); }
    } else if (event.key === 'Tab') {
      closeMenu(true);
    }
  });
  options.forEach(option => {
    option.addEventListener('click', function () { selectModel(option.dataset.value || ''); });
    option.addEventListener('mousemove', function () {
      const index = visibleOptions().indexOf(option);
      if (index >= 0 && index !== active) markActive(index);
    });
  });
  toggle.addEventListener('click', function () {
    if (!menu.hidden) closeMenu(true);
    else { search.focus(); openMenu(true); }
  });
  clear.addEventListener('click', function () {
    selectModel('');
    search.focus();
    openMenu(true);
  });
  document.addEventListener('pointerdown', function (event) {
    if (!picker.contains(event.target)) closeMenu(true);
  });
  form.addEventListener('submit', function (event) {
    const typed = search.value.trim();
    if (typed === selectedField.value) return;
    if (!typed) { selectedField.value = ''; return; }
    const exact = options.find(option => option.dataset.value.toLocaleLowerCase() === typed.toLocaleLowerCase());
    if (exact) { selectedField.value = exact.dataset.value; return; }
    event.preventDefault();
    openMenu(false);
    feedback.textContent = 'Select a listed model before applying.';
    search.focus();
  });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-surplus-dialog-trigger]').forEach(function (trigger) {
    var dialog = document.getElementById(trigger.dataset.surplusDialogTrigger || '');
    if (!dialog) return;

    function closeDialog() {
      if (typeof dialog.close === 'function' && dialog.open) dialog.close();
      else dialog.removeAttribute('open');
      trigger.setAttribute('aria-expanded', 'false');
    }
    function openDialog() {
      if (typeof dialog.showModal === 'function') {
        if (!dialog.open) dialog.showModal();
      } else {
        dialog.setAttribute('open', '');
      }
      trigger.setAttribute('aria-expanded', 'true');
    }

    trigger.addEventListener('click', openDialog);
    dialog.querySelectorAll('[data-surplus-dialog-close]').forEach(function (closeButton) {
      closeButton.addEventListener('click', closeDialog);
    });
    dialog.addEventListener('close', function () {
      trigger.setAttribute('aria-expanded', 'false');
    });
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) closeDialog();
    });
  });

  document.querySelectorAll('form.convert-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var d = form.dataset;
      var submitter = e.submitter || null;
      var reusePart = submitter && submitter.dataset.reusePart
        ? submitter.dataset.reusePart : d.reusePart;
      var reuseCircuit = submitter && submitter.dataset.reuseCircuit
        ? submitter.dataset.reuseCircuit : d.reuseCircuit;
      var reuseLength = submitter && submitter.dataset.reuseLen
        ? submitter.dataset.reuseLen : d.reuseLen;
      var scrapLength = submitter && submitter.dataset.scrapLen
        ? submitter.dataset.scrapLen : d.scrapLen;
      var lines = [];
      lines.push('Please PHYSICALLY VERIFY the wire in your hand.');
      lines.push('');
      lines.push('SOURCE:');
      lines.push('   Lot       : ' + (d.srcLot || '(unknown)'));
      lines.push('   Seq       : ' + (d.srcSeq || '(unknown)'));
      if (d.srcPart)    lines.push('   Part No   : ' + d.srcPart);
      if (d.srcCircuit) lines.push('   Circuit   : ' + d.srcCircuit);
      if (d.srcLen)     lines.push('   Length    : ' + d.srcLen + ' mm');
      if (d.srcStatus)  lines.push('   DB Status : ' + d.srcStatus);
      lines.push('');
      lines.push('TARGET:');
      lines.push('   Part No   : ' + (d.tgtPart || ''));
      lines.push('   Circuit   : ' + (d.tgtCircuit || ''));
      lines.push('   Length    : ' + (d.tgtLen || '') + ' mm');
      lines.push('');
      lines.push('SURPLUS: ' + (d.surplus || '0') + ' mm');
      if (reusePart) {
        lines.push('   Reuse as  : ' + reusePart + ' / ' + reuseCircuit
                   + ' (' + reuseLength + ' mm)');
        lines.push('   Scrap     : ' + (scrapLength || '0') + ' mm');
      } else {
        lines.push('   No reuse  → SCRAP ' + (d.surplus || '0') + ' mm');
      }
      lines.push('');
      lines.push('Confirm?');
      if (!window.confirm(lines.join('\n'))) e.preventDefault();
    });
  });
});
</script>

<?php else: ?>
<section class="panel warn">
  <h3>No Matches</h3>
  <?php if ($highRunnerModel !== '' && $totalCnt > 0): ?>
    <p class="muted">
      No conversion target is listed under high-runner model
      <code><?= h($highRunnerModel) ?></code>.
    </p>
    <p class="muted">
      <a href="<?= h($scanPageUrl(1, true, false)) ?>">Clear high-runner model filter</a>
    </p>
  <?php elseif ($pnFilter !== '' && $totalCnt > 0): ?>
    <p class="muted">No part number contains <code><?= h($pnFilter) ?></code>.</p>
    <p class="muted">
      <a href="<?= h($scanPageUrl(1, false)) ?>">Clear filter</a>
    </p>
  <?php else: ?>
    <p class="muted">
      No reference row has material code <code><?= h($refMc) ?></code>
      with length ≤ <b><?= number_format($refLen, 0) ?> mm</b>.
    </p>
  <?php endif; ?>
</section>
<?php endif; ?>

  </section>
  <?php endif; ?>
</div>

<?php endif; ?>
