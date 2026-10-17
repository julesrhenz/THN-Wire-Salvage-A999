<?php // tabs/guide.php ?>
<section class="panel guide">

  <h2>How WireSalvage AI Works</h2>

  <div class="guide-toc">
    <a href="#g-flow">Flow</a>
    <a href="#g-tabs">Tabs</a>
    <a href="#g-columns">Upload Columns</a>
    <a href="#g-uploads">Upload Notes</a>
    <a href="#g-match">Match Rules</a>
    <a href="#g-surplus">Surplus Rules</a>
    <a href="#g-convert">Convert Rules</a>
    <a href="#g-pending">Pending Lot</a>
    <a href="#g-kpi">KPIs &amp; Danger Zone</a>
    <a href="#g-roles">Roles</a>
    <a href="#g-status">Status Meanings</a>
    <a href="#g-faq">FAQ</a>
  </div>

  <h3 id="g-flow">Flow</h3>

  <div class="flowchart">
    <div class="flow-step">
      <div class="flow-num">1</div>
      <div class="flow-body">
        <h4>Scan the wire</h4>
        <p>Paste or scan the barcode on the obsolete wire.</p>
        <code>Example: VQA01053 0014 N91000-P4940-44</code>
      </div>
    </div>
    <div class="flow-arrow">↓</div>

    <div class="flow-step">
      <div class="flow-num">2</div>
      <div class="flow-body">
        <h4>System identifies the wire</h4>
        <p>It reads these from the reference database:</p>
        <ul>
          <li><b>Material code</b> (e.g. <code>1CI0035100</code>)</li>
          <li><b>Cutting length</b> (e.g. <code>1290 mm</code>)</li>
          <li>Lot + sequence number</li>
        </ul>
      </div>
    </div>
    <div class="flow-arrow">↓</div>

    <div class="flow-step highlight">
      <div class="flow-num">3</div>
      <div class="flow-body">
        <h4>System finds matching parts</h4>
        <p>It searches the whole reference database for rows where
           <b>both</b> rules are true:</p>
        <div class="rule-grid">
          <div class="rule-card">
            <div>
              <b>Same material code</b>
              <p class="muted">Exact match — no substitutes.</p>
            </div>
          </div>
          <div class="rule-card">
            <div>
              <b>Required length ≤ scanned length</b>
              <p class="muted">Can be cut shorter.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="flow-arrow">↓</div>

    <div class="flow-step">
      <div class="flow-num">4</div>
      <div class="flow-body">
        <h4>Review the list</h4>
        <p>Every row shows the Lot, Part No, Circuit, Required length,
           and the leftover (surplus).</p>
        <p class="muted">Use <b>Search Within Results</b> to filter by
           part number.</p>
      </div>
    </div>
    <div class="flow-arrow">↓</div>

    <div class="flow-step highlight">
      <div class="flow-num">5</div>
      <div class="flow-body">
        <h4>Convert here</h4>
        <p>Click <b>Convert here</b> on the row you want. A confirmation
           popup shows the source wire and the surplus plan for physical
           verification.</p>
      </div>
    </div>
    <div class="flow-arrow">↓</div>

    <div class="flow-step highlight">
      <div class="flow-num">6</div>
      <div class="flow-body">
        <h4>Enter the new lot number later</h4>
        <p>The conversion is queued in <b>Pending Lot Entry</b>. When
           the factory assigns the new lot number, open that tab and type
           it in — the row then moves to Completed.</p>
      </div>
    </div>
    <div class="flow-arrow">↓</div>

    <div class="flow-step final">
      <div class="flow-num">✔</div>
      <div class="flow-body">
        <h4>Done</h4>
        <p>Full history preserved — old wire, target part, new lot,
           surplus reuse, and scrap.</p>
      </div>
    </div>
  </div>

  <h3 id="g-tabs">Tabs</h3>

  <table class="data">
    <thead><tr>
      <th>Tab</th><th>Purpose</th><th>Who can use it</th>
    </tr></thead>
    <tbody>
      <tr><td><b>Scan Obsolete Wire</b></td>
          <td>Scan a barcode → see every part it can become.</td>
          <td>Admin · User</td></tr>
      <tr><td><b>Pending Lot Entry</b></td>
          <td>Enter the new lot number after the factory assigns it.</td>
          <td>Admin · User</td></tr>
      <tr><td><b>Upload Reference DB</b></td>
          <td>Import the master Excel list of every wire spec.</td>
          <td>Admin only</td></tr>
      <tr><td><b>Add Reference Row</b></td>
          <td>Manually add a single wire spec.</td>
          <td>Admin only</td></tr>
      <tr><td><b>History &amp; Clear Data</b></td>
          <td>KPIs, audit logs, and the Danger Zone.</td>
          <td>Admin only</td></tr>
      <tr><td><b>Guide</b></td>
          <td>This page.</td>
          <td>Admin · User</td></tr>
    </tbody>
  </table>

  <h3 id="g-columns">Upload Column Map</h3>

  <p class="muted">
    The importer reads the cutting length from <b>column O</b> and these
    fields from their fixed positions:
  </p>

  <table class="data">
    <thead><tr><th>Excel Column</th><th>Index</th><th>Field</th></tr></thead>
    <tbody>
      <tr><td>C</td><td>2</td><td>Lot No</td></tr>
      <tr><td>D</td><td>3</td><td>Part No</td></tr>
      <tr><td>F</td><td>5</td><td>Circuit Number</td></tr>
      <tr><td>K</td><td>10</td><td>Wire Kind</td></tr>
      <tr><td>L</td><td>11</td><td>Material Code</td></tr>
      <tr><td>M</td><td>12</td><td>Size / Gauge</td></tr>
      <tr><td>N</td><td>13</td><td>Color</td></tr>
      <tr><td><b>O</b></td><td>14</td><td><b>Cutting Length (mm)</b></td></tr>
      <tr><td>AA</td><td>26</td><td>Machine No</td></tr>
      <tr><td>AB</td><td>27</td><td>Seq No</td></tr>
    </tbody>
  </table>

  <p class="muted">
    <b>Unique key</b> = Part No + Circuit + Lot. Re-uploading the same
    file <b>never overwrites</b> old rows — existing rows are kept, only
    new unique keys are appended.
  </p>

  <h3 id="g-uploads">Upload Notes</h3>

  <ul>
    <li>Accepts <code>.csv</code>, <code>.xls</code>, <code>.xlsx</code>
        — Excel only if PhpSpreadsheet is installed.</li>
    <li>Every data row is imported. Missing values are stored as blank.</li>
    <li>Rows with an empty cutting length land in the DB with
        <code>length_mm = 0</code> and won't match any scan until fixed.</li>
  </ul>

  <h3 id="g-match">Match Rules</h3>

  <div class="rule-summary">
    <div class="rule-summary-item">
      <span class="check">✓</span>
      <div>
        <b>Same material code</b>
        <p>Exact match, case-insensitive, whitespace-trimmed.</p>
      </div>
    </div>
    <div class="rule-summary-item">
      <span class="check">✓</span>
      <div>
        <b>Target length ≤ scanned length</b>
        <p>The wire can be cut shorter to fit the target.</p>
      </div>
    </div>
    <div class="rule-summary-item">
      <span class="cross">✗</span>
      <div>
        <b>Wire kind, size, color</b>
        <p>Not filters. They only appear as notes on each row.</p>
      </div>
    </div>
    <div class="rule-summary-item">
      <span class="cross">✗</span>
      <div>
        <b>Never reused</b>
        <p>Converted wires are marked <code>CONVERTED</code> and can't
           appear in matches again.</p>
      </div>
    </div>
  </div>

  <h3 id="g-surplus">Surplus Rules</h3>

  <p class="muted">
    After converting, the remaining leftover is called the <b>surplus</b>.
    The system checks whether the surplus itself can be reused for
    another part:
  </p>

  <ul>
    <li><b>Surplus Use</b> column shows the best-fit part the leftover
        can become (green arrow) — plus up to two alternate options.</li>
    <li><b>Scrap</b> column shows the remaining leftover after the
        secondary cut, in orange. This is the part that becomes waste.</li>
    <li>If no part fits the surplus, the whole surplus is shown as
        <span class="scrap-tag">SCRAP</span>.</li>
  </ul>

  <h3 id="g-convert">Convert Rules</h3>

  <ul>
    <li>Click <b>Convert here</b> on any match row.</li>
    <li>A confirmation popup shows the source wire and surplus plan —
        <b>physically verify</b> against the wire in hand.</li>
    <li>The scanned wire is marked <code>CONVERTED</code> immediately
        and never appears in matches again.</li>
    <li>Conversion is <b>final</b> — undo requires restoring from a
        backup of <code>data/wiresalvage.db</code>.</li>
  </ul>

  <h3 id="g-pending">Pending Lot Entry</h3>

  <ul>
    <li>Every conversion starts as <b>PENDING_LOT</b>.</li>
    <li>When the factory assigns the new lot number, open this tab and
        type it into the row.</li>
    <li>Saving moves the row to <b>Completed</b> and records who entered
        it and when.</li>
    <li>Only <b>PENDING_LOT</b> rows can be edited. Completed rows are
        locked.</li>
  </ul>

  <h3 id="g-kpi">KPIs &amp; Danger Zone</h3>

  <p class="muted"><b>KPIs</b> on the History tab:</p>
  <ul>
    <li><b>Total Decided</b> — every "Convert here" click.</li>
    <li><b>Used</b> — records with status <code>USED</code>.</li>
    <li><b>Usage Rate</b> = Used ÷ Total.</li>
  </ul>

  <p class="muted"><b>Danger Zone</b> wipes entire tables:</p>
  <ul>
    <li><b>Reference DB</b> — every uploaded wire spec.</li>
    <li><b>Usage Decisions</b> — the salvage_recommendations table.</li>
    <li><b>Scan Audit Logs</b> — every scan event.</li>
    <li><b>Clear EVERYTHING</b> — all tables at once.</li>
  </ul>

  <p class="muted">
    Each clear requires typing <code>DELETE</code> exactly and confirming
    a browser dialog. <b>Always back up</b>
    <code>data/wiresalvage.db</code> first.
  </p>

  <h3 id="g-roles">Roles</h3>

  <table class="data">
    <thead><tr><th>Role</th><th>Can access</th></tr></thead>
    <tbody>
      <tr><td><b>admin</b></td>
          <td>All tabs — scan, pending lot entry, upload, add reference
              row, history &amp; danger zone, guide.</td></tr>
      <tr><td><b>user</b></td>
          <td>Scan, pending lot entry, guide.</td></tr>
    </tbody>
  </table>

  <p class="muted">
    Default credentials: <code>admin / admin123</code> ·
    <code>user / user123</code>. Change these in <code>auth.php</code>
    before deploying.
  </p>

  <h3 id="g-status">Status Meanings</h3>

  <table class="data">
    <thead><tr><th>Status</th><th>Meaning</th></tr></thead>
    <tbody>
      <tr><td><span class="badge reference">REFERENCE</span></td>
          <td>Reference DB row — a known wire spec.</td></tr>
      <tr><td><span class="badge available">AVAILABLE</span></td>
          <td>Wire is physically in stock and can be converted.</td></tr>
      <tr><td><span class="badge converted">CONVERTED</span></td>
          <td>Wire was used; no longer matches anything.</td></tr>
      <tr><td><span class="badge consumed">CONSUMED</span></td>
          <td>Wire has been physically consumed.</td></tr>
      <tr><td><span class="badge on_hold">ON_HOLD</span></td>
          <td>Manually paused — kept in stock.</td></tr>
      <tr><td><span class="badge obsolete">OBSOLETE</span></td>
          <td>Manually retired; ignored by matching.</td></tr>
      <tr><td><span class="badge scrap">SCRAP</span></td>
          <td>Scrapped — ignored by matching.</td></tr>
    </tbody>
  </table>

  <h3 id="g-faq">FAQ</h3>

  <div class="faq-grid">
    <div class="faq-item">
      <b>Why did nothing match?</b>
      <p>Either the material code isn't in the reference DB, or every row
         with that code is longer than the wire you scanned.</p>
    </div>
    <div class="faq-item">
      <b>Can I undo a conversion?</b>
      <p>Only by restoring from a backup of
         <code>data/wiresalvage.db</code>. Conversion is final.</p>
    </div>
    <div class="faq-item">
      <b>What's "surplus"?</b>
      <p>The leftover after cutting to the required length. It may be
         reusable for another part, or scrapped.</p>
    </div>
    <div class="faq-item">
      <b>Why confirm before converting?</b>
      <p>To make you physically check the lot number on the wire in your
         hand. Prevents converting the wrong wire.</p>
    </div>
    <div class="faq-item">
      <b>When do I enter the new lot number?</b>
      <p>As soon as the factory assigns it. Open
         <b>Pending Lot Entry</b>, find the row, type the lot, save.</p>
    </div>
    <div class="faq-item">
      <b>Do I need to remove the wire from stock?</b>
      <p>No — the system marks it <code>CONVERTED</code> automatically,
         so it never shows in matches again.</p>
    </div>
  </div>

</section>