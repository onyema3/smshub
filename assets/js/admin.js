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
        alert_box($alert, 'success', 'Message sent successfully!');
        $('#smshub_to').val('');
        $('#smshub_message').val('').trigger('input');
      } else {
        const msg = r.data?.results?.[0]?.error || r.data || 'Send failed';
        alert_box($alert, 'error', msg);
      }
    }).fail(() => { loader(false); alert_box($alert, 'error', 'Network error.'); });
  });

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
    const $el = $(this);
    post('smshub_delete_contact', { id: $el.data('id') }).done(r => { if (r.success) removeRow($el); });
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
          alert(`Imported ${r.data.imported} contacts. Errors: ${r.data.errors.length}`);
          if (r.data.imported > 0) location.reload();
        } else {
          alert('Import failed.');
        }
      });
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
