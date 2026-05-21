<?php defined('ABSPATH') || exit; ?>
<div class="smshub-wrap">
  <div class="flex-between" style="margin-bottom:24px;">
    <h1 class="mb-0"><span class="dashicons dashicons-media-text"></span> SMS Templates</h1>
    <button id="smshub-new-template" class="smshub-btn smshub-btn-primary">+ New Template</button>
  </div>

  <div class="smshub-loader"><div class="smshub-loader-bar"></div></div>

  <div class="smshub-card">
    <?php if (empty($templates)): ?>
      <div class="smshub-empty">
        <div class="dashicons dashicons-media-text"></div>
        <p>No templates yet. Create reusable message templates for faster sending.</p>
      </div>
    <?php else: ?>
    <div class="smshub-table-wrap">
      <table class="smshub-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Category</th>
            <th>Preview</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($templates as $t): ?>
          <tr>
            <td style="font-weight:500;color:var(--hub-text);"><?= esc_html($t['name']) ?></td>
            <td><span class="badge badge-active"><?= esc_html($t['category']) ?></span></td>
            <td class="smshub-truncate" title="<?= esc_attr($t['body']) ?>" style="max-width:280px;"><?= esc_html($t['body']) ?></td>
            <td style="font-size:12px;color:var(--hub-muted);"><?= esc_html(date('d M Y', strtotime($t['created_at']))) ?></td>
            <td class="flex" style="gap:8px;">
              <button class="smshub-btn smshub-btn-ghost smshub-btn-sm template-use" data-body="<?= esc_attr($t['body']) ?>">Use</button>
              <button class="smshub-btn smshub-btn-ghost smshub-btn-sm template-edit" data-template='<?= wp_json_encode($t) ?>'>Edit</button>
              <button class="smshub-btn smshub-btn-danger smshub-btn-sm template-delete" data-id="<?= (int)$t['id'] ?>">Delete</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="smshub-card" style="margin-top:24px;">
    <h3 style="margin:0 0 12px;font-size:13px;font-weight:700;color:var(--hub-text);">Template Variables</h3>
    <div class="smshub-var-grid">
      <?php foreach (['{site_name}','{site_url}','{date}','{time}','{order_id}','{order_total}','{order_status}','{customer_name}','{customer_phone}','{customer_email}','{user_name}','{user_email}','{user_phone}'] as $v): ?>
        <code><?= esc_html($v) ?></code>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Template Modal -->
<div id="smshub-template-modal" class="smshub-modal-overlay">
  <div class="smshub-modal">
    <div class="smshub-modal-header">
      <h2>New Template</h2>
      <button class="smshub-modal-close">x</button>
    </div>
    <form id="smshub-template-form">
      <input type="hidden" name="template_id">
      <div class="smshub-form-group">
        <label>Template Name <span class="req">*</span></label>
        <input name="name" class="smshub-input" type="text" placeholder="e.g. Order Confirmation" required>
      </div>
      <div class="smshub-form-group">
        <label>Category</label>
        <input name="category" class="smshub-input" type="text" placeholder="General" list="smshub-tpl-cats">
        <datalist id="smshub-tpl-cats">
          <?php foreach ($categories as $c): ?><option value="<?= esc_attr($c) ?>"><?php endforeach; ?>
        </datalist>
      </div>
      <div class="smshub-form-group">
        <label>Message Body <span class="req">*</span></label>
        <textarea name="body" class="smshub-textarea smshub-sms-body" required
          placeholder="Hi {customer_name}, your order #{order_id} is now {order_status}."></textarea>
        <div class="char-counter">0 chars · GSM-7 · 1 SMS</div>
      </div>
      <button type="submit" class="smshub-btn smshub-btn-primary" style="width:100%;">Save Template</button>
    </form>
  </div>
</div>
