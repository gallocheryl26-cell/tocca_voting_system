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
<div class="modal fade voter-modal voter-google-auth-modal" id="googleAuthModal" tabindex="-1" aria-labelledby="googleAuthModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="google-auth-hero">
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        <div class="google-auth-brand">
          <div class="google-auth-logo" aria-hidden="true">
            <img src="<?php echo htmlspecialchars((string) ($headerLogoSrc ?? 'img/tocca2023.jpg'), ENT_QUOTES, 'UTF-8'); ?>" alt="" onerror="this.style.display='none'">
            <i class="fa-solid fa-award"></i>
          </div>
          <p class="google-auth-kicker">Tatak Ormoc • Consumers’ Choice Awards</p>
          <h5 class="modal-title" id="googleAuthModalLabel">Your vote starts here.</h5>
          <p class="google-auth-subtitle">Sign in securely to start or continue your ballot.</p>
        </div>
      </div>
      <div class="modal-body google-auth-body">
        <div class="google-auth-promise">
          <i class="fa-solid fa-shield-heart" aria-hidden="true"></i>
          <span>One ballot is tied to one Google account.</span>
        </div>
        <div id="googleEmbeddedBrowserWarning" class="alert alert-warning text-start small d-none" role="alert">
          Google may block sign-in inside Facebook, Messenger, or Instagram. Open this page in Chrome or Safari, then try again.
        </div>
        <button type="button" class="btn google-auth-button w-100" id="googleSignInBtn">
          <span id="googleSignInSpinner" class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true"></span>
          <i class="fa-brands fa-google me-2" aria-hidden="true"></i>
          <span id="googleSignInLabel">Continue with Google</span>
        </button>
        <p class="google-auth-security-note"><i class="fa-solid fa-lock" aria-hidden="true"></i> Your Google password is never shared with TOCCA.</p>
        <div id="googleAuthMessage" class="small" role="status" aria-live="polite"></div>
        <div class="google-auth-divider" aria-hidden="true">
          <span></span><small>RETURNING VOTER</small><span></span>
        </div>
        <button type="button" class="btn google-auth-legacy-button w-100" id="legacyVoterLoginBtn">
          <i class="fa-solid fa-key me-2" aria-hidden="true"></i>Use mobile number + access code
        </button>
        <p class="google-auth-terms">By continuing, you agree to the voting <a href="<?php echo htmlspecialchars(tocca_voter_href('terms_and_conditions.php'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Terms</a> and <a href="<?php echo htmlspecialchars(tocca_voter_href('privacy_policy.php'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.</p>
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
