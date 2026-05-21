<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Campaigns admin page template.
 *
 * @var array $campaigns
 * @var array $groups
 * @var array $providers
 */
?>
<div class="smshub-wrap">
  <h1>Campaigns</h1>
  <p class="smshub-subtitle">Create and manage bulk SMS campaigns with audience targeting.</p>

  <div class="smshub-toolbar">
    <button class="smshub-btn smshub-btn-primary" id="smshub-new-campaign">+ New Campaign</button>
  </div>

  <div class="smshub-card">
    <table class="smshub-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Audience</th>
          <th>Status</th>
          <th>Progress</th>
          <th>Sent / Failed</th>
          <th>Scheduled</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ( empty( $campaigns ) ) : ?>
          <tr><td colspan="7" class="smshub-empty">No campaigns yet. Click "New Campaign" to create one.</td></tr>
        <?php else : ?>
          <?php foreach ( $campaigns as $camp ) :
            $total    = max( 1, (int) $camp['total_recipients'] );
            $done     = (int) $camp['sent_count'] + (int) $camp['failed_count'];
            $percent  = $camp['total_recipients'] > 0 ? round( $done / $total * 100 ) : 0;
          ?>
            <tr>
              <td><strong><?php echo esc_html( $camp['name'] ); ?></strong></td>
              <td>
                <span class="badge badge-info"><?php echo esc_html( $camp['audience_type'] ); ?></span>
                <?php if ( $camp['audience_type'] === 'group' ) echo ': ' . esc_html( $camp['audience_value'] ); ?>
              </td>
              <td>
                <?php
                  $status_class = 'badge-info';
                  if ( $camp['status'] === 'running' )   $status_class = 'badge-sent';
                  if ( $camp['status'] === 'completed' ) $status_class = 'badge-sent';
                  if ( $camp['status'] === 'failed' )    $status_class = 'badge-failed';
                  if ( $camp['status'] === 'paused' )    $status_class = 'badge-warning';
                ?>
                <span class="badge <?php echo $status_class; ?>"><?php echo esc_html( $camp['status'] ); ?></span>
              </td>
              <td>
                <div class="smshub-progress-bar">
                  <div class="smshub-progress-fill" style="width: <?php echo $percent; ?>%;"></div>
                </div>
                <small><?php echo $percent; ?>%</small>
              </td>
              <td><?php echo (int) $camp['sent_count']; ?> / <?php echo (int) $camp['failed_count']; ?></td>
              <td><?php echo $camp['scheduled_at'] ? esc_html( date( 'M j, H:i', strtotime( $camp['scheduled_at'] ) ) ) : '—'; ?></td>
              <td class="smshub-actions">
                <?php if ( in_array( $camp['status'], [ 'draft', 'paused' ], true ) ) : ?>
                  <button class="smshub-btn smshub-btn-sm smshub-btn-primary campaign-start" data-id="<?php echo (int) $camp['id']; ?>" title="Start">▶</button>
                <?php endif; ?>
                <?php if ( $camp['status'] === 'running' ) : ?>
                  <button class="smshub-btn smshub-btn-sm smshub-btn-warning campaign-pause" data-id="<?php echo (int) $camp['id']; ?>" title="Pause">⏸</button>
                <?php endif; ?>
                <button class="smshub-btn smshub-btn-sm smshub-btn-danger campaign-delete" data-id="<?php echo (int) $camp['id']; ?>" title="Delete">✕</button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- New/Edit Campaign Modal -->
<div class="smshub-modal-overlay" id="smshub-campaign-modal">
  <div class="smshub-modal smshub-modal-lg">
    <div class="smshub-modal-header">
      <h2>New Campaign</h2>
      <button class="smshub-modal-close">&times;</button>
    </div>
    <form id="smshub-campaign-form">
      <input type="hidden" name="campaign_id" value="">
      <div class="smshub-modal-body">
        <div class="smshub-field">
          <label>Campaign Name</label>
          <input type="text" name="camp_name" placeholder="e.g. Summer Sale Blast" required>
        </div>

        <div class="smshub-field">
          <label>Message</label>
          <textarea name="camp_message" class="smshub-sms-body" rows="4" placeholder="Type your SMS message..." required></textarea>
          <div class="char-counter">0 chars · GSM-7 · 1 SMS</div>
        </div>

        <div class="smshub-field-row">
          <div class="smshub-field">
            <label>Audience Type</label>
            <select name="camp_audience_type">
              <option value="numbers">Manual Numbers (comma-separated)</option>
              <option value="group">Contact Group</option>
              <option value="all">All Contacts</option>
            </select>
          </div>
          <div class="smshub-field">
            <label>Audience Value</label>
            <input type="text" name="camp_audience_value" placeholder="Group name or comma-separated numbers">
          </div>
        </div>

        <div class="smshub-field-row">
          <div class="smshub-field">
            <label>Provider</label>
            <select name="camp_provider">
              <option value="">Default</option>
              <?php foreach ( $providers as $key => $p ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $p->get_label() ); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="smshub-field">
            <label>Sender ID</label>
            <input type="text" name="camp_sender_id" placeholder="Optional sender ID">
          </div>
        </div>

        <div class="smshub-field">
          <label>Schedule (optional)</label>
          <input type="datetime-local" name="camp_scheduled_at">
          <small>Leave empty to start manually.</small>
        </div>
      </div>
      <div class="smshub-modal-footer">
        <button type="submit" class="smshub-btn smshub-btn-primary">Save Campaign</button>
      </div>
    </form>
  </div>
</div>
