<?php defined('ABSPATH') || exit; ?>
<div class="smshub-wrap">
  <div class="flex-between" style="margin-bottom:24px;">
    <h1 class="mb-0"><span class="dashicons dashicons-list-view"></span> SMS Log</h1>
    <button id="smshub-clear-log" class="smshub-btn smshub-btn-danger smshub-btn-sm">Clear All</button>
  </div>

  <div class="smshub-loader"><div class="smshub-loader-bar"></div></div>

  <!-- Filters -->
  <form method="get" class="flex" style="margin-bottom:18px;gap:10px;flex-wrap:wrap;">
    <input type="hidden" name="page" value="smshub-log">
    <input type="text" name="s" class="smshub-input" style="max-width:200px;"
      placeholder="Search number/message..." value="<?= esc_attr($_GET['s'] ?? '') ?>">
    <select name="status" class="smshub-select" style="max-width:150px;">
      <option value="">All Statuses</option>
      <option value="sent"    <?= selected($_GET['status'] ?? '', 'sent',    false) ?>>Sent</option>
      <option value="failed"  <?= selected($_GET['status'] ?? '', 'failed',  false) ?>>Failed</option>
      <option value="pending" <?= selected($_GET['status'] ?? '', 'pending', false) ?>>Pending</option>
    </select>
    <select name="provider" class="smshub-select" style="max-width:160px;">
      <option value="">All Providers</option>
      <?php foreach (\WPSMSHub\SMS_Manager::get_providers() as $k => $p): ?>
        <option value="<?= esc_attr($k) ?>" <?= selected($_GET['provider'] ?? '', $k, false) ?>><?= esc_html($p->get_label()) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="smshub-btn smshub-btn-ghost smshub-btn-sm">Filter</button>
  </form>

  <div class="smshub-card">
    <?php if (empty($data['items'])): ?>
      <div class="smshub-empty">
        <div class="dashicons dashicons-list-view"></div>
        <p>No SMS messages logged yet.</p>
      </div>
    <?php else: ?>
    <div class="smshub-table-wrap">
      <table class="smshub-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Provider</th>
            <th>Recipient</th>
            <th>Message</th>
            <th>Trigger</th>
            <th>Status</th>
            <th>Time</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data['items'] as $row): ?>
          <tr>
            <td class="smshub-mono" style="color:var(--hub-muted);">#<?= (int)$row['id'] ?></td>
            <td><span class="badge badge-active"><?= esc_html($row['provider']) ?></span></td>
            <td class="smshub-mono"><?= esc_html($row['recipient']) ?></td>
            <td class="smshub-truncate" title="<?= esc_attr($row['message']) ?>" style="max-width:220px;"><?= esc_html($row['message']) ?></td>
            <td style="font-size:11px;color:var(--hub-muted);"><?= esc_html($row['trigger_src'] ?: '—') ?></td>
            <td>
              <span class="badge badge-<?= esc_attr($row['status']) ?>"><?= esc_html($row['status']) ?></span>
              <?php if ($row['error_msg']): ?>
                <span title="<?= esc_attr($row['error_msg']) ?>" style="cursor:help;color:var(--hub-danger);margin-left:4px;">!</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;color:var(--hub-muted);white-space:nowrap;">
              <?= esc_html(date('d M y H:i', strtotime($row['created_at']))) ?>
            </td>
            <td>
              <button class="smshub-btn smshub-btn-ghost smshub-btn-sm log-delete" data-id="<?= (int)$row['id'] ?>">x</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="smshub-pagination">
      <span>Showing <?= count($data['items']) ?> of <?= number_format($data['total']) ?> entries</span>
      <?php
        $offset = (int)($_GET['offset'] ?? 0);
        $per    = 50;
        $total  = $data['total'];
        if ($total > $per):
      ?>
      <a href="?page=smshub-log&offset=<?= max(0, $offset-$per) ?>" class="smshub-btn smshub-btn-ghost smshub-btn-sm" <?= $offset === 0 ? 'disabled' : '' ?>>Prev</a>
      <a href="?page=smshub-log&offset=<?= $offset+$per ?>" class="smshub-btn smshub-btn-ghost smshub-btn-sm" <?= ($offset+$per >= $total) ? 'disabled' : '' ?>>Next</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
