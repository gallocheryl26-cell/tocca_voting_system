<?php
require_once dirname(__DIR__) . '/config.php';
require_once '../tocca_admin/db_connection.php';
require_once '../tocca_admin/get_logo.php';
require_once '../tocca_admin/includes/voter_portal_copy.php';
require_once '../nomination/rich_text_helpers.php';
require_once __DIR__ . '/lib/voter_redirect.php';
date_default_timezone_set('Asia/Manila');
$adminPreview = isset($_GET['admin_preview']) && (string) $_GET['admin_preview'] === '1';
if ($adminPreview) {
    require_once __DIR__ . '/../tocca_admin/require_admin_session.php';
    tocca_admin_require_login(false);
}

$now = date('Y-m-d H:i:s');
$result = $conn->query("SELECT * FROM tbl_events WHERE is_active = 1 AND COALESCE(is_archived, 0) = 0 ORDER BY year DESC, event_id DESC LIMIT 1");
$event = $result ? $result->fetch_assoc() : null;
if (
    !$adminPreview
    && (
        !$event
        || $now < $event['voting_start']
        || $now > $event['voting_end']
    )
) {
    tocca_voter_redirect('message.php');
}
if (!$event) {
    $event = ['event_id' => 0, 'event_name' => 'Preview', 'voting_start' => $now, 'voting_end' => $now];
}

