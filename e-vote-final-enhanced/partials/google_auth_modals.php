<?php
$googleAuthEnabled = strtolower(trim((string) tocca_config('voter_auth_mode'))) === 'google_with_legacy';
$googleQrChoiceId = isset($choice_id) ? (int) $choice_id : 0;
?>
<script>
window.TOCCA_GOOGLE_AUTH = <?php echo json_encode([
    'enabled' => $googleAuthEnabled,
    'legacyEnabled' => true,
    'qrChoiceId' => $googleQrChoiceId,
], JSON_UNESCAPED_SLASHES); ?>;
</script>
<div class="modal fade voter-modal" id="googleAuthModal" tabindex="-1" aria-labelledby="googleAuthModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="googleAuthModalLabel">Sign in to vote</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body px-4 py-4 text-center">
        <p class="mb-3">Use your Google account to start or resume your ballot. No SMS will be sent.</p>
        <div id="googleEmbeddedBrowserWarning" class="alert alert-warning text-start small d-none" role="alert">
          Google may block sign-in inside Facebook, Messenger, or Instagram. Open this page in Chrome or Safari, then try again.
        </div>
        <button type="button" class="btn btn-primary w-100 py-2" id="googleSignInBtn">
          <span id="googleSignInSpinner" class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true"></span>
          <i class="fa-brands fa-google me-2" aria-hidden="true"></i>
          <span id="googleSignInLabel">Continue with Google</span>
        </button>
        <div id="googleAuthMessage" class="small mt-3" role="status" aria-live="polite"></div>
        <div class="d-flex align-items-center gap-2 my-3 text-muted small" aria-hidden="true">
          <span class="border-top flex-grow-1"></span><span>existing voter?</span><span class="border-top flex-grow-1"></span>
        </div>
        <button type="button" class="btn btn-outline-primary w-100" id="legacyVoterLoginBtn">
          Use mobile number + access code
        </button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade voter-modal" id="linkGoogleModal" tabindex="-1" aria-labelledby="linkGoogleModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="linkGoogleModalLabel">Use Google next time?</h5>
      </div>
      <div class="modal-body px-4 py-4 text-center">
        <p>Linking Google keeps this same voter record, including all drafts and completed votes.</p>
        <button type="button" class="btn btn-primary w-100" id="linkGoogleNowBtn">
          <span id="linkGoogleSpinner" class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true"></span>
          <i class="fa-brands fa-google me-2" aria-hidden="true"></i>
          <span id="linkGoogleLabel">Link Google account</span>
        </button>
        <button type="button" class="btn btn-link w-100 mt-2" id="skipGoogleLinkBtn">Not now</button>
        <div id="linkGoogleMessage" class="small mt-2" role="status" aria-live="polite"></div>
      </div>
    </div>
  </div>
</div>
