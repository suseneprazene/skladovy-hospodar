<?php
add_action('admin_menu', function() {
    add_submenu_page('skladovy-hospodar', 'Položky', 'Položky', 'manage_options', 'skladovy-hospodar-mnozstvi', 'skladovy_hospodar_stock_page');
    add_submenu_page('skladovy-hospodar', 'Produkty a jejich složení', 'Produkty a jejich složení', 'manage_options', 'skladovy-hospodar-produkty', 'skladovy_hospodar_products_page');
});

// --- OPRAVENÝ VÝPIS HISTORIE ---
if (!function_exists('skladovy_hospodar_hist_page')) {
function skladovy_hospodar_hist_page() {
    echo '<div class="wrap"><h1>Historie vkladů a výběrů</h1>';
    $hist = get_option('skladovy_hospodar_hist', []);
    if (!$hist || !is_array($hist)) {
        echo "<p>Žádné transakce nejsou zaznamenány.</p>";
        echo '</div>';
        return;
    }
    $filter_cust = isset($_GET['zakaznik']) ? intval($_GET['zakaznik']) : 0;
    if ($filter_cust) {
        $hist = array_filter($hist, function($h) use ($filter_cust) {
            return (isset($h['customer_id']) && intval($h['customer_id']) === $filter_cust)
                || (isset($h['user_id']) && intval($h['user_id']) === $filter_cust);
        });
        echo '<div style="margin-bottom:16px;"><a href="' . admin_url('admin.php?page=skladovy-hospodar-historie') . '" class="button">Zpět na všechny záznamy</a></div>';
    }
    echo '<table style="border-collapse:collapse;width:100%;background:#fff;box-shadow:0 2px 8px #eee;">
        <thead>
            <tr>
                <th style="padding:5px 8px;">Datum</th>
                <th>Typ</th>
                <th>Zákazník / Uživatel</th>
                <th>Poznámka</th>
                <th>Položky / Produkt</th>
                <th>Celkem</th>
            </tr>
        </thead>
        <tbody>';
    foreach (array_reverse($hist) as $h) {
        $typ = $h['mode'] ?? '';
        if ($typ === 'vyroba') {
            // Uživatel (pro klikací filtr)
            $usr = '';
            if (!empty($h['user_id'])) {
                $u = get_userdata($h['user_id']);
                $name = $u ? esc_html($u->display_name) : 'ID '.$h['user_id'];
                $usr = '<a href="' . admin_url('admin.php?page=skladovy-hospodar-historie&zakaznik=' . intval($h['user_id'])) . '">' . $name . '</a>';
            }
            echo '<tr>
                <td style="padding:5px 8px;">'.esc_html($h['dt']).'</td>
                <td>Výroba</td>
                <td>'.($usr ?: '-').'</td>
                <td>'.esc_html($h['note'] ?? '').'</td>
                <td>';
            if (!empty($h['produkty'])) {
                foreach ($h['produkty'] as $prod) {
                    echo '<b>' . esc_html($prod['name']) . '</b> – ' . intval($prod['vyrobeno']) . ' ks';
                    if (isset($prod['stav_pred']) && isset($prod['stav_po'])) {
                        echo ' (stav: ' . esc_html($prod['stav_pred']) . ' → ' . esc_html($prod['stav_po']) . ')';
                    }
                    echo '<br>';
                }
                if (!empty($h['suroviny'])) {
                    $items = get_option('skladovy_hospodar_items', []);
                    echo '<small>Suroviny: ';
                    echo implode(', ', array_map(function($iid, $qty) use ($items){
                        $name = isset($items[$iid]) ? $items[$iid]['name'] : $iid;
                        return esc_html($name) . ' (−' . floatval($qty) . ')';
                    }, array_keys($h['suroviny']), $h['suroviny']));
                    echo '</small>';
                }
            }
            elseif (isset($h['product_id'])) {
                $pid = $h['product_id'] ?? '';
                $product_name = '';
                if ($pid && class_exists('WC_Product')) {
                    $p = wc_get_product($pid);
                    if ($p) $product_name = $p->get_name();
                }
                $qty = intval($h['qty'] ?? 0);
                $slozeni = '';
                if (!empty($h['items'])) {
                    $items = get_option('skladovy_hospodar_items', []);
                    $slozeni = implode('<br>', array_map(function($i) use ($items){
                        $nm = isset($i['name']) && $i['name'] !== '' ? $i['name'] : (isset($items[$i['item_id']]) ? $items[$i['item_id']]['name'] : $i['item_id']);
                        return esc_html($nm).' ('.floatval($i['qty']).')';
                    }, $h['items']));
                }
                echo '<b>'.esc_html($product_name).'</b> (vyrobeno '.intval($qty).' ks)<br><small>'.$slozeni.'</small>';
            }
            echo '</td>
                <td>-</td>
            </tr>';
        } else {
            // Zákazník nebo uživatel (proklik do Woo admina)
            $cust = '';
            if (!empty($h['customer_id'])) {
                $u = get_userdata($h['customer_id']);
                $name = $u ? esc_html($u->display_name) : 'ID '.$h['customer_id'];
                $cust = '<a href="' . admin_url('admin.php?page=wc-orders&s&search-filter=all&action=-1&m=0&packetery_order_type_js-wizard-packetery-order-type&_created_via&_customer_user=' . intval($h['customer_id']) . '&filter_action=Filtr&paged=1&action2=-1') . '" target="_blank">' . $name . '</a>';
            } elseif (!empty($h['user_id'])) {
                $u = get_userdata($h['user_id']);
                $name = $u ? esc_html($u->display_name) : 'ID '.$h['user_id'];
                $cust = $name;
            }
            echo '<tr>
                <td style="padding:5px 8px;">' . esc_html($h['dt']) . '</td>
                <td>'
                    . ($typ == 'prodej' ? 
                        (isset($h['evidence']) && $h['evidence'] ? 'Prodej (evidence)' : 'Prodej (bez evidence)') 
                      : 'Navýšení') . 
                '</td>
                <td>' . ($cust ?: '-') . '</td>
                <td>' . esc_html($h['note'] ?? '') . '</td>
                <td>';
            if (!empty($h['items']) && is_array($h['items'])) {
foreach ($h['items'] as $item) {
    echo sprintf(
        '%s (%d ks, sklad %d → %d) | Cena: %.2f Kč<br>',
        esc_html($item['name']),                 // Název produktu
        intval($item['qty']),                    // Počet prodaných kusů
        intval($item['before'] ?? 0),            // Stav skladu před (použití výchozí hodnoty, pokud chybí)
        intval($item['after'] ?? 0),             // Stav skladu po (použití výchozí hodnoty, pokud chybí)
        floatval($item['item_total_price'] ?? 0) // Cena za produkt (použití výchozí hodnoty, pokud chybí)
    );
}
            } else {
                echo '-';
            }
            echo '</td>
<td>' . number_format(floatval($h['cart_total'] ?? 0), 2, ',', ' ') . ' Kč</td>
            </tr>';
        }
    }
    echo '</tbody></table>';
    echo '</div>';
}
}

