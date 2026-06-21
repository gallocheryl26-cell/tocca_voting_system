<?php
// Shared modal markup used by the voter pages to preview an establishment's
// images and videos. Included from selected-category.php, summarypoll.php, and
// QR pages so the same UI works across the voting flow.
?>
<div class="modal fade voter-modal" id="choiceMediaModal" tabindex="-1" aria-labelledby="choiceMediaModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="choiceMediaModalLabel">Business Preview</h5>
      </div>
      <div class="modal-body choice-media-modal-body">
        <div id="choiceMediaLoading" class="choice-media-state text-center py-4">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
          <div class="text-muted small mt-2">Loading photos and videos…</div>
        </div>

        <div id="choiceMediaEmpty" class="choice-media-state text-center py-4 d-none">
          <div class="choice-media-empty-icon" aria-hidden="true"><i class="fa-solid fa-image"></i></div>
          <p class="mb-0 fw-semibold">No media yet</p>
          <p class="text-muted small mb-0">Photos or videos for this business have not been uploaded yet.</p>
        </div>

        <div id="choiceMediaError" class="alert alert-warning small d-none choice-media-state" role="alert"></div>

        <div id="choiceMediaCarouselWrapper" class="d-none">
          <div id="choiceMediaCarousel" class="carousel slide" data-bs-ride="false">
            <div class="carousel-indicators" id="choiceMediaIndicators"></div>
            <div class="carousel-inner" id="choiceMediaCarouselInner"></div>
            <button class="carousel-control-prev" type="button" data-bs-target="#choiceMediaCarousel" data-bs-slide="prev">
              <span class="carousel-control-prev-icon" aria-hidden="true"></span>
              <span class="visually-hidden">Previous</span>
            </button>
            <button class="carousel-control-next" type="button" data-bs-target="#choiceMediaCarousel" data-bs-slide="next">
              <span class="carousel-control-next-icon" aria-hidden="true"></span>
              <span class="visually-hidden">Next</span>
            </button>
          </div>
          <div id="choiceMediaCaption" class="text-center text-muted small mt-2"></div>
          <p id="choiceMediaCounter" class="text-center text-muted small mb-0 mt-1"></p>
        </div>
      </div>
      <div class="modal-footer">
        <small class="text-muted me-auto"><i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>Previewing does not cast your vote.</small>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
