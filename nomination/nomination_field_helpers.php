<?php
declare(strict_types=1);

/**
 * Shared registration field loading, health checks, and HTML rendering.
 * Used by the public registration form, admin preview, and maintenance tools.
 */

$__nf_schema = dirname(__DIR__) . '/tocca_admin/includes/admin_schema.php';
if (is_file($__nf_schema)) {
    require_once $__nf_schema;
}

if (!function_exists('nf_column_exists')) {
    function nf_column_exists(mysqli $conn, string $column): bool
    {
        return function_exists('admin_schema_column_exists')
            && admin_schema_column_exists($conn, 'tbl_nomination_fields', $column);
    }
}

if (!function_exists('nf_nomination_is_editable')) {
    /** Applicant may edit until Approve / Reject (needs_info and in_review stay editable). */
    function nf_nomination_is_editable(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), ['pending', 'submitted', 'needs_info', 'in_review', 'new', ''], true);
    }
}

if (!function_exists('nf_reference_candidates')) {
    /** Exact ref plus NOM-/REG- alias so old emails still resolve after prefix migration. */
    function nf_reference_candidates(string $raw): array
    {
        $ref = function_exists('tocca_normalize_reference')
            ? tocca_normalize_reference($raw)
            : strtoupper(preg_replace('/[^A-Z0-9\-]/', '', strtoupper(trim($raw))) ?? '');
        if ($ref === '') {
            return [];
        }
        $out = [$ref];
        if (preg_match('/^(NOM|REG)-(.+)$/', $ref, $m)) {
            $out[] = ($m[1] === 'NOM' ? 'REG' : 'NOM') . '-' . $m[2];
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('nf_fetch_nomination_by_reference')) {
    function nf_fetch_nomination_by_reference(mysqli $conn, string $raw): ?array
    {
        foreach (nf_reference_candidates($raw) as $ref) {
            $st = $conn->prepare(
                'SELECT nomination_id, event_id, reference_no, status FROM tbl_nominations WHERE reference_no = ? LIMIT 1'
            );
            if (!$st) {
                return null;
            }
            $st->bind_param('s', $ref);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row) {
                return $row;
            }
        }
        return null;
    }
}

/**
 * One answer per field per registration. Missing unique key made ON DUPLICATE KEY
 * INSERT extra rows on every update.
 */
if (!function_exists('nf_ensure_answers_unique')) {
    function nf_ensure_answers_unique(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $idx = $conn->query("SHOW INDEX FROM tbl_nomination_answers WHERE Key_name = 'uq_nom_answers_field'");
            $hasUnique = $idx instanceof mysqli_result && $idx->num_rows > 0;
            if ($idx instanceof mysqli_result) {
                $idx->free();
            }
            if ($hasUnique) {
                return;
            }
            $conn->query(
                'DELETE a FROM tbl_nomination_answers a
                 INNER JOIN tbl_nomination_answers b
                   ON a.nomination_id = b.nomination_id
                  AND a.field_id = b.field_id
                  AND a.id < b.id'
            );
            $conn->query(
                'ALTER TABLE tbl_nomination_answers
                 ADD UNIQUE KEY uq_nom_answers_field (nomination_id, field_id)'
            );
        } catch (Throwable $e) {
            error_log('nf_ensure_answers_unique: ' . $e->getMessage());
        }
    }
}

