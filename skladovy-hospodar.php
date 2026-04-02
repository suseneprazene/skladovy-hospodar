<?php
/*
Plugin Name: Skladový hospodář
Description: Privátní stránka pro správu skladu WooCommerce.
Version: 3.2.11
Author: suseneprazene
*/

if (!defined('ABSPATH')) exit;

// --- ADMIN MENU + HISTORIE ---
add_action('admin_menu', function() {
    add_menu_page(
        'Sklad',
        'Sklad',
        'manage_options',
        'skladovy-hospodar-produkty-list',
        'skladovy_hospodar_produkty_list_page',
        'dashicons-archive',
        25
    );

    add_submenu_page(
        'skladovy-hospodar-produkty-list',
        'Historie vkladů a výběrů',
        'Historie vkladů a výběrů',
        'manage_options',
        'skladovy-hospodar-historie',
        'skladovy_hospodar_hist_page'
    );
    add_submenu_page(
        'skladovy-hospodar-produkty-list',
        'Položky',
        'Položky',
        'manage_options',
        'skladovy-hospodar-mnozstvi',
        'skladovy_hospodar_stock_page'
    );
    add_submenu_page(
        'skladovy-hospodar-produkty-list',
        'Produkty a jejich složení',
        'Produkty a jejich složení',
        'manage_options',
        'skladovy-hospodar-produkty',
        'skladovy_hospodar_products_page'
    );
    add_submenu_page(
        'skladovy-hospodar-produkty-list',
        'Import / Export',
        'Import / Export',
        'manage_options',
        'skladovy-hospodar-importexport',
        'skladovy_hospodar_importexport_page'
    );
});
add_action('admin_init', function() {
    register_setting('skladovy_hospodar_settings', 'skladovy_hospodar_slug');
    register_setting('skladovy_hospodar_settings', 'skladovy_hospodar_odecet_ihned');
});

// --- FRONTEND: JS aplikace ---
add_filter('the_content', function($content) {
    $slug = get_option('skladovy_hospodar_slug', 'sklad');
    if (!is_page($slug)) return $content;

    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        $current_url = (is_ssl() ? "https://" : "http://") . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $login_url = wp_login_url($current_url);
        return '<div style="max-width:400px;margin:40px auto;padding:24px;text-align:center;border:1px solid #eee;border-radius:8px;background:#fff;">
            <h2>Přístup pouze pro administrátory</h2>
            <p>Tato stránka je dostupná jen administrátorům webu.</p>
            <a href="'.esc_url($login_url).'" style="display:inline-block;margin:16px 0;padding:10px 24px;border-radius:6px;background:#007cba;color:#fff;text-decoration:none;font-size:18px;">Přihlásit se jako administrátor</a>
        </div>';
    }

    ob_start();

    $product_map = (array)get_option('skladovy_hospodar_product_map', []);
    echo "<script>window.skladovyHospodarProductMap = ".json_encode($product_map).";</script>";

    // Získej všechny Woo produkty s hlídáním skladu (včetně variant) a jejich kategorie
    $all_products = [];
    $categoryIds = [];
    if (class_exists('WC_Product_Query')) {
        $q = new WC_Product_Query(['limit'=>400, 'status'=>array('publish','private'), 'return' => 'ids']);
        foreach ($q->get_products() as $pid) {
            $p = wc_get_product($pid);
            if (!$p) continue;

            // Variabilní produkty – projdi varianty
            if ($p->is_type('variable')) {
                foreach ($p->get_children() as $child_id) {
                    $var = wc_get_product($child_id);
                    if (!$var || !$var->managing_stock()) continue;
                    $cats = [];
                    $variant_terms = wp_get_post_terms($child_id, 'product_cat');
                    if (empty($variant_terms)) {
                        $variant_terms = wp_get_post_terms($pid, 'product_cat');
                    }
                    foreach ($variant_terms as $c) {
                        $cats[] = ['id'=>$c->term_id, 'name'=>$c->name];
                        $categoryIds[$c->term_id] = $c->name;
                    }
                    $attr = $var->get_attributes();
                    $variant_name = $p->get_name();
                    if ($attr && count($attr)) {
                        $variant_name .= ' – ' . implode(', ', array_map(function($a, $v) {
                            return wc_attribute_label($a) . ': ' . $v;
                        }, array_keys($attr), $attr));
                    }
                    $all_products[] = [
                        'id' => $child_id,
                        'name' => $variant_name,
                        'cats' => $cats,
                    ];
                }
            } elseif ($p->managing_stock()) {
                $cats = [];
                foreach (wp_get_post_terms($pid, 'product_cat') as $c) {
                    $cats[] = ['id'=>$c->term_id, 'name'=>$c->name];
                    $categoryIds[$c->term_id] = $c->name;
                }
                $all_products[] = [
                    'id' => $pid,
                    'name' => $p->get_name(),
                    'cats' => $cats,
                ];
            }
        }
    }
    $categories = [];
    foreach ($categoryIds as $id => $name) {
        $categories[] = ['id'=>$id, 'name'=>$name];
    }
    echo "<script>window.skladovyHospodarAllWooProducts = ".json_encode($all_products).";</script>";
    echo "<script>window.skladovyHospodarAllWooCategories = ".json_encode($categories).";</script>";

    // Předání všech surovin do JS
    $all_items = (array)get_option('skladovy_hospodar_items', []);
    echo "<script>window.skladovyHospodarItems = ".json_encode($all_items).";</script>";

    // Starý kód pro kategorie z product_map (ponechán pro zpětnou kompatibilitu, ale výrobní sekce už používá výše uvedené)
    $categoryIds = [];
    foreach ($product_map as $pid => $rozpad) {
        if (function_exists('wc_get_product')) {
            $p = wc_get_product($pid);
            if ($p) {
                foreach (wp_get_post_terms($pid, 'product_cat') as $c) {
                    $categoryIds[$c->term_id] = $c->name;
                }
            }
        }
    }
    $categories = [];
    foreach ($categoryIds as $id => $name) {
        $categories[] = ['id'=>$id, 'name'=>$name];
    }
    echo "<script>window.skladovyHospodarCategories = ".json_encode($categories).";</script>";

    // Vypiš hlavní aplikaci
    echo '<div id="skladovy-hospodar-app"></div>';

    // always output hlavní skripty a proměnnou AJAX
    echo '<script>window.skladovyHospodarAjax="' . esc_js( admin_url('admin-ajax.php') ) . '";</script>';
    echo '<link rel="stylesheet" href="' . esc_url( plugin_dir_url(__FILE__) . 'skladovy-hospodar.css?v=39' ) . '">';
    echo '<script src="' . esc_url( plugin_dir_url(__FILE__) . 'skladovy-hospodar.js?v=121' ) . '"></script>';

    return $content . ob_get_clean();
});

