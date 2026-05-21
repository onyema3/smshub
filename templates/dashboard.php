<?php defined('ABSPATH') || exit; ?>
<div class="smshub-wrap">
  <h1><span class="dashicons dashicons-smartphone"></span> WP SMS Hub</h1>

  <div class="smshub-loader"><div class="smshub-loader-bar"></div></div>
  <div id="smshub-send-alert" class="smshub-alert"></div>

  <!-- Stats -->
  <div class="smshub-grid">
    <div class="smshub-card">
      <h3>Total Sent</h3>
      <div class="stat"><?= number_format($stats['total']) ?></div>
    </div>
    <div class="smshub-card">
      <h3>Successful</h3>
      <div class="stat green"><?= number_format($stats['sent']) ?></div>
    </div>
    <div class="smshub-card">
      <h3>Failed</h3>
      <div class="stat red"><?= number_format($stats['failed']) ?></div>
    </div>
    <div class="smshub-card">
      <h3>Today</h3>
      <div class="stat orange"><?= number_format($stats['today']) ?></div>
    </div>
    <div class="smshub-card">
      <h3>Active Provider</h3>
      <div class="stat" style="font-size:18px;margin-top:4px;"><?= esc_html($active ?: '—') ?></div>
    </div>
  </div>

  <!-- Compose -->
  <div class="smshub-cols">
    <div class="smshub-card">
      <h2 class="mt-0" style="font-size:16px;font-weight:700;margin-bottom:18px;">📤 Send SMS</h2>
      <form id="smshub-send-form">
        <div class="smshub-form-group">
          <label>Recipients <span class="req">*</span></label>
          <input id="smshub_to" class="smshub-input" type="text"
            placeholder="+2348012345678, +2348098765432 or group:GroupName" required>
          <div style="font-size:11px;color:var(--hub-muted);margin-top:4px;">Separate multiple numbers with commas</div>
        </div>
        <div class="smshub-form-group">
          <label>Message <span class="req">*</span></label>
          <textarea id="smshub_message" class="smshub-textarea smshub-sms-body" required
            placeholder="Type your message here…"></textarea>
          <div class="char-counter">0 chars / 1 SMS</div>
        </div>
        <div class="smshub-cols" style="gap:12px;">
          <div class="smshub-form-group">
            <label>Provider</label>
            <select id="smshub_provider" class="smshub-select">
              <option value="">— Active (<?= esc_html($active ?: 'none') ?>)</option>
              <?php foreach ($providers as $key => $p): ?>
                <option value="<?= esc_attr($key) ?>"><?= esc_html($p->get_label()) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="smshub-form-group">
            <label>Override Sender ID</label>
            <input id="smshub_sender" class="smshub-input" type="text" placeholder="Leave blank for default">
          </div>
        </div>
        <button type="submit" class="smshub-btn smshub-btn-primary">
          <span class="dashicons dashicons-email-alt" style="font-size:16px;width:16px;height:16px;"></span>
          Send Now
        </button>
      </form>
    </div>

    <div class="smshub-card">
      <h2 class="mt-0" style="font-size:16px;font-weight:700;margin-bottom:18px;">🔌 Quick Links</h2>
      <p style="color:var(--hub-muted);font-size:13px;margin-bottom:16px;">Jump to plugin sections:</p>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <a href="<?= admin_url('admin.php?page=smshub-triggers') ?>" class="smshub-btn smshub-btn-ghost">
          <span class="dashicons dashicons-admin-links" style="font-size:15px;width:15px;height:15px;"></span> Manage Triggers
        </a>
        <a href="<?= admin_url('admin.php?page=smshub-contacts') ?>" class="smshub-btn smshub-btn-ghost">
          <span class="dashicons dashicons-groups" style="font-size:15px;width:15px;height:15px;"></span> Contacts &amp; Groups
        </a>
        <a href="<?= admin_url('admin.php?page=smshub-log') ?>" class="smshub-btn smshub-btn-ghost">
          <span class="dashicons dashicons-list-view" style="font-size:15px;width:15px;height:15px;"></span> SMS Log
        </a>
        <a href="<?= admin_url('admin.php?page=smshub-settings') ?>" class="smshub-btn smshub-btn-ghost">
          <span class="dashicons dashicons-admin-settings" style="font-size:15px;width:15px;height:15px;"></span> Settings
        </a>
      </div>
      <div style="margin-top:20px;padding:14px;background:#12141e;border-radius:8px;font-size:12px;color:var(--hub-muted);">
        <strong style="color:var(--hub-text);">REST API Endpoint</strong><br>
        <code class="smshub-mono" style="color:var(--hub-accent2);">POST <?= esc_url(rest_url('wp-sms-hub/v1/send')) ?></code>
        <br><br>
        Body: <code class="smshub-mono">{ "to": "+234...", "message": "..." }</code>
      </div>
    </div>
  </div>
</div>
