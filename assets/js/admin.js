/* WP SMS Hub – Admin JS */
(function($) {
  'use strict';

  const HUB = window.SMSHUB || {};
  const ajax = HUB.ajax_url;
  const nonce = HUB.nonce;

  // ── Utility ──────────────────────────────────────────────────────────
  function post(action, data, $btn) {
    if ($btn) $btn.prop('disabled', true);
    return $.post(ajax, { action, nonce, ...data })
      .always(() => { if ($btn) $btn.prop('disabled', false); });
  }

  function alert_box($el, type, msg) {
    $el.removeClass('success error info').addClass(type).html(msg).show();
    if (type === 'success') setTimeout(() => $el.fadeOut(), 4000);
  }

  function loader(show) { $('.smshub-loader').toggleClass('active', show); }

  // ── Char counter ─────────────────────────────────────────────────────
  $(document).on('input', '.smshub-sms-body', function() {
    const len = $(this).val().length;
    const parts = Math.ceil(len / 160) || 1;
    const $c = $(this).siblings('.char-counter');
    $c.html(`${len} chars / ${parts} SMS`).toggleClass('over', len > 160 * 3);
  });

  // ── Send SMS form ─────────────────────────────────────────────────────
  $(document).on('submit', '#smshub-send-form', function(e) {
    e.preventDefault();
    const $btn = $(this).find('.smshub-btn-primary');
    const $alert = $('#smshub-send-alert');
    loader(true);
    post('smshub_send_sms', {
      to: $('#smshub_to').val(),
      message: $('#smshub_message').val(),
      provider: $('#smshub_provider').val(),
      sender_id: $('#smshub_sender').val(),
    }, $btn).done(r => {
      loader(false);
      if (r.success) {
        alert_box($alert, 'success', '✓ Message sent successfully!');
        $('#smshub_to').val(''); $('#smshub_message').val('');
      } else {
        const msg = r.data?.results?.[0]?.error || r.data || 'Send failed';
        alert_box($alert, 'error', '✗ ' + msg);
      }
    }).fail(() => { loader(false); alert_box($alert, 'error', 'Network error.'); });
  });

  // ── Settings: provider select ─────────────────────────────────────────
  $(document).on('click', '.provider-card', function() {
    const key = $(this).data('key');
    $('.provider-card').removeClass('active').find('.provider-fields').hide();
    $(this).addClass('active').find('.provider-fields').show();
    $('#smshub_active_provider').val(key);
  });

  // ── Settings save ─────────────────────────────────────────────────────
  $(document).on('submit', '#smshub-settings-form', function(e) {
    e.preventDefault();
    const $btn = $(this).find('.smshub-btn-primary');
    const $alert = $('#smshub-settings-alert');
    const data = { active_provider: $('#smshub_active_provider').val(), admin_phone: $('#smshub_admin_phone').val() };
    // Gather per-provider settings
    data.provider_settings = {};
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
      alert_box($alert, r.success ? 'success' : 'error', r.success ? '✓ Settings saved.' : (r.data || 'Error'));
    });
  });

  // ── Test provider ─────────────────────────────────────────────────────
  $(document).on('click', '.smshub-btn-test', function() {
    const $btn = $(this);
    const key  = $btn.data('provider');
    const to   = prompt('Enter test phone number (with country code):');
    if (!to) return;
    loader(true);
    post('smshub_test_provider', { provider: key, test_number: to }, $btn).done(r => {
      loader(false);
      alert(r.success ? '✓ Test SMS sent!' : ('✗ Failed: ' + (r.data || JSON.stringify(r))));
    });
  });

  // ── Check balance ─────────────────────────────────────────────────────
  $(document).on('click', '.smshub-btn-balance', function() {
    const $btn = $(this);
    const key  = $btn.data('provider');
    loader(true);
    post('smshub_get_balance', { provider: key }, $btn).done(r => {
      loader(false);
      if (r.success && r.data) {
        const b = r.data;
        const bal = (typeof b.balance === 'object' && b.balance !== null)
          ? (b.balance.amount ?? b.balance.value ?? JSON.stringify(b.balance))
          : (b.balance ?? 'N/A');
        const cur = b.currency ?? '';
        alert('Balance: ' + bal + (cur ? ' ' + cur : ''));
      } else {
        alert('Balance check not supported or failed.');
      }
    });
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

  $(document).on('click', '.smshub-modal-close, .smshub-modal-overlay', function(e) {
    if (e.target === this) $(this).closest('.smshub-modal-overlay').removeClass('open');
  });

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
      if (r.success) { location.reload(); }
      else alert('Error: ' + (r.data || 'Unknown'));
    });
  });

  // ── Delete trigger ────────────────────────────────────────────────────
  $(document).on('click', '.trigger-delete', function() {
    if (!confirm('Delete this trigger?')) return;
    const id = $(this).data('id');
    post('smshub_delete_trigger', { id }).done(r => { if (r.success) $(this).closest('tr').fadeOut(); });
  });

  // ── Toggle trigger active ─────────────────────────────────────────────
  $(document).on('change', '.trigger-toggle', function() {
    post('smshub_toggle_trigger', { id: $(this).data('id'), active: this.checked ? 1 : 0 });
  });

  // ── Add contact modal ─────────────────────────────────────────────────
  $(document).on('click', '#smshub-new-contact', function() {
    $('#smshub-contact-modal').addClass('open');
  });

  $(document).on('submit', '#smshub-contact-form', function(e) {
    e.preventDefault();
    const $btn = $(this).find('.smshub-btn-primary');
    post('smshub_add_contact', {
      name:  $('[name=contact_name]').val(),
      phone: $('[name=contact_phone]').val(),
      group: $('[name=contact_group]').val(),
    }, $btn).done(r => {
      if (r.success) location.reload();
      else alert('Error: ' + (r.data || 'Duplicate or invalid phone.'));
    });
  });

  // ── Delete contact ────────────────────────────────────────────────────
  $(document).on('click', '.contact-delete', function() {
    if (!confirm('Remove this contact?')) return;
    const id = $(this).data('id');
    post('smshub_delete_contact', { id }).done(r => { if (r.success) $(this).closest('tr').fadeOut(); });
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
        if (r.success) alert(`Imported ${r.data.imported} contacts. Errors: ${r.data.errors.length}`);
        else alert('Import failed.');
      });
  });

  // ── Clear log ─────────────────────────────────────────────────────────
  $(document).on('click', '#smshub-clear-log', function() {
    if (!confirm('Clear ALL SMS log entries? This cannot be undone.')) return;
    post('smshub_clear_log', {}, $(this)).done(r => { if (r.success) location.reload(); });
  });

  $(document).on('click', '.log-delete', function() {
    const id = $(this).data('id');
    post('smshub_delete_log', { id }).done(r => { if (r.success) $(this).closest('tr').fadeOut(); });
  });

  // ── Tabs ──────────────────────────────────────────────────────────────
  $(document).on('click', '.smshub-tab', function() {
    const target = $(this).data('tab');
    $('.smshub-tab').removeClass('active');
    $(this).addClass('active');
    $('.smshub-tab-panel').hide();
    $('#tab-' + target).show();
  });
  $('.smshub-tab:first').trigger('click');

  // ── REST API key generate ─────────────────────────────────────────────
  $(document).on('click', '#smshub-gen-key', function() {
    const key = Array.from(crypto.getRandomValues(new Uint8Array(24))).map(b => b.toString(16).padStart(2,'0')).join('');
    $('#smshub_rest_api_key').val(key);
  });

})(jQuery);