// --- AJAX: Woo produkty s hlídáním skladu (pro Prodej + info box) ---
add_action('wp_ajax_hospodar_get_products', function() {
    if (!class_exists('WC_Product_Query')) wp_send_json_error('WooCommerce není aktivní.');

    $q = new WC_Product_Query(['limit' => 200, 'status' => array('publish','private'), 'return' => 'ids']);
    $ids = $q->get_products();

    $products = [];
    $categoryIds = [];
    $stocked_products = [];
    $low_stock_products = [];

    // --- FIXTURE ZDE ---
    $items_option = (array)get_option('skladovy_hospodar_items', []);

    foreach ($ids as $id) {
        $p = wc_get_product($id);
        if (!$p) continue;

        // LOGIKA PRO VARIANTY
        if ($p->is_type('variable')) {
            $children = $p->get_children();
            foreach ($children as $child_id) {
                $var = wc_get_product($child_id);
                if (!$var || !$var->managing_stock()) continue; // Přeskoč produkty bez stock
                $stock_qty = $var->get_stock_quantity();  // Přidaná validace
                if ($stock_qty === null) continue;       // Fix: Přeskoč produkty s prázdným skladem
                
                $cats = [];
                $category_terms = wp_get_post_terms($child_id, 'product_cat');
                if (empty($category_terms)) {
                    $category_terms = wp_get_post_terms($id, 'product_cat'); // fallback na rodičovský produkt
                }
                foreach ($category_terms as $c) {
                    $cats[] = ['id'=>$c->term_id,'name'=>$c->name];
                    $categoryIds[$c->term_id] = $c->name; // ✅ plní catMap pro JS
                }

                // Přidej do seznamů sledování skladu
                $products[] = [
                    'id' => $child_id,                 // ID
                    'name' => $var->get_name(),        // Název varianty
                    'stock' => $stock_qty,             // Skladem
                    'cats' => $cats
                ];
                $stocked_products[] = [
                    'category' => $cats[0]['name'] ?? 'Bez kategorie',
                    'name' => $var->get_name(),
                    'stock' => $stock_qty
                ];

                // Zkontroluj nízké zásoby
                $product_key = 'product_' . $child_id;
                $min_qty = isset($items_option[$product_key]) && isset($items_option[$product_key]['min'])
                    ? floatval($items_option[$product_key]['min']) : 0;
                if ($min_qty > 0 && $stock_qty <= $min_qty) {
                    $low_stock_products[] = [
                        'category'=> $cats[0]['name'] ?? 'Bez kategorie',
                        'name' => $var->get_name(),
                        'stock' => $stock_qty,
                        'min' => $min_qty
                    ];
                }
            }
        } 
        
        // LOGIKA PRO SIMPLE PRODUCTS
        elseif ($p->managing_stock()) {
            $stock_qty = $p->get_stock_quantity();
            if ($stock_qty === null) continue;

            $cats = [];
            foreach (wp_get_post_terms($id, 'product_cat') as $c) {
                $cats[] = ['id' => $c->term_id, 'name' => $c->name];
                $categoryIds[$c->term_id] = $c->name; // ✅ přidáno
            }

            $products[] = [
                'id' => $id,
                'name' => $p->get_name(),
                'stock' => $stock_qty,
                'cats' => $cats
            ];
            $stocked_products[] = [
                'category' => $cats[0]['name'] ?? 'Bez kategorie',
                'name' => $p->get_name(),
                'stock' => $stock_qty
            ];

            $product_key = 'product_' . $id;
            $min_qty = isset($items_option[$product_key]) && isset($items_option[$product_key]['min'])
                ? floatval($items_option[$product_key]['min']) : 0;
            if ($min_qty > 0 && $stock_qty <= $min_qty) {
                $low_stock_products[] = [
                    'category' => $cats[0]['name'] ?? 'Bez kategorie',
                    'name' => $p->get_name(),
                    'stock' => $stock_qty,
                    'min' => $min_qty
                ];
            }
        }
    }

    // Předej data do frontendu
    $categories = [];
    foreach ($categoryIds as $id => $name) $categories[] = ['id'=>$id, 'name'=>$name];

    wp_send_json([
        'products' => $products,
        'categories' => $categories,
        'stocked_products' => $stocked_products,
        'low_stock_products' => $low_stock_products
    ]);
});

// --- AJAX: update stock after sale ---
add_action('wp_ajax_hospodar_update_stock', function () {
    // Kontrola oprávnění
    if (!current_user_can('manage_options')) {
        wp_send_json(['ok' => false, 'msg' => 'Přístup zamítnut.']);
    }

    // Získání syrových dat ze vstupu
    $raw = isset($_POST['data']) ? $_POST['data'] : (file_get_contents('php://input') ?: '');
    $payload = json_decode(stripslashes($raw), true);

    // Validace vstupních dat
    if (!$payload || !is_array($payload)) {
        wp_send_json(['ok' => false, 'msg' => 'Chybné vstupní údaje.']);
    }

    // Data z payloadu
    $items = $payload['items'] ?? [];
    $note = sanitize_text_field($payload['note'] ?? 'Prodej přes Skladový hospodář');
    $resume = [];
    $produkty_hist = [];
    $total_price = 0; // Celková cena objednávky

    foreach ($items as $it) {
        $pid = intval($it['id'] ?? 0); // ID produktu
        $qty = intval($it['qty'] ?? 0); // Počet prodaných kusů
        $name = isset($it['name']) ? sanitize_text_field($it['name']) : 'Neznámý produkt';
        $price = floatval($it['price'] ?? 0); // Cena za kus

        if ($pid && $qty > 0) {
            // Získat produkt WooCommerce
            $p = function_exists('wc_get_product') ? wc_get_product($pid) : null;

            if ($p && $p->managing_stock()) {
                // Stav skladu před a po prodeji
                // WooCommerce sám odečítá zásoby – $after je aktuální stav po prodeji
                $after = (int) $p->get_stock_quantity();
                $before = $after + $qty; // stav před prodejem

                // Vypočítat cenu za položku a přičíst k celkové ceně
                $item_price = $price > 0 ? $price * $qty : $p->get_price() * $qty;
                $total_price += $item_price;

                // Přidání do historie
                $produkty_hist[] = [
                    'pid' => $pid,
                    'name' => $p->get_name(),
                    'qty' => $qty,
                    'before' => $before, // Stav skladu před prodejem
                    'after' => $after,   // Stav skladu po prodeji
                    'price_per_item' => $price > 0 ? $price : $p->get_price(),
                    'item_total_price' => $item_price,
                ];

                // Zobrazit shrnutí
                $resume[] = sprintf(
                    '%s: −%d ks (stav: %d → %d) | Celkem: %.2f Kč',
                    esc_html($p->get_name()),
                    $qty,
                    $before,
                    $after,
                    $item_price
                );
            }
        }
    }

    // Zaznamenání historie prodeje
    $hist = get_option('skladovy_hospodar_hist', []);
    $hist[] = [
        'dt' => current_time('Y-m-d H:i:s'),
        'mode' => 'prodej',
        'user_id' => get_current_user_id(),
        'note' => $note,
        'items' => $produkty_hist,
        'cart_total' => $total_price, // Celková cena objednávky
    ];
    update_option('skladovy_hospodar_hist', $hist);

    // Odeslání odpovědi API
    wp_send_json([
        'ok' => true,
        'msg' => 'Záznam o prodeji vytvořen.',
        'resume' => $resume,
        'produkty' => $produkty_hist,
        'cart_total' => $total_price,
    ]);
});

