<?php
declare(strict_types=1);

/**
 * Generates the unified TOCCA import workbook (Instructions + 4 data sheets).
 * Run: php tocca_admin/scripts/generate_test_event_import.php
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$outDir = dirname(__DIR__) . '/uploads';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$outFile = $outDir . '/tocca_import_template.xlsx';

$sheets = [
    'Instructions' => [
        ['TOCCA — Excel import guide (read this first)'],
        [''],
        ['WHAT THIS FILE IS FOR'],
        ['Use this workbook to load Categories, Awards, Business Categories (sheet: Establishment Types), and Businesses (sheet: Establishments) into the active event.'],
        ['Fill in the four data sheets, then upload from Admin → System Utilities → Import Data.'],
        [''],
        ['BEFORE YOU START'],
        ['• Activate the correct event in Admin first — import always applies to the currently active event.'],
        ['• Do not rename the sheet tabs (Categories, Awards, Establishment Types, Establishments).'],
        ['• Row 1 on each data sheet is a hint row. Row 2 is the column header row. Your data starts on row 3.'],
        ['• The sample rows below the headers WILL be imported — replace them with your real event data before uploading.'],
        ['• Fill sheets in this order: Categories → Awards → Establishment Types → Establishments.'],
        [''],
        ['IMPORTANT — Linking awards to business categories'],
        ['• The Establishment Types sheet (Business Categories in admin) is how awards get linked automatically.'],
        ['• If you import Categories + Awards only (and leave Establishment Types empty/missing), new awards will NOT'],
        ['  appear under any type on the registration form until an admin links them under File Maintenance → Business Categories.'],
        ['• Always fill award_keys on the Establishment Types sheet so applicants see the right awards after import.'],
        ['• On the public registration form, applicants can select ONE OR MORE business categories (checkboxes).'],
        ['  Awards shown are the combined list for all types they selected.'],
        [''],
        ['QUICK GLOSSARY (plain English)'],
        [''],
        ['status'],
        ['  Means Active or Inactive.'],
        ['  • 1 = Active (visible and usable)'],
        ['  • 0 = Inactive (hidden / turned off)'],
        ['  Use 1 for anything you want live during the event.'],
        [''],
        ['category_name'],
        ['  The award group name (e.g. Food, Service, Retail). This is NOT the business category.'],
        ['  Must be spelled the same way on the Awards sheet.'],
        [''],
        ['award_key'],
        ['  A short code YOU create for each award — the system does not generate this for you.'],
        ['  Examples: F1, F2, S1, FE1, R1'],
        ['  Rules:'],
        ['  • Must be unique across the whole file'],
        ['  • Use letters + numbers, no spaces (recommended)'],
        ['  • Copy the exact same code to Establishment Types and Establishments sheets'],
        ['  • Think of it as an ID you invent so rows can link together'],
        [''],
        ['award_name'],
        ['  The full award title shown to voters (e.g. “Best Chicken Barbecue”).'],
        [''],
        ['choice_type'],
        ['  Always use 1 (Options). Voters pick from linked businesses.'],
        ['  Freeform (0) is not used on the voting page and is ignored on import.'],
        [''],
        ['type_name (Establishment Types sheet)'],
        ['  The kind of business (e.g. Restaurant, Café, Hair Salon).'],
        ['  This appears on the registration form as “Business Category” (multi-select checkboxes).'],
        [''],
        ['award_keys (Establishment Types sheet)'],
        ['  Which awards this business type is allowed to join.'],
        ['  Copy the award_key values from the Awards sheet, separated by commas.'],
        ['  Example: F1, F2  means this type can participate in awards F1 and F2.'],
        ['  The same award_key may appear under more than one type (e.g. Best Burger for Restaurant and Fast Food).'],
        [''],
        ['establishment_type (Establishments sheet)'],
        ['  Must match a type_name from the Establishment Types sheet exactly.'],
        ['  One type per row for bulk import. To assign additional types later, edit the business in Admin.'],
        [''],
        ['award_key (Establishments sheet)'],
        ['  Which award this business is linked to. One award per row.'],
        ['  To link one business to two awards, add two rows with the same establishment_name.'],
        [''],
        ['SHEET-BY-SHEET'],
        [''],
        ['1) Categories — one row per category'],
        ['   category_name | status'],
        [''],
        ['2) Awards — one row per award'],
        ['   award_key | category_name | award_name | choice_type'],
        [''],
        ['3) Establishment Types (Business Categories in admin) — one row per business category (REQUIRED to auto-link awards)'],
        ['   type_name | award_keys | status'],
        [''],
        ['4) Establishments (Businesses in admin) — one row per business + award combination'],
        ['   establishment_name | email | status | establishment_type | award_key'],
        [''],
        ['COMMON MISTAKES'],
        ['• Typo in category_name or type_name between sheets (must match exactly)'],
        ['• Using award_name instead of award_key on Establishment Types / Establishments'],
        ['• Importing awards without filling Establishment Types — then wondering why types have no awards'],
        ['• Listing an award under a type that was not assigned in Establishment Types'],
        ['• Forgetting to add rows below the header (data must start on row 3)'],
        ['• Leaving “(EXAMPLE)” in a cell — rows with that text are skipped on purpose'],
        [''],
        ['NEED HELP?'],
        ['See the hint row (row 1) on each data sheet for a reminder of what goes in each column.'],
    ],
    'Categories' => [
        [
            'Group name shown in admin and voting (e.g. Food, Service). Must match exactly on the Awards sheet.',
            '1 = Active (show). 0 = Inactive (hide).',
        ],
        ['category_name', 'status'],
        ['Food', 1],
        ['Service', 1],
        ['Retail', 1],
        ['Feelings', 1],
    ],
    'Awards' => [
        [
            'Short unique code YOU invent (e.g. F1). Copy to other sheets. Do not use spaces.',
            'Must match category_name on Categories sheet exactly.',
            'Full award title voters will see (e.g. Best Chicken Barbecue).',
            'Always 1 = Options (pick businesses). Freeform is not used for voting.',
        ],
        ['award_key', 'category_name', 'award_name', 'choice_type'],
        ['F1', 'Food', 'Best Burger', 1],
        ['F2', 'Food', 'Best Chicken Barbecue', 1],
        ['F3', 'Food', 'Best Pancit', 1],
        ['S1', 'Service', 'Best Café', 1],
        ['S2', 'Service', 'Best Hair Salon', 1],
        ['S3', 'Service', 'Best Catering Services', 1],
        ['R1', 'Retail', 'Best Grocery Shop', 1],
        ['R2', 'Retail', 'Best Meat Shop', 1],
        ['FE1', 'Feelings', 'Best Date Place', 1],
        ['FE2', 'Feelings', 'Best Hairstylist', 1],
    ],
    'Establishment Types' => [
        [
            'Business type name (e.g. Restaurant). Shown as checkboxes on the registration form.',
            'award_key codes from Awards sheet, comma-separated (e.g. F1, F2). Links awards to this type.',
            '1 = Active. 0 = Inactive.',
        ],
        ['type_name', 'award_keys', 'status'],
        ['Café', 'S1, FE1', 1],
        ['Restaurant', 'F1, F2, F3, S3, FE1', 1],
        ['Fast Food Chain', 'F1, F2', 1],
        ['Bakery / Pastry Shop', 'S1', 1],
        ['Carinderia / Eatery', 'F2, F3', 1],
        ['Catering Service', 'S3', 1],
        ['Grocery / Mini Mart', 'R1', 1],
        ['Meat Shop', 'R2', 1],
        ['Hair Salon', 'S2, FE2', 1],
    ],
    'Establishments' => [
        [
            'Official business name.',
            'Contact email (optional).',
            '1 = Active business. 0 = Inactive.',
            'Must match type_name on Establishment Types sheet (one type per import row).',
            'award_key from Awards sheet. One award per row — duplicate the row for multiple awards.',
        ],
        ['establishment_name', 'email', 'status', 'establishment_type', 'award_key'],
        ["Eco's Grill and Restaurant", 'ecos.grill@test.local', 1, 'Restaurant', 'F1'],
        ["Eco's Grill and Restaurant", 'ecos.grill@test.local', 1, 'Restaurant', 'F2'],
        ["Angel's Burger", 'angels.burger@test.local', 1, 'Fast Food Chain', 'F1'],
        ['Bean & Brew Café', 'beanbrew@test.local', 1, 'Café', 'S1'],
        ['Ormoc Centrum', 'ormoc.centrum@test.local', 1, 'Grocery / Mini Mart', 'R1'],
        ['Style Cut Salon', 'stylecut@test.local', 1, 'Hair Salon', 'S2'],
        ['Style Cut Salon', 'stylecut@test.local', 1, 'Hair Salon', 'FE2'],
    ],
];

$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0);
$sheetIndex = 0;

foreach ($sheets as $name => $rows) {
    $sheet = $spreadsheet->createSheet($sheetIndex++);
    $sheet->setTitle($name);
    $sheet->fromArray($rows, null, 'A1');

    if ($name !== 'Instructions') {
        $sheet->getStyle('A1:Z1')->getFont()->setItalic(true);
        $sheet->getStyle('A2:Z2')->getFont()->setBold(true);
    } else {
        $sheet->getStyle('A1')->getFont()->setBold(true);
    }

    foreach (range('A', 'E') as $col) {
        $sheet->getColumnDimension($col)->setWidth(28);
    }
}

$spreadsheet->setActiveSheetIndex(1);

$writer = new Xlsx($spreadsheet);
$writer->save($outFile);
echo "Created: {$outFile}\n";
