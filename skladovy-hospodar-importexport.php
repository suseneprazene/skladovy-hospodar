<?php

// Zpracování exportu v admin_init (před odesláním HTML výstupu)
add_action('admin_init', function() {
    if (!current_user_can('manage_options')) return;

    if (isset($_POST['generate_csv_products']) && check_admin_referer('skladovy_hospodar_export')) {
        skladovy_hospodar_export_products();
        exit;
    }
    if (isset($_POST['generate_csv_items']) && check_admin_referer('skladovy_hospodar_export')) {
        skladovy_hospodar_export_items();
        exit;
    }
});

// Funkce pro vykreslení stránky Import / Export
function skladovy_hospodar_importexport_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Nemáte oprávnění k přístupu na tuto stránku.'));
    }

    // Import CSV - Položky a jejich stav
    if (isset($_POST['import_csv_items']) && check_admin_referer('skladovy_hospodar_import_items')) {
        skladovy_hospodar_import_items();
        return;
    }

    // Import CSV - Produkty a jejich složení
    if (isset($_POST['import_csv_products']) && check_admin_referer('skladovy_hospodar_import_products')) {
        skladovy_hospodar_import_products();
        return;
    }

    echo '<div class="wrap">';
    echo '<h1>Import / Export dat skladu</h1>';

    // Sekce pro export dat
    echo '<h2>Export dat</h2>';
    echo '<p>Stáhněte zálohu produktů nebo položek ve formátu CSV.</p>';
    echo '<form method="post">';
    wp_nonce_field('skladovy_hospodar_export');
    echo '<button type="submit" name="generate_csv_products" class="button">Exportovat produkty</button>';
    echo ' <button type="submit" name="generate_csv_items" class="button">Exportovat položky</button>';
    echo '</form>';

    // Sekce pro import dat
    echo '<h2>Import dat</h2>';
    echo '<p>Nahrajte CSV pro obnovení položek nebo produktů.</p>';

    // Formulář 1 – import položek
    echo '<form method="post" enctype="multipart/form-data" style="margin-bottom:16px">';
    wp_nonce_field('skladovy_hospodar_import_items');
    echo '<label>Importovat položky:</label><br>';
    echo '<input type="file" name="imported_csv_items" accept=".csv"><br style="margin-bottom:6px">';
    echo '<button type="submit" name="import_csv_items" class="button">Importovat položky</button>';
    echo '</form>';

    // Formulář 2 – import produktů
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('skladovy_hospodar_import_products');
    echo '<label>Importovat produkty:</label><br>';
    echo '<input type="file" name="imported_csv_products" accept=".csv"><br style="margin-bottom:6px">';
    echo '<button type="submit" name="import_csv_products" class="button">Importovat produkty</button>';
    echo '</form>';

    echo '</div>';
}

// Funkce pro export produktů do CSV
function skladovy_hospodar_export_products() {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=products_backup_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

    fputcsv($output, ['ID Produktu', 'Název Produktu', 'Složení (ID)', 'Složení (Název)']);

    $products = get_option('skladovy_hospodar_product_map', []);
    $items = get_option('skladovy_hospodar_items', []);

    foreach ($products as $product_id => $composition) {
        $product_name = isset($items["product_{$product_id}"])
            ? $items["product_{$product_id}"]['name']
            : 'Neznámý produkt';

        $composition_ids = [];
        $composition_names = [];
        foreach ($composition as $comp) {
            $composition_ids[] = $comp['item_id'] . ' (' . $comp['qty'] . ')';
            $composition_names[] = $items[$comp['item_id']]['name'] ?? 'Neznámá položka';
        }

        fputcsv($output, [
            $product_id,
            $product_name,
            implode('; ', $composition_ids),
            implode('; ', $composition_names),
        ]);
    }

    fclose($output);
    exit;
}

// Funkce pro export položek do CSV
function skladovy_hospodar_export_items() {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=items_backup_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

    fputcsv($output, ['ID Položky', 'Název Položky', 'Jednotka', 'Množství', 'Minimální množství', 'Poznámka']);
    $items = get_option('skladovy_hospodar_items', []);

    foreach ($items as $item_id => $item) {
        fputcsv($output, [
            $item_id,
            $item['name'] ?? '',
            $item['unit'] ?? '',
            $item['qty'] ?? 0,
            $item['min'] ?? 0,
            $item['note'] ?? '',
        ]);
    }

    fclose($output);
    exit;
}