// --- AJAX: find customer for frontend customer search ---
add_action('wp_ajax_hospodar_find_customer', function() {
    // Only allow admins (frontend page is admin-only)
    if (!current_user_can('manage_options')) {
        wp_send_json([]);
        return;
    }
    $q = isset($_POST['q']) ? trim(sanitize_text_field($_POST['q'])) : (isset($_GET['q']) ? trim(sanitize_text_field($_GET['q'])) : '');
    if ($q === '') wp_send_json([]);

    $args = [
        'search'         => '*' . $q . '*',
        'search_columns' => ['user_login','user_email','display_name','user_nicename'],
        'number'         => 30,
    ];
    $uq = new WP_User_Query($args);
    $results = [];
    foreach ($uq->get_results() as $user) {
        $results[] = [
            'id'    => $user->ID,
            'name'  => $user->display_name ?: $user->user_login,
            'email' => $user->user_email
        ];
    }
    wp_send_json($results);
});

// --- AJAX: Výroba (odečet surovin + navýšení balíčků + historie + souhrn) ---
add_action('wp_ajax_sklad_vyroba_frontend', 'sklad_vyroba_frontend');
function sklad_vyroba_frontend() {
    if (!current_user_can('manage_options')) wp_send_json(['ok'=>false,'msg'=>'Přístup zamítnut.']);

    // Jmena produktů
    $products = [];
    if (class_exists('WC_Product_Query')) {
        $q = new WC_Product_Query(['limit'=>400, 'status'=>array('publish','private'), 'return' => 'ids']);
        foreach ($q->get_products() as $pid2) {
            $p = wc_get_product($pid2);
            $products[$pid2] = $p ? $p->get_name() : (get_the_title($pid2) ?: ('Produkt '.$pid2));
        }
    }

    $product_map = (array)get_option('skladovy_hospodar_product_map', []);
    $items = (array)get_option('skladovy_hospodar_items', []);

    $pid = intval($_POST['product_id'] ?? 0);
    $hmotnost = floatval($_POST['hmotnost'] ?? 0);
    $kusy = intval($_POST['kusy'] ?? 0);
    $allow_negative = !empty($_POST['allow_negative']) && intval($_POST['allow_negative']) === 1;

    // Musí být zadán produkt a alespoň kusy nebo hmotnost
    if (!$pid || ($kusy <= 0 && $hmotnost <= 0)) {
        wp_send_json(['ok'=>false,'msg'=>'Chyba dat (produkt nebo kusy/hmotnost). Zadej počet kusů nebo zpracovanou hmotnost.']);
    }

    // Pokud kusy nejsou zadány, zkus dopočítat kusy z metadat produktu (volitelné)
    if ($kusy <= 0 && $hmotnost > 0 && function_exists('wc_get_product')) {
        $prod_for_meta = wc_get_product($pid);
        if ($prod_for_meta) {
            $kg_per_piece = (float)get_post_meta($pid, 'sh_kg_per_piece', true);
            if ($kg_per_piece > 0) {
                // dopočítej kusy (zaokrouhleno na celé kusy)
                $calculated = (int) round($hmotnost / $kg_per_piece);
                if ($calculated > 0) {
                    $kusy = $calculated;
                }
            }
        }
    }

    $rozpad = isset($product_map[$pid]) ? $product_map[$pid] : [];
    if (!is_array($rozpad)) $rozpad = [];
    $materials_info = [];
    if (count($rozpad) > 0) {
        // Zkontrolovat a připravit odečet surovin
        foreach ($rozpad as $part) {
            $iid = $part['item_id'];
            $qty = floatval($part['qty']);
            $mode = $part['mode'] ?? 'per_kg';

            if ($mode === 'per_kg') {
                if ($hmotnost <= 0) {
                    wp_send_json(['ok'=>false,'msg'=>'Recept obsahuje položku počítanou "na kg" ale nebyla zadána hmotnost.']);
                }
                $celkem = round($qty * $hmotnost, 3);
            } else {
                if ($kusy <= 0) {
                    wp_send_json(['ok'=>false,'msg'=>'Recept obsahuje položku počítanou "na kus" ale nebyl zadán počet kusů.']);
                }
                $celkem = round($qty * $kusy, 3);
            }

            // Podpora prefixu 'item_' pokud je uložen takto — provést dříve než čteme jednotku
            if (!isset($items[$iid]) && isset($items['item_'.$iid])) $iid = 'item_'.$iid;

            if (!isset($items[$iid])) {
                wp_send_json(['ok'=>false,'msg'=>'Položka pro rozpad nenalezena: '.$iid]);
            }

            // Determine unit and whether to apply 2% reserve:
            $unit = '';
            if (isset($items[$iid]['unit'])) $unit = strtolower(trim($items[$iid]['unit']));
            elseif (isset($part['unit'])) $unit = strtolower(trim($part['unit']));
            // Apply 2% reserve only for weight units 'g','mg','kg' (not for 'ks' or other piece units)
            if (in_array($unit, ['g','mg','kg'], true)) {
                $celkem = round($celkem * 1.02, 3);
            }

            $before = round(floatval($items[$iid]['qty']), 3);
            if ($celkem > $before && !$allow_negative) {
                wp_send_json(['ok'=>false,'msg'=>'Nedostatek položky: '.$items[$iid]['name'].' (potřeba '.$celkem.', skladem '.$before.')']);
            }
            $after = round($before - $celkem, 3);
            $min = isset($items[$iid]['min']) ? round(floatval($items[$iid]['min']), 3) : 0.0;
            $low = ($after <= $min);

            $materials_info[] = [
                'id' => $iid,
                'name' => $items[$iid]['name'],
                'unit' => $items[$iid]['unit'],
                'used' => $celkem,
                'before' => $before,
                'after' => $after,
                'min' => $min,
                'low' => $low
            ];
        }
        // Odečíst suroviny (i do mínusu, pokud povoleno)
        foreach ($materials_info as $s) {
            $items[$s['id']]['qty'] = $s['after'];
        }
    }

    // Navýšit hotový výrobek (interní balíčky)
    $product_name = $products[$pid] ?? (get_the_title($pid) ?: ('Produkt '.$pid));
    $product_key = 'product_'.$pid;

    // --- Zjisti stav z Woo skladem, pokud je managing_stock ---
    if (function_exists('wc_get_product')) {
        $prod = wc_get_product($pid);
        if ($prod && $prod->managing_stock()) {
            $pkg_before = (int)$prod->get_stock_quantity();
            $pkg_after = $pkg_before + $kusy;
        } else {
            $pkg_before = isset($items[$product_key]) ? round(floatval($items[$product_key]['qty']), 3) : 0.0;
            $pkg_after = round($pkg_before + $kusy, 3);
        }
    } else {
        $pkg_before = isset($items[$product_key]) ? round(floatval($items[$product_key]['qty']), 3) : 0.0;
        $pkg_after = round($pkg_before + $kusy, 3);
    }

    if (!isset($items[$product_key])) {
        $items[$product_key] = [
            'id'   => $product_key,
            'name' => $product_name,
            'unit' => 'ks',
            'qty'  => $pkg_after,
            'min'  => 0,
            'note' => 'Hotový výrobek (automaticky přidáno)'
        ];
    } else {
        $items[$product_key]['qty'] = $pkg_after;
    }

    update_option('skladovy_hospodar_items', $items);

    // NAVÝŠIT WOO SKLAD PRODUKTU PO VÝROBĚ
    if (function_exists('wc_get_product')) {
        $prod = wc_get_product($pid);
        if ($prod && $prod->managing_stock()) {
$woo_before = (int)$prod->get_stock_quantity();
$woo_after = $woo_before + $kusy;
$prod->set_stock_quantity($woo_after);
$prod->save();

// Synchronizace s interním stavem pluginu
$product_key = 'product_' . $pid;
if (!isset($items[$product_key])) {
    $items[$product_key] = [
        'id' => $product_key,
        'name' => $prod->get_name(),
        'unit' => 'ks',
        'qty' => $woo_after,
        'min' => 0,
        'note' => 'Hotový výrobek (automaticky přidáno)'
    ];
} else {
    $items[$product_key]['qty'] = $woo_after;
}
update_option('skladovy_hospodar_items', $items);
        }
    }

    // Historie (obsahuje i názvy surovin a název produktu)
    $hist = get_option('skladovy_hospodar_hist', []);
    $hist[] = [
        'dt' => current_time('Y-m-d H:i:s'),
        'mode' => 'vyroba',
        'user_id' => get_current_user_id(),
        'note' => 'Výroba (frontend)',
        'product_id' => $pid,
        'product_name' => $product_name,
        'qty' => $kusy,
        'hmotnost' => $hmotnost,
        'allow_negative' => $allow_negative,
        'items' => array_map(function($s){
            return [
                'item_id' => $s['id'],
                'name' => $s['name'],
                'qty' => $s['used']
            ];
        }, $materials_info)
    ];
    update_option('skladovy_hospodar_hist', $hist);

    // Souhrn (textový i strukturovaný)
    $resume = [];
    $resume[] = 'Hotový výrobek: '.$product_name.' navýšen z '.$pkg_before.' ks na '.$pkg_after.' ks (+'. $kusy .' ks).';
    if (!empty($materials_info)) {
        $resume[] = 'Suroviny odečteny:';
        foreach ($materials_info as $s) {
            $resume[] = '- '.$s['name'].': −'.$s['used'].' '.$s['unit'].' (zůstává '.$s['after'].' '.$s['unit'].')';
        }
    }
    if ($allow_negative) $resume[] = 'Pozn.: Povolen zápis i když suroviny dojdou (může být záporný stav surovin).';

    wp_send_json([
        'ok' => true,
        'msg' => 'Výroba zapsána.',
        'resume' => $resume,
        'pkg_before' => $pkg_before,
        'pkg_after' => $pkg_after,
        'materials_info' => $materials_info
    ]);
}

