<?php
require_once '../tocca_admin/db_connection.php';
require_once '../tocca_admin/get_logo.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mobile Number Verification</title>
  <link rel="icon" type="image/png" href="<?php echo $faviconPath; ?>">
  <link rel="stylesheet" href="css/user-style.css">
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <style>
    .footer-content {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      align-items: center;
      gap: 0.5rem;
      text-align: center;
    }

    .footer-text {
      color: inherit;
    }

    .footer-link {
      color: inherit;
      text-decoration: none;
    }

    .footer-link:hover {
      text-decoration: underline;
    }

    .footer-separator {
      color: inherit;
    }
  </style>
</head>
<body>
  <div class="category-layout-container" style="max-width: 700px;">
    <div class="category-box">
      <h2>MOBILE NUMBER VERIFICATION</h2>
    </div>

    <div class="category-panel">
      <form id="otpForm" action="?" method="post">
        <div class="mb-3 text-start">
          <label for="mobileNumber" class="form-label">Enter Mobile Number</label>
          <input type="text" id="mobileNumber" class="form-control" placeholder="+63" required>
        </div>

        <div class="mb-3 text-start">
          <div class="g-recaptcha" data-sitekey="6LeBm2wrAAAAANqTQxJLnqCw-9MTEdYP-Yv0-TJ7"></div>
        </div>

        <div class="mb-3 text-start">
          <button type="button" class="btn btn-save btn-custom" onclick="sendOTP()">Send OTP</button>
        </div>

        <div class="mb-3 text-start">
          <label for="otpCode" class="form-label">Enter One Time Pin <span class="text-muted">(OTP is sent to Your Mobile Number)</span></label>
          <input type="text" id="otpCode" class="form-control" placeholder="Enter verification code" required>
        </div>

        <div class="text-center">
          <button type="submit" class="btn btn-next btn-custom">Verify OTP</button>
        </div>
      </form>
    </div>

    <div class="footer">
      <div class="footer-content">
        <span class="footer-text">&copy; <?php echo date('Y'); ?> Tatak Ormoc Consumers&rsquo; Choice Awards</span>
        <span class="footer-separator">|</span>
        <a class="footer-link" href="privacy_policy.php">Privacy Policy</a>
        <span class="footer-separator">|</span>
        <a class="footer-link" href="terms_and_conditions.php">Terms &amp; Conditions</a>
      </div>
    </div>
  
  </div>

  <script>
    function sendOTP() {
      const mobile = document.getElementById('mobileNumber').value;
      if (!mobile.startsWith('+63') || mobile.length < 13) {
        alert('Please enter a valid PH mobile number.');
        return;
      }
      alert(`OTP sent to ${mobile}`);
    }

    document.getElementById('otpForm').addEventListener('submit', function(e) {
      e.preventDefault();
      const code = document.getElementById('otpCode').value;
      if (code.length === 6) {
        alert('OTP Verified! Proceeding...');
        window.location.href = 'thankyou.html';
      } else {
        alert('Invalid OTP');
      }
    });
  </script>
  <script src="disable-back.js"></script>
</body>
</html>
