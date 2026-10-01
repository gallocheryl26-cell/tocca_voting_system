<?php
$guideVersion = (int) (@filemtime(__DIR__ . '/../js/voting_tutorial.js') ?: time());
$guideCssVersion = (int) (@filemtime(__DIR__ . '/../css/voting-tutorial.css') ?: time());
$guideNarration = json_decode((string) @file_get_contents(__DIR__ . '/../audio/voter-guide/manifest.json'), true) ?: [];
?>
<script type="application/json" id="voteGuideNarration"><?= json_encode($guideNarration, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
<link rel="stylesheet" href="css/voting-tutorial.css?v=<?= $guideCssVersion ?>">
<div class="modal fade vote-guide-modal" id="voteTutorialModal" lang="en" tabindex="-1" aria-labelledby="voteGuideTitle" aria-describedby="voteGuideDescription" data-default-flow="<?= ($voteTutorialFlow ?? 'main') === 'qr' ? 'qr' : 'main' ?>">
  <div class="modal-dialog">
    <div class="modal-content vote-guide-dialog">
      <header class="vote-guide-header">
        <div><span class="vote-guide-eyebrow">TATAK ORMOC · VOTER GUIDE</span><h2 id="voteGuideTitle">How to vote</h2><p id="voteGuideDescription">Plays automatically. Pause or jump to any step.</p></div>
        <button type="button" class="vote-guide-close" data-bs-dismiss="modal" aria-label="Close voting tutorial">×</button>
      </header>
      <div class="vote-guide-modes" role="group" aria-label="Choose voting guide">
        <button type="button" data-guide-flow="main" aria-pressed="true"><i class="fa-solid fa-list-check" aria-hidden="true"></i> Main voting site</button>
        <button type="button" data-guide-flow="qr" aria-pressed="false"><i class="fa-solid fa-qrcode" aria-hidden="true"></i> Store QR voting</button>
        <span>Example ballot · Your votes stay unchanged</span>
      </div>
      <div class="vote-guide-mobile-step"><label for="voteGuideStepSelect">Tutorial step</label><select id="voteGuideStepSelect" aria-label="Choose tutorial step"></select></div>
      <div class="vote-guide-workspace">
        <nav class="vote-guide-map" aria-label="Voting tutorial steps"><ol id="voteGuideSteps"></ol></nav>
        <div class="vote-guide-stage">
          <div class="vote-guide-location"><span id="voteGuideLocation"></span><span class="vote-guide-example">Preview</span></div>
          <div class="vote-guide-viewport">
            <iframe id="voteGuideFrame" title="Example voting screen" sandbox="allow-scripts allow-same-origin" referrerpolicy="no-referrer"></iframe>
            <p id="voteGuideLoading" role="status">Loading voting screen…</p>
          </div>
          <div class="vote-guide-caption" aria-live="polite" aria-atomic="true">
            <span id="voteGuideCounter"></span><h3 id="voteGuideStepTitle"></h3><p id="voteGuideStepDescription"></p>
          </div>
        </div>
      </div>
      <footer class="vote-guide-player">
        <audio id="voteGuideAudio" preload="auto" aria-hidden="true"></audio>
        <div class="vote-guide-audio">
          <button type="button" id="voteGuideSound" aria-pressed="false" aria-label="Read tutorial steps aloud"><i class="fa-solid fa-volume-high" aria-hidden="true"></i> <span>Read aloud</span></button>
          <div class="vote-guide-voice"><label class="visually-hidden" for="voteGuideVoice">Narration voice</label><select id="voteGuideVoice" aria-label="Narration voice"><option value="guide">Guide narrator (English)</option></select></div>
          <button type="button" id="voteGuideReadAgain" aria-label="Read this step again" title="Read this step again" disabled><i class="fa-solid fa-rotate-right" aria-hidden="true"></i></button>
          <span id="voteGuideSpeechStatus" role="status">Turn on sound to hear each step.</span>
        </div>
        <div class="vote-guide-progress-caption"><span id="voteGuidePlaybackStatus">Explore at your own pace</span><strong id="voteGuideProgressText"></strong></div>
        <input id="voteGuideTimeline" type="range" min="1" value="1" aria-label="Jump to voting tutorial step">
        <div class="vote-guide-player-buttons">
          <button type="button" id="voteGuidePrevious" aria-label="Previous tutorial step"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <span>Previous</span></button>
          <div><button type="button" id="voteGuideRestart" aria-label="Restart voting tutorial"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i></button><button type="button" id="voteGuidePlay" class="vote-guide-play" aria-label="Play voting tutorial"><i class="fa-solid fa-play" aria-hidden="true"></i> <span>Play guide</span></button></div>
          <button type="button" id="voteGuideNext" aria-label="Next tutorial step"><span>Next</span> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
        </div>
      </footer>
    </div>
  </div>
</div>
<script src="js/voting_tutorial.js?v=<?= $guideVersion ?>" defer></script>
