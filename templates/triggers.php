<?php defined('ABSPATH') || exit; ?>
<div class="smshub-wrap">
  <div class="flex-between" style="margin-bottom:20px;">
    <h1 class="mb-0"><span class="dashicons dashicons-admin-links"></span> Triggers</h1>
    <button id="smshub-new-trigger" class="smshub-btn smshub-btn-primary">+ New Trigger</button>
  </div>

  <div class="smshub-loader"><div class="smshub-loader-bar"></div></div>

  <div class="smshub-card">
    <?php if (empty($triggers)): ?>
      <div class="smshub-empty">
        <div class="dashicons dashicons-admin-links"></div>
        <p>No triggers yet. Create one to automatically send SMS on WordPress events.</p>
      </div>
    <?php else: ?>
    <div class="smshub-table-wrap">
      <table class="smshub-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Event</th>
            <th>Provider</th>
            <th>Recipients</th>
            <th>Active</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($triggers as $t): ?>
          <tr>
            <td><strong><?= esc_html($t['name']) ?></strong></td>
            <td><code class="smshub-mono"><?= esc_html($events[$t['event']] ?? $t['event']) ?></code></td>
            <td><?= esc_html($t['provider'] ?: 'Active') ?></td>
            <td class="smshub-truncate" title="<?= esc_attr($t['recipients']) ?>"><?= esc_html($t['recipients']) ?></td>
            <td>
              <label class="smshub-toggle">
                <input type="checkbox" class="trigger-toggle" data-id="<?= (int)$t['id'] ?>" <?= checked($t['active'], 1, false) ?>>
                <span class="smshub-toggle-slider"></span>
              </label>
            </td>
            <td class="flex" style="gap:6px;">
              <button class="smshub-btn smshub-btn-ghost smshub-btn-sm trigger-edit"
                data-trigger='<?= wp_json_encode($t) ?>'>Edit</button>
              <button class="smshub-btn smshub-btn-danger smshub-btn-sm trigger-delete" data-id="<?= (int)$t['id'] ?>">Delete</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="smshub-card" style="margin-top:20px;">
    <h3 style="margin:0 0 10px;font-size:13px;font-weight:700;">📖 Template Variables</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;font-size:12px;">
      <?php foreach (['{site_name}','{site_url}','{date}','{time}','{order_id}','{order_total}','{order_status}','{customer_name}','{customer_phone}','{customer_email}','{user_name}','{user_email}','{user_phone}'] as $v): ?>
        <code class="smshub-mono" style="background:#12141e;padding:4px 8px;border-radius:5px;"><?= esc_html($v) ?></code>
      <?php endforeach; ?>
    </div>
    <p style="font-size:12px;color:var(--hub-muted);margin:8px 0 0;">For <strong>recipients</strong>: use phone numbers, <code>{customer_phone}</code>, <code>{user_phone}</code>, <code>admin</code>, or <code>group:GroupName</code></p>
  </div>
</div>

<!-- Trigger Modal -->
<div id="smshub-trigger-modal" class="smshub-modal-overlay">
  <div class="smshub-modal">
    <div class="smshub-modal-header">
      <h2>New Trigger</h2>
      <button class="smshub-modal-close">✕</button>
    </div>
    <form id="smshub-trigger-form">
      <input type="hidden" name="trigger_id">
      <div class="smshub-form-group">
        <label>Trigger Name <span class="req">*</span></label>
        <input name="name" class="smshub-input" type="text" placeholder="e.g. Order Completed to Customer" required>
      </div>
      <div class="smshub-cols">
        <div class="smshub-form-group">
          <label>WordPress Event <span class="req">*</span></label>
          <select name="event" class="smshub-select" required>
            <option value="">— Select Event —</option>
            <?php foreach ($events as $ev => $label): ?>
              <option value="<?= esc_attr($ev) ?>"><?= esc_html($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="smshub-form-group">
          <label>Provider (blank = active)</label>
          <select name="provider" class="smshub-select">
            <option value="">— Use Active Provider —</option>
            <?php foreach ($providers as $key => $p): ?>
              <option value="<?= esc_attr($key) ?>"><?= esc_html($p->get_label()) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="smshub-form-group">
        <label>Recipients <span class="req">*</span></label>
        <input name="recipients" class="smshub-input" type="text"
          placeholder="{customer_phone}, admin, group:VIPs, +2348012345678" required>
      </div>
      <div class="smshub-form-group">
        <label>Sender ID Override</label>
        <input name="sender_id" class="smshub-input" type="text" placeholder="Leave blank for default">
      </div>
      <div class="smshub-form-group">
        <label>Message Template <span class="req">*</span></label>
        <textarea name="message_tpl" class="smshub-textarea smshub-sms-body" required
          placeholder="Hi {customer_name}, your order #{order_id} is {order_status}. Total: {order_total}"></textarea>
        <div class="char-counter">0 chars / 1 SMS</div>
      </div>
      <div class="smshub-form-group flex">
        <label class="smshub-toggle" style="margin-right:10px;">
          <input type="checkbox" name="active" value="1" checked>
          <span class="smshub-toggle-slider"></span>
        </label>
        <span style="font-size:13px;">Active</span>
      </div>
      <button type="submit" class="smshub-btn smshub-btn-primary" style="margin-top:8px;">Save Trigger</button>
    </form>
  </div>
</div>