$portalCopy = voter_portal_copy_load($conn, (int) ($event['event_id'] ?? 0));
$introTitleHtml = htmlspecialchars((string) $portalCopy['intro_title'], ENT_QUOTES, 'UTF-8');
$introBodyHtml  = voter_portal_copy_intro_html($portalCopy);
$howToTitleHtml = htmlspecialchars((string) $portalCopy['how_to_title'], ENT_QUOTES, 'UTF-8');
$howToLeadHtml  = htmlspecialchars((string) $portalCopy['how_to_lead'], ENT_QUOTES, 'UTF-8');
$footerNoteHtml = htmlspecialchars((string) $portalCopy['footer_note'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php
    $eventYear = trim((string) ($event['year'] ?? ''));
    $pageTitle = "Tatak Ormoc Consumers' Choice Awards" . ($eventYear !== '' ? ' ' . $eventYear : '');
    include __DIR__ . '/partials/voter_head.php';
  ?>
  <script src="https://www.gstatic.com/firebasejs/10.12.1/firebase-app-compat.js"></script>
  <script src="https://www.gstatic.com/firebasejs/10.12.1/firebase-auth-compat.js"></script>
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <script>
    const firebaseConfig = {
      apiKey: "AIzaSyBWSN9I0gH2YrF-y53hgRiwzLKkMcGOKCg",
      authDomain: "tocca-voting-system.firebaseapp.com",
      projectId: "tocca-voting-system",
      storageBucket: "tocca-voting-system.firebasestorage.app",
      messagingSenderId: "197035166608",
      appId: "1:197035166608:web:35c18bc393bf5f17a18115"
    };
    firebase.initializeApp(firebaseConfig);
    window.TOCCA_AUTH_CONFIG = <?php echo json_encode([
      'recaptchaSiteKey' => (string) tocca_config('firebase_recaptcha_site_key'),
      'projectId' => 'tocca-voting-system',
    ], JSON_UNESCAPED_SLASHES); ?>;
  </script>
</head>
<body class="voter-page voter-page--home<?php echo $adminPreview ? ' voter-admin-preview' : ''; ?>">
<?php if ($adminPreview): ?>
<div class="voter-admin-preview-banner" role="status">Admin live preview — changes apply as you edit</div>
<?php endif; ?>
<div class="modal fade voter-modal" id="introModal" tabindex="-1" aria-labelledby="introModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content overflow-hidden">
      <div class="modal-header">
        <h5 class="modal-title" id="introModalLabel"><?php echo $introTitleHtml; ?></h5>
      </div>
      <div class="modal-body">
        <div id="introModalCopy"><?php echo $introBodyHtml; ?></div>
        <div class="intro-consent-box">
          <p class="mb-2 fw-semibold">Please review and acknowledge before proceeding:</p>
          <div class="form-check mb-2">
            <input class="form-check-input intro-consent-checkbox" type="checkbox" value="" id="agreeTerms" />
            <label class="form-check-label" for="agreeTerms">
              I have read and agree to the <a href="terms_and_conditions.php" target="_blank" rel="noopener noreferrer">Terms and Conditions</a>.
            </label>
          </div>
          <div class="form-check mb-0">
            <input class="form-check-input intro-consent-checkbox" type="checkbox" value="" id="agreePrivacy" />
            <label class="form-check-label" for="agreePrivacy">
              I have read and agree to the <a href="privacy_policy.php" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.
            </label>
          </div>
        </div>
      </div>
      <div class="modal-footer modal-footer-custom justify-content-end">
        <button type="button" class="btn btn-tocca-close disabled" id="closeIntroBtn" disabled>Close</button>
      </div>
    </div>
  </div>
</div>
  <div class="voter-shell main-container">
    <header class="voter-header">
      <img id="headerLogo" src="img/tocca2023.jpg" alt="TOCCA Header Image" class="header-logo" />
    </header>

    <section class="voter-hero">
      <h1><?php echo $howToTitleHtml; ?></h1>
      <p><?php echo $howToLeadHtml; ?></p>
    </section>

    <section class="voter-content">
      <ol class="voter-instruction-steps">
        <?php foreach ($portalCopy['steps'] as $idx => $step): ?>
        <li class="voter-instruction-step">
          <span class="step-num" aria-hidden="true"><?php echo (int) $idx + 1; ?></span>
          <div class="step-body">
            <strong><?php echo htmlspecialchars((string) $step['title'], ENT_QUOTES, 'UTF-8'); ?></strong><?php if ((string) ($step['body'] ?? '') !== ''): ?> — <?php echo htmlspecialchars((string) $step['body'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
      <p class="voter-instruction-note"><?php echo $footerNoteHtml; ?></p>
      <button type="button" class="proceed-button btn-voter-primary" aria-label="Start voting process">
        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i> Proceed to Voting
      </button>
    </section>

    <?php include __DIR__ . '/partials/voter_footer.php'; ?>
  </div>
<div class="modal fade voter-modal" id="voterVerificationModal" tabindex="-1" aria-labelledby="voterVerificationLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="voterVerificationLabel">Enter Your Mobile Number</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">We'll check whether you're a new voter or returning to continue your ballot.</p>
        <div class="mb-3">
          <label for="voterMobileInput" class="form-label">Mobile number <span class="text-muted fw-normal">(11 digits, starts with 09)</span></label>
          <input type="tel" id="voterMobileInput" class="form-control mobile-ph-input" maxlength="11" pattern="09\d{9}" placeholder="e.g. 09171234567" autocomplete="tel-national" inputmode="numeric">
        </div>
        <div id="mobileStatusMessage" class="small" role="status"></div>
      </div>
      <div class="modal-footer flex-column gap-2 border-0 pt-0">
        <div class="d-flex w-100 gap-2">
          <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary flex-fill" id="mobileContinueBtn">
            <span id="mobileLookupSpinner" class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true"></span>
            <span id="mobileContinueText">Check Number</span>
          </button>
        </div>
        <button type="button" class="btn btn-success w-100 d-none" id="mobileProceedNewBtn">Continue as New Voter</button>
        <button type="button" class="btn btn-outline-primary w-100 d-none" id="mobileProceedExistingBtn">Resume Your Vote</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade voter-modal" id="mobileResultModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center">
      <div class="modal-body pt-4 pb-3 px-4">
        <div id="mobileResultIcon" class="display-4 mb-3 text-primary" aria-hidden="true">
          <i class="fa-solid fa-circle-info"></i>
        </div>
        <h5 id="mobileResultTitle" class="fw-semibold mb-2"></h5>
        <p id="mobileResultMessage" class="mb-0 text-muted small"></p>
      </div>
      <div class="modal-footer flex-column gap-2 border-0 pt-0 px-4 pb-4">
        <button type="button" class="btn btn-primary w-100 d-none" id="mobileResultActionBtn">Continue</button>
        <button type="button" class="btn btn-outline-secondary w-100 d-none" id="mobileResultCloseBtn">Try Again</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade voter-modal" id="newVoterModal" tabindex="-1" aria-labelledby="newVoterLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold text-success" id="newVoterLabel">Welcome!</h5>
      </div>
      <div class="modal-body text-center">
        <p class="p-2">You are about to begin the voting process.</p>
      </div>
    </div>
  </div>
</div>
<div class="modal fade voter-modal" id="existingVoterModal" tabindex="-1" aria-labelledby="existingVoterLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="existingVoterLabel">Existing Voter Login</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-start px-4">
        <div class="alert alert-info small" role="alert" id="existingMobileNotice" style="display: none;"></div>
        <div class="mb-3">
          <label for="existingMobile" class="form-label fw-bold">Mobile Number <span class="text-muted fw-normal">(09XXXXXXXXX)</span></label>
          <input type="tel" class="form-control mobile-ph-input" id="existingMobile" maxlength="11" pattern="09\d{9}" placeholder="e.g. 09171234567" autocomplete="tel-national" inputmode="numeric">
        </div>
        <div class="mb-3">
          <label for="draftCode" class="form-label fw-bold">Access Code (4 digits)</label>
          <input type="password" class="form-control" id="draftCode" maxlength="4" placeholder="Enter your 4-digit access code">
        </div>
        <div class="d-grid">
          <button id="checkDraftBtn" class="btn btn-primary">Continue</button>
        </div>
        <div id="loginError" class="text-danger text-center mt-2" style="display: none;"></div>
        <div class="text-center mt-2 small">
          <a href="#" id="changeMobileLink" class="me-2">Use a different mobile number</a>
          &bull;
          <a href="#" id="forgotCodeLink">Forgot access code?</a>
        </div>
      </div>
    </div>
  </div>
</div>
<div class="modal fade voter-modal" id="forgotModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content p-3">
      <div class="modal-header">
        <h5 class="modal-title">Forgot Access Code</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="forgotStep1" class="d-block">
          <div class="mb-3">
            <label for="forgotMobile" class="form-label">Mobile number <span class="text-muted fw-normal">(11 digits, starts with 09)</span></label>
            <input type="tel" id="forgotMobile" class="form-control mobile-ph-input" maxlength="11" pattern="09\d{9}" placeholder="e.g. 09171234567" required inputmode="numeric" autocomplete="tel-national">
          </div>
          <div id="forgotRecaptchaContainer" class="mb-3"></div>
          <div id="forgotMessage" class="mt-2 small"></div>
          <button id="sendForgotOtpBtn" class="btn btn-primary w-100 mb-2" disabled>
            <span id="sendForgotOtpSpinner" class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true"></span>
            <span>Send OTP</span>
          </button>
        </div>
        <div id="forgotStep2" class="d-none">
          <div class="mb-3 mt-3">
            <label for="forgotOtpCode" class="form-label">Enter OTP</label>
            <input type="text" id="forgotOtpCode" class="form-control" placeholder="Enter OTP received" />
            <small id="forgotOtpSentToMobileMessage" class="form-text text-muted"></small>
          </div>
          <button id="verifyForgotOtpBtn" class="btn btn-success w-100">
            <span id="verifyForgotOtpSpinner" class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true"></span>
            <span>Verify OTP</span>
          </button>
          <button type="button" id="resendForgotOtpBtn" class="btn btn-link w-100 mt-2" style="display: none;">Resend OTP</button>
        </div>
        <div id="forgotVerifyMessage" class="mt-2 alert" style="display:none;"></div>
      </div>
    </div>
  </div>
</div>
<div class="modal fade voter-modal" id="otpModal" tabindex="-1" aria-labelledby="otpModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content p-3">
      <div class="modal-header">
        <h5 class="modal-title" id="otpModalLabel">Mobile Number Verification</h5>
      </div>
      <div class="modal-body" style="min-height: 200px;">
        <div id="otpStep1" class="d-block">
          <div class="mb-3">
            <label for="otpMobile" class="form-label">Mobile number <span class="text-muted fw-normal">(11 digits, starts with 09)</span></label>
            <input type="tel" id="otpMobile" class="form-control mobile-ph-input" maxlength="11" pattern="09\d{9}" placeholder="e.g. 09171234567" required inputmode="numeric" autocomplete="tel-national">
          </div>
          <div id="recaptchaContainer" class="mb-3"></div>
          <div id="otpMessage" class="mt-2 small"></div>
            <button id="sendOtpBtn" class="btn btn-primary w-100 mb-2" disabled>
              <span id="sendOtpSpinner" class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true"></span>
              <span>Send OTP</span>
            </button>
          </div>
        <div id="otpStep2" class="d-none">
          <div class="mb-3 mt-3">
            <label for="otpCode" class="form-label">Enter OTP</label>
            <input type="text" id="otpCode" class="form-control" placeholder="Enter OTP received" />
            <small id="otpSentToMobileMessage" class="form-text text-muted"></small>
          </div>
            <button id="verifyOtpBtn" class="btn btn-success w-100">
              <span id="verifyOtpSpinner" class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true"></span>
              <span>Verify OTP</span>
          </button>
          <button type="button" id="resendOtpBtn" class="btn btn-link w-100 mt-2" style="display: none;">Resend OTP</button>
        </div>
        <div id="otpVerifyMessage" class="mt-2 alert" style="display:none;"></div>
      </div>
    </div>
  </div>
</div>
<div class="modal fade voter-modal" id="draftCodeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Set Access Code</h5>
      </div>
      <div class="modal-body">
        <p>Please create a 4-digit access code to save your draft securely.</p>
        <input type="password" id="draftCodeInput" class="form-control mb-2" maxlength="4" placeholder="Enter 4-digit code" />
        <input type="password" id="draftCodeConfirmInput" class="form-control mb-2" maxlength="4" placeholder="Confirm 4-digit code" />
        <div id="draftCodeError" class="text-danger d-none" data-default-message="Invalid code. Must be 4 digits and match confirmation.">Invalid code. Must be 4 digits and match confirmation.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary" id="saveDraftCodeBtn">
          <span id="saveDraftSpinner" class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true"></span>
          <span>Save Code</span>
        </button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade voter-modal" id="incompleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Notice</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="incompleteModalMessage"></div>
    </div>
  </div>
</div>
<div class="modal fade voter-modal voter-redirect-modal" id="redirectNoticeModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Preparing Voting Session</h5>
      </div>
      <div class="modal-body">
        <div class="redirect-icon"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></div>
        <p id="redirectNoticeText" class="redirect-text">Please wait while we redirect you.</p>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/voter_modal_stack.js"></script>
<script src="api_fetch.js"></script>
<script src="apply_voter_style.js"></script>
<?php if ($adminPreview): ?>
<script>window.TOCCA_ADMIN_PREVIEW = true;</script>
<script src="js/voter_admin_preview.js"></script>
<?php endif; ?>
<script src="toast.js"></script>
<script src="js/voter_existing_login.js"></script>
<script src="js/otp_voter.js"></script>
<script>
  let voterModal;
  let voterOtpMode = "firebase";
  let forgotOtpMode = "firebase";
function showVoterVerificationModal() {
  if (voterModal) {
    voterModal.show();
  }
}
const RECAPTCHA_VALID_MS = 2 * 60 * 1000;
const RECAPTCHA_READY_TIMEOUT_MS = 10000;
const RECAPTCHA_READY_CHECK_INTERVAL_MS = 200;
const RECAPTCHA_RETRY_DELAY_MS = 1000;
const RECAPTCHA_MAX_RETRIES = 10;
let recaptchaSolveTime = null;
let recaptchaExpirationTimer = null;
let recaptchaInitPending = false;
let recaptchaRetryAttempts = 0;
let recaptchaInitInProgress = false;
let isSendingOtp = false;
let otpMobileInput;
let sendOtpBtn;
let sendOtpSpinner;
let otpCodeInput;
let verifyOtpBtn;
let verifyOtpSpinner;
let saveDraftSpinner;
let otpMessage;
let otpVerifyMessage;
let otpSentToMobileMessage;
let otpStep1;
let otpStep2;
let voterMobileInput;
let mobileContinueBtn;
let mobileLookupSpinner;
let mobileStatusMessage;
let mobileProceedNewBtn;
let mobileProceedExistingBtn;
let mobileResultModal;
let mobileResultTitle;
let mobileResultMessage;
let mobileResultIcon;
let mobileResultActionBtn;
let mobileResultCloseBtn;
let lastCheckedMobile = "";
function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}
async function waitForRecaptchaReady(timeoutMs = RECAPTCHA_READY_TIMEOUT_MS) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    if (window.firebase?.auth?.RecaptchaVerifier && typeof grecaptcha !== "undefined") {
      return true;
    }
    await sleep(RECAPTCHA_READY_CHECK_INTERVAL_MS);
  }
  return false;
}
function setOtpMessage(message, tone = "muted") {
  if (!otpMessage) return;
  if (!message) {
    otpMessage.innerHTML = "";
    otpMessage.style.display = "none";
    return;
  }
  otpMessage.innerHTML = `<span class="text-${tone}">${message}</span>`;
  otpMessage.style.display = "block";
}
function setMobileStatus(message, tone = "muted") {
  if (!mobileStatusMessage) return;
  if (!message) {
    mobileStatusMessage.innerHTML = "";
    mobileStatusMessage.className = "small";
    return;
  }
  mobileStatusMessage.className = `small text-${tone}`;
  mobileStatusMessage.innerHTML = message;
}
function hideMobileResultModal() {
  try {
    mobileResultModal?.hide();
  } catch (err) {
    console.warn("Unable to hide mobile result modal:", err);
  }
}
function resolveCheckedMobile() {
  const fromResult = document.getElementById("mobileResultModal")?.dataset.lookupMobile?.trim();
  return (lastCheckedMobile || fromResult || voterMobileInput?.value.trim() || "").trim();
}
function cleanupStaleModalBackdrops() {
  const openModals = document.querySelectorAll(".modal.show").length;
  if (openModals > 0) return;
  document.querySelectorAll(".modal-backdrop").forEach((el) => el.remove());
  document.body.classList.remove("modal-open");
  document.body.style.removeProperty("overflow");
  document.body.style.removeProperty("padding-right");
}
function openOtpVerificationModal(mobile) {
  if (!mobile || !/^09\d{9}$/.test(mobile)) return;
  lastCheckedMobile = mobile;
  if (otpMobileInput) {
    otpMobileInput.value = mobile;
    otpMobileInput.dispatchEvent(new Event("input"));
  }
  otpStep1?.classList.remove("d-none");
  otpStep2?.classList.add("d-none");
  setOtpMessage("");
  if (otpVerifyMessage) {
    otpVerifyMessage.innerHTML = "";
    otpVerifyMessage.style.display = "none";
  }
  if (otpSentToMobileMessage) otpSentToMobileMessage.textContent = "";
  try {
    voterModal?.hide();
  } catch (err) {
    console.warn("Unable to hide voter verification modal:", err);
  }
  mobileProceedNewBtn?.classList.add("d-none");
  const showOtp = () => {
    cleanupStaleModalBackdrops();
    const otpModalEl = document.getElementById("otpModal");
    const otpModalInstance = otpModalEl ? bootstrap.Modal.getOrCreateInstance(otpModalEl) : null;
    otpModalInstance?.show();
    initRecaptcha(true);
  };
  const resultEl = document.getElementById("mobileResultModal");
  if (resultEl?.classList.contains("show")) {
    const onResultHidden = () => {
      resultEl.removeEventListener("hidden.bs.modal", onResultHidden);
      showOtp();
    };
    resultEl.addEventListener("hidden.bs.modal", onResultHidden, { once: true });
    hideMobileResultModal();
    return;
  }
  hideMobileResultModal();
  setTimeout(showOtp, 150);
}
function showMobileLookupResult({
  title = "",
  message = "",
  tone = "primary",
  icon,
  actionLabel = null,
  onAction = null
} = {}) {
  setMobileStatus("", "muted");
  if (!mobileResultModal) {
    if (typeof onAction === "function") {
      onAction();
    }
    return;
  }
  try {
    voterModal?.hide();
  } catch (err) {
    console.warn("Unable to hide voter verification modal:", err);
  }
  const resolvedIcon = icon || (tone === "success"
    ? "fa-circle-check"
    : tone === "danger"
      ? "fa-triangle-exclamation"
      : tone === "primary"
        ? "fa-mobile-screen-button"
        : "fa-circle-info");
  if (mobileResultIcon) {
    mobileResultIcon.className = `display-4 mb-3 text-${tone}`;
    mobileResultIcon.innerHTML = `<i class="fa-solid ${resolvedIcon}"></i>`;
  }
  if (mobileResultTitle) {
    mobileResultTitle.textContent = title;
  }
  if (mobileResultMessage) {
    mobileResultMessage.innerHTML = message;
  }
  const resultEl = document.getElementById("mobileResultModal");
  if (resultEl && lastCheckedMobile) {
    resultEl.dataset.lookupMobile = lastCheckedMobile;
  }
  const hasAction = typeof onAction === "function" && actionLabel;
  if (mobileResultActionBtn) {
    if (hasAction) {
      mobileResultActionBtn.textContent = actionLabel;
      mobileResultActionBtn.classList.remove("d-none");
      mobileResultActionBtn.disabled = false;
      mobileResultActionBtn.onclick = () => {
        onAction();
      };
    } else {
      mobileResultActionBtn.classList.add("d-none");
      mobileResultActionBtn.onclick = null;
    }
  }
  if (mobileResultCloseBtn) {
    if (hasAction) {
      mobileResultCloseBtn.classList.add("d-none");
    } else {
      mobileResultCloseBtn.textContent = "Try Again";
      mobileResultCloseBtn.classList.remove("d-none");
      mobileResultCloseBtn.onclick = () => {
        hideMobileResultModal();
        voterModal?.show();
        if (voterMobileInput) {
          voterMobileInput.focus();
          if (voterMobileInput.value) {
            voterMobileInput.select();
          }
        }
      };
    }
  }
  mobileResultModal.show();
}
function resetMobileLookupState({ clearInput = false, clearLastChecked = true } = {}) {
  hideMobileResultModal();
  const resultEl = document.getElementById("mobileResultModal");
  if (resultEl) {
    delete resultEl.dataset.lookupMobile;
  }
  if (clearInput && voterMobileInput) {
    voterMobileInput.value = "";
  }
  if (clearLastChecked) {
    lastCheckedMobile = "";
  }
  setMobileStatus("");
  mobileProceedNewBtn?.classList.add("d-none");
  mobileProceedExistingBtn?.classList.add("d-none");
  if (mobileContinueBtn) {
    mobileContinueBtn.disabled = false;
  }
  if (mobileLookupSpinner) mobileLookupSpinner.classList.add("d-none");
  const mobileContinueText = document.getElementById("mobileContinueText");
  if (mobileContinueText) mobileContinueText.textContent = "Check Number";
}
function handleRecaptchaSolved() {
  recaptchaSolveTime = Date.now();
  clearTimeout(recaptchaExpirationTimer);
  recaptchaExpirationTimer = setTimeout(handleRecaptchaExpired, RECAPTCHA_VALID_MS);
  updateSendOtpState();
}
function handleRecaptchaExpired() {
  recaptchaSolveTime = null;
  clearTimeout(recaptchaExpirationTimer);
  recaptchaExpirationTimer = null;
  if (typeof window.onSummaryRecaptchaExpired === "function") window.onSummaryRecaptchaExpired();
  updateSendOtpState();
}
function buildRecaptchaVerifierOptions(callbacks) {
  const siteKey =
    (window.TOCCA_OTP_CONFIG && window.TOCCA_OTP_CONFIG.recaptcha_site_key) ||
    (window.TOCCA_AUTH_CONFIG && window.TOCCA_AUTH_CONFIG.recaptchaSiteKey) ||
    "";
  const opts = {
    size: "normal",
    callback: callbacks.onSolved || handleRecaptchaSolved,
    "expired-callback": callbacks.onExpired || handleRecaptchaExpired,
    "error-callback": callbacks.onError || handleRecaptchaExpired
  };
  if (siteKey) {
    opts.siteKey = siteKey;
  }
  return opts;
}
async function initRecaptcha(forceReset = false) {
  const container = document.getElementById("recaptchaContainer");
  if (!container) return;
  if (container.offsetParent === null) {
    recaptchaInitPending = true;
    return;
  }
  if (recaptchaInitInProgress) {
    recaptchaInitPending = forceReset || recaptchaInitPending;
    return;
  }
  recaptchaInitInProgress = true;
  recaptchaInitPending = false;
  if (forceReset) {
    try {
      if (window.recaptchaVerifier?.grecaptchaWidgetId !== undefined && typeof grecaptcha !== "undefined") {
        grecaptcha.reset(window.recaptchaVerifier.grecaptchaWidgetId);
      }
    } catch (e) {
      console.warn("Failed to reset existing reCAPTCHA widget:", e);
    }
    try { container.innerHTML = ""; } catch (e) {
      console.warn("Failed to clear reCAPTCHA container:", e);
    }
    window.recaptchaVerifier = null;
  }
  if (window.recaptchaVerifier && !forceReset) {
    recaptchaInitInProgress = false;
    setOtpMessage("");
    updateSendOtpState();
    return;
  }
  if (!window.recaptchaVerifier || forceReset) {
    setOtpMessage("Preparing verification…");
    if (sendOtpBtn) sendOtpBtn.disabled = true;
  }
  try {
      const ready = await waitForRecaptchaReady();
    if (!ready) {
      recaptchaRetryAttempts += 1;
      console.warn("reCAPTCHA libraries are still loading. Retrying…");
      if (recaptchaRetryAttempts >= RECAPTCHA_MAX_RETRIES) {
        setOtpMessage("Unable to load verification. Please refresh the page.", "danger");
        recaptchaInitPending = false;
        return;
      }
      recaptchaInitPending = true;
      setTimeout(() => initRecaptcha(forceReset), RECAPTCHA_RETRY_DELAY_MS);
      return;
    }
    if (container.offsetParent === null) {
      recaptchaInitPending = true;
      recaptchaRetryAttempts = 0;
      return;
    }
    window.recaptchaVerifier = new firebase.auth.RecaptchaVerifier(
      "recaptchaContainer",
      buildRecaptchaVerifierOptions({})
    );
    recaptchaSolveTime = null;
    clearTimeout(recaptchaExpirationTimer);
    const widgetId = await window.recaptchaVerifier.render();
    window.recaptchaVerifier.grecaptchaWidgetId = widgetId;
    recaptchaRetryAttempts = 0;
    setOtpMessage("");
    updateSendOtpState();
  } catch (err) {
    console.error("initRecaptcha failed:", err);
        recaptchaRetryAttempts += 1;
    if (recaptchaRetryAttempts >= RECAPTCHA_MAX_RETRIES) {
      setOtpMessage("Unable to load verification. Please refresh the page.", "danger");
      recaptchaInitPending = false;
    } else {
      setOtpMessage("Encountered a problem while loading the verification. Retrying…", "danger");
      recaptchaInitPending = true;
      setTimeout(() => initRecaptcha(true), RECAPTCHA_RETRY_DELAY_MS * 2);
    }
  } finally {
    recaptchaInitInProgress = false;
  }
}
function updateSendOtpState() {
  if (!otpMobileInput || !sendOtpBtn) return;
  const mobile = otpMobileInput.value.trim();
  const isValidMobile = /^(?:\+639|09)\d{9}$/.test(mobile);
  let recaptchaSolved = false;
  try {
    if (window.recaptchaVerifier && typeof grecaptcha !== "undefined" && window.recaptchaVerifier.grecaptchaWidgetId !== undefined) {
      const resp = grecaptcha.getResponse(window.recaptchaVerifier.grecaptchaWidgetId);
      recaptchaSolved = resp && resp.length > 0;
    }
  } catch (e) {
    recaptchaSolved = false;
  }
  const withinTime = recaptchaSolveTime && Date.now() - recaptchaSolveTime < RECAPTCHA_VALID_MS;
  if (sendOtpBtn) sendOtpBtn.disabled = !(isValidMobile && recaptchaSolved && withinTime);
}
function updateSendForgotOtpState() {
  if (!forgotMobileInput || !sendForgotOtpBtn) return;
  const mobile = forgotMobileInput.value.trim();
  const isValidMobile = /^(?:\+639|09)\d{9}$/.test(mobile);
  let recaptchaSolved = false;
  try {
    if (window.forgotRecaptchaVerifier && typeof grecaptcha !== "undefined") {
      const widgetId = window.forgotRecaptchaVerifier.grecaptchaWidgetId;
      if (widgetId !== undefined) {
        const resp = grecaptcha.getResponse(widgetId);
        recaptchaSolved = resp && resp.length > 0;
      }
    }
  } catch (e) {
    recaptchaSolved = false;
  }
  sendForgotOtpBtn.disabled = !(isValidMobile && recaptchaSolved);
}
function advanceVoterToOtpStep2(mobile) {
  otpMessage.innerHTML = `<span class="text-success">OTP sent to ${mobile}</span>`;
  if (otpSentToMobileMessage) otpSentToMobileMessage.textContent = `OTP sent to ${mobile}`;
  otpStep1.classList.add("d-none");
  otpStep2.classList.remove("d-none");
  localStorage.setItem("otp_mobile", mobile);
  localStorage.setItem("currentIndexModal", "otpModal");
  localStorage.setItem("otpStep", "2");
}
function advanceForgotToOtpStep2(mobile) {
  forgotMessage.innerHTML = `<span class="text-success">OTP sent to ${mobile}</span>`;
  if (forgotOtpSentToMobileMessage) forgotOtpSentToMobileMessage.textContent = `OTP sent to ${mobile}`;
  forgotStep1.classList.add("d-none");
  forgotStep2.classList.remove("d-none");
  localStorage.setItem("forgot_mobile", mobile);
}
async function establishVoterSessionAfterOtp(mobile, idToken = null) {
  const payload = { mobile_number: mobile };
  if (idToken) {
    payload.id_token = idToken;
  }
  const response = await fetch("register_new_voter.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin",
    body: JSON.stringify(payload)
  });
  const data = await response.json();
  if (!response.ok || data.status !== "success") {
    const msg = data.message || "Failed to verify session. Please try again.";
    throw new Error(msg);
  }
  localStorage.setItem("otp_mobile", mobile);
  localStorage.setItem("voter_mobile", mobile);
  localStorage.setItem("voter_id", data.voter_id);
  return data;
}
async function completeVoterRegistrationAfterOtp(mobile) {
  const data = await establishVoterSessionAfterOtp(mobile);
  localStorage.setItem("voter_type", "new");
  const otpModalEl = document.getElementById("otpModal");
  const draftCodeModalEl = document.getElementById("draftCodeModal");
  bootstrap.Modal.getInstance(otpModalEl)?.hide();
  const draftModal = bootstrap.Modal.getOrCreateInstance(draftCodeModalEl);
  draftModal.show();
  localStorage.setItem("currentIndexModal", "draftCodeModal");
  localStorage.removeItem("otpStep");
  if (typeof window.bindSaveDraftButton === "function") {
    window.bindSaveDraftButton(() => {
      draftModal.hide();
      const newVoterEl = document.getElementById("newVoterModal");
      bootstrap.Modal.getOrCreateInstance(newVoterEl).show();
    });
  }
}
document.addEventListener("DOMContentLoaded", async () => {
  await ToccaOtp.loadConfig();
  const introModalEl = document.getElementById("introModal");
  const instructionModalEl = document.getElementById("instructionModal");
  const voterModalEl = document.getElementById("voterVerificationModal");
  const newVoterModalEl = document.getElementById("newVoterModal");
  const existingVoterModalEl = document.getElementById("existingVoterModal");
  const mobileResultModalEl = document.getElementById("mobileResultModal");
  const otpModalEl = document.getElementById("otpModal");
  const draftCodeModalEl = document.getElementById("draftCodeModal");
  const incompleteModalEl = document.getElementById("incompleteModal");
  const forgotModalEl = document.getElementById("forgotModal");
  const existingMobileNotice = document.getElementById("existingMobileNotice");
  const instructionModal = instructionModalEl ? new bootstrap.Modal(instructionModalEl) : null;
  const introModal = new bootstrap.Modal(introModalEl);
  voterModal = new bootstrap.Modal(voterModalEl);
  const newVoterModal = new bootstrap.Modal(newVoterModalEl);
  const existingVoterModal = new bootstrap.Modal(existingVoterModalEl);
  mobileResultModal = mobileResultModalEl ? new bootstrap.Modal(mobileResultModalEl, { backdrop: "static", keyboard: false }) : null;
  const otpModal = new bootstrap.Modal(otpModalEl);
  const draftCodeModal = new bootstrap.Modal(draftCodeModalEl);
  const incompleteModal = new bootstrap.Modal(incompleteModalEl);
  const forgotModal = new bootstrap.Modal(forgotModalEl);
  otpMobileInput = document.getElementById("otpMobile");
  sendOtpBtn = document.getElementById("sendOtpBtn");
  sendOtpSpinner = document.getElementById("sendOtpSpinner");
  otpCodeInput = document.getElementById("otpCode");
  verifyOtpBtn = document.getElementById("verifyOtpBtn");
  verifyOtpSpinner = document.getElementById("verifyOtpSpinner");
  saveDraftSpinner = document.getElementById("saveDraftSpinner");
  otpMessage = document.getElementById("otpMessage");
  otpVerifyMessage = document.getElementById("otpVerifyMessage");
  otpSentToMobileMessage = document.getElementById("otpSentToMobileMessage");
  otpStep1 = document.getElementById("otpStep1");
  otpStep2 = document.getElementById("otpStep2");
  voterMobileInput = document.getElementById("voterMobileInput");
  mobileContinueBtn = document.getElementById("mobileContinueBtn");
  mobileLookupSpinner = document.getElementById("mobileLookupSpinner");
  mobileStatusMessage = document.getElementById("mobileStatusMessage");
  mobileProceedNewBtn = document.getElementById("mobileProceedNewBtn");
  mobileProceedExistingBtn = document.getElementById("mobileProceedExistingBtn");
  mobileResultIcon = document.getElementById("mobileResultIcon");
  mobileResultTitle = document.getElementById("mobileResultTitle");
  mobileResultMessage = document.getElementById("mobileResultMessage");
  mobileResultActionBtn = document.getElementById("mobileResultActionBtn");
  mobileResultCloseBtn = document.getElementById("mobileResultCloseBtn");
  forgotMobileInput = document.getElementById("forgotMobile");
  sendForgotOtpBtn = document.getElementById("sendForgotOtpBtn");
  sendForgotOtpSpinner = document.getElementById("sendForgotOtpSpinner");
  forgotOtpCodeInput = document.getElementById("forgotOtpCode");
  verifyForgotOtpBtn = document.getElementById("verifyForgotOtpBtn");
  verifyForgotOtpSpinner = document.getElementById("verifyForgotOtpSpinner");
  forgotMessage = document.getElementById("forgotMessage");
  forgotVerifyMessage = document.getElementById("forgotVerifyMessage");
  forgotOtpSentToMobileMessage = document.getElementById("forgotOtpSentToMobileMessage");
  forgotStep1 = document.getElementById("forgotStep1");
  forgotStep2 = document.getElementById("forgotStep2");
  window.forgotRecaptchaVerifier = new firebase.auth.RecaptchaVerifier(
    "forgotRecaptchaContainer",
    buildRecaptchaVerifierOptions({
      onSolved: () => { updateSendForgotOtpState(); },
      onExpired: () => { updateSendForgotOtpState(); },
      onError: () => { updateSendForgotOtpState(); }
    })
  );
  const modalMap = {
    introModal,
    voterVerificationModal: voterModal,
    newVoterModal,
    existingVoterModal,
    otpModal,
  };
  await initRecaptcha();
  if (window.TOCCA_ADMIN_PREVIEW) {
    return;
  }
  const savedModal = localStorage.getItem("currentIndexModal");
  if (savedModal === "otpModal") {
    const step = localStorage.getItem("otpStep") || "1";
    otpStep1.classList.toggle("d-none", step === "2");
    otpStep2.classList.toggle("d-none", step === "1");
    otpModal.show();
  } else if (savedModal === "draftCodeModal") {
    draftCodeModal.show();
    bindSaveDraftButton(() => newVoterModal.show());
  } else if (savedModal && modalMap[savedModal]) {
    modalMap[savedModal].show();
  } else {
    introModal.show();
  }
  [introModalEl, voterModalEl, newVoterModalEl, existingVoterModalEl, otpModalEl, draftCodeModalEl, forgotModalEl].forEach(el => {
    if (!el) return;
    el.addEventListener("shown.bs.modal", () => {
      localStorage.setItem("currentIndexModal", el.id);
      if (el.id === "otpModal") {
        setOtpMessage("");
        if (otpVerifyMessage) {
          otpVerifyMessage.innerHTML = "";
          otpVerifyMessage.style.display = "none";
        }
        voterOtpMode = "firebase";
        const step = otpStep2.classList.contains("d-none") ? "1" : "2";
        localStorage.setItem("otpStep", step);
        initRecaptcha();
      } else if (el.id === "voterVerificationModal") {
        resetMobileLookupState({ clearInput: false });
        setTimeout(() => {
          if (voterMobileInput) {
            voterMobileInput.focus();
            if (voterMobileInput.value) voterMobileInput.select();
          }
        }, 200);
      } else if (el.id === "forgotModal") {
        if (window.forgotRecaptchaVerifier && !window.forgotRecaptchaVerifier.grecaptchaWidgetId) {
          window.forgotRecaptchaVerifier.render().then(widgetId => {
            window.forgotRecaptchaVerifier.grecaptchaWidgetId = widgetId;
          }).catch(err => console.error("Forgot recaptcha render failed:", err));
        }
      }
    });
    el.addEventListener("hidden.bs.modal", () => {
      if (localStorage.getItem("currentIndexModal") === el.id) {
        localStorage.removeItem("currentIndexModal");
        if (el.id === "otpModal") {
          localStorage.removeItem("otpStep");
          recaptchaInitPending = true;
          recaptchaRetryAttempts = 0;
          setOtpMessage("");
        } else if (el.id === "voterVerificationModal") {
          const mobileResultOpen = document.getElementById("mobileResultModal")?.classList.contains("show");
          if (!mobileResultOpen) {
            resetMobileLookupState();
          }
        } else if (el.id === "existingVoterModal" && existingMobileNotice) {
          existingMobileNotice.style.display = "none";
          existingMobileNotice.textContent = "";
        }
      }
    });
  });
  const closeIntroBtn = document.getElementById("closeIntroBtn"); 
  const introConsentCheckboxes = document.querySelectorAll(".intro-consent-checkbox");
  const updateIntroCloseState = () => {
    if (!closeIntroBtn) return;
    const allChecked = Array.from(introConsentCheckboxes).every(cb => cb.checked);
    closeIntroBtn.disabled = !allChecked;
    closeIntroBtn.classList.toggle("disabled", !allChecked);
  };
  if (introConsentCheckboxes.length > 0) {
    introConsentCheckboxes.forEach(cb => cb.addEventListener("change", updateIntroCloseState));
    updateIntroCloseState();
  }
  if (closeIntroBtn) {
    closeIntroBtn.addEventListener("click", () => {
      introModal.hide();
    });
  }
  if (instructionModal) {
    const proceedInstructionBtn = document.getElementById("instructionProceedBtn");
    if (proceedInstructionBtn) {
      proceedInstructionBtn.addEventListener("click", () => {
        instructionModal.hide();
        setTimeout(() => voterModal.show(), 400);
      });
    }
  }
  const proceedBtn = document.querySelector(".proceed-button");
  if (proceedBtn) {
    proceedBtn.addEventListener("click", showVoterVerificationModal);
  }
  const mobileContinueText = document.getElementById("mobileContinueText");
  mobileContinueBtn?.addEventListener("click", async () => {
    if (!voterMobileInput) return;
    const mobile = voterMobileInput.value.trim()
    mobileProceedNewBtn?.classList.add("d-none");
    mobileProceedExistingBtn?.classList.add("d-none");
    lastCheckedMobile = "";
    if (!/^09\d{9}$/.test(mobile)) {
      setMobileStatus("Enter a valid mobile number in 09XXXXXXXXX format.", "danger");
      showToast("Enter a valid mobile number.", "danger");
      return;
    }
    mobileContinueBtn.disabled = true;
    if (mobileLookupSpinner) mobileLookupSpinner.classList.remove("d-none");
    if (mobileContinueText) mobileContinueText.textContent = "Checking…";
    setMobileStatus("Checking our records…", "muted");
    try {
      const statusRes = await fetch("check_mobile_status.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ mobile_number: mobile })
      });
      if (!statusRes.ok) {
        throw new Error("request-failed");
      }
      const statusData = await statusRes.json();
      if (statusData.status === "new") {
        lastCheckedMobile = mobile;
        showMobileLookupResult({
          title: "New Voter",
          message: `<strong>${mobile}</strong> is not registered yet. Next, verify this number with reCAPTCHA and the OTP sent to your phone.`,
          tone: "primary",
          icon: "fa-user-plus",
          actionLabel: "Continue to Verification",
          onAction: proceedAsNewVoter
        });
      } else if (statusData.status === "exists") {
        if (Number(statusData.has_voted) === 1) {
          showMobileLookupResult({
            title: "All Awards Completed",
            message: `The number <strong>${mobile}</strong> has already finished voting in all categories for this event.`,
            tone: "danger",
            icon: "fa-circle-check"
          });
        } else if (Number(statusData.has_draft_code) === 0) {
          lastCheckedMobile = mobile;
          showMobileLookupResult({
            title: "Finish Registration",
            message: `We found <strong>${mobile}</strong> but no access code yet. Continue to set your 4-digit access code before voting.`,
            tone: "primary",
            icon: "fa-key",
            actionLabel: "Set Access Code",
            onAction: proceedAsNewVoterNeedsCode
          });
        } else {
          lastCheckedMobile = mobile;
          const hasDraft = Number(statusData.has_data) === 1;
          const message = hasDraft
            ? `Welcome back! We found saved progress for <strong>${mobile}</strong>. Enter your access code to continue.`
            : `We found your account for <strong>${mobile}</strong>. Enter your 4-digit access code to continue.`;
          showMobileLookupResult({
            title: hasDraft ? "Resume Your Vote" : "Welcome Back",
            message,
            tone: hasDraft ? "success" : "primary",
            icon: hasDraft ? "fa-rotate-right" : "fa-right-to-bracket",
            actionLabel: "Enter Access Code",
            onAction: proceedAsExistingVoter
          });
        }
      } else if (statusData.status === "closed") {
        showMobileLookupResult({
          title: "Voting Not Open",
          message: statusData.message || "Voting is not open at this time. Please check the official schedule and try again later.",
          tone: "danger"
        });
      } else {
        showMobileLookupResult({
          title: "Unable to Check Number",
          message: statusData.message || "We couldn't verify this mobile number. Please try again.",
          tone: "danger"
        });
      }
    } catch (error) {
      console.error("Mobile lookup failed:", error);
      showMobileLookupResult({
        title: "Lookup Unavailable",
        message: "Unable to check the mobile number right now. Please try again shortly.",
        tone: "danger"
      });
      showToast("Unable to check mobile number right now.", "danger");
    } finally {
      if (mobileLookupSpinner) mobileLookupSpinner.classList.add("d-none");
      if (mobileContinueText) mobileContinueText.textContent = "Check Number";
      mobileContinueBtn.disabled = false;
    }
  });
  voterMobileInput?.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      mobileContinueBtn?.click();
    }
  });
  const proceedAsNewVoterNeedsCode = async () => {
    if (!lastCheckedMobile) return;
    hideMobileResultModal();
    voterModal?.hide();
    try {
      const res = await fetch("check_voter_session.php", { credentials: "same-origin" });
      const data = await res.json();
      if (data.authenticated && data.voter_id) {
        if (otpMobileInput) {
          otpMobileInput.value = lastCheckedMobile;
        }
        localStorage.setItem("otp_mobile", lastCheckedMobile);
        localStorage.setItem("voter_mobile", lastCheckedMobile);
        draftCodeModal.show();
        bindSaveDraftButton(() => newVoterModal.show());
        return;
      }
    } catch (err) {
      console.error("Session check failed:", err);
    }
    proceedAsNewVoter();
  };
  const proceedAsNewVoter = () => {
    const mobile = resolveCheckedMobile();
    openOtpVerificationModal(mobile);
  };
  const proceedAsExistingVoter = () => {
    const mobile = resolveCheckedMobile();
    if (!mobile) return;
    lastCheckedMobile = mobile;
    hideMobileResultModal();
    const existingMobileInput = document.getElementById("existingMobile");
    const loginError = document.getElementById("loginError");
    if (existingMobileInput) {
      existingMobileInput.value = lastCheckedMobile;
      existingMobileInput.focus();
    }
    if (existingMobileNotice) {
      existingMobileNotice.innerHTML = `Continuing as <strong>${lastCheckedMobile}</strong>. Enter your access code to resume your vote.`;
      existingMobileNotice.style.display = "block";
    }
    if (loginError) loginError.style.display = "none";
    voterModal.hide();
    mobileProceedExistingBtn?.classList.add("d-none");
    setTimeout(() => existingVoterModal.show(), 200);
  };
  mobileProceedNewBtn?.addEventListener("click", proceedAsNewVoter);
  mobileProceedExistingBtn?.addEventListener("click", proceedAsExistingVoter);
  const forgotCodeLink = document.getElementById("forgotCodeLink");
  if (forgotCodeLink) {
    forgotCodeLink.addEventListener("click", (e) => {
      e.preventDefault();
      existingVoterModal.hide();
      forgotModal.show();
    });
  }
  const changeMobileLink = document.getElementById("changeMobileLink");
  if (changeMobileLink) {
    changeMobileLink.addEventListener("click", (e) => {
      e.preventDefault();
      existingVoterModal.hide();
      setTimeout(() => {
        voterModal.show();
        resetMobileLookupState({ clearInput: false });
        if (voterMobileInput) {
          voterMobileInput.focus();
          if (voterMobileInput.value) voterMobileInput.select();
        }
      }, 200);
    });
  }
  const newVoterModalElShow = document.getElementById("newVoterModal");
  if (newVoterModalElShow) {
    newVoterModalElShow.addEventListener("shown.bs.modal", () => {
      setTimeout(async () => {
        try {
          const r = await fetch("check_voter_session.php", { credentials: "same-origin" });
          const d = await r.json();
          if (!d.can_access_ballot) {
            showToast("Please complete mobile verification before voting.", "danger");
            newVoterModal.hide();
            voterModal.show();
            return;
          }
        } catch (err) {
          console.error("Session check failed:", err);
          showToast("Unable to verify your session. Please try again.", "danger");
          return;
        }
        localStorage.removeItem("currentIndexModal");
        window.location.href = "category.php";
      }, 2000);
    });
  }
