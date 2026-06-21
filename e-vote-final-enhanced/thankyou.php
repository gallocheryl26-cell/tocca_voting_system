<?php
require_once '../tocca_admin/db_connection.php';
require_once '../tocca_admin/get_logo.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php $pageTitle = 'Thank You | Tatak Ormoc'; include __DIR__ . '/partials/voter_head.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="voter-page voter-page--thanks">
  <div class="voter-shell">
    <header class="voter-header">
      <img src="img/tocca2023.jpg" alt="TOCCA Header Image" class="header-logo" />
    </header>

    <section class="thankyou-hero">
      <div class="thanks-icon" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></div>
      <h1>Thank you!</h1>
      <p>Your participation in the 2024 Tatak Ormoc Consumers&rsquo; Choice Awards has been recorded.</p>
    </section>

    <section class="thankyou-progress-panel">
      <h2>Your voting progress</h2>
      <div class="thankyou-chart-row">
        <div class="thankyou-chart-wrap">
          <canvas id="voteChart" aria-label="Voting progress chart"></canvas>
          <div class="thankyou-chart-center" id="chartCenterText">0%</div>
        </div>
        <div class="thankyou-legend">
          <div class="thankyou-legend-item">
            <span class="thankyou-legend-dot" style="background:#339af0;"></span>
            Cast votes: <strong class="ms-1 legend-voted">0</strong>
          </div>
          <div class="thankyou-legend-item">
            <span class="thankyou-legend-dot" style="background:#fab005;"></span>
            Not yet cast: <strong class="ms-1 legend-unanswered">0</strong>
          </div>
        </div>
      </div>
    </section>

    <p class="thankyou-footer-note">
      To vote again later, sign in with your mobile number and access code.
      <a href="index.php">Return to voting portal</a>
    </p>

    <?php include __DIR__ . '/partials/voter_footer.php'; ?>
  </div>

  <div class="modal fade voter-modal" id="feedbackModal" tabindex="-1" aria-labelledby="feedbackModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form id="feedbackForm" autocomplete="off">
          <div class="modal-header">
            <h5 class="modal-title" id="feedbackModalLabel">We value your feedback</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <textarea class="form-control" name="feedback" rows="4" placeholder="Share your experience with the voting portal…" required></textarea>
            <fieldset class="border rounded p-3 mt-3">
              <legend class="float-none w-auto px-2 fs-6 fw-semibold mb-0">Sender visibility</legend>
              <div class="form-check mt-2">
                <input class="form-check-input" type="radio" name="fbVisibility" id="fbAnon" value="1" checked>
                <label class="form-check-label" for="fbAnon">Submit as <strong>Anonymous</strong></label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="fbVisibility" id="fbShow" value="0">
                <label class="form-check-label" for="fbShow">Show my <strong>mobile number</strong> with this feedback</label>
              </div>
              <p class="small text-muted mb-0 mt-2">Your vote remains private; this only labels feedback for organizers.</p>
            </fieldset>
          </div>
          <div class="modal-footer">
            <button type="submit" class="btn btn-primary w-100">Submit feedback</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <button type="button" id="openFeedbackBtn" class="btn btn-primary"
          style="position:fixed; right:16px; bottom:16px; z-index:1040; border-radius:999px; padding:10px 14px; box-shadow:0 10px 30px rgba(0,0,0,.18);">
    <i class="fa-solid fa-comment-dots me-2" aria-hidden="true"></i> Feedback
  </button>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="apply_voter_style.js"></script>
  <script src="toast.js"></script>
  <script src="progress_helper.js"></script>
  <script>
    function launchConfetti() {
      const duration = 2000;
      const end = Date.now() + duration;
      (function frame() {
        confetti({ particleCount: 4, angle: 60, spread: 55, origin: { x: 0 } });
        confetti({ particleCount: 4, angle: 120, spread: 55, origin: { x: 1 } });
        if (Date.now() < end) requestAnimationFrame(frame);
      })();
    }
    let confettiLaunched = false;
    function maybeLaunchConfetti(percent) {
      if (!confettiLaunched && percent === 100) {
        launchConfetti();
        confettiLaunched = true;
      }
    }

    function updateProgressUI(answered, unanswered, percent) {
      document.getElementById('chartCenterText').textContent = `${percent}%`;
      document.querySelector('.legend-voted').textContent = answered;
      document.querySelector('.legend-unanswered').textContent = unanswered;
      maybeLaunchConfetti(percent);
    }

    window.addEventListener('DOMContentLoaded', () => {
      const stored = sessionStorage.getItem('signoutProgress');
      let answered = 0;
      let questionCount = 0;
      if (stored) {
        const d = JSON.parse(stored);
        answered = d.done || 0;
        questionCount = d.questionCount || d.total || 0;
      }
      const unanswered = Math.max(questionCount - answered, 0);
      const percent = questionCount ? Math.round((answered / questionCount) * 100) : 0;
      updateProgressUI(answered, unanswered, percent);

      const ctx = document.getElementById('voteChart').getContext('2d');
      new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: ['Cast', 'Not cast'],
          datasets: [{
            data: [answered, unanswered],
            backgroundColor: ['#339af0', '#fab005'],
            borderWidth: 0
          }]
        },
        options: {
          cutout: '72%',
          responsive: true,
          maintainAspectRatio: true,
          plugins: { legend: { display: false } }
        }
      });

      const mobileNumber = localStorage.getItem('otp_mobile');
      const eventId = localStorage.getItem('current_event_id');
      if (mobileNumber && eventId) {
        fetch(`get_voter_progress.php?mobile=${encodeURIComponent(mobileNumber)}&event_id=${eventId}`)
          .then(res => res.json())
          .then(({ answered: a, unanswered: u, percent: p }) => updateProgressUI(a, u, p))
          .catch(console.error);
      }
    });

    document.addEventListener('DOMContentLoaded', function () {
      const feedbackModalEl = document.getElementById('feedbackModal');
      const openFeedbackBtn = document.getElementById('openFeedbackBtn');
      const feedbackModal = feedbackModalEl ? bootstrap.Modal.getOrCreateInstance(feedbackModalEl) : null;

      openFeedbackBtn?.addEventListener('click', () => {
        feedbackModal?.show();
      });

      setTimeout(() => {
        const voters_id = localStorage.getItem('voter_id') || sessionStorage.getItem('voter_id');
        const event_id = localStorage.getItem('current_event_id') || sessionStorage.getItem('current_event_id');
        if (voters_id && event_id) {
          fetch(`check_feedback.php?voters_id=${encodeURIComponent(voters_id)}&event_id=${encodeURIComponent(event_id)}`)
            .then(r => r.json())
            .then(data => { if (!data.hasFeedback) feedbackModal?.show(); })
            .catch(console.error);
        }
      }, 900);

      document.getElementById('feedbackForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const ta = document.querySelector('[name="feedback"]');
        const feedback = ta.value.trim();
        if (!feedback) {
          if (typeof showToast === 'function') showToast('Please enter your feedback.', 'danger');
          return;
        }
        const voters_id = localStorage.getItem('voter_id') || sessionStorage.getItem('voter_id');
        const event_id = localStorage.getItem('current_event_id') || sessionStorage.getItem('current_event_id');
        if (!voters_id || !event_id) return;

        const isAnon = document.querySelector('input[name="fbVisibility"]:checked')?.value ?? '1';
        const params = new URLSearchParams();
        params.set('feedback', feedback);
        params.set('voters_id', voters_id);
        params.set('event_id', event_id);
        params.set('is_anonymous', isAnon);

        fetch('save_feedback.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: params.toString(),
          credentials: 'same-origin'
        })
          .then(r => r.text())
          .then(txt => {
            if (txt.trim() === 'success') {
              if (typeof showToast === 'function') showToast('Thank you for your feedback!', 'success');
              feedbackModal?.hide();
              ta.value = '';
            } else if (typeof showToast === 'function') {
              showToast('Failed to save feedback.', 'danger');
            }
          })
          .catch(err => console.error(err));
      });
    });
  </script>
</body>
</html>
