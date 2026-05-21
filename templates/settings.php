<?php defined('ABSPATH') || exit; ?>
<div class="smshub-wrap">
  <h1><span class="dashicons dashicons-admin-settings"></span> Settings</h1>

  <div class="smshub-loader"><div class="smshub-loader-bar"></div></div>
  <div id="smshub-settings-alert" class="smshub-alert"></div>

  <form id="smshub-settings-form">
    <input type="hidden" id="smshub_active_provider" value="<?= esc_attr($active) ?>">

    <div class="smshub-card" style="margin-bottom:24px;">
      <h2 class="mt-0" style="font-size:17px;font-weight:700;margin-bottom:18px;">General</h2>
      <div class="smshub-cols">
        <div class="smshub-form-group">
          <label>Admin Phone</label>
          <input id="smshub_admin_phone" name="admin_phone" class="smshub-input" type="text"
            value="<?= esc_attr($admin_phone) ?>" placeholder="+2348012345678">
          <div style="font-size:11px;color:var(--hub-muted);margin-top:6px;">Receives trigger:admin alerts</div>
        </div>
        <div class="smshub-form-group">
          <label>REST API Key</label>
          <div class="flex">
            <input id="smshub_rest_api_key" name="rest_api_key" class="smshub-input smshub-mono" type="text"
              value="<?= esc_attr($api_key) ?>" placeholder="Generate a key for external API access">
            <button type="button" id="smshub-gen-key" class="smshub-btn smshub-btn-ghost smshub-btn-sm">Generate</button>
            <button type="button" id="smshub-copy-key" class="smshub-btn smshub-btn-ghost smshub-btn-sm">Copy</button>
          </div>
        </div>
      </div>
    </div>

    <div class="smshub-card" style="margin-bottom:24px;">
      <h2 class="mt-0" style="font-size:17px;font-weight:700;margin-bottom:18px;">Reliability</h2>
      <p style="color:var(--hub-text-secondary);font-size:13px;margin-bottom:20px;">Configure retry behavior and failover for maximum delivery reliability.</p>
      <?php
        $failover   = get_option('wpsmshub_failover_provider', '');
        $maxRetries = get_option('wpsmshub_max_retries', 3);
      ?>
      <div class="smshub-cols">
        <div class="smshub-form-group">
          <label>Max Retries</label>
          <select id="smshub_max_retries" name="max_retries" class="smshub-select">
            <option value="0" <?= selected($maxRetries, 0, false) ?>>No retries</option>
            <option value="1" <?= selected($maxRetries, 1, false) ?>>1 retry</option>
            <option value="2" <?= selected($maxRetries, 2, false) ?>>2 retries</option>
            <option value="3" <?= selected($maxRetries, 3, false) ?>>3 retries (recommended)</option>
            <option value="5" <?= selected($maxRetries, 5, false) ?>>5 retries</option>
          </select>
          <div style="font-size:11px;color:var(--hub-muted);margin-top:6px;">Exponential backoff: 5s, 30s, 2min between retries</div>
        </div>
        <div class="smshub-form-group">
          <label>Failover Provider</label>
          <select id="smshub_failover_provider" name="failover_provider" class="smshub-select">
            <option value="">None (no failover)</option>
            <?php foreach ($providers as $key => $p): ?>
              <option value="<?= esc_attr($key) ?>" <?= selected($failover, $key, false) ?>><?= esc_html($p->get_label()) ?></option>
            <?php endforeach; ?>
          </select>
          <div style="font-size:11px;color:var(--hub-muted);margin-top:6px;">If primary provider fails after all retries, try this one</div>
        </div>
      </div>
      <div style="margin-top:12px;padding:14px;background:rgba(10,12,16,0.4);border:1px solid var(--hub-border);border-radius:var(--hub-radius-sm);font-size:12px;color:var(--hub-text-secondary);">
        <strong style="color:var(--hub-text);">Webhook URL for Delivery Reports</strong><br>
        <code class="smshub-mono" style="color:var(--hub-accent2);margin-top:6px;display:inline-block;"><?= esc_url(rest_url('wp-sms-hub/v1/webhook/')) ?>{provider}</code>
        <br><span style="color:var(--hub-muted);margin-top:4px;display:inline-block;">Configure this URL in your provider's dashboard to receive delivery status updates.</span>
      </div>
    </div>

    <div class="smshub-card" style="margin-bottom:24px;">
      <h2 class="mt-0" style="font-size:17px;font-weight:700;margin-bottom:8px;">SMS Providers</h2>
      <p style="color:var(--hub-text-secondary);font-size:13px;margin-bottom:20px;">Select your active provider and configure credentials. Click a card to activate it.</p>
      <div class="provider-grid">
        <?php foreach ($providers as $key => $provider):
          $is_active = ($key === $active);
          $fields = $provider->get_settings_fields();
          $saved  = get_option('wpsmshub_provider_' . $key, []);
        ?>
        <div class="provider-card <?= $is_active ? 'active' : '' ?>" data-key="<?= esc_attr($key) ?>">
          <?php if ($is_active): ?><div class="active-dot" title="Active"></div><?php endif; ?>
          <div class="pname"><?= esc_html($provider->get_label()) ?></div>
          <div style="font-size:11px;color:var(--hub-muted);font-weight:500;"><?= esc_html($key) ?></div>

          <div class="provider-fields <?= $is_active ? 'open' : '' ?>">
            <?php foreach ($fields as $f): ?>
            <div class="smshub-form-group">
              <label><?= esc_html($f['label']) ?><?= !empty($f['required']) ? ' <span class="req">*</span>' : '' ?></label>
              <?php
              $fname = "provider_settings[{$key}][{$f['key']}]";
              $val   = $saved[$f['key']] ?? '';
              if ($f['type'] === 'select' && !empty($f['options'])): ?>
                <select name="<?= esc_attr($fname) ?>" class="smshub-select">
                  <?php foreach ($f['options'] as $ov => $ol): ?>
                    <option value="<?= esc_attr($ov) ?>" <?= selected($val, $ov, false) ?>><?= esc_html($ol) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php elseif ($f['type'] === 'checkbox'): ?>
                <label class="smshub-toggle">
                  <input type="checkbox" name="<?= esc_attr($fname) ?>" value="1" <?= checked($val, '1', false) ?>>
                  <span class="smshub-toggle-slider"></span>
                </label>
              <?php else: ?>
                <input type="<?= esc_attr($f['type']) ?>" name="<?= esc_attr($fname) ?>"
                  class="smshub-input" value="<?= esc_attr($val) ?>"
                  placeholder="<?= esc_attr($f['placeholder'] ?? '') ?>">
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <div class="flex" style="gap:10px;margin-top:12px;">
              <button type="button" class="smshub-btn smshub-btn-ghost smshub-btn-sm smshub-btn-test" data-provider="<?= esc_attr($key) ?>">
                Test
              </button>
              <button type="button" class="smshub-btn smshub-btn-ghost smshub-btn-sm smshub-btn-balance" data-provider="<?= esc_attr($key) ?>">
                Balance
              </button>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <button type="submit" class="smshub-btn smshub-btn-primary">
      <span class="dashicons dashicons-saved" style="font-size:15px;width:15px;height:15px;"></span>
      Save All Settings
    </button>
  </form>
</div>