sendOtpBtn?.addEventListener("click", async () => {
  if (isSendingOtp) return;
  isSendingOtp = true;
  const mobile = otpMobileInput.value.trim();
  otpMessage.style.display = "block";
  if (!/^09\d{9}$/.test(mobile)) {
    otpMessage.innerHTML = `<span class="text-danger">Enter a valid mobile number in 09XXXXXXXXX format.</span>`;
    showToast("Enter a valid mobile number.", "danger");
    isSendingOtp = false;
    return;
  }
  const response = grecaptcha.getResponse(window.recaptchaVerifier?.grecaptchaWidgetId);
  if (!response) {
    otpMessage.innerHTML = `<span class="text-danger">Please solve the reCAPTCHA first.</span>`;
        showToast("Please solve the reCAPTCHA first.", "danger");
    isSendingOtp = false;
    return;
  }
  try {
    const statusRes = await fetch("check_mobile_status.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ mobile_number: mobile })
    });
    const statusData = await statusRes.json();
    if (statusData.status === "exists") {
      if (Number(statusData.has_voted) === 1) {
        showToast("This mobile number has already completed voting.", "danger");
        isSendingOtp = false;
        return;
      }
      if (Number(statusData.has_draft_code) === 1) {
        showToast("This number is already registered. Go back and choose Enter Access Code to continue.", "warning");
        isSendingOtp = false;
        return;
      }
    }
  } catch (err) {
    console.error("Mobile check failed:", err);
    showToast("Error validating mobile number.", "danger");
    isSendingOtp = false;
    return;
  }
  sendOtpBtn.disabled = true;
  const sendText = sendOtpBtn.querySelector("span:nth-child(2)");
  if (sendOtpSpinner) sendOtpSpinner.classList.remove("d-none");
  if (sendText) sendText.textContent = "Sending...";
  setOtpMessage("");
  const recaptchaToken = grecaptcha.getResponse(window.recaptchaVerifier?.grecaptchaWidgetId);
  try {
    if (ToccaOtp.useServerMode()) {
      const data = await ToccaOtp.sendServerOtp(mobile, recaptchaToken);
      voterOtpMode = "server";
      advanceVoterToOtpStep2(mobile);
      if (data.debug_otp && window.TOCCA_OTP_CONFIG?.app_debug) {
        otpMessage.innerHTML += `<span class="text-warning d-block mt-1">Development OTP: <strong>${data.debug_otp}</strong></span>`;
        showToast("Development mode: OTP shown on screen.", "warning");
      }
    } else {
      const fullPhone = "+63" + mobile.slice(1);
      const confirmationResult = await firebase.auth().signInWithPhoneNumber(fullPhone, window.recaptchaVerifier);
      window.confirmationResult = confirmationResult;
      voterOtpMode = "firebase";
      advanceVoterToOtpStep2(mobile);
    }
  } catch (error) {
    if (ToccaOtp.canFallbackFromFirebase(error)) {
      try {
        const data = await ToccaOtp.sendServerOtp(mobile, recaptchaToken);
        voterOtpMode = "server";
        advanceVoterToOtpStep2(mobile);
        if (data.debug_otp && window.TOCCA_OTP_CONFIG?.app_debug) {
          otpMessage.innerHTML += `<span class="text-warning d-block mt-1">Development OTP: <strong>${data.debug_otp}</strong></span>`;
        }
        showToast("Using SMS verification (Firebase billing is not enabled).", "warning");
      } catch (fallbackErr) {
        console.error("Server OTP fallback failed:", fallbackErr);
        otpMessage.innerHTML = `<span class="text-danger">${fallbackErr.message || "Failed to send OTP."}</span>`;
        showToast(fallbackErr.message || "Failed to send OTP.", "danger");
        sendOtpBtn.disabled = false;
      }
    } else {
      console.error("OTP sending failed:", error);
      const msg = ToccaOtp.formatSendError(error);
      otpMessage.innerHTML = `<span class="text-danger">${msg}</span>`;
      const errCode = ToccaOtp.getFirebaseErrorCode(error);
      const toastMsg =
        ToccaOtp.isLocalhostHost() && ToccaOtp.isInvalidCredentialError(error)
          ? "Use http://127.0.0.1 instead of localhost for OTP."
          : msg.length > 140
            ? msg.slice(0, 140) + "…"
            : msg || "Failed to send OTP.";
      showToast(toastMsg, "danger");
      sendOtpBtn.disabled = false;
      if (
        errCode === "auth/invalid-app-credential" ||
        errCode === "auth/missing-app-credential" ||
        errCode === "auth/captcha-check-failed" ||
        (ToccaOtp.isBillingError(error) && window.TOCCA_OTP_CONFIG?.firebase_sms_ready)
      ) {
        initRecaptcha(true);
      }
    }
  } finally {
    if (sendOtpSpinner) sendOtpSpinner.classList.add("d-none");
    if (sendText) sendText.textContent = "Send OTP";
    isSendingOtp = false;
  }
});
sendForgotOtpBtn?.addEventListener("click", async () => {
  const mobile = forgotMobileInput.value.trim();
  forgotMessage.style.display = "block";
  if (!/^09\d{9}$/.test(mobile)) {
    forgotMessage.innerHTML = `<span class="text-danger">Enter a valid mobile number in 09XXXXXXXXX format.</span>`;
    showToast("Enter a valid mobile number.", "danger");
    return;
  }
  try {
    const statusRes = await fetch("check_has_draft.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ mobile_number: mobile })
    });
    const statusData = await statusRes.json();
    if (statusData.status === "blocked") {
      showToast(statusData.message || "This mobile number has already completed voting.", "danger");
      return;
    }
    if (statusData.status !== "has_draft") {
      forgotMessage.innerHTML = `<span class="text-danger">No access code found for this mobile number.</span>`;
      showToast("No access code found for this mobile number.", "danger");
      return;
    }
  } catch (err) {
    console.error("Draft check failed:", err);
    showToast("Error checking access code.", "danger");
    return;
  }
  let recaptchaSolved = false;
  try {
    if (window.forgotRecaptchaVerifier && typeof grecaptcha !== "undefined") {
      const widgetId = window.forgotRecaptchaVerifier.grecaptchaWidgetId;
      if (widgetId !== undefined) {
        const resp = grecaptcha.getResponse(widgetId);
        recaptchaSolved = resp && resp.length > 0;
      }
    }
  } catch (e) {
    recaptchaSolved = false;
  }
  if (!recaptchaSolved) {
    forgotMessage.innerHTML = `<span class="text-danger">Please solve the reCAPTCHA first.</span>`;
    showToast("Please solve the reCAPTCHA first.", "danger");
    return;
  }
  sendForgotOtpBtn.disabled = true;
  const sendText = sendForgotOtpBtn.querySelector("span:nth-child(2)");
  if (sendForgotOtpSpinner) sendForgotOtpSpinner.classList.remove("d-none");
  if (sendText) sendText.textContent = "Sending...";
  const forgotRecaptchaToken = grecaptcha.getResponse(window.forgotRecaptchaVerifier?.grecaptchaWidgetId);
  try {
    if (ToccaOtp.useServerMode()) {
      await ToccaOtp.sendServerOtp(mobile, forgotRecaptchaToken, "reset_code");
      forgotOtpMode = "server";
      advanceForgotToOtpStep2(mobile);
    } else {
      const fullPhone = "+63" + mobile.slice(1);
      const confirmationResult = await firebase.auth().signInWithPhoneNumber(fullPhone, window.forgotRecaptchaVerifier);
      window.forgotConfirmationResult = confirmationResult;
      forgotOtpMode = "firebase";
      advanceForgotToOtpStep2(mobile);
    }
  } catch (error) {
    if (ToccaOtp.canFallbackFromFirebase(error)) {
      try {
        await ToccaOtp.sendServerOtp(mobile, forgotRecaptchaToken, "reset_code");
        forgotOtpMode = "server";
        advanceForgotToOtpStep2(mobile);
        showToast("Using SMS verification (Firebase billing is not enabled).", "warning");
      } catch (fallbackErr) {
        forgotMessage.innerHTML = `<span class="text-danger">${fallbackErr.message || "Failed to send OTP."}</span>`;
        showToast(fallbackErr.message || "Failed to send OTP.", "danger");
        sendForgotOtpBtn.disabled = false;
      }
    } else {
      console.error("OTP sending failed:", error);
      forgotMessage.innerHTML = `<span class="text-danger">${ToccaOtp.formatSendError(error)}</span>`;
      showToast("Failed to send OTP.", "danger");
      sendForgotOtpBtn.disabled = false;
    }
  } finally {
    if (sendForgotOtpSpinner) sendForgotOtpSpinner.classList.add("d-none");
    if (sendText) sendText.textContent = "Send OTP";
  }
});
  verifyOtpBtn?.addEventListener("click", async () => {
    const code = otpCodeInput.value.trim();
    const mobile = otpMobileInput.value.trim();
    if (!code) {
      if (otpVerifyMessage) {
        otpVerifyMessage.innerHTML = `<span class="text-danger">Please enter the OTP code.</span>`;
        otpVerifyMessage.style.display = "block";
      }
      return;
    }
    verifyOtpBtn.disabled = true;
    const verifyText = verifyOtpBtn.querySelector("span:nth-child(2)");
    if (verifyOtpSpinner) verifyOtpSpinner.classList.remove("d-none");
    if (verifyText) verifyText.textContent = "Verifying...";
    try {
      const mobile = otpMobileInput.value.trim();
      if (voterOtpMode === "server") {
        await ToccaOtp.verifyServerOtp(mobile, code);
        await completeVoterRegistrationAfterOtp(mobile);
      } else {
        const result = await window.confirmationResult.confirm(code);
        const idToken = await result.user.getIdToken();
        const response = await fetch("register_new_voter.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          credentials: "same-origin",
          body: JSON.stringify({ mobile_number: mobile, id_token: idToken })
        });
        const data = await response.json();
        if (response.ok && data.status === "success") {
          localStorage.setItem("otp_mobile", mobile);
          localStorage.setItem("voter_mobile", mobile);
          localStorage.setItem("voter_id", data.voter_id);
          localStorage.setItem("voter_type", "new");
          otpModal.hide();
          draftCodeModal.show();
          localStorage.setItem("currentIndexModal", "draftCodeModal");
          localStorage.removeItem("otpStep");
          bindSaveDraftButton(() => {
            draftCodeModal.hide();
            newVoterModal.show();
          });
        } else {
          const msg = data.message || "Failed to register. Please try again.";
          if (otpVerifyMessage) {
            otpVerifyMessage.innerHTML = `<span class='text-danger'>${msg}</span>`;
            otpVerifyMessage.style.display = "block";
          }
        }
      }
    } catch (err) {
      console.error("OTP verification failed:", err);
      if (otpVerifyMessage) {
        otpVerifyMessage.innerHTML = `<span class='text-danger'>${err.message || "Failed to verify. Please try again."}</span>`;
        otpVerifyMessage.style.display = "block";
      }
  } finally {
    verifyOtpBtn.disabled = false;
    if (verifyOtpSpinner) verifyOtpSpinner.classList.add("d-none");
    if (verifyText) verifyText.textContent = "Verify OTP";
  }
});
verifyForgotOtpBtn?.addEventListener("click", async () => {
  const code = forgotOtpCodeInput.value.trim();
  if (!code) {
    if (forgotVerifyMessage) {
      forgotVerifyMessage.innerHTML = `<span class="text-danger">Please enter the OTP code.</span>`;
      forgotVerifyMessage.style.display = "block";
    }
    return;
  }
  verifyForgotOtpBtn.disabled = true;
  const verifyText = verifyForgotOtpBtn.querySelector("span:nth-child(2)");
  if (verifyForgotOtpSpinner) verifyForgotOtpSpinner.classList.remove("d-none");
  if (verifyText) verifyText.textContent = "Verifying...";
  try {
    let verifiedMobile = "";
    if (forgotOtpMode === "server") {
      verifiedMobile = (localStorage.getItem("forgot_mobile") || forgotMobileInput?.value || "").trim();
      if (!/^09\d{9}$/.test(verifiedMobile)) {
        throw new Error("missing-verified-mobile");
      }
      await ToccaOtp.verifyServerOtp(verifiedMobile, code);
      await establishVoterSessionAfterOtp(verifiedMobile);
    } else {
      const result = await window.forgotConfirmationResult.confirm(code);
      const firebasePhone = result?.user?.phoneNumber || "";
      if (firebasePhone.startsWith("+63")) {
        const digitsOnly = firebasePhone.replace(/\D/g, "");
        if (digitsOnly.startsWith("63") && digitsOnly.length === 12) {
          verifiedMobile = "0" + digitsOnly.slice(2);
        }
      } else if (/^09\d{9}$/.test(firebasePhone)) {
        verifiedMobile = firebasePhone;
      }
      if (!verifiedMobile) {
        const storedForgotMobile = localStorage.getItem("forgot_mobile") || "";
        if (/^09\d{9}$/.test(storedForgotMobile)) {
          verifiedMobile = storedForgotMobile;
        }
      }
      if (!/^09\d{9}$/.test(verifiedMobile)) {
        throw new Error("missing-verified-mobile");
      }
      const idToken = await result.user.getIdToken();
      await establishVoterSessionAfterOtp(verifiedMobile, idToken);
    }
    localStorage.setItem("forgot_mobile", verifiedMobile);
    localStorage.setItem("otp_mobile", verifiedMobile);
    localStorage.setItem("voter_mobile", verifiedMobile);
    forgotModal.hide();
    document.getElementById("draftCodeModal").querySelector(".modal-title").textContent = "Reset Access Code";
    draftCodeModal.show();
    bindSaveDraftButton(() => {
      draftCodeModal.hide();
      showToast("Access code reset successfully. Please login with your new code.", "success");
      existingVoterModal.show();
    });
  } catch (err) {
    console.error("OTP verification failed:", err);
    const errorMsg = err?.message === "missing-verified-mobile"
      ? "<span class='text-danger'>We couldn't confirm your verified mobile number. Please restart the reset process.</span>"
      : "<span class='text-danger'>Failed to verify. Please try again.</span>";
    if (forgotVerifyMessage) {
      forgotVerifyMessage.innerHTML = errorMsg;
      forgotVerifyMessage.style.display = "block";
    }
    if (err?.message === "missing-verified-mobile") {
      showToast("Unable to confirm your mobile number. Please restart the reset process.", "danger");
    }
  } finally {
    verifyForgotOtpBtn.disabled = false;
    if (verifyForgotOtpSpinner) verifyForgotOtpSpinner.classList.add("d-none");
    if (verifyText) verifyText.textContent = "Verify OTP";
  }
});
  otpMobileInput?.addEventListener("input", updateSendOtpState);
  forgotMobileInput?.addEventListener("input", updateSendForgotOtpState);
  window.recaptchaCallback = function () { handleRecaptchaSolved(); };
  window.onSummaryRecaptchaExpired = function () {
    console.log("reCAPTCHA expired");
    initRecaptcha(true);
    if (sendOtpBtn) sendOtpBtn.disabled = true;
    if (otpMessage) {
      otpMessage.innerHTML = "<span class='text-danger'>reCAPTCHA expired. Please try again.</span>";
      otpMessage.style.display = "block";
    }
    updateSendOtpState();
  };
  window.onSummaryRecaptchaError = function () {
    console.error("reCAPTCHA error");
    initRecaptcha(true);
    if (sendOtpBtn) sendOtpBtn.disabled = true;
    if (otpMessage) {
      otpMessage.innerHTML = "<span class='text-danger'>reCAPTCHA error. Please try again.</span>";
      otpMessage.style.display = "block";
    }
    updateSendOtpState();
  };
