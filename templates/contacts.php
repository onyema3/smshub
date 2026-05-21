<?php defined('ABSPATH') || exit; ?>
<div class="smshub-wrap">
  <div class="flex-between" style="margin-bottom:24px;">
    <h1 class="mb-0"><span class="dashicons dashicons-groups"></span> Contacts</h1>
    <div class="flex" style="gap:10px;">
      <button id="smshub-new-contact" class="smshub-btn smshub-btn-primary">+ Add Contact</button>
      <button id="smshub-export-csv" class="smshub-btn smshub-btn-ghost">Export CSV</button>
      <button class="smshub-btn smshub-btn-ghost" onclick="document.getElementById('smshub-import-modal').classList.add('open')">Import CSV</button>
    </div>
  </div>

  <div class="smshub-loader"><div class="smshub-loader-bar"></div></div>

  <!-- Filters -->
  <form method="get" class="flex" style="margin-bottom:18px;gap:10px;">
    <input type="hidden" name="page" value="smshub-contacts">
    <input type="text" name="s" class="smshub-input" style="max-width:220px;"
      placeholder="Search..." value="<?= esc_attr($_GET['s'] ?? '') ?>">
    <select name="group" class="smshub-select" style="max-width:180px;" id="smshub-filter-group">
      <option value="">All Groups</option>
      <?php foreach ($groups as $g): ?>
        <option value="<?= esc_attr($g) ?>" <?= selected($_GET['group'] ?? '', $g, false) ?>><?= esc_html($g) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="smshub-btn smshub-btn-ghost smshub-btn-sm">Filter</button>
    <button type="button" id="smshub-bulk-delete" class="smshub-btn smshub-btn-danger smshub-btn-sm" style="display:none;">Delete Selected</button>
  </form>

  <div class="smshub-card">
    <?php if (empty($data['items'])): ?>
      <div class="smshub-empty">
        <div class="dashicons dashicons-groups"></div>
        <p>No contacts found. Add some or import a CSV.</p>
        <p style="font-size:12px;margin-top:8px;">CSV format: <code class="smshub-mono">name, phone, group</code></p>
      </div>
    <?php else: ?>
    <div class="smshub-table-wrap">
      <table class="smshub-table">
        <thead>
          <tr>
            <th><input type="checkbox" id="smshub-select-all"></th>
            <th>Name</th>
            <th>Phone</th>
            <th>Group</th>
            <th>Added</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data['items'] as $c): ?>
          <tr data-id="<?= (int)$c['id'] ?>">
            <td><input type="checkbox" class="contact-check" value="<?= (int)$c['id'] ?>"></td>
            <td style="font-weight:500;color:var(--hub-text);"><?= esc_html($c['name']) ?></td>
            <td class="smshub-mono"><?= esc_html($c['phone']) ?></td>
            <td><span class="badge badge-active"><?= esc_html($c['group_name']) ?></span></td>
            <td style="font-size:12px;color:var(--hub-muted);"><?= esc_html(date('d M Y', strtotime($c['created_at']))) ?></td>
            <td class="flex" style="gap:6px;">
              <button class="smshub-btn smshub-btn-ghost smshub-btn-sm contact-edit"
                data-contact='<?= wp_json_encode($c) ?>'>Edit</button>
              <button class="smshub-btn smshub-btn-danger smshub-btn-sm contact-delete" data-id="<?= (int)$c['id'] ?>">Remove</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="smshub-pagination">
      <span>Showing <?= count($data['items']) ?> of <?= $data['total'] ?> contacts</span>
      <?php
        $offset = (int)($_GET['offset'] ?? 0);
        $per    = 50;
        $total  = $data['total'];
        if ($total > $per):
      ?>
      <a href="?page=smshub-contacts&offset=<?= max(0, $offset-$per) ?>&group=<?= esc_attr($_GET['group'] ?? '') ?>" class="smshub-btn smshub-btn-ghost smshub-btn-sm" <?= $offset === 0 ? 'disabled' : '' ?>>Prev</a>
      <a href="?page=smshub-contacts&offset=<?= $offset+$per ?>&group=<?= esc_attr($_GET['group'] ?? '') ?>" class="smshub-btn smshub-btn-ghost smshub-btn-sm" <?= ($offset+$per >= $total) ? 'disabled' : '' ?>>Next</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Add/Edit Contact Modal -->
<div id="smshub-contact-modal" class="smshub-modal-overlay">
  <div class="smshub-modal">
    <div class="smshub-modal-header">
      <h2>Add Contact</h2>
      <button class="smshub-modal-close">x</button>
    </div>
    <form id="smshub-contact-form">
      <input type="hidden" name="contact_id">
      <div class="smshub-form-group">
        <label>Name <span class="req">*</span></label>
        <input name="contact_name" class="smshub-input" type="text" required placeholder="Full name">
      </div>
      <div class="smshub-form-group">
        <label>Phone <span class="req">*</span></label>
        <input name="contact_phone" class="smshub-input" type="text" required placeholder="+2348012345678">
      </div>
      <div class="smshub-form-group">
        <label>Group</label>
        <input name="contact_group" class="smshub-input" type="text" placeholder="Default" list="smshub-groups">
        <datalist id="smshub-groups">
          <?php foreach ($groups as $g): ?><option value="<?= esc_attr($g) ?>"><?php endforeach; ?>
        </datalist>
      </div>
      <button type="submit" class="smshub-btn smshub-btn-primary" style="width:100%;">Save Contact</button>
    </form>
  </div>
</div>

<!-- Import CSV Modal -->
<div id="smshub-import-modal" class="smshub-modal-overlay">
  <div class="smshub-modal">
    <div class="smshub-modal-header">
      <h2>Import Contacts (CSV)</h2>
      <button class="smshub-modal-close">x</button>
    </div>
    <p style="color:var(--hub-text-secondary);font-size:13px;margin-bottom:18px;">CSV must have headers: <code class="smshub-mono">name, phone, group</code> (group is optional)</p>
    <form id="smshub-import-form" enctype="multipart/form-data">
      <div class="smshub-form-group">
        <label>CSV File <span class="req">*</span></label>
        <input name="csv_file" class="smshub-input" type="file" accept=".csv" required style="padding:8px;">
      </div>
      <button type="submit" class="smshub-btn smshub-btn-success" style="width:100%;">Import</button>
    </form>
  </div>
</div>
