<?php
declare(strict_types=1);

/**
 * Legacy URL — appearance settings merged into Voter Portal.
 */
$tab = (isset($_GET['tab']) && $_GET['tab'] === 'content') ? 'content' : 'appearance';
header('Location: voter_portal_copy.php?tab=' . urlencode($tab), true, 302);
exit;