if (window.VoterExistingLogin) {
  VoterExistingLogin.init({
    onSuccess(data, mobile, draftCode) {
      const redirectNoticeModalEl = document.getElementById("redirectNoticeModal");
      const redirectNoticeTextEl = document.getElementById("redirectNoticeText");
      const redirectNoticeModal = redirectNoticeModalEl ? bootstrap.Modal.getOrCreateInstance(redirectNoticeModalEl) : null;
      const redirectAfterNotice = (message, target, destinationLabel, delayMs = 1400) => {
        redirectNoticeModal?.show();
        const countdownStart = Math.max(1, Math.ceil(delayMs / 1000));
        let secondsLeft = countdownStart;
        const tick = () => {
          if (redirectNoticeTextEl) {
            redirectNoticeTextEl.textContent = `${message} Opening ${destinationLabel} in ${secondsLeft}s...`;
          }
          secondsLeft -= 1;
        };
        tick();
        const countdownTimer = setInterval(() => {
          if (secondsLeft <= 0) {
            clearInterval(countdownTimer);
            return;
          }
          tick();
        }, 1000);
        setTimeout(() => { window.location.href = target; }, delayMs);
      };
      localStorage.setItem("verified_mobile", mobile);
      localStorage.setItem("draft_code", draftCode);
      localStorage.setItem("voter_id", String(data.voter_info.voters_id));
      localStorage.setItem("voter_mobile", mobile);
      localStorage.setItem("voter_type", "existing");
      const unanswered = data.unanswered_questions || [];
      localStorage.setItem("unanswered", JSON.stringify(unanswered));
      const categoryIds = [...new Set(unanswered.map(q => String(q.category_id)))];
      localStorage.setItem("unanswered_categories", JSON.stringify(categoryIds));
      localStorage.removeItem("currentIndexModal");
      existingVoterModal.hide();

      if (data.completion_status === "completed" || Number(data.voter_info.has_voted) === 1) {
        localStorage.clear();
        redirectAfterNotice("Your voting is already complete.", "thankyou.php", "the completion page");
        return;
      }

      if (unanswered.length === 0) {
        redirectAfterNotice("Access code verified.", "summarypoll.php", "your summary");
        return;
      }

      redirectAfterNotice("Access code verified.", "category.php", "categories");
    }
  });
}
  function bindSaveDraftButton(callback) {
    const saveDraftBtn = document.getElementById("saveDraftCodeBtn");
    const saveDraftText = saveDraftBtn?.querySelector("span:nth-child(2)");
    const draftInput = document.getElementById("draftCodeInput");
    const draftConfirmInput = document.getElementById("draftCodeConfirmInput");
    const errorDisplay = document.getElementById("draftCodeError");
    const defaultDraftErrorText = errorDisplay?.dataset?.defaultMessage || errorDisplay?.textContent || "Invalid code. Must be 4 digits and match confirmation.";
    saveDraftBtn?.addEventListener("click", async () => {
      const code = draftInput.value.trim();
      const confirm = draftConfirmInput.value.trim();
      if (!/^\d{4}$/.test(code) || code !== confirm) {
        if (errorDisplay) {
          errorDisplay.textContent = defaultDraftErrorText;
          errorDisplay.classList.remove("d-none");
        }
        return;
      }
      const mobileFromStorage = (localStorage.getItem("otp_mobile") || localStorage.getItem("voter_mobile") || localStorage.getItem("forgot_mobile") || "").trim();
      if (!/^09\d{9}$/.test(mobileFromStorage)) {
        if (errorDisplay) {
          errorDisplay.textContent = "Unable to determine your verified mobile number. Please restart the verification process.";
          errorDisplay.classList.remove("d-none");
        }
        showToast("Unable to determine your verified mobile number. Please redo the OTP verification.", "danger");
        return;
      }
      if (errorDisplay) {
        errorDisplay.textContent = defaultDraftErrorText;
        errorDisplay.classList.add("d-none");
      }
      if (saveDraftSpinner) saveDraftSpinner.classList.remove("d-none");
      if (saveDraftBtn) saveDraftBtn.disabled = true;
      if (saveDraftText) saveDraftText.textContent = "Saving...";
      try {
        const res = await fetch("save_draft_code.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ mobile: mobileFromStorage, draft_code: code })
        });
        const result = await res.json();
        if (result.status === "success") {
          localStorage.setItem("draft_code", code);
          localStorage.removeItem("forgot_mobile");
          if (typeof callback === "function") callback();
        } else {
          showToast(result.message || "Failed to save access code.", "danger");
        }
      } catch (err) {
        console.error("Error saving access code:", err);
        showToast("Something went wrong while saving your access code.", "danger");
      } finally {
        if (saveDraftSpinner) saveDraftSpinner.classList.add("d-none");
        if (saveDraftBtn) saveDraftBtn.disabled = false;
        if (saveDraftText) saveDraftText.textContent = "Save Code";
      }
    });
  }
  window.bindSaveDraftButton = bindSaveDraftButton;
});

  // Standard Philippine Mobile Number Sanitizer (09XXXXXXXXX)
  document.querySelectorAll('.mobile-ph-input').forEach(input => {
    input.addEventListener('input', function() {
      let val = this.value;
      // Normalize +63 or 63 prefix to local 0 prefix
      if (val.startsWith('+63')) {
        val = '0' + val.substring(3);
      } else if (val.startsWith('63') && val.length > 11) {
        val = '0' + val.substring(2);
      }
      // Strip non-digits and enforce 11-character limit
      this.value = val.replace(/\D/g, '').substring(0, 11);
    });
  });


</script>
<div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3">
  <div id="validationToast" class="toast text-bg-info" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body"></div>
    </div>
  </div>
</div>
<script language='javascript' type='text/javascript'>
function DisableBackButton() {
window.history.forward()
}
DisableBackButton();
window.onload = DisableBackButton;
window.onpageshow = function(evt) { if (evt.persisted) DisableBackButton() }
window.onunload = function() { void (0) }
</script>
</body>
</html>