// --- SKLADOVÉ POLOŽKY (CRUD + NAVÝŠENÍ + HROMADNÉ NAVÝŠENÍ + ŘAZENÍ) ---
function skladovy_hospodar_stock_page() {
    $items = (array)get_option('skladovy_hospodar_items', []);
    $notice = '';

    // --- NAVÝŠENÍ STAVU (jednotlivě) ---
    if (
        isset($_POST['sh_increase_item']) && 
        isset($_POST['increase_id'], $_POST['increase_qty']) &&
        check_admin_referer('sh_increase_item')
    ) {
        $iid = $_POST['increase_id'];
        $plus = floatval($_POST['increase_qty']);
        if ($plus > 0 && isset($items[$iid])) {
$items[$iid]['qty'] = round(floatval($items[$iid]['qty']) + $plus, 3);

// Synchronizace s WooCommerce API, pokud je produkt hlídán skladem.
if ($iid && function_exists('wc_get_product')) {
    $woo_product = wc_get_product($iid);
    if ($woo_product && $woo_product->managing_stock()) {
        $woo_product->set_stock_quantity($items[$iid]['qty']);
        $woo_product->save();
    }
}
            update_option('skladovy_hospodar_items', $items);
            // Zapsat i do historie!
            $hist = get_option('skladovy_hospodar_hist', []);
            $hist[] = [
                'dt' => current_time('Y-m-d H:i:s'),
                'mode' => 'navyseni',
                'customer_id' => get_current_user_id(),
                'note' => 'Navýšení skladu ručně v adminu',
                'items' => [
                    [
                        'id' => $iid,
                        'name' => $items[$iid]['name'],
                        'qty' => $plus
                    ]
                ],
                'cart_total' => 0,
                'evidence' => 1,
            ];
            update_option('skladovy_hospodar_hist', $hist);
            wp_redirect(admin_url('admin.php?page=skladovy-hospodar-mnozstvi&increased='.$iid));
            exit;
        }
    }

    // --- HROMADNÉ NAVÝŠENÍ ---
    if (
        isset($_POST['sh_increase_bulk']) &&
        isset($_POST['bulk_ids'], $_POST['bulk_qty_all']) &&
        check_admin_referer('sh_increase_bulk')
    ) {
        $bulk_ids = (array)$_POST['bulk_ids'];
        $bulk_qty_all = floatval($_POST['bulk_qty_all']);
        if ($bulk_qty_all > 0 && !empty($bulk_ids)) {
            foreach ($bulk_ids as $id) {
                if (isset($items[$id])) {
                    $items[$id]['qty'] = round(floatval($items[$id]['qty']) + $bulk_qty_all, 3);
                    // Do historie
                    $hist = get_option('skladovy_hospodar_hist', []);
                    $hist[] = [
                        'dt' => current_time('Y-m-d H:i:s'),
                        'mode' => 'navyseni',
                        'customer_id' => get_current_user_id(),
                        'note' => 'Hromadné navýšení skladu v adminu',
                        'items' => [
                            [
                                'id' => $id,
                                'name' => $items[$id]['name'],
                                'qty' => $bulk_qty_all
                            ]
                        ],
                        'cart_total' => 0,
                        'evidence' => 1,
                    ];
                    update_option('skladovy_hospodar_hist', $hist);
                }
            }
            update_option('skladovy_hospodar_items', $items);
            $notice = '<div class="updated"><p>Navýšení provedeno u vybraných položek.</p></div>';
        }
    }

    // Notifikace po redirectu
    if (isset($_GET['increased']) && isset($items[$_GET['increased']])) {
        $notice = '<div class="updated"><p>Stav položky <strong>'.esc_html($items[$_GET['increased']]['name']).'</strong> byl navýšen.</p></div>';
    }

    if (isset($_POST['sh_sklad_save_item']) && check_admin_referer('sh_sklad_item')) {
        $id = sanitize_text_field($_POST['item_id']);
        $name = sanitize_text_field($_POST['item_name']);
        $unit = sanitize_text_field($_POST['item_unit']);
        $qty = floatval($_POST['item_qty']);
        $min = floatval($_POST['item_min']);
        $note = sanitize_text_field($_POST['item_note']);
        if (!$id) $id = 'item_' . time() . rand(1000,9999);
        $items[$id] = [
            'id' => $id,
            'name' => $name,
            'unit' => $unit,
            'qty' => $qty,
            'min' => $min,
            'note' => $note,
        ];
        update_option('skladovy_hospodar_items', $items);
        $notice = '<div class="updated"><p>Položka uložena.</p></div>';
    }

    if (isset($_GET['delete_item']) && isset($items[$_GET['delete_item']])) {
        unset($items[$_GET['delete_item']]);
        update_option('skladovy_hospodar_items', $items);
        $notice = '<div class="updated"><p>Položka smazána.</p></div>';
    }

    $edit = null;
    if (isset($_GET['edit_item']) && isset($items[$_GET['edit_item']])) {
        $edit = $items[$_GET['edit_item']];
    }

    echo '<div class="wrap"><h1>Položky</h1>';
    echo $notice;

    echo '<form method="post">';
    wp_nonce_field('sh_sklad_item');
    echo '<h3>'.($edit?'Upravit':'Přidat').' položku</h3>';
    echo '<input type="hidden" name="item_id" value="'.esc_attr($edit['id'] ?? '').'">';
    echo '<table class="form-table"><tr><th>Název</th><td><input name="item_name" value="'.esc_attr($edit['name'] ?? '').'" required></td></tr>';
    echo '<tr><th>Jednotka</th><td><input name="item_unit" value="'.esc_attr($edit['unit'] ?? '').'" placeholder="kg, g, ks…" required></td></tr>';
    echo '<tr><th>Množství</th><td><input name="item_qty" type="number" step="0.01" value="'.esc_attr($edit['qty'] ?? 0).'" required></td></tr>';
    echo '<tr><th>Minimální množství <span title="Pro upozornění při nízkém stavu">?</span></th><td><input name="item_min" type="number" step="0.01" value="'.esc_attr($edit['min'] ?? 0).'"></td></tr>';
    echo '<tr><th>Poznámka</th><td><input name="item_note" value="'.esc_attr($edit['note'] ?? '').'"></td></tr>';
    echo '</table>';
    echo '<button type="submit" name="sh_sklad_save_item" class="button button-primary">'.($edit?'Uložit změny':'Přidat položku').'</button>';
    if ($edit) echo ' <a href="'.admin_url('admin.php?page=skladovy-hospodar-mnozstvi').'" class="button">Zpět</a>';
    echo '</form><hr>';

    // --- SLOUČENÁ TABULKA SKLADU (suroviny); hotové výrobky jsou skryté ---
    ?>
    <form method="post">
    <?php wp_nonce_field('sh_increase_bulk'); ?>
    <h3>Položky skladu</h3>
    <table id="sh-items-table" class="widefat striped" style="max-width:1200px">
        <thead>
            <tr>
                <th style="width:40px"></th>
                <th data-sort="string">Název</th>
                <th data-sort="string">Jednotka</th>
                <th data-sort="number">Množství</th>
                <th data-sort="number">Min</th>
                <th data-sort="string">Poznámka</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item):
            // Skryj hotové výrobky (balíčky) v adminu (nejsou určeny k ruční editaci)
            if (strpos($item['id'], 'product_') === 0 && trim($item['note']) === 'Hotový výrobek (automaticky přidáno)') continue;
