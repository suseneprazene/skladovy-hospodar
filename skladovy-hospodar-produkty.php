<?php
/**
 * Admin page: Produkty (hromadný přehled)
 * Součást pluginu Skladový hospodář
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function() {
    add_submenu_page(
        'skladovy-hospodar-produkty-list',
        'Sklad',
        'Sklad',
        'manage_options',
        'skladovy-hospodar-produkty-list',
        'skladovy_hospodar_produkty_list_page'
    );
});

function skladovy_hospodar_get_categories() {
    $terms = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);
    $cats = [];
    if (!is_wp_error($terms)) {
        foreach ($terms as $t) {
            $cats[$t->term_id] = $t->name;
        }
    }
    return $cats;
}

function skladovy_hospodar_produkty_list_page() {
    if (!class_exists('WC_Product_Query')) {
        echo '<div class="notice notice-error"><p>WooCommerce není aktivní.</p></div>';
        return;
    }

    // --- Zpracování POST (hromadné uložení) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sh_save_bulk'])) {
    if (!empty($_POST['product_id']) && is_array($_POST['product_id'])) {
        foreach ($_POST['product_id'] as $i => $pid_raw) {
            $pid = intval($pid_raw);
            if (!$pid) continue;

            $p = wc_get_product($pid);
            if (!$p) continue;

            // Aktualizace ceny produktu
            if (isset($_POST['price'][$i])) {
                $price = floatval($_POST['price'][$i]);
                $p->set_regular_price($price);
            }

            // Aktualizace skladového stavu
            $manage_stock = !empty($_POST['manage_stock'][$i]);
            $p->set_manage_stock($manage_stock);

            if (isset($_POST['stock_status'][$i])) {
                $stock_status = sanitize_text_field($_POST['stock_status'][$i]);
                $p->set_stock_status($stock_status);
            }

            if ($manage_stock && isset($_POST['stock_qty'][$i])) {
                $stock_qty = intval($_POST['stock_qty'][$i]);
                $p->set_stock_quantity($stock_qty);
            }

            // ✅ PŘIDÁNÍ UKLÁDÁNÍ BADGE SLEVA
            $show_badge = !empty($_POST['show_sale_badge'][$i]) ? '1' : '0';
            update_post_meta($pid, '_skladovy_hospodar_show_sale_badge', $show_badge);

            $p->save();
        }

        echo '<div class="updated"><p>Produkty byly uloženy.</p></div>';
    }
}

    $cats = skladovy_hospodar_get_categories();
    $selected_cat = isset($_GET['filter_cat']) ? intval($_GET['filter_cat']) : 0;
    $selected_stock = isset($_GET['filter_stock']) ? sanitize_text_field($_GET['filter_stock']) : '';

    $args = [
        'limit' => 300,
        'status' => array('publish','private'),
        'return' => 'ids',
    ];
    if ($selected_cat > 0) {
        $args['tax_query'] = [
            [
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $selected_cat,
            ]
        ];
    }

    $ids = (new WC_Product_Query($args))->get_products();

    // Vyber hlavní produkty (simple nebo variable)
$main_products = [];
foreach ($ids as $id) {
    $p = wc_get_product($id);
    if ($p && ($p->is_type('simple') || $p->is_type('variable'))) {
        $main_products[] = $p;
    }
}

// Seřazení produktů podle kategorie:
usort($main_products, function ($a, $b) use ($cats) {
    $catA = implode(', ', wp_get_post_terms($a->get_id(), 'product_cat', ['fields' => 'names']));
    $catB = implode(', ', wp_get_post_terms($b->get_id(), 'product_cat', ['fields' => 'names']));
    return strcasecmp($catA, $catB);
});

    // Filtrování podle skladu (pokud zvoleno)
    if ($selected_stock) {
        foreach ($main_products as $k => $p) {
            $show = false;
            if ($p->is_type('variable')) {
                foreach ($p->get_children() as $vid) {
                    $v = wc_get_product($vid);
                    if ($v && $v->get_stock_status() === $selected_stock) { $show = true; break; }
                }
            } else {
                if ($p->get_stock_status() === $selected_stock) $show = true;
            }
            if (!$show) unset($main_products[$k]);
        }
    }

    // --- HTML výpis ---
    echo '<div class="wrap">';
    echo '<h1>Produkty (hromadný přehled)</h1>';

    // Filtr formulář
    echo '<form method="get" style="margin-bottom:20px;display:flex;gap:20px;align-items:flex-end;">';
    echo '<input type="hidden" name="page" value="skladovy-hospodar-produkty-list">';
    echo '<label>Kategorie:<br><select name="filter_cat" style="min-width:180px;"><option value="0">– Vše –</option>';
    foreach ($cats as $cid => $cname) {
        echo '<option value="'.esc_attr($cid).'"'.($selected_cat==$cid?' selected':'').'>'.esc_html($cname).'</option>';
    }
    echo '</select></label>';
    echo '<label>Sklad:<br><select name="filter_stock"><option value="">– Vše –</option>
        <option value="instock"'.($selected_stock=='instock'?' selected':'').'>Skladem</option>
        <option value="outofstock"'.($selected_stock=='outofstock'?' selected':'').'>Není skladem</option>
        </select></label>';
    echo '<button type="submit" class="button">Filtrovat</button>';
    echo '</form>';

    // Table + form for bulk save
    echo '<form method="post" style="overflow-x:auto">';
    echo '<table class="widefat striped sklad-tabulka-produkty" style="min-width:1100px">';
    echo '<thead>
    <tr>
        <th></th>
        <th data-sort="number">ID</th>
        <th data-sort="string">Typ</th>
        <th>SKU</th>
        <th data-sort="string">Název produktu</th>
        <th data-sort="string">Kategorie</th>
        <th>Cena</th>
        <th>Hlídání skladu</th>
        <th>Stav skladu</th>
        <th data-sort="number">Množství</th>
        <th style="text-align:center;">Badge sleva</th>
    </tr>
    </thead><tbody>';

    $row_index = 0;
    foreach ($main_products as $p) {
        $is_variable = $p->is_type('variable');
        $manage_stock = $p->get_manage_stock();
        $stock_status = $p->get_stock_status();
        $qty = $manage_stock ? (is_numeric($p->get_stock_quantity()) ? $p->get_stock_quantity() : 0) : '';
        $row_style = '';
        if ($manage_stock) {
            $qty_num = is_numeric($qty) ? $qty : 0;
            if ($qty_num >= 30) $row_style = 'background:#e9ffeb;';
            elseif ($qty_num < 10) $row_style = 'background:#fff5e0;';
        }

        // category names
        $cats_names = [];
        $cats_ids = wp_get_post_terms($p->get_id(), 'product_cat', ['fields'=>'ids']);
        foreach ($cats_ids as $cid) {
            if (isset($cats[$cid])) $cats_names[] = $cats[$cid];
        }
        $expand_btn = '';
        $expand_classes = '';
        $variant_ids = $is_variable ? $p->get_children() : [];
        if ($is_variable && count($variant_ids) > 0) {
            $expand_btn = '<button type="button" class="expand-variants button" data-row="'.esc_attr($row_index).'" aria-expanded="false" style="font-weight:bold;">+</button>';
            $expand_classes = ' has-variants';
        }
        $parent_row_index = $row_index;

        // Main product row
        echo '<tr class="main-product-row'.esc_attr($expand_classes).'" style="'.esc_attr($row_style).'">';
        echo '<td style="text-align:center">'.$expand_btn.'</td>';
        echo '<td><input type="hidden" name="product_id[]" value="'.esc_attr($p->get_id()).'">'.esc_html($p->get_id()).'</td>';
        echo '<td>'.($is_variable ? 'Produkt s variantami ('.count($variant_ids).')' : 'Jednoduchý produkt').'</td>';
        echo '<td><input type="text" name="sku[]" value="'.esc_attr($p->get_sku()).'" style="width:110px;" readonly></td>';
        echo '<td>'.esc_html($p->get_name()).'</td>';
        echo '<td>'.esc_html(implode(', ', $cats_names)).'</td>';
        echo '<td><input type="number" name="price[]" value="'.esc_attr($p->get_regular_price()).'" style="width:80px;"></td>';
        echo '<td style="text-align:center;"><input type="checkbox" name="manage_stock['.$row_index.']" '.($manage_stock?'checked':'').'></td>';
echo '<td>
        <select name="stock_status[]" class="stock-status-select '.($manage_stock ? 'with-stock-management' : '').'">
            <option value="instock" '.($stock_status=='instock'?'selected':'').'>Skladem</option>
            <option value="outofstock" '.($stock_status=='outofstock'?'selected':'').'>Není skladem</option>
        </select>
    </td>';
        echo '<td><input type="number" name="stock_qty['.$row_index.']" value="'.esc_attr($qty).'" style="width:60px;"></td>';
        $show_badge = get_post_meta($p->get_id(), '_skladovy_hospodar_show_sale_badge', true);
        echo '<td style="text-align:center;"><input type="checkbox" name="show_sale_badge['.$row_index.']" value="1" '.($show_badge !== '0' ? 'checked' : '').'></td>';
        echo '</tr>';
        $row_index++;

        // Variant rows (hidden by default)
        if ($is_variable && count($variant_ids) > 0) {
            foreach ($variant_ids as $vid) {
                $v = wc_get_product($vid);
                if (!$v) continue;
                $manage_stock_v = $v->get_manage_stock();
                $stock_status_v = $v->get_stock_status();
                $qty_v = $manage_stock_v ? (is_numeric($v->get_stock_quantity()) ? $v->get_stock_quantity() : 0) : '';
                $row_style_v = '';
                if ($manage_stock_v) {
                    $qty_num_v = is_numeric($qty_v) ? $qty_v : 0;
                    if ($qty_num_v >= 30) $row_style_v = 'background:#e9ffeb;';
                    elseif ($qty_num_v < 10) $row_style_v = 'background:#fff5e0;';
                }
                $attr = $v->get_attributes();
                $attrs = [];
                foreach ($attr as $k=>$val) $attrs[] = wc_attribute_label($k).': '.$val;

                echo '<tr class="variant-row variant-row-'.esc_attr($parent_row_index).'" style="display:none;'.$row_style_v.'">';
                echo '<td style="text-align:right;color:#888;padding-right:0;">↳</td>';
                echo '<td><input type="hidden" name="product_id[]" value="'.esc_attr($v->get_id()).'">'.esc_html($v->get_id()).'</td>';
                echo '<td>Varianta</td>';
                echo '<td><input type="text" name="sku[]" value="'.esc_attr($v->get_sku()).'" style="width:110px;" readonly></td>';
                echo '<td>'.esc_html($v->get_name()).'<br><small style="color:#888;">'.esc_html(implode(', ', $attrs)).'</small></td>';
                echo '<td></td>';
                echo '<td><input type="number" name="price[]" value="'.esc_attr($v->get_regular_price()).'" style="width:80px;"></td>';
                echo '<td style="text-align:center;"><input type="checkbox" name="manage_stock['.$row_index.']" '.($manage_stock_v?'checked':'').'></td>';
echo '<td>
        <select name="stock_status[]" class="stock-status-select '.($manage_stock_v ? 'with-stock-management' : '').'">
            <option value="instock" '.($stock_status_v=='instock'?'selected':'').'>Skladem</option>
            <option value="outofstock" '.($stock_status_v=='outofstock'?'selected':'').'>Není skladem</option>
        </select>
    </td>';
                echo '<td><input type="number" name="stock_qty['.$row_index.']" value="'.esc_attr($qty_v).'" style="width:60px;"></td>';
                $show_badge_v = get_post_meta($v->get_id(), '_skladovy_hospodar_show_sale_badge', true);
                echo '<td style="text-align:center;"><input type="checkbox" name="show_sale_badge['.$row_index.']" value="1" '.($show_badge_v !== '0' ? 'checked' : '').'></td>';
                echo '</tr>';
                $row_index++;
            }
        }
    }

    echo '</tbody></table>';
    submit_button('Uložit změny', 'primary', 'sh_save_bulk');
    echo '</form>';

    // JS for expanding variants and simple table sorting
    ?>
 <script>
document.addEventListener('DOMContentLoaded',function(){
    // Funkce pro rozevírání variant produktů
    document.querySelectorAll('.expand-variants').forEach(function(btn){
        btn.addEventListener('click', function(){
            var row = this.getAttribute('data-row');
            var expanded = this.getAttribute('aria-expanded') === "true";
            var rows = document.querySelectorAll('.variant-row-' + row);
            for(var i = 0; i < rows.length; i++){
                rows[i].style.display = expanded ? 'none' : ''; // Rozevření/stažení řádků
            }
            this.textContent = expanded ? '+' : '−'; // Změna symbolu
            this.setAttribute('aria-expanded', expanded ? "false" : "true");
        });
    });

    // Jednoduché třídění tabulky pro data-sort
    const table = document.querySelector('.sklad-tabulka-produkty');
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

    // Aktivace/deaktivace pole "Stav skladu" dle checkboxu "Hlídání skladu"
    document.querySelectorAll('input[name^="manage_stock"]').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            var parentRow = this.closest('tr');
            var stockStatusField = parentRow.querySelector('select[name^="stock_status"]');
            if (this.checked) {
                stockStatusField.setAttribute('readonly', 'true'); // Pole je jen pro čtení
                stockStatusField.classList.add('readonly');
            } else {
                stockStatusField.removeAttribute('readonly'); // Pole je editovatelné
                stockStatusField.classList.remove('readonly');
            }
        });
    });
});
document.addEventListener('DOMContentLoaded', function() {
    // Najdi všechny select boxy pro stav skladu hlavního produktu
    document.querySelectorAll('.main-product-row .stock-status-select').forEach(function(stockSelect) {
        stockSelect.addEventListener('change', function() {
            // Najdi, zda má hlavní produkt varianty
            var parentRow = this.closest('.main-product-row');
            var rowIndex = Array.from(document.querySelectorAll('.main-product-row')).indexOf(parentRow);
            var variantRows = document.querySelectorAll('.variant-row-' + rowIndex);

            // Vezmi nově vybranou hodnotu hlavního produktu
            var stockStatus = this.value;

            // Aktualizuj všechny varianty stejného produktu
            variantRows.forEach(function(variantRow) {
                var variantStockSelect = variantRow.querySelector('.stock-status-select');
                variantStockSelect.value = stockStatus;
            });
        });
    });
});
</script>
    <style>
    .variant-row td { padding-left: 24px !important; background: #f7f8fa !important; font-size: 14px; }
    .main-product-row.has-variants > td:first-child { text-align: center; }
    .expand-variants { font-size: 18px; padding: 1px 11px; line-height: 1; background: #f2f2fa; border: 1px solid #aaa; border-radius: 6px; cursor: pointer; }
    .sklad-tabulka-produkty th.sorted-asc:after { content: " ▲"; }
    .sklad-tabulka-produkty th.sorted-desc:after { content: " ▼"; }
	.stock-status-select.with-stock-management {
    background-color: #e9ecef; /* Šedé pozadí */
    opacity: 0.7; /* Lehce průhledné pro vizuální odlišení */
}
    </style>
    <?php

    echo '</div>'; // .wrap
}