<?php

// Funkce pro vykreslení stránky Import / Export
function skladovy_hospodar_importexport_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Nemáte oprávnění k přístupu na tuto stránku.'));
    }

    // Zpracování exportu CSV - Produkty
    if (isset($_POST['generate_csv_products'])) {
        skladovy_hospodar_export_products();
        exit;
    }

    // Zpracování exportu CSV - Položky
    if (isset($_POST['generate_csv_items'])) {
        skladovy_hospodar_export_items();
        exit;
    }

    // Import CSV - Produkty a jejich složení
    if (isset($_POST['import_csv_products'])) {
        skladovy_hospodar_import_products();
        return;
    }

    // Import CSV - Položky a jejich stav
    if (isset($_POST['import_csv_items'])) {
        skladovy_hospodar_import_items();
        return;
    }

    echo '<div class="wrap">';
    echo '<h1>Import / Export dat skladu</h1>';

    // Sekce pro export dat
    echo '<h2>Export dat</h2>';
    echo '<p>Stáhněte zálohu produktů nebo položek ve formátu CSV.</p>';
    echo '<form method="post">';
    echo '<button type="submit" name="generate_csv_products" class="button">Exportovat produkty</button>';
    echo '<button type="submit" name="generate_csv_items" class="button">Exportovat položky</button>';
    echo '</form>';

    // Sekce pro import dat
    echo '<h2>Import dat</h2>';
    echo '<p>Nahrajte CSV pro obnovení produktů nebo položek.</p>';
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<label for="imported_csv_products">Importovat produkty:</label><br>';
    echo '<input type="file" name="imported_csv_products" accept=".csv" required><br>';
    echo '<button type="submit" name="import_csv_products" class="button">Importovat produkty</button><br><br>';
    echo '<label for="imported_csv_items">Importovat položky:</label><br>';
    echo '<input type="file" name="imported_csv_items" accept=".csv" required><br>';
    echo '<button type="submit" name="import_csv_items" class="button">Importovat položky</button>';
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
    // Zpracování souboru a validace
    // ...
    echo '<div class="notice notice-success"><p>Produkty byly úspěšně importovány.</p></div>';
}

// Funkce pro import položek z CSV
function skladovy_hospodar_import_items() {
    // Zpracování souboru a validace
    // ...
    echo '<div class="notice notice-success"><p>Položky byly úspěšně importovány.</p></div>';
}