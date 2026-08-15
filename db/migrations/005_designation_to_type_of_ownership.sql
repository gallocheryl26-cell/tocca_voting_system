-- Public registration field: Designation / Type of Business/Company → Type of Ownership.
-- Internal field name is unchanged.

UPDATE tbl_nomination_fields
SET label = 'Type of Ownership'
WHERE name LIKE '%designation%'
   OR label LIKE '%Designation%'
   OR label IN ('Type of Business/Company', 'Type of Business / Company', 'Type of Business');
