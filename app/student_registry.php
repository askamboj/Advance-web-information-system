<?php
function normalize_student_id(string $value): string {
    $value = strtoupper(trim($value));
    valid((bool)preg_match('/\A[A-Z0-9][A-Z0-9-]{0,39}\z/D', $value), 'Student IDs must contain 1-40 letters, digits or hyphens.');
    return $value;
}
function eligible_student_id(string $value, bool $lock = false): string {
    $id = normalize_student_id($value);
    valid((bool)one('SELECT student_number FROM eligible_student_ids WHERE student_number=?'.($lock?' FOR UPDATE':''), [$id]), 'This student ID is not on the approved list. Contact the campus administrator.');
    return $id;
}
function student_ids_from_rows(array $rows): array {
    $ids = []; $column = 0; $first = true;
    foreach ($rows as $index => $row) {
        if (!array_filter($row, static fn($cell) => trim((string)$cell) !== '')) continue;
        if ($first) {
            $first = false;
            $headers = array_map(static fn($cell) => strtolower(preg_replace('/[\s_-]+/', '', ltrim(trim((string)$cell), "\xEF\xBB\xBF"))), $row);
            $headerColumn = array_search('studentid', $headers, true);
            if ($headerColumn !== false) { $column = $headerColumn; continue; }
        }
        $value = (string)($row[$column] ?? '');
        if (trim($value) === '') continue;
        try { $id = normalize_student_id($value); }
        catch (UserError) { throw new UserError('Invalid student ID on row '.($index+1).'. Use letters, digits and hyphens, and format Excel IDs as Text. No IDs were imported.'); }
        $ids[$id] = $id;
        valid(count($ids) <= 5000, 'Import at most 5,000 student IDs at a time.');
    }
    valid(count($ids) > 0, 'No student IDs found. Use a student_id column or put IDs in the first column.');
    return array_values($ids);
}
function registry_xml(string $content): DOMXPath {
    valid(strlen($content) <= 6*1024*1024 && !preg_match('/<!DOCTYPE|<!ENTITY/i', $content), 'This spreadsheet contains unsupported XML.');
    $previous = libxml_use_internal_errors(true);
    try {
        $document = new DOMDocument();
        valid($document->loadXML($content, LIBXML_NONET | LIBXML_COMPACT), 'This spreadsheet could not be read. Save it again as .xlsx or CSV.');
        return new DOMXPath($document);
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
}
function registry_xlsx_rows(string $path): array {
    // Phar reads ZIP containers without extracting uploaded files or requiring ext-zip.
    try { $archive = new PharData($path); }
    catch (Throwable) { throw new UserError('This is not a readable .xlsx file. Save the workbook as Excel .xlsx or CSV.'); }
    $total = 0; $count = 0;
    foreach (new RecursiveIteratorIterator($archive) as $entry) {
        $total += $entry->getSize(); $count++;
        valid($total <= 16*1024*1024 && $count <= 200, 'The spreadsheet is too large when opened. Use a small workbook containing only student IDs.');
    }
    $read = static function(string $name) use ($archive): string {
        valid(isset($archive[$name]), 'Required Excel worksheet data is missing.');
        valid($archive[$name]->getSize() <= 6*1024*1024, 'The worksheet is too large.');
        return $archive[$name]->getContent();
    };
    $book = registry_xml($read('xl/workbook.xml'));
    $sheet = $book->query('//*[local-name()="sheets"]/*[local-name()="sheet"]')->item(0);
    valid($sheet instanceof DOMElement, 'The workbook has no worksheets.');
    $relationship = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
    $relations = registry_xml($read('xl/_rels/workbook.xml.rels'));
    $target = '';
    foreach ($relations->query('//*[local-name()="Relationship"]') as $relation) {
        if ($relation->getAttribute('Id') === $relationship && $relation->getAttribute('TargetMode') !== 'External') $target = $relation->getAttribute('Target');
    }
    valid($target !== '' && !str_contains($target, '..') && !str_contains($target, '\\'), 'Unsupported worksheet path.');
    $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
    $shared = [];
    if (isset($archive['xl/sharedStrings.xml'])) {
        $strings = registry_xml($read('xl/sharedStrings.xml'));
        foreach ($strings->query('//*[local-name()="si"]') as $item) {
            $value = '';
            foreach ($strings->query('.//*[local-name()="t"]', $item) as $text) $value .= $text->textContent;
            $shared[] = $value;
        }
    }
    $sheetXml = registry_xml($read($target)); $rows = [];
    foreach ($sheetXml->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
        valid(count($rows) < 5001, 'Import at most 5,000 rows plus a header.');
        $cells = [];
        foreach ($sheetXml->query('./*[local-name()="c"]', $row) as $cell) {
            valid(!$sheetXml->query('./*[local-name()="f"]', $cell)->length, 'Formula cells are not supported. Paste student IDs as plain text values.');
            valid((bool)preg_match('/\A([A-Z]{1,3})[0-9]+\z/', $cell->getAttribute('r'), $match), 'Unsupported Excel cell reference.');
            $column = 0;
            foreach (str_split($match[1]) as $letter) $column = $column*26 + ord($letter)-64;
            valid($column <= 64, 'Use a simple spreadsheet with no more than 64 columns.');
            $value = $sheetXml->evaluate('string(./*[local-name()="v"])', $cell);
            if ($cell->getAttribute('t') === 's') {
                valid(ctype_digit($value) && isset($shared[(int)$value]), 'Invalid Excel text reference.');
                $value = $shared[(int)$value];
            } elseif ($cell->getAttribute('t') === 'inlineStr') {
                $value = '';
                foreach ($sheetXml->query('./*[local-name()="is"]//*[local-name()="t"]', $cell) as $text) $value .= $text->textContent;
            }
            $cells[$column-1] = $value;
        }
        $rows[] = $cells;
    }
    return $rows;
}
function registry_uploaded_ids(): array {
    $file = $_FILES['student_file'] ?? null;
    valid(is_array($file) && ($file['error']??UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK, 'Choose an Excel .xlsx or CSV file up to 2 MB.');
    valid(is_string($file['tmp_name']) && is_uploaded_file($file['tmp_name']) && $file['size'] <= 2*1024*1024, 'Choose a file up to 2 MB.');
    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    valid(in_array($extension, ['xlsx','csv'], true), 'Use Excel .xlsx or CSV. For older .xls files, use Save As to create an .xlsx file.');
    if ($extension === 'csv') {
        $handle = fopen($file['tmp_name'], 'rb'); $rows = [];
        try {
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                valid(count($rows) < 5001 && count($row) <= 64, 'Import at most 5,000 rows and 64 columns.');
                $rows[] = $row;
            }
        } finally { fclose($handle); }
        return student_ids_from_rows($rows);
    }
    // The PHP upload name has no ZIP extension; use a private temporary ZIP name.
    $temporary = ROOT.'/var/registry-'.bin2hex(random_bytes(12)).'.zip';
    try {
        valid(move_uploaded_file($file['tmp_name'], $temporary), 'The uploaded spreadsheet could not be read.');
        return student_ids_from_rows(registry_xlsx_rows($temporary));
    } catch (UserError $ex) { throw $ex;
    } catch (Throwable) { throw new UserError('The spreadsheet could not be read. Save it again as .xlsx or CSV. No IDs were imported.');
    } finally { if (is_file($temporary)) unlink($temporary); }
}
function handle_student_registry(string $action): bool {
    if (!in_array($action, ['add-student-ids','import-student-ids'], true)) return false;
    $u = admin();
    if ($action === 'import-student-ids') $ids = registry_uploaded_ids();
    else {
        $lines = preg_split('/\R/', field('student_ids', 1, 15000));
        $ids = student_ids_from_rows(array_map(static fn($line) => [$line], $lines));
    }
    $added = 0;
    db()->beginTransaction();
    try {
        foreach ($ids as $id) {
            $added += query('INSERT INTO eligible_student_ids(student_number,added_by) VALUES(?,?) ON DUPLICATE KEY UPDATE student_number=VALUES(student_number)', [$id,$u['id']])->rowCount();
        }
        audit('registry.ids_added', (int)$u['id']); db()->commit();
    } catch (Throwable $ex) { if (db()->inTransaction()) db()->rollBack(); throw $ex; }
    flash($added.' student IDs added. '.(count($ids)-$added).' already on the list.'); go('admin');
}
function student_registry_admin_section(): void {
    admin();
    $search = get('student_search');
    $items = $search === '' ? rows('SELECT e.student_number,e.created_at,c.account_id FROM eligible_student_ids e LEFT JOIN student_id_claims c ON c.student_number=e.student_number ORDER BY e.created_at DESC,e.student_number LIMIT 50') : rows('SELECT e.student_number,e.created_at,c.account_id FROM eligible_student_ids e LEFT JOIN student_id_claims c ON c.student_number=e.student_number WHERE e.student_number=?', [strtoupper(trim($search))]);
    ?>
    <section class="section"><div class="section-title"><h2>Approved student IDs</h2><span class="pill"><?=e((string)scalar('SELECT COUNT(*) FROM eligible_student_ids'))?> IDs</span></div>
    <p>New personal registrations must match this list. Each ID can register one personal account. Existing accounts keep their access.</p>
    <div class="settings-grid"><section class="panel"><h3>Add student IDs manually</h3>
    <form method="post" action="<?=e(url('admin'))?>"><?=action('add-student-ids')?>
    <label class="field"><span>Student IDs, one per line</span><textarea name="student_ids" rows="6" maxlength="15000" required placeholder="001234&#10;CC-1002"><?=old('student_ids')?></textarea></label>
    <button class="btn">Add IDs</button></form></section>
    <section class="panel"><h3>Upload an Excel list</h3><p>Use .xlsx or CSV, up to 2 MB and 5,000 IDs. Put a <strong>student_id</strong> header above the IDs, or use the first column. Only the first worksheet is imported. Duplicate IDs are skipped.</p><p>Format Excel IDs as <strong>Text</strong> to preserve leading zeros. Older .xls files must be saved as .xlsx first.</p>
    <form method="post" enctype="multipart/form-data" action="<?=e(url('admin'))?>"><?=action('import-student-ids')?>
    <label class="field"><span>Student ID file</span><input type="file" name="student_file" accept=".xlsx,.csv" required></label><button class="btn">Import student IDs</button></form></section></div>
    <section class="panel"><form method="get" action="index.php"><input type="hidden" name="page" value="admin"><label class="field"><span>Find an exact student ID</span><input name="student_search" maxlength="40" value="<?=e($search)?>"></label><button class="btn btn-small">Search</button> <a href="<?=e(url('admin'))?>">Show latest IDs</a></form>
    <p><?= $search===''?'Latest 50 IDs.':'Search results.' ?></p><div class="manage-list">
    <?php foreach ($items as $item): ?><div class="manage-row"><strong><?=e($item['student_number'])?></strong><span class="pill"><?=$item['account_id']?'Personal account registered':'Available for registration'?></span></div><?php endforeach; ?>
    <?php if (!$items): ?><p>No matching student IDs.</p><?php endif; ?></div></section></section>
<?php }