// --- AJAX: Preview multi výroba (bez zápisu) ---
// vrací materials_info a has_shortage (používá se v JS modal preview)
add_action('wp_ajax_sklad_vyroba_multi_preview', function() {
    if (!current_user_can('manage_options')) wp_send_json(['ok'=>false,'msg'=>'Přístup zamítnut.']);

    $product_map = (array)get_option('skladovy_hospodar_product_map', []);
    $items = (array)get_option('skladovy_hospodar_items', []);

    $products = isset($_POST['products']) ? json_decode(stripslashes($_POST['products']), true) : [];
    if (!$products || !is_array($products)) wp_send_json(['ok'=>false,'msg'=>'Chybné vstupní údaje.']);

    $allow_negative = !empty($_POST['allow_negative']) && intval($_POST['allow_negative']) === 1;

    // build product names cache (optional)
    $prod_names = [];
    if (class_exists('WC_Product_Query')) {
        $q = new WC_Product_Query(['limit'=>400, 'status'=>array('publish','private'), 'return' => 'ids']);
        foreach ($q->get_products() as $pid) {
            $p = wc_get_product($pid);
            $prod_names[$pid] = $p ? $p->get_name() : (get_the_title($pid) ?: ('Produkt '.$pid));
        }
    }

    $suroviny = [];
    // collect required amounts
    foreach ($products as $sel) {
        $pid = isset($sel['pid']) ? intval($sel['pid']) : (isset($sel['id']) ? intval($sel['id']) : 0);
        $kusy = isset($sel['qty']) ? intval($sel['qty']) : 0;
        $hmotnost = isset($sel['hmotnost']) ? floatval($sel['hmotnost']) : (isset($sel['hmot']) ? floatval($sel['hmot']) : 0.0);
        $rozpad = isset($product_map[$pid]) ? $product_map[$pid] : [];

        // Validate per_kg/per_piece requirements
        foreach ($rozpad as $part_check) {
            $mode_check = isset($part_check['mode']) ? $part_check['mode'] : 'per_kg';
            if ($mode_check === 'per_kg' && $hmotnost <= 0) {
                wp_send_json(['ok'=>false,'msg'=>"Produkt ".($prod_names[$pid] ?? $pid)." vyžaduje zadanou hmotnost (kg)."]);
            }
            if ($mode_check !== 'per_kg' && $kusy <= 0) {
                wp_send_json(['ok'=>false,'msg'=>"Produkt ".($prod_names[$pid] ?? $pid)." vyžaduje zadání počtu vyrobených kusů."]);
            }
        }

        foreach ($rozpad as $part) {
            $iid = $part['item_id'];
            $qty = floatval($part['qty']);
            $mode = isset($part['mode']) ? $part['mode'] : 'per_kg';

            // support prefix fallback BEFORE reading unit
            if (!isset($items[$iid]) && isset($items['item_'.$iid])) $iid = 'item_'.$iid;

            $kolik = ($mode === 'per_kg') ? ($qty * $hmotnost) : ($qty * $kusy);

            // Najít jednotku (pokud je v Items, použij ji; jinak fallback na část)
            $unit = isset($items[$iid]['unit']) ? strtolower(trim($items[$iid]['unit'])) : (isset($part['unit']) ? strtolower(trim($part['unit'])) : '');
            // Aplikuj navýšení 2 % pouze pro suroviny ve váhových jednotkách (g/mg/kg)
            if (in_array($unit, ['g','mg','kg'], true)) {
                $kolik = round($kolik * 1.02, 3);
            } else {
                $kolik = round($kolik, 3);
            }

            if (!isset($suroviny[$iid])) $suroviny[$iid] = 0.0;
            $suroviny[$iid] += $kolik;
        }
    }

    // prepare before_map and check shortages (respect allow_negative)
    $before_map = [];
    foreach ($suroviny as $iid => $kolik) {
        if (!isset($items[$iid]) && isset($items['item_'.$iid])) $iid = 'item_'.$iid;
        $before = isset($items[$iid]) ? floatval($items[$iid]['qty']) : 0.0;
        $before_map[$iid] = $before;
        if ($kolik > $before && !$allow_negative) {
            $name = isset($items[$iid]) ? $items[$iid]['name'] : $iid;
            // Instead of returning immediately, collect shortages. We'll return full materials_info below.
            // prepare list of shortages (we'll use has_shortage flag)
        }
    }

    // build materials_info for preview
    $materials_info = [];
    $has_shortage = false;
    foreach ($before_map as $iid => $before) {
        $used = isset($suroviny[$iid]) ? $suroviny[$iid] : 0.0;
        $after = round($before - $used, 3);
        $min = isset($items[$iid]['min']) ? round(floatval($items[$iid]['min']), 3) : 0.0;
        $name = isset($items[$iid]) ? $items[$iid]['name'] : $iid;
        $unit = isset($items[$iid]) ? $items[$iid]['unit'] : '';
        $low = ($after <= $min);
        $shortage = ($used > $before);
        if ($shortage && !$allow_negative) $has_shortage = true;
        $materials_info[] = [
            'id' => $iid,
            'name' => $name,
            'unit' => $unit,
            'used' => $used,
            'before' => $before,
            'after' => $after,
            'min' => $min,
            'low' => $low,
            'shortage' => $shortage
        ];
    }

    wp_send_json([
        'ok' => true,
        'materials_info' => $materials_info,
        'suroviny' => $suroviny,
        'has_shortage' => $has_shortage
    ]);
});

