<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Sub-Accounts admin page template.
 *
 * @var array $accounts
 */
?>
<div class="smshub-wrap">
  <h1>Sub-Accounts</h1>
  <p class="smshub-subtitle">Manage multi-tenant API access with individual rate limits.</p>

  <div class="smshub-toolbar">
    <button class="smshub-btn smshub-btn-primary" id="smshub-new-subaccount">+ New Sub-Account</button>
  </div>

  <div class="smshub-card">
    <table class="smshub-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>API Key</th>
          <th>Daily Limit</th>
          <th>Monthly Limit</th>
          <th>Total Sent</th>
          <th>Status</th>
          <th>Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ( empty( $accounts ) ) : ?>
          <tr><td colspan="8" class="smshub-empty">No sub-accounts yet. Click "New Sub-Account" to create one.</td></tr>
        <?php else : ?>
          <?php foreach ( $accounts as $acc ) : ?>
            <tr>
              <td><strong><?php echo esc_html( $acc['name'] ); ?></strong></td>
              <td><code class="smshub-api-key"><?php echo esc_html( $acc['api_key'] ); ?></code></td>
              <td><?php echo (int) $acc['daily_limit']; ?></td>
              <td><?php echo (int) $acc['monthly_limit']; ?></td>
              <td><?php echo (int) $acc['total_sent']; ?></td>
              <td>
                <label class="smshub-toggle">
                  <input type="checkbox" class="sa-toggle" data-id="<?php echo (int) $acc['id']; ?>" <?php checked( $acc['status'], 'active' ); ?>>
                  <span class="smshub-toggle-slider"></span>
                </label>
                <span class="badge badge-<?php echo $acc['status'] === 'active' ? 'sent' : 'failed'; ?>">
                  <?php echo esc_html( $acc['status'] ); ?>
                </span>
              </td>
              <td><?php echo esc_html( date( 'M j, Y', strtotime( $acc['created_at'] ) ) ); ?></td>
              <td>
                <button class="smshub-btn smshub-btn-sm smshub-btn-danger sa-delete" data-id="<?php echo (int) $acc['id']; ?>" title="Delete">✕</button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- New Sub-Account Modal -->
<div class="smshub-modal-overlay" id="smshub-subaccount-modal">
  <div class="smshub-modal">
    <div class="smshub-modal-header">
      <h2>New Sub-Account</h2>
      <button class="smshub-modal-close">&times;</button>
    </div>
    <form id="smshub-subaccount-form">
      <div class="smshub-modal-body">
        <div class="smshub-field">
          <label>Account Name</label>
          <input type="text" name="sa_name" placeholder="e.g. Partner App" required>
        </div>
        <div class="smshub-field">
          <label>Daily Limit</label>
          <input type="number" name="sa_daily_limit" value="100" min="1" required>
        </div>
        <div class="smshub-field">
          <label>Monthly Limit</label>
          <input type="number" name="sa_monthly_limit" value="3000" min="1" required>
        </div>
      </div>
      <div class="smshub-modal-footer">
        <button type="submit" class="smshub-btn smshub-btn-primary">Create Sub-Account</button>
      </div>
    </form>
  </div>
</div>
