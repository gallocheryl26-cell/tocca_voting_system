document.addEventListener('DOMContentLoaded', function () {
    const sendOtpBtn = document.getElementById('sendOtpBtn');
    const verifyOtpBtn = document.getElementById('verifyOtpBtn');
    const otpInput = document.getElementById('otp');
    const mobileInput = document.getElementById('mobile');

    let generatedOtp = null;

    sendOtpBtn.addEventListener('click', function () {
      const mobile = mobileInput.value.trim();
      if (!mobile || mobile.length < 10) {
        alert("Please enter a valid mobile number.");
        return;
      }

      // Simulate OTP generation
      generatedOtp = Math.floor(100000 + Math.random() * 900000).toString();
      sessionStorage.setItem('generatedOTP', generatedOtp);

      alert("OTP sent to your mobile number (simulated): " + generatedOtp);
    });

    verifyOtpBtn.addEventListener('click', function () {
      const enteredOtp = otpInput.value.trim();
      const storedOtp = sessionStorage.getItem('generatedOTP');

      if (enteredOtp === storedOtp) {
        const voteData = JSON.parse(localStorage.getItem('voteSummary'));
        if (!voteData) {
          alert("No vote data found.");
          return;
        }

        // Submit vote to backend
        fetch('submit_vote.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ votes: voteData, mobile: mobileInput.value.trim() })
        })
        .then(response => response.json())
        .then(data => {
          if (data.status === 'success') {
            alert("Vote successfully submitted!");
            localStorage.removeItem('user_answers');
            localStorage.removeItem('voteSummary');
            window.location.href = 'thankyou.html';
          } else {
            alert("Failed to submit. Try again.");
          }
        })
        .catch(err => {
          console.error(err);
          alert("Something went wrong while submitting.");
        });
      } else {
        alert("Invalid OTP. Please try again.");
      }
    });
  });
