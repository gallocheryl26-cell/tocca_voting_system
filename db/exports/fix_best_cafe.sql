-- Fix "Best Caf" / broken Café spelling after catalog import.
-- phpMyAdmin → u556809062_tocca_db → SQL → Go

UPDATE tbl_questions
SET question_name = CONCAT('Best Caf', UNHEX('C3A9'))
WHERE question_id = 77
   OR question_name LIKE 'Best Caf%';

UPDATE tbl_establishment_types
SET type_name = CONCAT('Caf', UNHEX('C3A9'))
WHERE type_id = 4;

UPDATE tbl_establishment_types
SET type_name = CONCAT(
  'Restaurant / Food Stall / Food Cart / Food Kiosk / Caf',
  UNHEX('C3A9'),
  ' / Fastfood / Snack House / Eatery'
)
WHERE type_id = 6;