if (!function_exists('nf_upsert_nomination_answer')) {
    function nf_upsert_nomination_answer(mysqli $conn, int $nominationId, int $fieldId, string $answer): void
    {
        nf_ensure_answers_unique($conn);
        $find = $conn->prepare(
            'SELECT id FROM tbl_nomination_answers
             WHERE nomination_id = ? AND field_id = ?
             ORDER BY id DESC LIMIT 1'
        );
        if (!$find) {
            throw new RuntimeException('Prepare answer lookup failed: ' . $conn->error);
        }
        $find->bind_param('ii', $nominationId, $fieldId);
        $find->execute();
        $row = $find->get_result()->fetch_assoc();
        $find->close();
        if ($row) {
            $id = (int) $row['id'];
            $upd = $conn->prepare('UPDATE tbl_nomination_answers SET answer = ? WHERE id = ?');
            if (!$upd) {
                throw new RuntimeException('Prepare answer update failed: ' . $conn->error);
            }
            $upd->bind_param('si', $answer, $id);
            if (!$upd->execute()) {
                throw new RuntimeException('Update answer failed: ' . $upd->error);
            }
            $upd->close();
            return;
        }
        $ins = $conn->prepare(
            'INSERT INTO tbl_nomination_answers (nomination_id, field_id, answer) VALUES (?, ?, ?)'
        );
        if (!$ins) {
            throw new RuntimeException('Prepare answer insert failed: ' . $conn->error);
        }
        $ins->bind_param('iis', $nominationId, $fieldId, $answer);
        if (!$ins->execute()) {
            throw new RuntimeException('Insert answer failed: ' . $ins->error);
        }
        $ins->close();
    }
}

if (!function_exists('nf_parse_options')) {
    function nf_parse_options($raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (is_array($raw)) {
            return array_values(array_filter(array_map('trim', $raw), static fn($v) => $v !== ''));
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return [];
        }
        $try = json_decode($s, true);
        if (is_array($try)) {
            return array_values(array_filter(array_map('trim', $try), static fn($v) => $v !== ''));
        }
        $parts = preg_split('/[\r\n,\|]+/u', $s);
        return array_values(array_filter(array_map('trim', $parts), static fn($v) => $v !== ''));
    }
}

