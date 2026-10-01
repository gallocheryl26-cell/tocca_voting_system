<?php
$googleAuthEnabled = strtolower(trim((string) tocca_config('voter_auth_mode'))) === 'google_with_legacy';
$googleQrChoiceId = isset($choice_id) ? (int) $choice_id : 0;
?>
<script>
window.TOCCA_GOOGLE_AUTH = <?php echo json_encode([
    'enabled' => $googleAuthEnabled,
    'legacyEnabled' => false,
    'qrChoiceId' => $googleQrChoiceId,
], JSON_UNESCAPED_SLASHES); ?>;
</script>
<div class="modal fade voter-modal" id="googleAuthModal" tabindex="-1" aria-labelledby="googleAuthModalLabel" aria-describedby="googleAuthDescription" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="googleAuthModalLabel">Sign in to vote</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body px-4 py-4 text-center">
        <p id="googleAuthDescription" class="mb-2">Choose a Google account to start or continue voting.</p>
        <p class="small text-muted mb-4">Use the same account each time to keep your voting progress together.</p>
        <div id="googleEmbeddedBrowserWarning" class="alert alert-warning text-start small d-none" role="alert">
          Google may block sign-in inside Facebook, Messenger, or Instagram. Open this page in Chrome or Safari, then try again.
        </div>
        <button type="button" class="btn btn-primary w-100 py-2" id="googleSignInBtn">
          <span id="googleSignInSpinner" class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true"></span>
          <i class="fa-brands fa-google me-2" aria-hidden="true"></i>
          <span id="googleSignInLabel">Continue with Google</span>
        </button>
        <div id="googleAuthMessage" class="small mt-3" role="status" aria-live="polite"></div>
        <p class="small text-muted mb-0">Google will open in a new window. After signing in, you’ll return to your ballot.</p>
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