// --- AJAX: Multi produktová výroba s 2% navýšením surovin (u g/mg/kg) ---
add_action('wp_ajax_sklad_vyroba_multi_frontend', function() {
    if (!current_user_can('manage_options')) wp_send_json(['ok'=>false,'msg'=>'Přístup zamítnut.']);

    $product_map = (array)get_option('skladovy_hospodar_product_map', []);
    $items = (array)get_option('skladovy_hospodar_items', []);

    // NOVĚ: získání zákazníka
    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;

    $products = isset($_POST['products']) ? json_decode(stripslashes($_POST['products']), true) : [];
    if (!$products || !is_array($products)) wp_send_json(['ok'=>false, 'msg'=>'Chybné vstupní údaje.']);

    $allow_negative = !empty($_POST['allow_negative']) && intval($_POST['allow_negative']) === 1;

    $prod_names = [];
    if (class_exists('WC_Product_Query')) {
        $q = new WC_Product_Query(['limit'=>400, 'status'=>array('publish','private'), 'return' => 'ids']);
        foreach ($q->get_products() as $pid) {
            $p = wc_get_product($pid);
            $prod_names[$pid] = $p ? $p->get_name() : (get_the_title($pid) ?: ('Produkt '.$pid));
        }
    }

    $suroviny = [];
    // sběr potřebného množství
    foreach ($products as $sel) {
        $pid = intval($sel['pid']);
        $kusy = isset($sel['qty']) ? intval($sel['qty']) : 0;
        $hmotnost = isset($sel['hmotnost']) ? floatval($sel['hmotnost']) : 0.0;
        $rozpad = isset($product_map[$pid]) ? $product_map[$pid] : [];

        // Validace: pokud rozpad obsahuje per_kg položku, musí být zadána hmotnost
        foreach ($rozpad as $part_check) {
            $mode_check = isset($part_check['mode']) ? $part_check['mode'] : 'per_kg';
            if ($mode_check === 'per_kg' && $hmotnost <= 0) {
                wp_send_json(['ok'=>false, 'msg'=>"Produkt ".($prod_names[$pid] ?? $pid)." vyžaduje zadanou hmotnost (kg)."]);
            }
            if ($mode_check !== 'per_kg' && $kusy <= 0) {
                wp_send_json(['ok'=>false, 'msg'=>"Produkt ".($prod_names[$pid] ?? $pid)." vyžaduje zadání počtu vyrobených kusů."]);
            }
        }

        foreach ($rozpad as $part) {
            $iid = $part['item_id'];
            $qty = floatval($part['qty']);
            $mode = isset($part['mode']) ? $part['mode'] : 'per_kg';

            // fallback na item_ prefix dříve, než čteme jednotku
            if (!isset($items[$iid]) && isset($items['item_'.$iid])) $iid = 'item_'.$iid;

            $kolik = ($mode === 'per_kg') ? ($qty * $hmotnost) : ($qty * $kusy);

            // Najít jednotku (pokud je v Items, použij ji; jinak fallback na část)
            $unit = isset($items[$iid]['unit']) ? strtolower(trim($items[$iid]['unit'])) : (isset($part['unit']) ? strtolower(trim($part['unit'])) : '');
            // Aplikuj navýšení 2 % pouze pro suroviny v gramech/mase (g/mg/kg)
            if (in_array($unit, ['g','mg','kg'], true)) {
                $kolik = round($kolik * 1.02, 3); // 2% rezervy
            } else {
                $kolik = round($kolik, 3);
            }

            if (!isset($suroviny[$iid])) $suroviny[$iid] = 0.0;
            $suroviny[$iid] += $kolik;
        }
    }

    // Kontrola dostupnosti surovin (respektuje allow_negative) a příprava před-stavů
    $before_map = [];
    foreach ($suroviny as $iid => $kolik) {
        // fallback prefix
        if (!isset($items[$iid]) && isset($items['item_'.$iid])) $iid = 'item_'.$iid;
        $before = isset($items[$iid]) ? floatval($items[$iid]['qty']) : 0.0;
        $before_map[$iid] = $before;
        if ($kolik > $before && !$allow_negative) {
            $name = isset($items[$iid]) ? $items[$iid]['name'] : $iid;
            wp_send_json(['ok'=>false, 'msg'=>"Nedostatek suroviny: $name (potřeba $kolik, skladem $before)"]);
        }
    }

    // Odečíst suroviny (i do mínusu, pokud povoleno)
    foreach ($suroviny as $iid => $kolik) {
        if (!isset($items[$iid]) && isset($items['item_'.$iid])) $iid = 'item_'.$iid;
        $items[$iid]['qty'] = round($items[$iid]['qty'] - $kolik, 3);
    }

    // Sestav materiálové info (before/used/after/min/low)
    $materials_info = [];
    foreach ($before_map as $iid => $before) {
        $used = isset($suroviny[$iid]) ? $suroviny[$iid] : 0.0;
        $after = round($before - $used, 3);
        $min = isset($items[$iid]['min']) ? round(floatval($items[$iid]['min']), 3) : 0.0;
        $name = isset($items[$iid]) ? $items[$iid]['name'] : $iid;
        $unit = isset($items[$iid]) ? $items[$iid]['unit'] : '';
        $low = ($after <= $min);
        $materials_info[] = [
            'id' => $iid,
            'name' => $name,
            'unit' => $unit,
            'used' => $used,
            'before' => $before,
            'after' => $after,
            'min' => $min,
            'low' => $low
        ];
    }

    $resume = [];
    foreach ($products as $sel) {
        $pid = intval($sel['pid']);
        $kusy = isset($sel['qty']) ? intval($sel['qty']) : 0;
        $hmotnost = isset($sel['hmotnost']) ? floatval($sel['hmotnost']) : 0.0;
        $product_name = $prod_names[$pid] ?? (get_the_title($pid) ?: ('Produkt '.$pid));
        $product_key = 'product_'.$pid;

        if (function_exists('wc_get_product')) {
            $prod = wc_get_product($pid);
            if ($prod && $prod->managing_stock()) {
                $before = (int)$prod->get_stock_quantity();
                $after = $before + $kusy;
                $prod->set_stock_quantity($after);
                $prod->set_stock_status('instock');
                $prod->save();
                $resume[] = "$product_name: navýšeno z $before ks na $after ks (+$kusy ks)";
            } else {
                $before = isset($items[$product_key]) ? floatval($items[$product_key]['qty']) : 0;
                $after = $before + $kusy;
                $items[$product_key]['qty'] = $after;
                $resume[] = "$product_name: navýšeno z $before ks na $after ks (+$kusy ks)";
            }
        }
    }

    update_option('skladovy_hospodar_items', $items);

    // Rozšířený zápis produktů se stavem před a po (včetně hmotnosti)
    $produkty_hist = [];
    foreach ($products as $sel) {
        $pid = intval($sel['pid']);
        $kusy = isset($sel['qty']) ? intval($sel['qty']) : 0;
        $hmotnost = isset($sel['hmotnost']) ? floatval($sel['hmotnost']) : 0.0;
        $product_name = $prod_names[$pid] ?? (get_the_title($pid) ?: ('Produkt '.$pid));
        $before = null;
        $after = null;
        if (function_exists('wc_get_product')) {
            $prod = wc_get_product($pid);
            if ($prod && $prod->managing_stock()) {
                // Because we've already updated the product above, compute before/after accordingly
                $current = (int)$prod->get_stock_quantity();
                $after = $current;
                $before = $current - $kusy;
            } else {
                $item_key = 'product_'.$pid;
                $before = isset($items[$item_key]) ? (float)$items[$item_key]['qty'] - $kusy : 0;
                $after = isset($items[$item_key]) ? (float)$items[$item_key]['qty'] : 0;
            }
        }
        $catname = 'Bez kategorie';
        if (function_exists('wp_get_post_terms')) {
            $terms = wp_get_post_terms($pid, 'product_cat');
            if (!empty($terms) && isset($terms[0]->name)) $catname = $terms[0]->name;
        }

        $produkty_hist[] = [
            'pid' => $pid,
            'name' => $product_name,
            'vyrobeno' => $kusy,
            'hmotnost' => $hmotnost,
            'stav_pred' => $before,
            'stav_po' => $after,
            'category' => $catname,
        ];
    }

    // Save history similar to other endpoints
    // Ensure variables used in history exist and items array uses consistent keys (id/name/qty/before/after)
    $note = 'Výroba (frontend)';
    $cart_total = null;
    $evidence = 0;

    $items_for_history = array_map(function($p){
        return [
            'id' => isset($p['pid']) ? $p['pid'] : (isset($p['id']) ? $p['id'] : null),
            'name' => isset($p['name']) ? $p['name'] : '',
            'qty' => isset($p['vyrobeno']) ? $p['vyrobeno'] : (isset($p['qty']) ? $p['qty'] : 0),
            'before' => isset($p['stav_pred']) ? $p['stav_pred'] : (isset($p['before']) ? $p['before'] : null),
            'after' => isset($p['stav_po']) ? $p['stav_po'] : (isset($p['after']) ? $p['after'] : null),
        ];
    }, $produkty_hist);

    $hist = get_option('skladovy_hospodar_hist', []);
    $hist[] = [
        'dt' => current_time('Y-m-d H:i:s'),
        'mode' => 'vyroba',
        'user_id' => get_current_user_id(),
        'customer_id' => $customer_id,
        'note' => $note,
        'items' => $items_for_history,
        'produkty' => $produkty_hist,
        'cart_total' => $cart_total,
        'evidence' => $evidence,
    ];
    update_option('skladovy_hospodar_hist', $hist);

    wp_send_json([
        'ok' => true,
        'msg' => 'Výdej zapsán.',
        'resume' => $resume,
        'produkty' => $produkty_hist,
        'cart_total' => $cart_total
    ]);
});