if (!function_exists('nf_parse_validation')) {
    /** @return array<string, mixed> */
    function nf_parse_validation(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('nf_normalize_validation_input')) {
  /**
   * @param array<string, mixed> $post
   * @return string|null JSON or null
   */
    function nf_normalize_validation_input(array $post, string $type): ?string
    {
        $out = [];
        $maxLen = trim((string) ($post['val_max_length'] ?? ''));
        if ($maxLen !== '' && ctype_digit($maxLen) && $type !== 'file') {
            $out['max_length'] = (int) $maxLen;
        }
        $pattern = trim((string) ($post['val_pattern'] ?? ''));
        if ($pattern !== '' && $type !== 'file') {
            $out['pattern'] = $pattern;
        }
        $accept = trim((string) ($post['val_accept'] ?? ''));
        if ($accept !== '' && $type === 'file') {
            $out['accept'] = $accept;
        }
        $min = trim((string) ($post['val_min'] ?? ''));
        $max = trim((string) ($post['val_max'] ?? ''));
        if ($min !== '' && is_numeric($min)) {
            $out['min'] = $min + 0;
        }
        if ($max !== '' && is_numeric($max)) {
            $out['max'] = $max + 0;
        }
        return $out === [] ? null : json_encode($out, JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('nf_field_event_id_from_post')) {
    /**
     * Resolve event_id for a registration field from POST (radio or legacy checkbox).
     * NULL = shared across all events.
     */
    function nf_field_event_id_from_post(array $post, ?int $activeEventId): ?int
    {
        if ($activeEventId === null || $activeEventId <= 0) {
            return null;
        }
        $scope = strtolower(trim((string) ($post['event_scope'] ?? '')));
        if ($scope === 'event') {
            return $activeEventId;
        }
        if ($scope === 'all') {
            return null;
        }
        if (!empty($post['event_only'])) {
            return $activeEventId;
        }

        return $activeEventId;
    }
}

if (!function_exists('nf_select_sql')) {
    function nf_select_sql(mysqli $conn): string
    {
        $cols = ['id', 'name', 'label', '`type`', 'options', 'is_required', 'is_active', 'sort_order'];
        foreach (['event_id', 'help_text', 'placeholder', 'validation_json', 'profile_role', 'field_width'] as $c) {
            if (nf_column_exists($conn, $c)) {
                $cols[] = $c;
            }
        }
        return implode(', ', $cols);
    }
}

if (!function_exists('nf_is_designation_field')) {
    /** Field formerly labeled Designation (internal name kept). */
    function nf_is_designation_field(array $f): bool
    {
        $name  = strtolower(trim((string) ($f['name'] ?? '')));
        $label = strtolower(trim((string) ($f['label'] ?? '')));
        if ($name !== '' && str_contains($name, 'designation')) {
            return true;
        }
        if ($label !== '' && (
            preg_match('/\bdesignation\b/', $label)
            || preg_match('/type of (ownership|business)/', $label)
            || preg_match('/^type of business(\s*\/\s*company)?$/', $label)
        )) {
            return true;
        }
        return false;
    }
}

if (!function_exists('nf_is_ownership_type_value')) {
    /** True when an answer is a legal structure, not an establishment name. */
    function nf_is_ownership_type_value(string $value): bool
    {
        $v = strtolower(trim($value));
        if ($v === '') {
            return false;
        }
        return (bool) preg_match(
            '/^(sole\s+proprietorship|single\s+proprietorship|partnership|corporation|one\s*person\s*corporation|\bopc\b|cooperative|co-?operative|co-?op|llc|ltd\.?|inc\.?)$/i',
            $v
        );
    }
}

if (!function_exists('nf_is_business_name_field')) {
    /** Official / registered establishment name — not Type of Ownership. */
    function nf_is_business_name_field(array $f): bool
    {
        if (nf_is_designation_field($f)) {
            return false;
        }
        $name  = strtolower(trim((string) ($f['name'] ?? '')));
        $label = strtolower(trim((string) ($f['label'] ?? '')));
        if (in_array($name, ['official_business_name', 'business_name', 'name_of_business', 'company_name'], true)) {
            return true;
        }
        $looksLikeName = (bool) preg_match(
            '/official.*business.*name|(^|\b)business\s*name\b|company\s*name|establishment\s*name|trade\s*name|store\s*name|name of (the )?(business|company|establishment)/',
            $label
        );
        if ($looksLikeName) {
            return true;
        }
        return false;
    }
}

if (!function_exists('nf_pick_business_name')) {
    /**
     * Pick the establishment name from answer rows (name/label/profile_role/answer).
     *
     * @param list<array<string,mixed>> $fieldRows
     */
    function nf_pick_business_name(array $fieldRows, string $fallback = ''): string
    {
        $best = '';
        $bestRank = 99;
        foreach ($fieldRows as $f) {
            $ans = trim((string) ($f['answer'] ?? $f['value'] ?? ''));
            if ($ans === '' || nf_is_ownership_type_value($ans) || !nf_is_business_name_field($f)) {
                continue;
            }
            $name  = strtolower(trim((string) ($f['name'] ?? '')));
            $label = strtolower(trim((string) ($f['label'] ?? '')));
            $rank = 3;
            if ($name === 'official_business_name' || preg_match('/official.*business.*name/', $label)) {
                $rank = 0;
            } elseif (in_array($name, ['business_name', 'name_of_business', 'company_name'], true)) {
                $rank = 1;
            } elseif (preg_match('/(^|\b)business\s*name\b|company\s*name/', $label)) {
                $rank = 2;
            }
            if ($rank < $bestRank) {
                $bestRank = $rank;
                $best = $ans;
            }
        }
        if ($best !== '') {
            return $best;
        }
        $fb = trim($fallback);
        if ($fb !== '' && !nf_is_ownership_type_value($fb)) {
            return $fb;
        }
        return '';
    }
}

if (!function_exists('nf_public_field_label')) {
    function nf_public_field_label(array $f): string
    {
        if (nf_is_designation_field($f)) {
            return 'Type of Ownership';
        }
        return trim((string) ($f['label'] ?? ''));
    }
}

if (!function_exists('nf_enrich_field')) {
    /** @param array<string, mixed> $row */
    function nf_enrich_field(array $row): array
    {
        $row['label']           = nf_public_field_label($row);
        $row['options_arr']     = nf_parse_options($row['options'] ?? null);
        $row['validation_arr']  = nf_parse_validation($row['validation_json'] ?? null);
        $row['profile_role']    = (string) ($row['profile_role'] ?? 'custom');
        $row['field_width']     = (string) ($row['field_width'] ?? 'half');
        $row['help_text']       = (string) ($row['help_text'] ?? '');
        $row['placeholder']     = (string) ($row['placeholder'] ?? '');
        $row['event_id']        = isset($row['event_id']) && $row['event_id'] !== null && $row['event_id'] !== ''
            ? (int) $row['event_id'] : null;
        if (($row['type'] ?? '') === 'file' && is_array($row['validation_arr'])) {
            unset($row['validation_arr']['pattern'], $row['validation_arr']['max_length'], $row['validation_arr']['min'], $row['validation_arr']['max']);
        }
        return $row;
    }
}

if (!function_exists('nf_load_fields')) {
    /**
     * @param array{active_only?:bool, include_inactive?:bool, maintenance_list?:bool} $opts
     * @return list<array<string, mixed>>
     */
    function nf_load_fields(mysqli $conn, int $eventId = 0, array $opts = []): array
    {
        $activeOnly = $opts['active_only'] ?? true;
        $maintList  = $opts['maintenance_list'] ?? false;
        nf_ensure_answers_unique($conn);

        $sql    = 'SELECT ' . nf_select_sql($conn) . ' FROM tbl_nomination_fields WHERE 1=1';
        $types  = '';
        $params = [];

        if ($activeOnly && empty($opts['include_inactive'])) {
            $sql .= ' AND is_active = 1';
        }

        if ($maintList && $eventId > 0 && nf_column_exists($conn, 'event_id')) {
            $sql .= ' AND (event_id IS NULL OR event_id = ?)';
            $types  .= 'i';
            $params[] = $eventId;
        } elseif (!$maintList && $eventId > 0 && nf_column_exists($conn, 'event_id')) {
            $sql .= ' AND (event_id IS NULL OR event_id = ?)';
            $types  .= 'i';
            $params[] = $eventId;
        }

        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $rows = [];
        if ($types === '') {
            $res = $conn->query($sql);
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $rows[] = nf_enrich_field($r);
                }
                $res->free();
            }
            return $rows;
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = nf_enrich_field($r);
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('nf_field_col_class')) {
    function nf_field_col_class(array $f): string
    {
        return (($f['field_width'] ?? 'half') === 'full') ? 'col-12' : 'col-12 col-md-6';
    }
}

if (!function_exists('nf_file_accept')) {
    function nf_file_accept(array $f): string
    {
        $role = (string) ($f['profile_role'] ?? '');
        // Mayor's permit and logo are always image uploads.
        if ($role === 'mayor_permit' || $role === 'logo') {
            return '.png,.jpg,.jpeg,.webp';
        }
        $accept = $f['validation_arr']['accept'] ?? null;
        if (is_string($accept) && trim($accept) !== '') {
            return trim($accept);
        }
        return '.png,.jpg,.jpeg,.webp';
    }
}

if (!function_exists('nf_input_attrs')) {
    /** @return array<string, string> */
    function nf_input_attrs(array $f, bool $preview = false): array
    {
        $attrs = [];
        if (!$preview && !empty($f['is_required'])) {
            $attrs['required'] = 'required';
        }
        $ph = trim((string) ($f['placeholder'] ?? ''));
        if ($ph !== '') {
            $attrs['placeholder'] = $ph;
        }
        $v = $f['validation_arr'] ?? [];
        if (!empty($v['max_length'])) {
            $attrs['maxlength'] = (string) (int) $v['max_length'];
        }
        if (!empty($v['pattern']) && ($f['type'] ?? '') !== 'file') {
            $attrs['pattern'] = (string) $v['pattern'];
        }
        if (isset($v['min']) && ($f['type'] ?? '') === 'number') {
            $attrs['min'] = (string) $v['min'];
        }
        if (isset($v['max']) && ($f['type'] ?? '') === 'number') {
            $attrs['max'] = (string) $v['max'];
        }
        $fieldType = (string) ($f['type'] ?? '');
        if (!$preview && $fieldType === 'tel') {
            $attrs['inputmode'] = 'tel';
            $attrs['autocomplete'] = 'tel';
        }
        if (!$preview && $fieldType === 'email') {
            $attrs['autocomplete'] = 'email';
            $attrs['autocorrect'] = 'off';
            $attrs['autocapitalize'] = 'none';
            $attrs['spellcheck'] = 'false';
        }
        return $attrs;
    }
}

if (!function_exists('nf_attrs_string')) {
    function nf_attrs_string(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $k => $v) {
            $parts[] = $k . '="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '"';
        }
        return $parts ? ' ' . implode(' ', $parts) : '';
    }
}

if (!function_exists('nf_render_field')) {
    /**
     * @param array<string, mixed> $f
     * @param array{preview?:bool, h?:callable} $opts
     */
    function nf_render_field(array $f, array $opts = []): void
    {
        $preview = !empty($opts['preview']);
        $h       = $opts['h'] ?? static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $fid      = (int) $f['id'];
        $inputId  = 'nf_' . $fid;
        $dbName   = trim((string) ($f['name'] ?? ''));
        $baseName = $dbName !== '' ? $dbName : "fields[$fid]";
        $chkName  = $dbName !== '' ? "{$dbName}[]" : "fields[$fid][]";
        $fileName = $dbName !== '' ? $dbName : "files[$fid]";
        $required = !empty($f['is_required']);
        $reqMark  = $required ? '<span class="text-danger">*</span>' : '';
        $type     = (string) $f['type'];
        $options  = $f['options_arr'] ?? [];
        $inactive = empty($f['is_active']);
        $attrStr  = nf_attrs_string(nf_input_attrs($f, $preview));

        $colClass = nf_field_col_class($f);
        $wrapClass = $colClass;
        if ($type === 'select') {
            $wrapClass .= ' nom-field-select-wrap';
        }
        if ($preview && $inactive) {
            $wrapClass .= ' nf-preview-inactive';
        }
        ?>
        <div class="<?= $h($wrapClass) ?>" data-field-id="<?= $fid ?>" data-field-type="<?= $h($type) ?>" data-profile-role="<?= $h((string) ($f['profile_role'] ?? 'custom')) ?>">
          <?php if ($preview && $inactive): ?>
            <span class="badge bg-secondary mb-1">Hidden from applicants</span>
          <?php endif; ?>
          <label class="form-label" for="<?= $h($inputId) ?>">
            <?= $h($f['label'] ?? '') ?> <?= $reqMark ?>
          </label>
          <?php
          if ($type === 'textarea') {
              echo '<textarea id="' . $h($inputId) . '" name="' . $h($baseName) . '" class="form-control"' . $attrStr;
              if ($preview) {
                  echo ' disabled';
              }
              echo '></textarea>';
          } elseif ($type === 'select') {
              echo '<select id="' . $h($inputId) . '" name="' . $h($baseName) . '" class="form-select"' . $attrStr;
              if ($preview) {
                  echo ' disabled';
              }
              echo '><option value="">-- Select --</option>';
              foreach ($options as $opt) {
                  echo '<option value="' . $h($opt) . '">' . $h($opt) . '</option>';
              }
              echo '</select>';
          } elseif ($type === 'checkbox') {
              echo '<div class="d-flex flex-wrap gap-2">';
              if (!empty($options)) {
                  foreach ($options as $idx => $opt) {
                      $cid = $inputId . '_cb_' . $idx;
                      echo '<div class="form-check"><input class="form-check-input" type="checkbox" id="' . $h($cid) . '" name="' . $h($chkName) . '" value="' . $h($opt) . '"' . $attrStr;
                      if ($preview) {
                          echo ' disabled';
                      }
                      echo '><label class="form-check-label" for="' . $h($cid) . '">' . $h($opt) . '</label></div>';
                  }
              } else {
                  echo '<div class="text-muted small">No options configured.</div>';
              }
              echo '</div>';
          } elseif ($type === 'radio') {
              echo '<div class="d-flex flex-wrap gap-2">';
              if (!empty($options)) {
                  foreach ($options as $idx => $opt) {
                      $rid = $inputId . '_r_' . $idx;
                      echo '<div class="form-check"><input class="form-check-input" type="radio" id="' . $h($rid) . '" name="' . $h($baseName) . '" value="' . $h($opt) . '"' . $attrStr;
                      if ($preview) {
                          echo ' disabled';
                      }
                      echo '><label class="form-check-label" for="' . $h($rid) . '">' . $h($opt) . '</label></div>';
                  }
              } else {
                  echo '<div class="text-muted small">No options configured.</div>';
              }
              echo '</div>';
          } elseif ($type === 'file') {
              $accept = nf_file_accept($f);
              echo '<input id="' . $h($inputId) . '" name="' . $h($fileName) . '" class="form-control dynamic-file" type="file" accept="' . $h($accept) . '"' . $attrStr;
              if ($preview) {
                  echo ' disabled';
              }
              echo '>';
              if (!$preview) {
                  echo '<input type="hidden" id="' . $h($inputId) . '_temp" name="' . $h($dbName !== '' ? $dbName . '_temp' : "files_temp[$fid]") . '" value="">';
              }
              echo '<div class="form-text">Accepted: ' . $h($accept) . '</div>';
          } else {
              $map    = ['email' => 'email', 'tel' => 'tel', 'url' => 'url', 'number' => 'number', 'date' => 'date', 'text' => 'text'];
              $inType = $map[$type] ?? 'text';
              echo '<input id="' . $h($inputId) . '" name="' . $h($baseName) . '" type="' . $h($inType) . '" class="form-control"' . $attrStr;
              if ($preview) {
                  echo ' disabled';
              }
              echo '>';
          }
          $help = trim((string) ($f['help_text'] ?? ''));
          if ($help !== '') {
              echo '<div class="form-text">' . $h($help) . '</div>';
          }
          if (!$preview) {
              echo '<div class="invalid-feedback js-field-error" role="alert"></div>';
          }
          ?>
        </div>
        <?php
    }
}

if (!function_exists('nf_render_fields_grid')) {
    /** @param list<array<string, mixed>> $fields */
    function nf_render_fields_grid(array $fields, array $opts = []): void
    {
        $preview = !empty($opts['preview']);
        $activeOnly = $opts['active_only'] ?? !$preview;

        echo '<div class="row g-3">';
        foreach ($fields as $f) {
            if ($activeOnly && empty($f['is_active'])) {
                continue;
            }
            nf_render_field($f, $opts);
        }
        echo '</div>';
    }
}

if (!function_exists('nf_nomination_stats')) {
    /** @return array<string, int> */
    function nf_nomination_stats(mysqli $conn, int $eventId): array
    {
        $stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'needs_info' => 0, 'rejected' => 0];
        if ($eventId <= 0 || !admin_table_exists($conn, 'tbl_nominations')) {
            return $stats;
        }
        $sql = "SELECT status, COUNT(*) AS c FROM tbl_nominations WHERE event_id = ? GROUP BY status";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $stats;
        }
        $stmt->bind_param('i', $eventId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $c = (int) ($row['c'] ?? 0);
            $stats['total'] += $c;
            $st = strtolower((string) ($row['status'] ?? ''));
            if (isset($stats[$st])) {
                $stats[$st] = $c;
            }
        }
        $stmt->close();
        return $stats;
    }
}

