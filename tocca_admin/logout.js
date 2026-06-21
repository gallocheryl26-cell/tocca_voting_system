(function () {
  var btn = document.getElementById('confirmLogout');
  if (btn) {
    btn.addEventListener('click', function () {
      window.location.href = 'logout.php';
    });
  }
})();