// --- AJAX: Odpis (odepsat vybrané produkty ze skladu) ---
add_action('wp_ajax_hospodar_odpis', 'hospodar_odpis');
function hospodar_odpis() {
    if (!current_user_can('manage_options')) wp_send_json(['ok'=>false,'msg'=>'Přístup zamítnut.']);

    $data = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : [];
    if (!$data || !is_array($data)) wp_send_json(['ok'=>false,'msg'=>'Neplatná data (items).']);

    $note = isset($_POST['note']) ? sanitize_text_field($_POST['note']) : '';
    $items_option = (array)get_option('skladovy_hospodar_items', []);
    $resume = [];
    $histItems = [];

    // Build product name cache
    $prod_names = [];
    if (class_exists('WC_Product_QUERY')) {
        $q = new WC_Product_Query(['limit'=>400, 'status'=>array('publish','private'), 'return' => 'ids']);
        foreach ($q->get_products() as $pid) {
            $p = wc_get_product($pid);
            if ($p) $prod_names[$pid] = $p->get_name();
        }
    }

    foreach ($data as $it) {
        $pid = intval($it['id'] ?? 0);
        $qty = intval($it['qty'] ?? 0);
        if (!$pid || $qty <= 0) continue;

        // Invalidate cache & reload
        if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($pid);
        clean_post_cache($pid);
        $p = function_exists('wc_get_product') ? wc_get_product($pid) : null;

        $name = $it['name'] ?? '';
        if (!$name) $name = $prod_names[$pid] ?? (function_exists('get_the_title') ? get_the_title($pid) : ('ID '.$pid));

        if ($p && $p->managing_stock()) {
            $before = (int)$p->get_stock_quantity();
            $after = max(0, $before - $qty);
            $p->set_stock_quantity($after);
            if ($after <= 0) $p->set_stock_status('outofstock');
            $p->save();

            $resume[] = $name.': '.$before.' → '.$after.' (−'.$qty.' ks)';
            $histItems[] = [
                'id' => $pid,
                'name' => $name,
                'qty' => $qty,
                'before' => $before,
                'after' => $after
            ];
        } else {
            // fallback to internal items storage (product_ prefix)
            $key = 'product_'.$pid;
            if (!isset($items_option[$key])) {
                $before = 0;
                $after = round(0 - $qty, 3);
                $items_option[$key] = [
                    'id' => $key,
                    'name' => $name,
                    'unit' => 'ks',
                    'qty' => $after,
                    'min' => 0,
                    'note' => 'Hotový výrobek (automaticky přidáno)'
                ];
            } else {
                $before = isset($items_option[$key]) ? floatval($items_option[$key]['qty']) : 0;
                $after = round($before - $qty, 3);
                $items_option[$key]['qty'] = $after;
            }
            $resume[] = $name.': '.$before.' → '.$after.' (−'.$qty.' ks)';
            $histItems[] = [
                'id' => $pid,
                'name' => $name,
                'qty' => $qty,
                'before' => $before,
                'after' => $after
            ];
        }
    }

    // Save items option if modified
    update_option('skladovy_hospodar_items', $items_option);
    // Zapsat do historie
    $hist = get_option('skladovy_hospodar_hist', []);
    $hist[] = [
        'dt' => current_time('Y-m-d H:i:s'),
        'mode' => 'odpis',
        'user_id' => get_current_user_id(),
        'note' => $note ?: 'Odpis skladu (frontend)',
        'items' => $histItems,
    ];
    update_option('skladovy_hospodar_hist', $hist);

    wp_send_json(['ok'=>true, 'resume' => $resume, 'items' => $histItems]);
}