if (!function_exists('nf_compute_health')) {
    /**
     * @param list<array<string, mixed>> $fields
     * @return array{ok:bool, issues:list<string>, warnings:list<string>, stats:array<string,int>}
     */
    function nf_compute_health(
        array $fields,
        ?array $intro,
        ?array $instructions,
        int $establishmentTypeCount = -1
    ): array {
        $issues    = [];
        $warnings  = [];
        $active    = array_filter($fields, static fn($f) => !empty($f['is_active']));
        $orders    = [];

        if ($establishmentTypeCount === 0) {
            $issues[] = 'No natures of business are configured for this event — applicants cannot pick a type or awards.';
        } elseif ($establishmentTypeCount > 0 && $establishmentTypeCount < 2) {
            $warnings[] = 'Only one nature of business is set up — confirm award links under Nature of Business.';
        }

        if (count($active) === 0) {
            $issues[] = 'Add at least one visible question — otherwise applicants will see a blank form.';
        }

        foreach ($fields as $f) {
            if (empty($f['is_active'])) {
                continue;
            }
            $order = (int) ($f['sort_order'] ?? 0);
            $orders[$order] = ($orders[$order] ?? 0) + 1;
            $type = (string) ($f['type'] ?? '');
            $lbl  = (string) ($f['label'] ?? 'Untitled');
            if (in_array($type, ['select', 'checkbox', 'radio'], true) && empty($f['options_arr'])) {
                $issues[] = '"' . $lbl . '" needs answer choices — open it and add options (one per line).';
            }
            if ($type === 'file' && empty($f['validation_arr']['accept'])) {
                $warnings[] = '"' . $lbl . '" accepts images only by default. Add file types under Advanced if you need PDFs, etc.';
            }
        }

        foreach ($orders as $order => $cnt) {
            if ($cnt > 1) {
                $warnings[] = $cnt . ' questions share the same position — drag rows to reorder or click Fix question order.';
            }
        }

        $orderKeys = array_keys($orders);
        sort($orderKeys, SORT_NUMERIC);
        if (count($orderKeys) > 1) {
            $expected = range((int) min($orderKeys), (int) min($orderKeys) + count($orderKeys) - 1);
            if ($orderKeys !== $expected) {
                $warnings[] = 'Question order has gaps — click Fix question order for a clean 1, 2, 3… sequence.';
            }
        }

        if (empty($intro['is_active']) || trim((string) ($intro['body_html'] ?? '')) === '') {
            $warnings[] = 'Welcome message is off or empty — applicants may not see an opening note.';
        }
        if (empty($instructions['is_active'])) {
            $warnings[] = 'Step-by-step instructions are turned off.';
        } elseif (empty($instructions['bullets'])) {
            $warnings[] = 'Instructions are on but no steps are written yet.';
        }

        return [
            'ok'       => $issues === [],
            'issues'   => $issues,
            'warnings' => $warnings,
            'stats'    => ['active' => count($active), 'total' => count($fields)],
        ];
    }
}

