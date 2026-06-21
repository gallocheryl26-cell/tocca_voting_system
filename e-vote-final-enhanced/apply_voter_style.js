/**
 * Apply admin Voter Portal appearance (colors, logo).
 * Server also injects CSS via partials/voter_appearance_head.php; this updates logo and reinforces colors.
 */
function applyVoterAppearance(data) {
  if (!data || !document.body) {
    return;
  }

  const root = document.documentElement;
  const body = document.body;
  const bg = data.bgColor || (window.__voterAppearance && window.__voterAppearance.bgColor);
  const text = data.textColor || (window.__voterAppearance && window.__voterAppearance.textColor);
  const logo = data.headerLogo || (window.__voterAppearance && window.__voterAppearance.headerLogo);

  if (bg) {
    root.style.setProperty('--voter-appearance-bg', bg);
    body.classList.add('voter-appearance-custom');
  }
  if (text) {
    root.style.setProperty('--voter-appearance-text', text);
    body.classList.add('voter-appearance-custom');
  }
  /* Hero band always uses light text on the blue gradient */
  body.querySelectorAll('.voter-hero h1, .voter-hero h2, .voter-hero p').forEach((el) => {
    el.style.removeProperty('color');
  });

  if (data.primaryColor) {
    root.style.setProperty('--voter-primary-color', data.primaryColor);
  }
  if (data.buttonColor) {
    root.style.setProperty('--voter-button-color', data.buttonColor);
  }

  const headerLogo = document.getElementById('headerLogo');
  if (headerLogo && logo) {
    headerLogo.src = logo;
  }
}

window.applyVoterAppearance = applyVoterAppearance;

document.addEventListener('DOMContentLoaded', () => {
  if (window.__voterAppearance) {
    document.body.classList.add('voter-appearance-custom');
    applyVoterAppearance(window.__voterAppearance);
  }
  fetch('get_voter_style.php', { credentials: 'same-origin' })
    .then((res) => {
      if (!res.ok) {
        throw new Error('get_voter_style HTTP ' + res.status);
      }
      return res.json();
    })
    .then((data) => applyVoterAppearance(data))
    .catch((err) => console.error('Failed to apply voter style:', err));
});