// --- NEW/UPDATED: Skutečné vytvoření Woo objednávky při "prodeji" ---
// Upraveno: tolerantnější parsing 'data' payloadu (podporuje různé formáty POSTu),
// detailní chybové hlášky, lepší fallbacky — to řeší situaci, kdy JS/Fetch odesílá data jinak.
add_action('wp_ajax_hospodar_create_order', function() {
    if (!current_user_can('manage_options')) wp_send_json(['ok'=>false, 'data'=>'Přístup zamítnut.']);

    $customer_id = intval($_POST['customer_id'] ?? 0);

    // try to get payload robustly (supporting typical form-encoding, raw body, double-encoded JSON, ...)
    $raw_data = null;
    if (isset($_POST['data'])) {
        $raw_data = $_POST['data'];
    } else {
        $raw = file_get_contents('php://input');
        if ($raw) {
            // parse_str for form-encoded raw body
            parse_str($raw, $parsed);
            if (!empty($parsed['data'])) $raw_data = $parsed['data'];
            else $raw_data = $raw;
        }
    }

    $data = [];
    if ($raw_data) {
        // common case: JSON string
        $decoded = json_decode(stripslashes($raw_data), true);
        if ($decoded === null) {
            // try without stripslashes
            $decoded = json_decode($raw_data, true);
            // if still null, maybe it's double-encoded (string inside string)
            if ($decoded === null && is_string($raw_data)) {
                $tmp = json_decode($raw_data, true);
                if (is_string($tmp)) $decoded = json_decode($tmp, true);
            }
        }
        if (is_array($decoded)) $data = $decoded;
    } elseif (isset($_POST['items'])) {
        // older callers may send items directly as POST param
        $items_raw = $_POST['items'];
        $decoded_items = json_decode(stripslashes($items_raw), true);
        if ($decoded_items === null) $decoded_items = $items_raw;
        $data = ['items' => $decoded_items];
    }

    if (!$customer_id || !$data || !isset($data['items']) || !is_array($data['items']) || count($data['items']) === 0) {
        wp_send_json(['ok'=>false, 'data'=>'Chybí data pro objednávku. Odeslaný customer_id: '.intval($customer_id) .'; data_raw: '.(isset($raw_data) ? substr($raw_data,0,400) : '(empty)')]);
    }

    if (!class_exists('WC_Order')) {
        wp_send_json(['ok'=>false, 'data'=>'Nelze vytvořit objednávku – WooCommerce není aktivní.']);
    }

    try {
        $order = wc_create_order([
            'customer_id' => $customer_id,
            'created_via' => 'skladovy_hospodar',
        ]);
        if (is_wp_error($order)) {
            wp_send_json(['ok'=>false, 'data'=>'Chyba při vytváření objednávky: '.$order->get_error_message()]);
        }

        foreach ($data['items'] as $item) {
            $product_id = intval($item['id'] ?? 0);
            $qty = intval($item['qty'] ?? 0);
            if ($product_id && $qty > 0) {
                $product = wc_get_product($product_id);
                if ($product) {
                    $unit_price = isset($item['price']) ? floatval($item['price']) : floatval($product->get_price());
                    $args = [];
                    if (isset($item['sale']) && $item['sale'] > 0) {
                        $subtotal = $unit_price * $qty / (1 - ($item['sale']/100));
                        $args['subtotal'] = round($subtotal, 2);
                        $args['total'] = round($unit_price * $qty, 2);
                    }
                    $order->add_product($product, $qty, $args);
                } else {
                    // product not found; continue but record in order note
                    $order->add_order_note("Produkt ID $product_id nebyl nalezen při přidávání do objednávky.", false, true);
                }
            }
        }

        // nastavit zákazníka a poznámku
        $order->set_customer_id($customer_id);
        $order->add_order_note('Objednávka vytvořena přes Skladový hospodář.');

        // vyplnit billing údaje pokud uživatel existuje
        $user = get_userdata($customer_id);
        if ($user) {
            $order->set_billing_first_name($user->first_name);
            $order->set_billing_last_name($user->last_name);
            $order->set_billing_email($user->user_email);
            $order->set_billing_address_1('');
            $order->set_billing_city('');
            $order->set_billing_postcode('');
            $order->set_billing_country('CZ');
            $order->set_billing_phone('');
        }

        // spočítat cenu a uložit stav "completed"
        $order->calculate_totals();
        $order->update_status('completed');
        $order->save();

        wp_send_json(['ok'=>true, 'order_id'=>$order->get_id()]);
    } catch (Exception $e) {
        wp_send_json(['ok'=>false, 'data'=>'Výjimka při vytváření objednávky: '.$e->getMessage()]);
    }
});