if (!function_exists('nf_renumber_sort_orders')) {
    function nf_renumber_sort_orders(mysqli $conn, int $eventId): int
    {
        $fields = nf_load_fields($conn, $eventId, ['active_only' => false, 'maintenance_list' => true]);
        $n = 0;
        foreach ($fields as $f) {
            $id = (int) $f['id'];
            $st = $conn->prepare('UPDATE tbl_nomination_fields SET sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            if ($st) {
                $st->bind_param('ii', $n, $id);
                $st->execute();
                $st->close();
            }
            $n++;
        }
        return $n;
    }
}

if (!function_exists('nf_reorder_fields')) {
    /** @param list<int> $orderedIds */
    function nf_reorder_fields(mysqli $conn, array $orderedIds): bool
    {
        $n = 0;
        foreach ($orderedIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $st = $conn->prepare('UPDATE tbl_nomination_fields SET sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            if (!$st) {
                return false;
            }
            $st->bind_param('ii', $n, $id);
            if (!$st->execute()) {
                $st->close();
                return false;
            }
            $st->close();
            $n++;
        }
        return true;
    }
}

if (!function_exists('nf_profile_role_label')) {
    function nf_profile_role_label(string $role): string
    {
        $roles = function_exists('nomination_form_profile_roles')
            ? nomination_form_profile_roles()
            : [];
        return $roles[$role] ?? $role;
    }
}

