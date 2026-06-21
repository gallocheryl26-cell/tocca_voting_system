document.addEventListener('DOMContentLoaded', () => {
  const scriptEl = document.currentScript;
  const styleUrl =
    (scriptEl && scriptEl.getAttribute('data-style-url')) ||
    'get_nomination_style.php';
  fetch(styleUrl)
    .then(res => res.json())
    .then(data => {
      document.body.style.backgroundColor = data.bgColor;
      document.body.style.color = data.textColor || '#000';
      const banner = document.querySelector('.hero-banner img');
      if (banner && data.headerImage) {
        banner.src = data.headerImage;
      }
      if (data.favicon) {
        let link = document.querySelector("link[rel='icon']");
        if (!link) {
          link = document.createElement('link');
          link.rel = 'icon';
          document.head.appendChild(link);
        }
        link.href = data.favicon;
      }
    })
    .catch(err => console.error('Failed to apply form style:', err));
});