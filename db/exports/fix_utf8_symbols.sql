-- Repair garbled punctuation imported from the catalog dump (ΓÇÖ, ΓÇô, ├⌐).
-- phpMyAdmin → u556809062_tocca_db → SQL → Go
-- Then upload nomination/rich_text_helpers.php so the public form also repairs on display.

SET NAMES utf8mb4;

-- ’  (right apostrophe) was stored as ΓÇÖ
UPDATE tbl_nomination_texts
SET body_html = REPLACE(body_html, CONVERT(UNHEX('CE93C387C396') USING utf8mb4), CONVERT(UNHEX('E28099') USING utf8mb4))
WHERE body_html LIKE CONCAT('%', CONVERT(UNHEX('CE93C387C396') USING utf8mb4), '%');

UPDATE tbl_nomination_texts
SET title = REPLACE(title, CONVERT(UNHEX('CE93C387C396') USING utf8mb4), CONVERT(UNHEX('E28099') USING utf8mb4))
WHERE title LIKE CONCAT('%', CONVERT(UNHEX('CE93C387C396') USING utf8mb4), '%');

UPDATE tbl_nomination_texts
SET bullets_json = REPLACE(bullets_json, CONVERT(UNHEX('CE93C387C396') USING utf8mb4), CONVERT(UNHEX('E28099') USING utf8mb4))
WHERE bullets_json LIKE CONCAT('%', CONVERT(UNHEX('CE93C387C396') USING utf8mb4), '%');

-- –  (en dash) was stored as ΓÇô
UPDATE tbl_nomination_texts
SET body_html = REPLACE(body_html, CONVERT(UNHEX('CE93C387C3B4') USING utf8mb4), CONVERT(UNHEX('E28093') USING utf8mb4))
WHERE body_html LIKE CONCAT('%', CONVERT(UNHEX('CE93C387C3B4') USING utf8mb4), '%');

UPDATE tbl_voter_portal_copy
SET intro_body = REPLACE(intro_body, CONVERT(UNHEX('CE93C387C396') USING utf8mb4), CONVERT(UNHEX('E28099') USING utf8mb4)),
    how_to_lead = REPLACE(how_to_lead, CONVERT(UNHEX('CE93C387C396') USING utf8mb4), CONVERT(UNHEX('E28099') USING utf8mb4)),
    steps_json = REPLACE(steps_json, CONVERT(UNHEX('CE93C387C396') USING utf8mb4), CONVERT(UNHEX('E28099') USING utf8mb4)),
    footer_note = REPLACE(footer_note, CONVERT(UNHEX('CE93C387C396') USING utf8mb4), CONVERT(UNHEX('E28099') USING utf8mb4));

UPDATE tbl_voter_portal_copy
SET intro_body = REPLACE(intro_body, CONVERT(UNHEX('CE93C387C3B4') USING utf8mb4), CONVERT(UNHEX('E28093') USING utf8mb4)),
    how_to_lead = REPLACE(how_to_lead, CONVERT(UNHEX('CE93C387C3B4') USING utf8mb4), CONVERT(UNHEX('E28093') USING utf8mb4)),
    steps_json = REPLACE(steps_json, CONVERT(UNHEX('CE93C387C3B4') USING utf8mb4), CONVERT(UNHEX('E28093') USING utf8mb4)),
    footer_note = REPLACE(footer_note, CONVERT(UNHEX('CE93C387C3B4') USING utf8mb4), CONVERT(UNHEX('E28093') USING utf8mb4));

-- é was stored as ├⌐ (Best Café / Café types — skip if already fixed)
UPDATE tbl_questions
SET question_name = REPLACE(question_name, CONVERT(UNHEX('E2949CE28C90') USING utf8mb4), CONVERT(UNHEX('C3A9') USING utf8mb4))
WHERE question_name LIKE CONCAT('%', CONVERT(UNHEX('E2949CE28C90') USING utf8mb4), '%');

UPDATE tbl_establishment_types
SET type_name = REPLACE(type_name, CONVERT(UNHEX('E2949CE28C90') USING utf8mb4), CONVERT(UNHEX('C3A9') USING utf8mb4))
WHERE type_name LIKE CONCAT('%', CONVERT(UNHEX('E2949CE28C90') USING utf8mb4), '%');