if (!function_exists('nf_field_type_labels')) {
    /** @return array<string, string> */
    function nf_field_type_labels(): array
    {
        return [
            'text'     => 'Short text',
            'textarea' => 'Long text (paragraph)',
            'select'   => 'Dropdown list',
            'checkbox' => 'Checkboxes (pick any)',
            'radio'    => 'Single choice (radio)',
            'file'     => 'File upload',
            'email'    => 'Email',
            'tel'      => 'Phone number',
            'url'      => 'Website link',
            'number'   => 'Number',
            'date'     => 'Date',
        ];
    }
}

if (!function_exists('nf_type_label')) {
    function nf_type_label(string $type): string
    {
        $labels = nf_field_type_labels();
        return $labels[$type] ?? ucfirst($type);
    }
}

if (!function_exists('nf_next_sort_order')) {
    function nf_next_sort_order(mysqli $conn): int
    {
        $res = $conn->query('SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM tbl_nomination_fields');
        if ($res && ($row = $res->fetch_assoc())) {
            $res->free();
            return (int) ($row['n'] ?? 0);
        }
        return 0;
    }
}

if (!function_exists('nf_establishment_types_for_event')) {
    /**
     * Active establishment types for an event (same source as the public form).
     *
     * @return list<array{type_id:int, type_name:string}>
     */
    function nf_establishment_types_for_event(mysqli $conn, int $eventId): array
    {
        if ($eventId <= 0) {
            return [];
        }
        $helper = dirname(__DIR__) . '/tocca_admin/includes/establishment_type_event_helpers.php';
        if (!is_file($helper)) {
            return [];
        }
        require_once $helper;
        if (!function_exists('et_fetch_type_options_for_event')) {
            return [];
        }
        return et_fetch_type_options_for_event($conn, $eventId);
    }
}

