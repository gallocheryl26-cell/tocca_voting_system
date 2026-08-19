-- Remove Song from registration Nature of Business + rename Website field.
-- Run on live via phpMyAdmin if CLI is unavailable.

UPDATE tbl_establishment_types SET status = 0 WHERE LOWER(type_name) = 'song';

DELETE eta FROM tbl_establishment_type_awards eta
INNER JOIN tbl_establishment_types et ON et.type_id = eta.type_id
WHERE LOWER(et.type_name) = 'song';

DELETE net FROM tbl_nomination_establishment_types net
INNER JOIN tbl_establishment_types et ON et.type_id = net.type_id
WHERE LOWER(et.type_name) = 'song';

DELETE cet FROM tbl_choice_establishment_types cet
INNER JOIN tbl_establishment_types et ON et.type_id = cet.type_id
WHERE LOWER(et.type_name) = 'song';

UPDATE tbl_nominations n
INNER JOIN tbl_establishment_types et ON et.type_id = n.establishment_type_id
SET n.establishment_type_id = NULL
WHERE LOWER(et.type_name) = 'song';

UPDATE tbl_choices c
INNER JOIN tbl_establishment_types et ON et.type_id = c.establishment_type_id
SET c.establishment_type_id = NULL
WHERE LOWER(et.type_name) = 'song';

UPDATE tbl_nomination_fields
SET label = 'Website/Facebook Page Link',
    placeholder = 'https://example.com or Facebook page URL',
    updated_at = NOW()
WHERE LOWER(name) = 'website' OR LOWER(label) = 'website';
