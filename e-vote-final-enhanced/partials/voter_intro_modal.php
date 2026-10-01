<div class="modal fade voter-modal" id="introModal" tabindex="-1" aria-labelledby="introModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content overflow-hidden">
      <div class="modal-header">
        <h5 class="modal-title" id="introModalLabel"><?php echo $introTitleHtml; ?></h5>
      </div>
      <div class="modal-body">
        <div id="introModalCopy"><?php echo $introBodyHtml; ?></div>
        <?php if (!$votingOnHold): ?>
        <div class="intro-consent-box">
          <p class="mb-2 fw-semibold">Please review and acknowledge before proceeding:</p>
          <div class="form-check mb-2">
            <input class="form-check-input intro-consent-checkbox" type="checkbox" value="" id="agreeTerms" />
            <label class="form-check-label" for="agreeTerms">
              I have read and agree to the <a href="<?php echo tocca_voter_href('terms_and_conditions.php'); ?>" target="_blank" rel="noopener noreferrer">Terms and Conditions</a>.
            </label>
          </div>
          <div class="form-check mb-0">
            <input class="form-check-input intro-consent-checkbox" type="checkbox" value="" id="agreePrivacy" />
            <label class="form-check-label" for="agreePrivacy">
              I have read and agree to the <a href="<?php echo tocca_voter_href('privacy_policy.php'); ?>" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.
            </label>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer modal-footer-custom justify-content-end">
        <button type="button" class="btn btn-tocca-close disabled" id="closeIntroBtn" disabled>Proceed to sign in <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i></button>
      </div>
    </div>
  </div>
</div>