if (!function_exists('nf_render_establishment_type_field')) {
    /**
     * Renders the built-in Establishment Type control (matches public registration form).
     *
     * @param list<array{type_id:int, type_name:string}> $types
     * @param array{preview?:bool, h?:callable} $opts
     */
    function nf_render_establishment_type_field(array $types, array $opts = []): void
    {
        $preview = !empty($opts['preview']);
        $h       = $opts['h'] ?? static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        ?>
        <div class="col-12" data-built-in="establishment_type">
          <?php if ($preview): ?>
            <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle mb-1">Built-in field</span>
          <?php endif; ?>
          <span class="form-label d-block">
            Nature of Business <span class="text-danger">*</span>
          </span>
          <div class="nom-est-type-list" role="group" aria-describedby="establishmentTypeHelpPreview">
            <?php if ($types === []): ?>
              <div class="text-muted small">No natures of business configured.</div>
            <?php else: ?>
              <?php foreach ($types as $t):
                $tid = (int) ($t['type_id'] ?? 0);
                $tname = (string) ($t['type_name'] ?? '');
                if ($tid <= 0 || $tname === '') continue;
                $cid = 'nf_est_type_' . $tid;
                $wide = (str_contains($tname, ' / ') || strlen($tname) > 40) ? ' nom-est-type-wide' : '';
              ?>
                <div class="form-check<?= $wide ?>">
                  <input class="form-check-input" type="checkbox" id="<?= $h($cid) ?>"
                    name="establishment_type_ids[]" value="<?= $h((string) $tid) ?>"
                    <?= $preview ? 'disabled' : '' ?>>
                  <label class="form-check-label" for="<?= $h($cid) ?>"><?= $h($tname) ?></label>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div id="establishmentTypeHelpPreview" class="form-text">
            Select <strong>all that apply</strong>. This controls which awards appear on the next step.
          </div>
          <?php if ($preview && $types === []):
            $manageUrl = (string) ($opts['manage_url'] ?? 'establishment_types.php');
          ?>
            <div class="alert alert-warning small mt-2 mb-0">
              No natures of business are set up for this event yet.
              <a href="<?= $h($manageUrl) ?>">Set up nature of business</a>.
            </div>
          <?php endif; ?>
          <?php if (!$preview): ?>
            <div class="invalid-feedback">Please select at least one nature of business.</div>
          <?php endif; ?>
        </div>
        <?php
    }
}
