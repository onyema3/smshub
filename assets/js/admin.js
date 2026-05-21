/* WP SMS Hub – Admin JS */
(function($) {
  'use strict';

  const HUB = window.SMSHUB || {};
  const ajax = HUB.ajax_url;
  const nonce = HUB.nonce;

  // ── Utility ──────────────────────────────────────────────────────────
  function post(action, data, $btn) {
    if ($btn) $btn.prop('disabled', true).css('opacity', '0.6');
    return $.post(ajax, { action, nonce, ...data })
      .always(() => { if ($btn) $btn.prop('disabled', false).css('opacity', '1'); });
  }

  // ── Toast notification system ───────────────────────────────────────
  $('body').append('<div id="smshub-toasts" style="position:fixed;top:40px;right:20px;z-index:999999;display:flex;flex-direction:column;gap:10px;pointer-events:none;"></div>');
  function toast(msg, type = 'info') {
    const colors = { success: 'var(--hub-accent2)', error: 'var(--hub-danger)', info: 'var(--hub-accent-light)' };
    const bgs = { success: 'var(--hub-accent2-glow)', error: 'var(--hub-danger-glow)', info: 'var(--hub-accent-glow)' };
    const $t = $(`<div class="smshub-toast" style="
      pointer-events:auto;background:var(--hub-surface-solid);border:1px solid ${colors[type]};
      color:${colors[type]};padding:12px 20px;border-radius:10px;font-size:13px;font-weight:500;
      font-family:var(--hub-font);box-shadow:0 8px 32px rgba(0,0,0,0.4);
      transform:translateX(100%);opacity:0;transition:all 0.3s cubic-bezier(0.4,0,0.2,1);
      max-width:340px;backdrop-filter:blur(12px);
    ">${msg}</div>`);
    $('#smshub-toasts').append($t);
    setTimeout(() => $t.css({ transform: 'translateX(0)', opacity: 1 }), 10);
    const duration = type === 'error' ? 6000 : 4000;
    setTimeout(() => {
      $t.css({ transform: 'translateX(100%)', opacity: 0 });
      setTimeout(() => $t.remove(), 300);
    }, duration);
  }

  function alert_box($el, type, msg) {
    $el.removeClass('success error info').addClass(type).html(msg).hide().fadeIn(200);
    if (type === 'success') setTimeout(() => $el.fadeOut(300), 4000);
    toast(msg, type);
  }

  function loader(show) {
    const $l = $('.smshub-loader');
    if (show) $l.addClass('active').hide().fadeIn(150);
    else $l.fadeOut(150, function() { $(this).removeClass('active'); });
  }

  // ── Smooth row removal ───────────────────────────────────────────────
  function removeRow($el) {
    $el.closest('tr').css({ transition: 'all 0.3s ease', opacity: 0, transform: 'translateX(-10px)' });
    setTimeout(() => $el.closest('tr').remove(), 300);
  }

  // ── Char counter with GSM/Unicode detection ────────────────────────
  const GSM_CHARS = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
  const GSM_EXT = '^{}\\[~]|€';
  function isGSM7(text) {
    for (let i = 0; i < text.length; i++) {
      if (GSM_CHARS.indexOf(text[i]) === -1 && GSM_EXT.indexOf(text[i]) === -1) return false;
    }
    return true;
  }
  $(document).on('input', '.smshub-sms-body', function() {
    const text = $(this).val();
    const len = text.length;
    const gsm = isGSM7(text);
    const limit = gsm ? 160 : 70;
    const multiLimit = gsm ? 153 : 67;
    const parts = len <= limit ? 1 : Math.ceil(len / multiLimit);
    const enc = gsm ? 'GSM-7' : 'Unicode';
    const $c = $(this).siblings('.char-counter');
    $c.html(`${len} chars · ${enc} · ${parts} SMS`).toggleClass('over', parts > 3);
  });

  // ── Send SMS form (handled below with schedule support) ────────────

  // ── Settings: provider select ─────────────────────────────────────────
  $(document).on('click', '.provider-card', function(e) {
    // Don't trigger card selection when clicking buttons inside the card
    if ($(e.target).closest('.smshub-btn').length) return;

    const $card = $(this);
    const key = $card.data('key');

    // Deactivate all cards
    $('.provider-card').not($card).removeClass('active').find('.active-dot').remove();
    $('.provider-card').not($card).find('.provider-fields').removeClass('open').slideUp(200);

    // Activate clicked card
    $card.addClass('active');
    if (!$card.find('.active-dot').length) {
      $card.prepend('<div class="active-dot" title="Active"></div>');
    }
    $card.find('.provider-fields').addClass('open').slideDown(250);
    $('#smshub_active_provider').val(key);
  });

  // ── Settings save ─────────────────────────────────────────────────────
  $(document).on('submit', '#smshub-settings-form', function(e) {
    e.preventDefault();
    const $btn = $(this).find('.smshub-btn-primary');
    const $alert = $('#smshub-settings-alert');
    const data = { active_provider: $('#smshub_active_provider').val(), admin_phone: $('#smshub_admin_phone').val(), failover_provider: $('#smshub_failover_provider').val(), max_retries: $('#smshub_max_retries').val() };
    data.provider_settings = {};
    data.weekly_digest = $('#smshub_weekly_digest').is(':checked') ? 'yes' : 'no';
    data.digest_email = $('#smshub_digest_email').val();
    data.ip_whitelist = $('#smshub_ip_whitelist').val();
    data.auto_purge_days = $('#smshub_auto_purge_days').val();
    $('.provider-card').each(function() {
      const key = $(this).data('key');
      data.provider_settings[key] = {};
      $(this).find('[name^="provider_settings"]').each(function() {
        const field = $(this).attr('name').match(/\[([^\]]+)\]$/)?.[1];
        if (field) data.provider_settings[key][field] = $(this).val();
      });
    });
    loader(true);
    post('smshub_save_settings', data, $btn).done(r => {
      loader(false);
      alert_box($alert, r.success ? 'success' : 'error', r.success ? 'Settings saved.' : (r.data || 'Error'));
    });
  });

  // ── Test provider ─────────────────────────────────────────────────────
  $(document).on('click', '.smshub-btn-test', function(e) {
    e.preventDefault();
    const $btn = $(this);
    const key  = $btn.data('provider');
    const to   = prompt('Enter test phone number (with country code):');
    if (!to) return;
    loader(true);
    post('smshub_test_provider', { provider: key, test_number: to }, $btn).done(r => {
      loader(false);
      const $alert = $('#smshub-settings-alert');
      if (r.success) alert_box($alert, 'success', 'Test SMS sent!');
      else alert_box($alert, 'error', 'Failed: ' + (r.data || JSON.stringify(r)));
    });
  });

  // ── Check balance ─────────────────────────────────────────────────────
  $(document).on('click', '.smshub-btn-balance', function(e) {
    e.preventDefault();
    const $btn = $(this);
    const key  = $btn.data('provider');
    loader(true);
    post('smshub_get_balance', { provider: key }, $btn).done(r => {
      loader(false);
      const $alert = $('#smshub-settings-alert');
      if (r.success && r.data) {
        const b = r.data;
        const bal = (typeof b.balance === 'object' && b.balance !== null)
          ? (b.balance.amount ?? b.balance.value ?? JSON.stringify(b.balance))
          : (b.balance ?? 'N/A');
        const cur = b.currency ?? '';
        alert_box($alert, 'info', 'Balance: ' + bal + (cur ? ' ' + cur : ''));
      } else {
        alert_box($alert, 'error', 'Balance check not supported or failed.');
      }
    });
  });

  // ── Copy API key ────────────────────────────────────────────────────
  $(document).on('click', '#smshub-copy-key', function() {
    const key = $('#smshub_rest_api_key').val();
    if (!key) return toast('No key to copy', 'error');
    navigator.clipboard.writeText(key).then(() => toast('API key copied!', 'success'));
  });

  // ── Trigger modal ─────────────────────────────────────────────────────
  $(document).on('click', '#smshub-new-trigger', function() {
    openTriggerModal(null);
  });
  $(document).on('click', '.trigger-edit', function() {
    openTriggerModal($(this).data('trigger'));
  });

  function openTriggerModal(data) {
    const $modal = $('#smshub-trigger-modal');
    if (data) {
      $modal.find('[name=trigger_id]').val(data.id);
      $modal.find('[name=name]').val(data.name);
      $modal.find('[name=event]').val(data.event);
      $modal.find('[name=provider]').val(data.provider);
      $modal.find('[name=recipients]').val(data.recipients);
      $modal.find('[name=sender_id]').val(data.sender_id);
      $modal.find('[name=message_tpl]').val(data.message_tpl);
      $modal.find('[name=active]').prop('checked', data.active == 1);
      $modal.find('.smshub-modal-header h2').text('Edit Trigger');
    } else {
      $modal.find('form')[0].reset();
      $modal.find('[name=trigger_id]').val('');
      $modal.find('.smshub-modal-header h2').text('New Trigger');
    }
    $modal.addClass('open');
  }

  // ── Modal close ───────────────────────────────────────────────────────
  $(document).on('click', '.smshub-modal-close', function() {
    $(this).closest('.smshub-modal-overlay').removeClass('open');
  });
  $(document).on('click', '.smshub-modal-overlay', function(e) {
    if (e.target === this) $(this).removeClass('open');
  });
  $(document).on('keydown', function(e) {
    if (e.key === 'Escape') $('.smshub-modal-overlay.open').removeClass('open');
  });

  // ── Save trigger ──────────────────────────────────────────────────────
  $(document).on('submit', '#smshub-trigger-form', function(e) {
    e.preventDefault();
    const $btn = $(this).find('.smshub-btn-primary');
    const data = {
      trigger_id:  $('[name=trigger_id]').val(),
      name:        $('[name=name]').val(),
      event:       $('[name=event]').val(),
      provider:    $('[name=provider]').val(),
      recipients:  $('[name=recipients]').val(),
      sender_id:   $('[name=sender_id]').val(),
      message_tpl: $('[name=message_tpl]').val(),
      active:      $('[name=active]').is(':checked') ? 1 : 0,
    };
    loader(true);
    post('smshub_save_trigger', data, $btn).done(r => {
      loader(false);
      if (r.success) location.reload();
      else alert('Error: ' + (r.data || 'Unknown'));
    });
  });

  // ── Delete trigger ────────────────────────────────────────────────────
  $(document).on('click', '.trigger-delete', function() {
    if (!confirm('Delete this trigger?')) return;
    const $el = $(this);
    post('smshub_delete_trigger', { id: $el.data('id') }).done(r => { if (r.success) removeRow($el); });
  });

  // ── Toggle trigger active ─────────────────────────────────────────────
  $(document).on('change', '.trigger-toggle', function() {
    post('smshub_toggle_trigger', { id: $(this).data('id'), active: this.checked ? 1 : 0 });
  });

  // ── Add/Edit contact modal ──────────────────────────────────────────
  $(document).on('click', '#smshub-new-contact', function() {
    const $modal = $('#smshub-contact-modal');
    $modal.find('form')[0].reset();
    $modal.find('[name=contact_id]').val('');
    $modal.find('.smshub-modal-header h2').text('Add Contact');
    $modal.addClass('open');
  });

  $(document).on('click', '.contact-edit', function() {
    const c = $(this).data('contact');
    const $modal = $('#smshub-contact-modal');
    $modal.find('[name=contact_id]').val(c.id);
    $modal.find('[name=contact_name]').val(c.name);
    $modal.find('[name=contact_phone]').val(c.phone);
    $modal.find('[name=contact_group]').val(c.group_name);
    $modal.find('.smshub-modal-header h2').text('Edit Contact');
    $modal.addClass('open');
  });

  $(document).on('submit', '#smshub-contact-form', function(e) {
    e.preventDefault();
    const $btn = $(this).find('.smshub-btn-primary');
    const id = $('[name=contact_id]').val();
    const action = id ? 'smshub_edit_contact' : 'smshub_add_contact';
    post(action, {
      id,
      name:  $('[name=contact_name]').val(),
      phone: $('[name=contact_phone]').val(),
      group: $('[name=contact_group]').val(),
    }, $btn).done(r => {
      if (r.success) location.reload();
      else toast('Error: ' + (r.data || 'Duplicate or invalid phone.'), 'error');
    });
  });

  // ── Delete contact ────────────────────────────────────────────────────
  $(document).on('click', '.contact-delete', function() {
    if (!confirm('Remove this contact?')) return;
    const $el = $(this);
    post('smshub_delete_contact', { id: $el.data('id') }).done(r => { if (r.success) removeRow($el); });
  });

  // ── Contact select all / bulk delete ──────────────────────────────────
  $(document).on('change', '#smshub-select-all', function() {
    $('.contact-check').prop('checked', this.checked);
    toggleBulkBtn();
  });
  $(document).on('change', '.contact-check', toggleBulkBtn);
  function toggleBulkBtn() {
    const count = $('.contact-check:checked').length;
    $('#smshub-bulk-delete').toggle(count > 0).text(`Delete Selected (${count})`);
  }
  $(document).on('click', '#smshub-bulk-delete', function() {
    const ids = $('.contact-check:checked').map(function() { return $(this).val(); }).get();
    if (!ids.length || !confirm(`Delete ${ids.length} contacts?`)) return;
    post('smshub_bulk_delete_contacts', { ids }, $(this)).done(r => {
      if (r.success) {
        toast(`Deleted ${r.data.deleted} contacts`, 'success');
        setTimeout(() => location.reload(), 500);
      }
    });
  });

  // ── Export contacts CSV ───────────────────────────────────────────────
  $(document).on('click', '#smshub-export-csv', function() {
    const group = $('#smshub-filter-group').val() || '';
    post('smshub_export_contacts', { group }, $(this)).done(r => {
      if (r.success && r.data.csv) {
        const csv = r.data.csv.map(row => row.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = 'contacts-' + (group || 'all') + '.csv'; a.click();
        URL.revokeObjectURL(url);
        toast(`Exported ${r.data.count} contacts`, 'success');
      }
    });
  });

  // ── CSV import ────────────────────────────────────────────────────────
  $(document).on('submit', '#smshub-import-form', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('action', 'smshub_import_contacts');
    fd.append('nonce', nonce);
    loader(true);
    $.ajax({ url: ajax, type: 'POST', data: fd, processData: false, contentType: false })
      .done(r => {
        loader(false);
        if (r.success) {
          toast(`Imported ${r.data.imported} contacts. Errors: ${r.data.errors.length}`, 'success');
          if (r.data.imported > 0) setTimeout(() => location.reload(), 500);
        } else {
          toast('Import failed.', 'error');
        }
      });
  });

  // ── Templates ─────────────────────────────────────────────────────────
  $(document).on('click', '#smshub-new-template', function() {
    const $modal = $('#smshub-template-modal');
    $modal.find('form')[0].reset();
    $modal.find('[name=template_id]').val('');
    $modal.find('.smshub-modal-header h2').text('New Template');
    $modal.addClass('open');
  });

  $(document).on('click', '.template-edit', function() {
    const t = $(this).data('template');
    const $modal = $('#smshub-template-modal');
    $modal.find('[name=template_id]').val(t.id);
    $modal.find('[name=name]').val(t.name);
    $modal.find('[name=category]').val(t.category);
    $modal.find('[name=body]').val(t.body);
    $modal.find('.smshub-modal-header h2').text('Edit Template');
    $modal.addClass('open');
  });

  $(document).on('submit', '#smshub-template-form', function(e) {
    e.preventDefault();
    const $btn = $(this).find('.smshub-btn-primary');
    post('smshub_save_template', {
      template_id: $('[name=template_id]').val(),
      name: $('[name=name]', this).val(),
      category: $('[name=category]', this).val(),
      body: $('[name=body]', this).val(),
    }, $btn).done(r => {
      if (r.success) location.reload();
      else toast('Error saving template', 'error');
    });
  });

  $(document).on('click', '.template-delete', function() {
    if (!confirm('Delete this template?')) return;
    const $el = $(this);
    post('smshub_delete_template', { id: $el.data('id') }).done(r => { if (r.success) removeRow($el); });
  });

  $(document).on('click', '.template-use', function() {
    const body = $(this).data('body');
    // If we're on dashboard, fill the message textarea
    if ($('#smshub_message').length) {
      $('#smshub_message').val(body).trigger('input');
      toast('Template loaded into message field', 'info');
      $('html, body').animate({ scrollTop: $('#smshub_message').offset().top - 100 }, 300);
    } else {
      // Copy to clipboard
      navigator.clipboard.writeText(body).then(() => toast('Template copied to clipboard', 'success'));
    }
  });

  // ── Schedule SMS (from dashboard form) ────────────────────────────────
  $(document).on('submit', '#smshub-send-form', function(e) {
    e.preventDefault();
    const scheduled = $('#smshub_schedule').val();
    const $btn = $(this).find('.smshub-btn-primary');
    const $alert = $('#smshub-send-alert');
    loader(true);

    if (scheduled) {
      // Schedule for later
      post('smshub_schedule_sms', {
        to: $('#smshub_to').val(),
        message: $('#smshub_message').val(),
        provider: $('#smshub_provider').val(),
        sender_id: $('#smshub_sender').val(),
        scheduled_at: scheduled,
      }, $btn).done(r => {
        loader(false);
        if (r.success) {
          alert_box($alert, 'success', `${r.data.scheduled} message(s) scheduled!`);
          $('#smshub_to').val(''); $('#smshub_message').val('').trigger('input'); $('#smshub_schedule').val('');
        } else {
          alert_box($alert, 'error', r.data || 'Schedule failed');
        }
      }).fail(() => { loader(false); alert_box($alert, 'error', 'Network error.'); });
    } else {
      // Send immediately
      post('smshub_send_sms', {
        to: $('#smshub_to').val(),
        message: $('#smshub_message').val(),
        provider: $('#smshub_provider').val(),
        sender_id: $('#smshub_sender').val(),
      }, $btn).done(r => {
        loader(false);
        if (r.success) {
          alert_box($alert, 'success', r.queued ? r.message : 'Message sent successfully!');
          $('#smshub_to').val(''); $('#smshub_message').val('').trigger('input');
        } else {
          const msg = r.data?.results?.[0]?.error || r.data || 'Send failed';
          alert_box($alert, 'error', msg);
        }
      }).fail(() => { loader(false); alert_box($alert, 'error', 'Network error.'); });
    }
  });

  // ── Clear log ─────────────────────────────────────────────────────────
  $(document).on('click', '#smshub-clear-log', function() {
    if (!confirm('Clear ALL SMS log entries? This cannot be undone.')) return;
    post('smshub_clear_log', {}, $(this)).done(r => { if (r.success) location.reload(); });
  });

  $(document).on('click', '.log-delete', function() {
    const $el = $(this);
    post('smshub_delete_log', { id: $el.data('id') }).done(r => { if (r.success) removeRow($el); });
  });

  // ── Resend SMS from log ───────────────────────────────────────────────
  $(document).on('click', '.log-resend', function() {
    const $el = $(this);
    const data = { to: $el.data('to'), message: $el.data('message'), provider: $el.data('provider') };
    $el.prop('disabled', true).css('opacity', '0.6');
    post('smshub_resend_sms', data).done(r => {
      $el.prop('disabled', false).css('opacity', '1');
      if (r.success) {
        toast('Message resent successfully!', 'success');
        $el.closest('tr').find('.badge').removeClass('badge-failed').addClass('badge-sent').text('sent');
      } else {
        toast('Resend failed: ' + (r.data || 'Unknown error'), 'error');
      }
    });
  });

  // ── Tabs ──────────────────────────────────────────────────────────────
  $(document).on('click', '.smshub-tab', function() {
    const target = $(this).data('tab');
    $('.smshub-tab').removeClass('active');
    $(this).addClass('active');
    $('.smshub-tab-panel').hide().filter('#tab-' + target).fadeIn(200);
  });
  $('.smshub-tab:first').trigger('click');

  // ── REST API key generate ─────────────────────────────────────────────
  $(document).on('click', '#smshub-gen-key', function() {
    const key = Array.from(crypto.getRandomValues(new Uint8Array(24))).map(b => b.toString(16).padStart(2,'0')).join('');
    $('#smshub_rest_api_key').val(key).css('opacity', '0').animate({ opacity: 1 }, 300);
  });

  // ── Sub-Accounts ────────────────────────────────────────────────────
  $(document).on('click', '#smshub-new-subaccount', function() {
    $('#smshub-subaccount-modal').addClass('open');
  });
  $(document).on('submit', '#smshub-subaccount-form', function(e) {
    e.preventDefault();
    const $btn = $(this).find('.smshub-btn-primary');
    post('smshub_create_sub_account', {
      name: $('[name=sa_name]').val(),
      daily_limit: $('[name=sa_daily_limit]').val(),
      monthly_limit: $('[name=sa_monthly_limit]').val(),
    }, $btn).done(r => { if (r.success) location.reload(); else toast(r.data || 'Error', 'error'); });
  });
  $(document).on('click', '.sa-delete', function() {
    if (!confirm('Delete this sub-account?')) return;
    const $el = $(this);
    post('smshub_delete_sub_account', { id: $el.data('id') }).done(r => { if (r.success) removeRow($el); });
  });
  $(document).on('change', '.sa-toggle', function() {
    post('smshub_toggle_sub_account', { id: $(this).data('id'), active: this.checked ? 1 : 0 });
  });

  // ── Campaigns ───────────────────────────────────────────────────────
  $(document).on('click', '#smshub-new-campaign', function() {
    const $modal = $('#smshub-campaign-modal');
    $modal.find('form')[0].reset();
    $modal.find('[name=campaign_id]').val('');
    $modal.find('.smshub-modal-header h2').text('New Campaign');
    $modal.addClass('open');
  });
  $(document).on('submit', '#smshub-campaign-form', function(e) {
    e.preventDefault();
    const $btn = $(this).find('.smshub-btn-primary');
    post('smshub_save_campaign', {
      campaign_id: $('[name=campaign_id]').val(),
      name: $('[name=camp_name]').val(),
      message: $('[name=camp_message]').val(),
      audience_type: $('[name=camp_audience_type]').val(),
      audience_value: $('[name=camp_audience_value]').val(),
      provider: $('[name=camp_provider]').val(),
      sender_id: $('[name=camp_sender_id]').val(),
      scheduled_at: $('[name=camp_scheduled_at]').val(),
    }, $btn).done(r => { if (r.success) location.reload(); else toast(r.data || 'Error', 'error'); });
  });
  $(document).on('click', '.campaign-start', function() {
    const $el = $(this);
    post('smshub_start_campaign', { id: $el.data('id') }, $el).done(r => {
      if (r.success) { toast('Campaign started!', 'success'); location.reload(); }
      else toast(r.data || 'Failed to start', 'error');
    });
  });
  $(document).on('click', '.campaign-pause', function() {
    post('smshub_pause_campaign', { id: $(this).data('id') }).done(r => { if (r.success) location.reload(); });
  });
  $(document).on('click', '.campaign-delete', function() {
    if (!confirm('Delete this campaign?')) return;
    const $el = $(this);
    post('smshub_delete_campaign', { id: $el.data('id') }).done(r => { if (r.success) removeRow($el); });
  });

  // ── Outbound Webhooks ───────────────────────────────────────────────
  $(document).on('click', '#smshub-add-webhook', function() {
    const url = $('#smshub_webhook_url').val();
    const name = $('#smshub_webhook_name').val() || 'Webhook';
    if (!url) return toast('URL required', 'error');
    post('smshub_save_outbound_webhook', { webhook_url: url, webhook_name: name, webhook_events: ['all'] }, $(this)).done(r => {
      if (r.success) { toast('Webhook added', 'success'); location.reload(); }
      else toast(r.data || 'Failed', 'error');
    });
  });
  $(document).on('click', '.webhook-delete', function() {
    const $el = $(this);
    post('smshub_delete_outbound_webhook', { index: $el.data('index') }).done(r => {
      if (r.success) removeRow($el);
    });
  });

  // ── Dark/Light mode toggle ──────────────────────────────────────────
  $(function() {
    const $wrap = $('.smshub-wrap');
    if (!$wrap.length) return;
    // Add toggle button to h1
    const saved = localStorage.getItem('smshub_theme') || 'dark';
    if (saved === 'light') $wrap.addClass('smshub-light');
    const $toggle = $('<button class="smshub-theme-toggle" title="Toggle theme"><span class="dashicons dashicons-visibility" style="font-size:14px;width:14px;height:14px;"></span><span class="smshub-theme-label">' + (saved === 'light' ? 'Dark' : 'Light') + '</span></button>');
    $wrap.find('h1').append($toggle);
    $toggle.on('click', function() {
      const isLight = $wrap.toggleClass('smshub-light').hasClass('smshub-light');
      localStorage.setItem('smshub_theme', isLight ? 'light' : 'dark');
      $(this).find('.smshub-theme-label').text(isLight ? 'Dark' : 'Light');
      toast('Switched to ' + (isLight ? 'light' : 'dark') + ' mode', 'info');
    });
  });

  // ── Analytics Chart (Dashboard) ─────────────────────────────────────
  $(function() {
    const $canvas = $('#smshub-chart');
    if (!$canvas.length || typeof Chart === 'undefined') return;

    post('smshub_get_analytics', { days: 30 }).done(r => {
      if (!r.success || !r.data.daily) return;
      const daily = r.data.daily;
      const labels = daily.map(d => d.date.substring(5)); // MM-DD
      const sent = daily.map(d => parseInt(d.sent) || 0);
      const failed = daily.map(d => parseInt(d.failed) || 0);

      new Chart($canvas[0].getContext('2d'), {
        type: 'bar',
        data: {
          labels,
          datasets: [
            { label: 'Sent', data: sent, backgroundColor: 'rgba(0, 228, 184, 0.7)', borderRadius: 4, barPercentage: 0.7 },
            { label: 'Failed', data: failed, backgroundColor: 'rgba(255, 82, 119, 0.7)', borderRadius: 4, barPercentage: 0.7 },
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            x: { grid: { display: false }, ticks: { color: '#5e6680', font: { size: 10 } } },
            y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#5e6680', font: { size: 10 } } },
          }
        }
      });
    });
  });

  // ── Entrance animations ───────────────────────────────────────────────
  $(function() {
    $('.smshub-grid .smshub-card, .smshub-cols > .smshub-card').each(function(i) {
      $(this).css({ opacity: 0, transform: 'translateY(12px)' });
      setTimeout(() => {
        $(this).css({ transition: 'all 0.4s cubic-bezier(0.4, 0, 0.2, 1)', opacity: 1, transform: 'translateY(0)' });
      }, 60 * i);
    });
  });

})(jQuery);