// Funkce pro import produktů z CSV
function skladovy_hospodar_import_products() {
    echo '<div class="wrap"><h1>Import / Export dat skladu</h1>';

    if (empty($_FILES['imported_csv_products']['tmp_name'])) {
        echo '<div class="notice notice-error"><p>Nebyl vybrán žádný soubor.</p></div>';
        echo '</div>';
        return;
    }

    $file = $_FILES['imported_csv_products'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        echo '<div class="notice notice-error"><p>Neplatný formát souboru. Očekáváno CSV.</p></div>';
        echo '</div>';
        return;
    }

    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        echo '<div class="notice notice-error"><p>Nelze otevřít soubor.</p></div>';
        echo '</div>';
        return;
    }

    // Přeskoč BOM
    $bom = fread($handle, 3);
    if (strlen($bom) !== 3 || $bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    // Přeskoč hlavičkový řádek
    fgetcsv($handle);

    $existing = get_option('skladovy_hospodar_product_map', []);
    $product_map = is_array($existing) ? $existing : [];
    $imported = 0;

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 3) continue;
        $product_id = trim($row[0]);
        if ($product_id === '') continue;

        $composition_raw = trim($row[2]);
        $composition = [];

        if ($composition_raw !== '') {
            // Formát: "item_123 (45); item_456 (78)"
            $parts = array_filter(array_map('trim', explode(';', $composition_raw)));
foreach ($parts as $part) {
    if (preg_match('/^(\S+)\s*\((\d+(?:\.\d+)?)\)$/', $part, $m)) {
        $composition[] = [
            'item_id' => $m[1],
            'qty'     => floatval($m[2]),
            'mode'    => 'per_piece', // ← přidat výchozí hodnotu
        ];
    }
}
        }

$product_map[intval($product_id)] = $composition;
        $imported++;
    }

    fclose($handle);
    update_option('skladovy_hospodar_product_map', $product_map);

    echo '<div class="notice notice-success"><p>Produkty byly úspěšně importovány (' . $imported . ' záznamů).</p></div>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=skladovy-hospodar-importexport')) . '">&larr; Zpět na Import / Export</a></p>';
    echo '</div>';
}

// Funkce pro import položek z CSV
function skladovy_hospodar_import_items() {
    echo '<div class="wrap"><h1>Import / Export dat skladu</h1>';

    if (empty($_FILES['imported_csv_items']['tmp_name'])) {
        echo '<div class="notice notice-error"><p>Nebyl vybrán žádný soubor.</p></div>';
        echo '</div>';
        return;
    }

    $file = $_FILES['imported_csv_items'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        echo '<div class="notice notice-error"><p>Neplatný formát souboru. Očekáváno CSV.</p></div>';
        echo '</div>';
        return;
    }

    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        echo '<div class="notice notice-error"><p>Nelze otevřít soubor.</p></div>';
        echo '</div>';
        return;
    }

    // Přeskoč BOM
    $bom = fread($handle, 3);
    if (strlen($bom) !== 3 || $bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    // Přeskoč hlavičkový řádek
    fgetcsv($handle);

    $existing = get_option('skladovy_hospodar_items', []);
    $items = is_array($existing) ? $existing : [];
    $imported = 0;

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 1) continue;
        $item_id = trim($row[0]);
        if ($item_id === '') continue;

        $items[$item_id] = [
            'name' => isset($row[1]) ? trim($row[1]) : '',
            'unit' => isset($row[2]) ? trim($row[2]) : '',
            'qty'  => isset($row[3]) ? floatval($row[3]) : 0,
            'min'  => isset($row[4]) ? floatval($row[4]) : 0,
            'note' => isset($row[5]) ? trim($row[5]) : '',
        ];
        $imported++;
    }

    fclose($handle);
    update_option('skladovy_hospodar_items', $items);

    echo '<div class="notice notice-success"><p>Položky byly úspěšně importovány (' . $imported . ' záznamů).</p></div>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=skladovy-hospodar-importexport')) . '">&larr; Zpět na Import / Export</a></p>';
    echo '</div>';
}
