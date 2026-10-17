<?php
// tabs/inventory.php
$highRunnerReferences = high_runner_catalog();
ksort($highRunnerReferences, SORT_NATURAL | SORT_FLAG_CASE);
?>
<div class="two-col inventory-reference-layout">
<section class="panel">
  <h2>Add Reference Row</h2>

  <form method="post" action="actions.php" class="form-grid"
        style="max-width:720px">
    <input type="hidden" name="action" value="add_reference">
    <label>Lot No <input name="lot_no" required></label>
    <label>Seq No <input name="seq_no" required></label>
    <label>Part No <input name="part_no" required></label>
    <label>Model <input name="model_code" placeholder="e.g. MQ4 MAIN"></label>
    <label>Sub / Circuit No <input name="circuit_number" required></label>
    <label>Wire Kind <input name="wire_kind"></label>
    <label>Material Code <input name="material_code" required></label>
    <label>Size / Gauge <input name="size"></label>
    <label>Color <input name="color"></label>
    <label>Cutting Length (mm)
      <input name="length_mm" type="number" step="0.1"
             value="1000" required></label>
    <label>Machine No <input name="machine_number"></label>
    <button class="btn approve" type="submit">Save Reference Row</button>
  </form>
</section>

<section class="panel high-runner-panel">
  <h2>High Runner Reference</h2>
  <p class="muted">
    Add or update the part numbers that should be prioritized in conversion
    results. Changes take effect on the next page load.
  </p>

  <form method="post" action="actions.php" class="form-grid high-runner-form"
        style="max-width:720px">
    <input type="hidden" name="action" value="save_high_runner">
    <label>Part No
      <input name="high_runner_part_no" required placeholder="e.g. N91000-P4940-44">
    </label>
    <label>Model / Label
      <input name="high_runner_model_label" required placeholder="e.g. MQ4 MAIN">
    </label>
    <button class="btn approve" type="submit">Add / Update High Runner</button>
  </form>

  <h3>Current High Runner Parts (<?= count($highRunnerReferences) ?>)</h3>
  <?php if ($highRunnerReferences): ?>
    <div class="table-scroll high-runner-table" role="region"
         aria-label="Current high-runner references" tabindex="0">
      <table class="data">
        <thead><tr><th>Part No</th><th>Model / Label</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($highRunnerReferences as $partNo => $modelLabel): ?>
          <tr>
            <td><b><?= h($partNo) ?></b></td>
            <td><?= h($modelLabel ?: '—') ?></td>
            <td class="high-runner-actions">
              <form method="post" action="actions.php"
                    onsubmit="return confirm('Remove this high-runner reference?')">
                <input type="hidden" name="action" value="remove_high_runner">
                <input type="hidden" name="high_runner_part_no" value="<?= h($partNo) ?>">
                <button class="btn reject small" type="submit">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="muted">No high-runner references have been added.</p>
  <?php endif; ?>
</section>
</div>
