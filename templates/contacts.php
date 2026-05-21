<?php defined('ABSPATH') || exit; ?>
<div class="smshub-wrap">
  <div class="flex-between" style="margin-bottom:20px;">
    <h1 class="mb-0"><span class="dashicons dashicons-groups"></span> Contacts</h1>
    <div class="flex" style="gap:8px;">
      <button id="smshub-new-contact" class="smshub-btn smshub-btn-primary">+ Add Contact</button>
      <button class="smshub-btn smshub-btn-ghost" onclick="document.getElementById('smshub-import-modal').classList.add('open')">⬆ Import CSV</button>
    </div>
  </div>

  <div class="smshub-loader"><div class="smshub-loader-bar"></div></div>

  <!-- Filters -->
  <form method="get" class="flex" style="margin-bottom:16px;gap:10px;">
    <input type="hidden" name="page" value="smshub-contacts">
    <input type="text" name="s" class="smshub-input" style="max-width:220px;"
      placeholder="Search…" value="<?= esc_attr($_GET['s'] ?? '') ?>">
    <select name="group" class="smshub-select" style="max-width:180px;">
      <option value="">All Groups</option>
      <?php foreach ($groups as $g): ?>
        <option value="<?= esc_attr($g) ?>" <?= selected($_GET['group'] ?? '', $g, false) ?>><?= esc_html($g) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="smshub-btn smshub-btn-ghost smshub-btn-sm">Filter</button>
  </form>

  <div class="smshub-card">
    <?php if (empty($data['items'])): ?>
      <div class="smshub-empty">
        <div class="dashicons dashicons-groups"></div>
        <p>No contacts found. Add some or import a CSV.</p>
        <p style="font-size:12px;">CSV format: <code>name, phone, group</code></p>
      </div>
    <?php else: ?>
    <div class="smshub-table-wrap">
      <table class="smshub-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Phone</th>
            <th>Group</th>
            <th>Added</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data['items'] as $c): ?>
          <tr>
            <td><?= esc_html($c['name']) ?></td>
            <td class="smshub-mono"><?= esc_html($c['phone']) ?></td>
            <td><span class="badge badge-active"><?= esc_html($c['group_name']) ?></span></td>
            <td><?= esc_html(date('d M Y', strtotime($c['created_at']))) ?></td>
            <td>
              <button class="smshub-btn smshub-btn-danger smshub-btn-sm contact-delete" data-id="<?= (int)$c['id'] ?>">Remove</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="padding:12px 0 0;font-size:12px;color:var(--hub-muted);">
      Showing <?= count($data['items']) ?> of <?= $data['total'] ?> contacts
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Add Contact Modal -->
<div id="smshub-contact-modal" class="smshub-modal-overlay">
  <div class="smshub-modal">
    <div class="smshub-modal-header">
      <h2>Add Contact</h2>
      <button class="smshub-modal-close">✕</button>
    </div>
    <form id="smshub-contact-form">
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
      <button type="submit" class="smshub-btn smshub-btn-primary">Add Contact</button>
    </form>
  </div>
</div>

<!-- Import CSV Modal -->
<div id="smshub-import-modal" class="smshub-modal-overlay">
  <div class="smshub-modal">
    <div class="smshub-modal-header">
      <h2>Import Contacts (CSV)</h2>
      <button class="smshub-modal-close">✕</button>
    </div>
    <p style="color:var(--hub-muted);font-size:13px;">CSV must have headers: <code>name, phone, group</code> (group is optional)</p>
    <form id="smshub-import-form" enctype="multipart/form-data">
      <div class="smshub-form-group">
        <label>CSV File <span class="req">*</span></label>
        <input name="csv_file" class="smshub-input" type="file" accept=".csv" required style="padding:6px;">
      </div>
      <button type="submit" class="smshub-btn smshub-btn-success">Import</button>
    </form>
  </div>
</div>