$low = (!empty($item['min']) && $item['min'] > 0 && $item['qty'] < $item['min']);            ?>
            <tr<?php if($low) echo ' style="background:#fff4e5"'; ?>>
                <td style="text-align:center"><input type="checkbox" name="bulk_ids[]" value="<?php echo esc_attr($item['id']); ?>" class="bulk-item-chk"></td>
                <td><?php echo esc_html($item['name']); ?></td>
                <td><?php echo esc_html($item['unit']); ?></td>
           <td><?php echo esc_html(isset($item['qty']) ? $item['qty'] : ''); ?></td>
<td><?php echo esc_html(isset($item['min']) ? $item['min'] : ''); ?></td>
                <td><?php echo esc_html($item['note']); ?></td>
                <td>
                    <a href="#" class="button" onclick="event.preventDefault();showIncreaseForm('<?php echo esc_js($item['id']); ?>','<?php echo esc_js($item['name']); ?>');">Navýšit</a>
                    <a href="<?php echo admin_url('admin.php?page=skladovy-hospodar-mnozstvi&edit_item='.$item['id']); ?>" class="button">Upravit</a>
                    <a href="<?php echo admin_url('admin.php?page=skladovy-hospodar-mnozstvi&delete_item='.$item['id']); ?>" class="button" onclick="return confirm('Opravdu smazat?')">Smazat</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div style="margin:12px 0 0 3px;display:flex;align-items:center;gap:8px;">
        <label for="bulk_qty_all"><b>Navýšit vybrané položky o:</b></label>
        <input type="number" name="bulk_qty_all" id="bulk_qty_all" step="0.01" min="0" style="width:110px;">
        <button type="submit" name="sh_increase_bulk" class="button button-primary">Hromadně navýšit</button>
    </div>
    </form>
    <div id="increase-modal" style="display:none;position:fixed;left:0;top:0;width:100vw;height:100vh;background:rgba(0,0,0,.2);z-index:9999;">
        <div style="max-width:400px;margin:70px auto;background:#fff;padding:28px 30px;border-radius:10px;box-shadow:0 6px 24px #aaa;">
            <form method="post" style="margin-bottom:0;">
                <h3 id="increase-title"></h3>
                <input type="hidden" name="increase_id" id="increase_id" value="">
                <label>Navýšit o:<br>
                    <input type="number" name="increase_qty" id="increase_qty" min="0.01" step="any" required style="width:100px;">
                </label>
                <br><br>
                <button type="submit" name="sh_increase_item" class="button button-primary">Potvrdit navýšení</button>
                <button type="button" class="button" onclick="document.getElementById('increase-modal').style.display='none';return false;">Zavřít</button>
                <?php wp_nonce_field('sh_increase_item'); ?>
            </form>
        </div>
    </div>
    <script>
    function showIncreaseForm(id, name) {
        document.getElementById('increase_id').value = id;
        document.getElementById('increase_qty').value = '';
        document.getElementById('increase-title').innerText = 'Navýšit stav: ' + name;
        document.getElementById('increase-modal').style.display = 'block';
        setTimeout(()=>document.getElementById('increase_qty').focus(),100);
    }
    // Řazení tabulky
    document.addEventListener('DOMContentLoaded',function(){
        const table = document.getElementById('sh-items-table');
        if (!table) return;
        const getCellVal = (tr, idx, type) => {
            let val = tr.children[idx].innerText || tr.children[idx].textContent;
            if (type === 'number') return parseFloat(val.replace(',','.')) || 0;
            return val.toLowerCase();
        };
        table.querySelectorAll('th[data-sort]').forEach(function(th, idx) {
            th.style.cursor = 'pointer';
            let asc = true;
            th.onclick = function() {
                const type = th.getAttribute('data-sort');
                const rows = Array.from(table.tBodies[0].rows);
                rows.sort(function(a, b) {
                    let va = getCellVal(a, idx, type);
                    let vb = getCellVal(b, idx, type);
                    if (va < vb) return asc ? -1 : 1;
                    if (va > vb) return asc ? 1 : -1;
                    return 0;
                });
                rows.forEach(row => table.tBodies[0].appendChild(row));
                asc = !asc;
                table.querySelectorAll('th').forEach(h=>h.classList.remove('sorted-asc','sorted-desc'));
                th.classList.add(asc ? 'sorted-asc' : 'sorted-desc');
            };
        });
    });
    </script>
    <style>
    #sh-items-table th.sorted-asc:after { content: " ▲"; }
    #sh-items-table th.sorted-desc:after { content: " ▼"; }
    #sh-items-table th { user-select:none; }
    #increase-modal { z-index: 99999; }
    </style>
    <?php
    echo '</div>';
}

