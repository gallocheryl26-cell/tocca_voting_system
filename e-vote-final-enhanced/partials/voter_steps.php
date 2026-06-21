<?php
/** Voting step indicator. Set $voterStep (1–3) before include. */
$voterStep = isset($voterStep) ? (int) $voterStep : 1;
?>
<nav class="voter-steps" aria-label="Voting progress">
  <ol>
    <li class="<?php echo $voterStep > 1 ? 'done' : ($voterStep === 1 ? 'active' : ''); ?>">
      <span class="step-num" aria-hidden="true"><?php echo $voterStep > 1 ? '✓' : '1'; ?></span>
      Categories
    </li>
    <li class="<?php echo $voterStep > 2 ? 'done' : ($voterStep === 2 ? 'active' : ''); ?>">
      <span class="step-num" aria-hidden="true"><?php echo $voterStep > 2 ? '✓' : '2'; ?></span>
      Vote
    </li>
    <li class="<?php echo $voterStep === 3 ? 'active' : ''; ?>">
      <span class="step-num" aria-hidden="true">3</span>
      Summary
    </li>
  </ol>
</nav>
