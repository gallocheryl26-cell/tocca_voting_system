/**
 * Email format check + common typo suggestions (mirrors email_check.php).
 */
(function (global) {
  'use strict';

  const POPULAR = [
    'gmail.com',
    'yahoo.com',
    'yahoo.com.ph',
    'hotmail.com',
    'outlook.com',
    'icloud.com',
    'live.com',
    'proton.me',
    'protonmail.com',
    'mail.com',
    'aol.com',
    'ymail.com',
    'msn.com',
  ];

  const DOMAIN_TYPOS = {
    'gmial.com': 'gmail.com',
    'gmai.com': 'gmail.com',
    'gmil.com': 'gmail.com',
    'gamil.com': 'gmail.com',
    'gnail.com': 'gmail.com',
    'gmail.co': 'gmail.com',
    'gmail.con': 'gmail.com',
    'gmail.om': 'gmail.com',
    'gmail.comm': 'gmail.com',
    'gmail.coml': 'gmail.com',
    'gmail.cmo': 'gmail.com',
    'gmailcom': 'gmail.com',
    'hotmial.com': 'hotmail.com',
    'hotmal.com': 'hotmail.com',
    'hotmail.co': 'hotmail.com',
    'hotmali.com': 'hotmail.com',
    'outlok.com': 'outlook.com',
    'outlook.co': 'outlook.com',
    'outllok.com': 'outlook.com',
    'yaho.com': 'yahoo.com',
    'yahooo.com': 'yahoo.com',
    'yahoo.co': 'yahoo.com',
    'yhoo.com': 'yahoo.com',
    'yahho.com': 'yahoo.com',
    'icloud.co': 'icloud.com',
    'iclod.com': 'icloud.com',
    'protonmail.co': 'protonmail.com',
  };

  function levenshtein(a, b) {
    if (a === b) return 0;
    if (!a.length) return b.length;
    if (!b.length) return a.length;
    const row = new Array(b.length + 1);
    for (let j = 0; j <= b.length; j++) row[j] = j;
    for (let i = 1; i <= a.length; i++) {
      let prev = i - 1;
      row[0] = i;
      for (let j = 1; j <= b.length; j++) {
        const tmp = row[j];
        const cost = a[i - 1] === b[j - 1] ? 0 : 1;
        row[j] = Math.min(
          row[j] + 1,
          row[j - 1] + 1,
          prev + cost
        );
        prev = tmp;
      }
    }
    return row[b.length];
  }

  function parseAddress(raw) {
    let s = String(raw || '').trim().replace(/[\s\t]+/g, '');
    if (!s) return null;
    if (s.toLowerCase().startsWith('mailto:')) s = s.slice(7);
    const at = s.indexOf('@');
    if (at <= 0 || at === s.length - 1) return null;
    const local = s.slice(0, at).trim();
    const domain = s.slice(at + 1).trim().toLowerCase();
    if (!local || !domain) return null;
    return { local, domain, full: local + '@' + domain };
  }

  function cleanDomain(domain) {
    let d = String(domain || '').trim().toLowerCase();
    const trailingDigits = d.match(/^(.+\.(?:com|net|org|ph|edu|gov|co\.uk|com\.ph))(\d+)$/);
    if (trailingDigits) return trailingDigits[1];
    return d;
  }

  function suggestDomain(domain) {
    const cleaned = cleanDomain(domain);
    if (DOMAIN_TYPOS[cleaned]) return DOMAIN_TYPOS[cleaned];
    if (POPULAR.includes(cleaned)) return null;

    let best = null;
    let bestDist = 3;
    for (const candidate of POPULAR) {
      const dist = levenshtein(cleaned, candidate);
      if (dist < bestDist) {
        bestDist = dist;
        best = candidate;
      }
    }
    if (best && bestDist <= 2 && best !== cleaned) return best;
    return null;
  }

  function isValidFormat(email) {
    const s = String(email || '').trim();
    if (!s || s.length > 254) return false;
    const parsed = parseAddress(s);
    if (!parsed) return false;
    if (parsed.local.includes('..') || parsed.domain.includes('..')) return false;
    if (!parsed.domain.includes('.')) return false;
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s);
  }

  function emailCheck(raw) {
    const parsed = parseAddress(raw);
    if (!parsed) {
      return {
        valid: false,
        normalized: String(raw || '').trim(),
        suggestion: null,
        error: 'Enter a valid email address (example: name@example.com).',
      };
    }

    const cleanedDomain = cleanDomain(parsed.domain);
    const normalized = parsed.local + '@' + cleanedDomain;

    if (!isValidFormat(normalized)) {
      return {
        valid: false,
        normalized,
        suggestion: null,
        error: 'Enter a valid email address (example: name@example.com).',
      };
    }

    const suggestedDomain = suggestDomain(parsed.domain);
    let suggestion = null;
    if (cleanedDomain !== parsed.domain) {
      suggestion = {
        email: parsed.local + '@' + cleanedDomain,
        reason: 'Extra characters were detected in the domain.',
      };
    } else if (suggestedDomain && suggestedDomain !== cleanedDomain) {
      suggestion = {
        email: parsed.local + '@' + suggestedDomain,
        reason: 'The domain looks mistyped.',
      };
    }

    return {
      valid: true,
      normalized,
      suggestion,
      error: null,
    };
  }

  global.EmailCheck = {
    check: emailCheck,
    isValidFormat,
    parseAddress,
  };
})(typeof window !== 'undefined' ? window : globalThis);
