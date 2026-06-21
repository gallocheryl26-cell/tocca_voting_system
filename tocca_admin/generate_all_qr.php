<?php
require_once __DIR__ . '/require_admin_api.php';
require_once 'qr_utils.php';
require_once 'qr_comm.php';

try {
    if (!($conn instanceof mysqli)) {
        throw new Exception('Database connection not available.');
    }

    // Decide whether to force overwrite (default: true for bulk)
    $source = array_merge($_GET ?? [], $_POST ?? []);
    $force  = isset($source['force']) ? (bool)$source['force'] : true;

    // 🔧 Load the SAME frame config used by the draggable preview
    $storedConfig = qr_frame_load_config($conn);   // from qr_frame_config.php
    $frameOptions = [];

    if (is_array($storedConfig)) {
        // Pass the raw config JSON so normalize_frame_overrides()
        // can map frame_box_x, frame_box_y, etc. correctly.
        $frameOptions['frame_config'] = json_encode($storedConfig);

        // Ensure use_frame is ON (even if some legacy flag disagrees)
        if (array_key_exists('use_frame', $storedConfig)) {
            $frameOptions['use_frame'] = (bool)$storedConfig['use_frame'];
        } else {
            $frameOptions['use_frame'] = true;
        }

        // If we have an absolute path from DB, give it explicitly too
        if (!empty($storedConfig['frame_path_absolute'])) {
            $frameOptions['frame_path'] = $storedConfig['frame_path_absolute'];
        } elseif (!empty($storedConfig['frame_path'])) {
            $frameOptions['frame_path'] = $storedConfig['frame_path'];
        }
    } else {
        // Fallback: force constants if DB config not found
        $frameOptions = [
            'use_frame'  => true,
            'frame_path' => FRAME_PATH,
            'box_x'      => FRAME_BOX_X,
            'box_y'      => FRAME_BOX_Y,
            'box_w'      => FRAME_BOX_W,
            'box_h'      => FRAME_BOX_H,
        ];
    }

    // Get all active establishments
    $sql    = "SELECT choice_id, choice_name FROM tbl_choices WHERE status = 1";
    $result = $conn->query($sql);

    if (!$result || $result->num_rows === 0) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'No active businesses found.'
        ]);
        exit;
    }

    $generated = [];
    $skipped   = [];
    $noFrame   = [];
    $errors    = [];

    while ($row = $result->fetch_assoc()) {
        $choiceId   = (int)$row['choice_id'];
        $choiceName = $row['choice_name'];

        // Use the SAME frame options for every QR in this batch
        $qr     = generateAndSaveQR($choiceId, $force, $frameOptions);
        $status = $qr['status'] ?? 'error';

        if ($status === 'success') {
            $name = $qr['name'] ?? $choiceName;
            $generated[] = $name;

            // Track if, for some reason, it still fell back to no frame
            if (empty($qr['frame']['used'])) {
                $noFrame[] = $name;
            }
        } elseif ($status === 'skipped') {
            // (Kept for compatibility, though generateAndSaveQR currently always overwrites)
            $skipped[] = $qr['name'] ?? $choiceName;
        } else {
            $errors[] = [
                'choice_id' => $choiceId,
                'name'      => $choiceName,
                'message'   => $qr['message'] ?? 'Unknown error during QR generation.'
            ];
        }
    }

    echo json_encode([
        'status'    => 'success',
        'message'   => $force
            ? 'Bulk QR regeneration completed.'
            : 'Bulk QR generation completed.',
        'generated' => $generated,
        'skipped'   => $skipped,
        'no_frame'  => $noFrame,  // for debugging (can ignore in JS)
        'errors'    => $errors,   // for debugging (can ignore in JS)
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Bulk QR generation failed: ' . $e->getMessage()
    ]);
}