// --- PRODUKTY A JEJICH SLOŽENÍ (NOVĚ: typ dávkování pro každou položku) ---
function skladovy_hospodar_products_page() {
    $items_all = (array)get_option('skladovy_hospodar_items', []);
    // Suroviny do výběru: odfiltruj hotové výrobky product_*
    $items = array_filter($items_all, function($it){
        return !(strpos($it['id'], 'product_') === 0 && trim($it['note']) === 'Hotový výrobek (automaticky přidáno)');
    });

    $product_map = (array)get_option('skladovy_hospodar_product_map', []);
    $notice = '';

    // Smazání složení produktu
    if (isset($_GET['delete_product']) && isset($product_map[$_GET['delete_product']])) {
        unset($product_map[$_GET['delete_product']]);
        update_option('skladovy_hospodar_product_map', $product_map);
        $notice = '<div class="updated"><p>Složení produktu smazáno.</p></div>';
    }

    $selected_pid = isset($_GET['product']) ? intval($_GET['product']) : 0;

    // Uložení složení produktu
    if (isset($_POST['sh_save_product_items']) && $selected_pid && check_admin_referer('sh_product_items')) {
        $ids = $_POST['rozpad_id'] ?? [];
        $qtys = $_POST['rozpad_qty'] ?? [];
        $modes = $_POST['rozpad_mode'] ?? [];
        $used = [];
        $map = [];
        foreach ($ids as $i => $item_id) {
            $item_id = sanitize_text_field($item_id);
            $qty = floatval($qtys[$i]);
            $mode = in_array(($modes[$i] ?? ''), ['per_kg','per_piece']) ? $modes[$i] : 'per_kg';
            if ($item_id && $qty > 0 && !isset($used[$item_id])) {
                $map[] = ['item_id' => $item_id, 'qty' => $qty, 'mode' => $mode];
                $used[$item_id] = true;
            }
        }
        $product_map[$selected_pid] = $map;
        update_option('skladovy_hospodar_product_map', $product_map);
        $notice = '<div class="updated"><p>Složení produktu uloženo.</p></div>';
    }

    echo '<div class="wrap"><h1>Produkty a jejich složení</h1>';
    echo $notice;

    // Výběr produktu z WooCommerce
    $products = [];
    $categories = [];
    if (class_exists('WC_Product_Query')) {
        // zahrneme také soukromé produkty
        $q = new WC_Product_Query(['limit'=>400, 'status'=>array('publish','private'), 'return'=>'ids']);
        foreach ($q->get_products() as $pid) {
            $p = wc_get_product($pid);
            if (!$p) continue;
            if ($p->is_type('simple')) {
                $products[$pid] = $p->get_name();
                $cats = wp_get_post_terms($pid, 'product_cat');
                $categories[$pid] = count($cats) ? $cats[0]->name : '(Bez kategorie)';
            } elseif ($p->is_type('variable')) {
                // Přidej každou variantu jako samostatný "produkt" pro rozpad
                $children = $p->get_children();
                foreach ($children as $child_id) {
                    $var = wc_get_product($child_id);
                    if (!$var) continue;
                    $attr = $var->get_attributes();
                    $variant_name = $p->get_name().' – '.implode(', ', array_map(function($a,$v){return wc_attribute_label($a).': '.$v;}, array_keys($attr), $attr));
                    $products[$child_id] = $variant_name;
                    $cats = wp_get_post_terms($child_id, 'product_cat');
                    if (empty($cats)) $cats = wp_get_post_terms($pid, 'product_cat');
                    $categories[$child_id] = count($cats) ? $cats[0]->name : '(Bez kategorie)';
                }
            }
        }
    }

    // Formulář pro úpravu složení
    echo '<form method="get" style="margin-bottom:20px;">';
    echo '<input type="hidden" name="page" value="skladovy-hospodar-produkty">';
    echo '<label>Vyber produkt: <select name="product" onchange="this.form.submit()"><option value="">-- Vyber --</option>';
    foreach ($products as $pid=>$name) {
        echo '<option value="'.$pid.'"'.($pid==$selected_pid?' selected':'').'>'.esc_html($name).'</option>';
    }
    echo '</select></label></form>';

    if ($selected_pid && isset($products[$selected_pid])) {
        $rozpad = $product_map[$selected_pid] ?? [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rozpad_id'])) {
            $rozpad = [];
            $ids = $_POST['rozpad_id'];
            $qtys = $_POST['rozpad_qty'];
            $modes = $_POST['rozpad_mode'] ?? [];
            $used = [];
            foreach ($ids as $i => $item_id) {
                $item_id = sanitize_text_field($item_id);
                $qty = floatval($qtys[$i]);
                $mode = in_array(($modes[$i] ?? ''), ['per_kg','per_piece']) ? $modes[$i] : 'per_kg';
                if ($item_id && $qty > 0 && !isset($used[$item_id])) {
                    $rozpad[] = ['item_id' => $item_id, 'qty' => $qty, 'mode' => $mode];
                    $used[$item_id] = true;
                }
            }
        }

        // v selectu skryj už použité položky (kromě právě editovaného řádku)
        $used_items = array_column($rozpad, 'item_id');
        echo '<h3>Rozpad produktu: <span style="color:#007cba">'.esc_html($products[$selected_pid]).'</span></h3>';
        echo '<form method="post" id="rozpad-form">';
        wp_nonce_field('sh_product_items');
        echo '<table class="form-table" id="rozpad-table"><tr><th>Položka</th><th>Množství</th><th>Typ dávkování</th><th></th></tr>';
        $count = max(count($rozpad), 1);
        for ($i=0; $i<$count; $i++) {
            $curr_used = $used_items;
            unset($curr_used[$i]); // povol vlastní hodnotu
            $item_id = $rozpad[$i]['item_id'] ?? '';
            $qty = $rozpad[$i]['qty'] ?? '';
            $mode = $rozpad[$i]['mode'] ?? 'per_kg';
            echo '<tr>
                <td><select name="rozpad_id[]"><option value="">-- Vyber --</option>';
            foreach ($items as $it) {
                $disabled = in_array($it['id'], $curr_used) ? ' disabled' : '';
                echo '<option value="'.$it['id'].'"'.($item_id==$it['id']?' selected':'').$disabled.'>'.esc_html($it['name']).' ('.$it['unit'].')</option>';
            }
            echo '</select></td>
                <td><input type="number" name="rozpad_qty[]" step="0.01" value="'.esc_attr($qty).'" required></td>
                <td>
                    <select name="rozpad_mode[]">
                        <option value="per_kg"'.($mode=='per_kg'?' selected':'').'>na 1 kg vstupu</option>
                        <option value="per_piece"'.($mode=='per_piece'?' selected':'').'>na 1 balíček</option>
                    </select>
                </td>
                <td><button type="button" class="button remove-row" title="Odebrat řádek">−</button></td>
            </tr>';
        }
        echo '</table>';
        echo '<button type="button" class="button" id="add-row">+ Přidat další položku</button> ';
        echo '<button type="submit" name="sh_save_product_items" class="button button-primary">Uložit složení</button>';
        echo '</form>';

        // Přehled aktuálního složení vybraného produktu pod formulářem
        echo '<hr><h4>Aktuální složení tohoto produktu:</h4>';
        if (count($rozpad) > 0) {
            echo '<table class="widefat striped" style="max-width:600px">';
            echo '<thead><tr><th>Položka</th><th>Množství</th><th>Typ dávkování</th></tr></thead><tbody>';
foreach ($rozpad as $part) {
    // Kontrola, že $part je pole a obsahuje nutné klíče
    if (!is_array($part) || !isset($part['item_id'])) {
        continue; // Přeskočíme neplatné hodnoty
    }

    $id = $part['item_id'];  // Bezpečný přístup k 'item_id'
    $mode = $part['mode'] ?? 'per_kg';  // Typ dávkování
    $quantity = $part['qty'] ?? 0;  // Množství
    
    // Výpis s bezpečnostní kontrolou
    echo '<tr>
        <td>' . (isset($items_all[$id]) ? esc_html($items_all[$id]['name']) : '<span style="color:red">neznámá položka</span>') . '</td>
        <td>' . esc_html($quantity) . (isset($items_all[$id]) ? ' ' . $items_all[$id]['unit'] : '') . '</td>
        <td>' . ($mode == 'per_kg' ? 'na 1 kg vstupu' : 'na 1 balíček') . '</td>
    </tr>';
}
            echo '</tbody></table>';
        } else {
            echo '<p><em>Žádné složení není nastaveno.</em></p>';
        }
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            let table = document.getElementById('rozpad-table');
            document.getElementById('add-row').onclick = function(e) {
                e.preventDefault();
                let tr = document.createElement('tr');
                tr.innerHTML = table.rows[1].innerHTML; // klonuj druhý řádek (první je hlavička)
                // Vynuluj hodnoty a povol selecty
                tr.querySelectorAll('select,input').forEach(el => {
                    if (el.tagName === 'SELECT') el.selectedIndex = 0;
                    if (el.tagName === 'INPUT') el.value = '';
                    if (el.tagName === 'SELECT') {
                        for (let i=0;i<el.options.length;i++) el.options[i].disabled = false;
                    }
                });
                table.appendChild(tr);
                bindRemove();
            };
            function bindRemove() {
                table.querySelectorAll('.remove-row').forEach(btn=>{
                    btn.onclick = function(){
                        if (table.rows.length > 2) btn.closest('tr').remove();
                    };
                });
            }
            bindRemove();
        });
        </script>
        <?php
    }

    // Výpis všech produktů s jejich složením pod sebou podle kategorie
    $all = [];
    foreach ($product_map as $pid => $slozeni) {
        if (!isset($products[$pid])) continue;
        $cat = $categories[$pid] ?? '';
        $all[$cat][$pid] = $slozeni;
    }
    ksort($all);
    echo '<hr><h3>Přehled všech produktů a jejich složení</h3>';
    foreach ($all as $cat => $prods) {
        echo '<h4 style="margin-top:30px">'.esc_html($cat).'</h4>';
        echo '<table class="widefat striped" style="margin-bottom:20px;max-width:900px">';
        echo '<thead><tr><th>Produkt</th><th>Položky (suroviny, složení)</th><th>Akce</th></tr></thead><tbody>';
        foreach ($prods as $pid => $slozeni) {
            echo '<tr><td style="font-weight:bold">'.esc_html($products[$pid]).'</td><td>';
            if (count($slozeni)) {
                echo '<ul style="margin:0 0 0 18px">';
                foreach ($slozeni as $part) {
                    $id = $part['item_id'];
                    $mode = $part['mode'] ?? 'per_kg';
                    echo '<li>'.(isset($items_all[$id]) ? esc_html($items_all[$id]['name']) : '<span style="color:red">neznámá položka</span>').' – '.esc_html($part['qty']).(isset($items_all[$id])?' '.$items_all[$id]['unit']:'').' <small>('.($mode=='per_kg'?'na 1 kg vstupu':'na 1 balíček').')</small></li>';
                }
                echo '</ul>';
            } else {
                echo '<em>Žádné složení</em>';
            }
            echo '</td><td>
                <a href="'.admin_url('admin.php?page=skladovy-hospodar-produkty&product='.$pid).'" class="button">Editovat</a>
                <a href="'.admin_url('admin.php?page=skladovy-hospodar-produkty&delete_product='.$pid).'" class="button" onclick="return confirm(\'Opravdu smazat složení produktu?\')">Smazat</a>
            </td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

// --- UPOZORNĚNÍ NA NÍZKÝ STAV SKLADU ---
add_action('admin_notices', function(){
    if (!current_user_can('manage_options')) return;
    $screen = get_current_screen();
    if (!$screen || strpos($screen->base, 'skladovy-hospodar') === false) return;
    $items = (array)get_option('skladovy_hospodar_items', []);
    $low = [];
    foreach ($items as $item) {
        // Ignoruj hotové výrobky (balíčky) v upozornění
        if (strpos($item['id'], 'product_') === 0 && trim($item['note']) === 'Hotový výrobek (automaticky přidáno)') continue;
        if (!empty($item['min']) && $item['qty'] < $item['min'])
            $low[] = $item;
    }
    if ($low) {
        echo '<div class="notice notice-warning"><p><b>Pozor, nízký stav těchto položek:</b><br>';
        foreach ($low as $i) {
            echo esc_html($i['name']).' ('.esc_html($i['qty']).' '.$i['unit'].' skladem, min. '.esc_html($i['min']).')<br>';
        }
        echo '</p></div>';
    }
});