// --- KONTROLA A SKRYTÍ/ZOBRAZENÍ BADGE SLEVA ---
add_filter('woocommerce_sale_flash', 'skladovy_hospodar_custom_sale_badge', 10, 3);
function skladovy_hospodar_custom_sale_badge($html, $post, $product) {
    // Získej hodnotu checkboxu z meta dat produktu
    $show_badge = get_post_meta($product->get_id(), '_skladovy_hospodar_show_sale_badge', true);
    
    // Pokud je checkbox explicitně VYPNUTÝ (hodnota '0'), skryj badge
    if ($show_badge === '0') {
        return ''; // Vrátí prázdný string = badge se nezobrazí
    }
    
    // Jinak vrať výchozí badge (nebo ponech WooCommerce výchozí chování)
    return $html;
}

// --- CSS PRO SKRYTÍ BADGE (záložní řešení pro nestandardní šablony) ---
add_action('wp_head', 'skladovy_hospodar_hide_sale_badge_css');
function skladovy_hospodar_hide_sale_badge_css() {
    // Aplikuj pouze na stránkách jednotlivých produktů
    if (!is_product()) {
        return;
    }
    
    // Bezpečně získej ID produktu
    $product_id = get_the_ID();
    if (!$product_id) {
        return;
    }
    
    // Získej hodnotu checkboxu
    $show_badge = get_post_meta($product_id, '_skladovy_hospodar_show_sale_badge', true);
    
    // Pokud je badge vypnutý, přidej CSS pro skrytí
    if ($show_badge === '0') {
        echo '<style>
            .onsale, .badge-sale, span.onsale, .woocommerce span.onsale {
                display: none !important;
            }
        </style>';
    }
}

require_once __DIR__.'/skladovy-hospodar-rozsireni.php';
require_once __DIR__.'/skladovy-hospodar-produkty.php';
require_once __DIR__.'/skladovy-hospodar-importexport.php';

// --- SKLADOVÝ ODEČET A NAVÝŠENÍ PŘI ZMĚNĚ STAVU OBJEDNÁVKY ---

// --- AJAX: Vytvoření nového zákazníka ---
add_action('wp_ajax_hospodar_create_customer', function() {
    // Kontrola oprávnění
    if (!current_user_can('manage_options')) {
        wp_send_json(['ok' => false, 'msg' => 'Nemáte oprávnění pro tuto akci.']);
        return;
    }

    // Získání jména zákazníka z POST dat
    $name = isset($_POST['name']) ? trim(sanitize_text_field($_POST['name'])) : '';
    if ($name === '') {
        wp_send_json(['ok' => false, 'msg' => 'Jméno zákazníka nebylo zadáno.']);
        return;
    }

    // Zkusit vytvořit uživatele
    $user_id = wp_insert_user([
        'user_login' => sanitize_title($name), // Uživatelské jméno
        'display_name' => $name, // Zobrazované jméno
        'user_pass' => wp_generate_password(), // Náhodné heslo
        'role' => 'customer', // Role "customer"
    ]);

    // Zpracování chyb při vytváření uživatele
    if (is_wp_error($user_id)) {
        $error_message = $user_id->get_error_message(); // Detailní popis chyby
        wp_send_json(['ok' => false, 'msg' => 'Chyba při vytváření zákazníka: ' . $error_message]);
        return;
    }

    // Úspěšné vytvoření zákazníka
    wp_send_json(['ok' => true, 'id' => $user_id, 'name' => $name]);
});