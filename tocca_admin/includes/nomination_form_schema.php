<?php
declare(strict_types=1);

/**
 * Schema upgrades for registration form fields (maintenance module only).
 */

require_once __DIR__ . '/admin_schema.php';

if (!function_exists('nomination_form_schema_ensure')) {
    function nomination_form_schema_ensure(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        if (!admin_table_exists($conn, 'tbl_nomination_fields')) {
            return;
        }

        $columns = [
            'event_id'         => 'ADD COLUMN `event_id` INT NULL DEFAULT NULL COMMENT \'NULL = all events\' AFTER `sort_order`',
            'help_text'        => 'ADD COLUMN `help_text` VARCHAR(500) NULL DEFAULT NULL AFTER `event_id`',
            'placeholder'      => 'ADD COLUMN `placeholder` VARCHAR(255) NULL DEFAULT NULL AFTER `help_text`',
            'validation_json'  => 'ADD COLUMN `validation_json` TEXT NULL DEFAULT NULL AFTER `placeholder`',
            'profile_role'     => 'ADD COLUMN `profile_role` VARCHAR(50) NOT NULL DEFAULT \'custom\' AFTER `validation_json`',
            'field_width'      => 'ADD COLUMN `field_width` VARCHAR(10) NOT NULL DEFAULT \'half\' AFTER `profile_role`',
        ];

        foreach ($columns as $col => $alter) {
            if (!admin_schema_column_exists($conn, 'tbl_nomination_fields', $col)) {
                @$conn->query('ALTER TABLE `tbl_nomination_fields` ' . $alter);
            }
        }

        if (admin_schema_column_exists($conn, 'tbl_nomination_fields', 'event_id')) {
            $idx = $conn->query("SHOW INDEX FROM `tbl_nomination_fields` WHERE Key_name = 'idx_nom_fields_event'");
            if ($idx && $idx->num_rows === 0) {
                @$conn->query(
                    'ALTER TABLE `tbl_nomination_fields`
                     ADD INDEX `idx_nom_fields_event` (`event_id`, `is_active`, `sort_order`)'
                );
            }
            if ($idx) {
                $idx->free();
            }
        }
    }
}

if (!function_exists('nomination_form_profile_roles')) {
    /** @return array<string, string> */
    function nomination_form_profile_roles(): array
    {
        return [
            'custom'        => 'Other / custom',
            'business_name' => 'Business / establishment name',
            'owner_name'    => 'Owner or manager name',
            'email'         => 'Email address',
            'mobile'        => 'Mobile number',
            'address'       => 'Business address',
            'website'       => 'Website or social link',
            'mayor_permit'  => "Mayor's permit (image upload)",
            'logo'          => 'Company logo (upload)',
        ];
    }
}

if (!function_exists('nomination_form_profile_role_groups')) {
    /**
     * Optgroup labels for the review-group dropdown (admin nomination_fields UI).
     *
     * @return array<string, list<string>> group label => role keys
     */
    function nomination_form_profile_role_groups(): array
    {
        return [
            'Standard contact & business' => [
                'business_name',
                'owner_name',
                'email',
                'mobile',
                'address',
                'website',
                'mayor_permit',
                'logo',
            ],
            'Other' => ['custom'],
        ];
    }
}

if (!function_exists('nomination_form_suggest_profile_role')) {
    /**
     * Suggest a profile_role key from question label text (admin hints only).
     */
    function nomination_form_suggest_profile_role(string $label): ?string
    {
        $s = strtolower(trim($label));
        if ($s === '') {
            return null;
        }
        $rules = [
            'logo'          => '/\b(logo|brand\s*mark)\b/u',
            'mayor_permit'  => '/\b(mayor|business\s*permit|mayor.?s?\s*permit)\b/u',
            'website'       => '/\b(website|web\s*site|facebook|instagram|social\s*media|url)\b/u',
            'email'         => '/\b(e-?mail|email\s*address)\b/u',
            'mobile'        => '/\b(mobile|cell\s*phone|phone|contact\s*no|telephone|tel)\b/u',
            'address'       => '/\b(address|location|street|barangay|city)\b/u',
            'owner_name'    => '/\b(owner|manager|proprietor|representative|contact\s*person)\b/u',
            'business_name' => '/((official|registered|trade|store|establishment|company|business)\s+name\b|\bname\s+of\s+(the\s+)?(business|company|establishment))/u',
        ];
        foreach ($rules as $role => $pattern) {
            if (preg_match($pattern, $s)) {
                return $role;
            }
        }

        return null;
    }
}
