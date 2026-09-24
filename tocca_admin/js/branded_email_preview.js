(function (global) {
  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;');
  }

  function plainToHtml(plain, name) {
    let body = String(plain || '')
      .replaceAll('[NAME]', name || 'Business')
      .replace(/\{\{\s*NAME\s*\}\}/gi, name || 'Business');
    body = body.replace(/^\s*(hi|hello)\s+[^,\n]*,\s*/i, '');
    const parts = body.split(/\n\s*\n/).map((part) => part.trim()).filter(Boolean);
    if (!parts.length) return '';
    return parts
      .map((part) => '<p style="margin:0 0 14px;">' + escapeHtml(part).replace(/\n/g, '<br>') + '</p>')
      .join('');
  }

  function html(options) {
    const subject = options.subject || '';
    const heading = options.heading || subject || 'Tatak Ormoc';
    const greetingName = String(options.greetingName || '').trim();
    const greeting = greetingName ? 'Hello ' + escapeHtml(greetingName) + ',' : 'Hello,';
    const bodyHtml = options.bodyHtml || '';
    const ctaLabel = options.ctaLabel || '';
    const ctaUrl = options.ctaUrl || '';
    const trackUrl = String(options.trackUrl || global.toccaTrackUrl || '').trim();
    const showAssist = options.showRegistrationAssist !== false;
    let assistHtml = 'Replies to this mailbox are not monitored.';
    if (showAssist) {
      assistHtml = 'Replies to this mailbox are not monitored. To check a registration, use Track My Registration on the Tatak Ormoc website.';
      if (trackUrl) {
        const safeTrack = escapeHtml(trackUrl);
        assistHtml = 'Replies to this mailbox are not monitored. To check a registration, open <a href="' + safeTrack + '" style="color:#2563eb;">Track My Registration</a>.';
      }
    }
    const year = new Date().getFullYear();

    let ctaBlock = '';
    if (ctaUrl && ctaLabel) {
      const safeUrl = escapeHtml(ctaUrl);
      ctaBlock =
        '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 12px;"><tr>' +
        '<td bgcolor="#2563eb" style="border-radius:6px;">' +
        '<a href="' + safeUrl + '" style="display:inline-block;padding:12px 22px;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;">' +
        escapeHtml(ctaLabel) + '</a></td></tr></table>' +
        '<p style="margin:0 0 8px;color:#6b7280;font-size:13px;line-height:1.5;">If the button does not work, copy and paste this link into your browser:</p>' +
        '<p style="margin:0 0 20px;word-break:break-all;"><a href="' + safeUrl + '" style="color:#2563eb;font-size:13px;">' + safeUrl + '</a></p>';
    }

    return (
      '<div style="background:#f3f4f6;padding:16px 12px;font-family:Arial,Helvetica,sans-serif;">' +
      '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;">' +
      '<tr><td bgcolor="#2563eb" style="height:4px;line-height:4px;font-size:0;">&nbsp;</td></tr>' +
      '<tr><td bgcolor="#eeeeee" style="padding:28px 32px;">' +
      '<div style="color:#111111;font-size:11px;letter-spacing:1.6px;font-weight:700;">CITY GOVERNMENT OF ORMOC</div>' +
      '<div style="color:#111111;font-size:26px;font-weight:700;margin-top:8px;line-height:1.2;">Tatak Ormoc</div>' +
      '<div style="color:#6b7280;font-size:14px;margin-top:6px;">Consumers’ Choice Awards (TOCCA)</div>' +
      '</td></tr>' +
      '<tr><td bgcolor="#ffffff" style="padding:32px;color:#111111;">' +
      '<p style="margin:0 0 18px;color:#111111;font-size:16px;">' + greeting + '</p>' +
      '<h1 style="margin:0 0 18px;color:#111111;font-size:28px;line-height:1.25;font-weight:700;">' + escapeHtml(heading) + '</h1>' +
      '<div style="color:#111111;font-size:15px;line-height:1.65;">' + bodyHtml + '</div>' +
      ctaBlock +
      '</td></tr>' +
      '<tr><td bgcolor="#eeeeee" style="padding:24px 32px;">' +
      '<p style="margin:0 0 10px;color:#111111;font-size:14px;font-weight:700;">This is an automated message. Please do not reply.</p>' +
      '<p style="margin:0 0 14px;color:#6b7280;font-size:13px;line-height:1.5;">' + assistHtml + '</p>' +
      '<p style="margin:0;color:#6b7280;font-size:12px;">&copy; ' + year + ' Tatak Ormoc Consumers’ Choice Awards</p>' +
      '</td></tr></table></div>'
    );
  }

  function renderInto(target, options) {
    if (!target) return;
    target.innerHTML = html(options);
  }

  function qrPosterHtml() {
    return (
      '<p style="margin:18px 0 10px;font-weight:700;">Your voting QR poster</p>' +
      '<p style="margin:0 0 10px;color:#4b5563;font-size:14px;line-height:1.5;">Display this framed poster in-store or online. The same file is attached to this email.</p>' +
      '<p style="margin:0 0 18px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;color:#6b7280;font-size:13px;display:inline-block;">QR poster is attached to this email</p>'
    );
  }

  global.toccaBrandedEmail = { escapeHtml, plainToHtml, html, renderInto, qrPosterHtml };
})(window);
