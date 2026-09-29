<?php
/**
 * Product variables: the Size / Colour / Unit lists, and what each product uses.
 *
 * Lives in config/ rather than the page itself, and is required by the pages that
 * need it, so nothing unauthenticated can run the DDL. A migrate_*.php in the
 * project root is the mistake migrate_staff_module.php already made.
 *
 * Shape: a variable is a named list (Size -> S, M, L). A product picks at most
 * one value per variable, and picking nothing is fine - every mapping is
 * optional, because a shop must still be able to save "Biscuit 200g" without
 * inventing a size for it.
 *
 * Unit is deliberately special. products.unit has been a column since before
 * this table existed and the POS, invoice and reports all read it, so rather
 * than migrate that column the Unit variable is treated as its editor: its
 * values feed the existing dropdown and keep writing the same column. A new
 * variable needs no such special case and goes through product_variable_map.
 */

/** Create the three tables if they are not there yet, and seed the first list. */
function productVariablesEnsureTables($db)
{
    appSafeDdl($db, "CREATE TABLE IF NOT EXISTS product_variables (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(60) NOT NULL,
        -- Stable key, used by the form field names and by the Unit special case.
        -- Not unique across shops: two owners may each have their own 'material'.
        slug VARCHAR(60) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        owner_id INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_owner (owner_id, sort_order)
    ) ENGINE=InnoDB");

    appSafeDdl($db, "CREATE TABLE IF NOT EXISTS product_variable_values (
        id INT AUTO_INCREMENT PRIMARY KEY,
        variable_id INT NOT NULL,
        value VARCHAR(60) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        -- One value cannot be added to the same variable twice; the shop would
        -- otherwise end up with two identical entries in the same dropdown.
        UNIQUE KEY uk_var_value (variable_id, value),
        INDEX idx_variable (variable_id, sort_order)
    ) ENGINE=InnoDB");

    // No foreign keys: this project creates its tables without them, and a
    // cascade here would silently delete a shop's size list.
    appSafeDdl($db, "CREATE TABLE IF NOT EXISTS product_variable_map (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        variable_id INT NOT NULL,
        value_id INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        -- One value per variable per product. The form sends a single select, so
        -- this holds for every product created through the UI; the key makes a
        -- second write impossible rather than quietly overwriting.
        UNIQUE KEY uk_product_var (product_id, variable_id),
        INDEX idx_product (product_id)
    ) ENGINE=InnoDB");

    productVariablesSeed($db);
}

/**
 * The three lists every shop starts with.
 *
 * Seeded rather than left empty because an empty Variables page cannot be
 * understood at a glance, and because the Unit values are what the Add Product
 * form will offer. The Unit list matches the options products.php used to
 * hardcode, so nothing that worked before stops working.
 */
function productVariablesSeed($db)
{
    $defaults = [
        ['name' => 'Size',     'slug' => 'size',     'values' => ['Free', 'S', 'M', 'L', 'XL', 'XXL']],
        ['name' => 'Colour',   'slug' => 'colour',   'values' => ['Black', 'White', 'Blue', 'Red', 'Green', 'Grey', 'Beige', 'Brown']],
        ['name' => 'Unit',     'slug' => 'unit',     'values' => ['piece', 'kg', 'gram', 'liter', 'pack', 'box', 'dozen']],
    ];

    $stmt = $db->prepare("SELECT id FROM product_variables WHERE slug = ? LIMIT 1");
    $insVar = $db->prepare("INSERT INTO product_variables (name, slug, sort_order, status) VALUES (?,?,?,'active')");
    $insVal = $db->prepare("INSERT IGNORE INTO product_variable_values (variable_id, value, sort_order) VALUES (?,?,?)");

    foreach ($defaults as $i => $d) {
        $stmt->execute([$d['slug']]);
        $id = $stmt->fetchColumn();
        // closeCursor() before touching any other statement.
        //
        // fetchColumn() reads one value and stops. It does not close the result
        // set. On a host where pdo_mysql hands back unbuffered results the
        // connection still has that cursor open, and the next execute() on a
        // different statement is refused with
        //     SQLSTATE[HY000] General error: 2014
        //     Cannot execute queries while other unbuffered queries are active
        // which took the Variable Name page and the Products page down as a
        // blank HTTP 500. It never appeared on XAMPP, where results arrive
        // buffered, which is why it looked fine for months.
        $stmt->closeCursor();

        if (!$id) {
            $insVar->execute([$d['name'], $d['slug'], $i]);
            $id = (int)$db->lastInsertId();
            $insVar->closeCursor();
        }
        foreach ($d['values'] as $j => $v) {
            $insVal->execute([$id, $v, $j]);
            $insVal->closeCursor();
        }
    }
}

/** Turn a name into a usable slug. Bengali and other non-ASCII names are kept. */
function productVariableSlug($name)
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    // preg_replace strips non-ASCII entirely, so an all-Bengali name arrives
    // empty. Fall back to a readable transliteration-free key rather than ''
    return $slug !== '' ? $slug : 'var-' . substr(md5($name), 0, 6);
}

/**
 * Every variable with its values attached, in display order.
 * @return array<int, array{id:int,name:string,slug:string,values:array}>
 */
function getProductVariables($db, $includeInactive = false)
{
    $sql = "SELECT id, name, slug, sort_order, status FROM product_variables"
        . ($includeInactive ? "" : " WHERE status = 'active'")
        . " ORDER BY sort_order ASC, name ASC";
    $vars = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if (!$vars) { return []; }

    $ids = array_column($vars, 'id');
    $in = implode(',', array_map('intval', $ids));
    $vals = $db->query("SELECT id, variable_id, value FROM product_variable_values
                        WHERE variable_id IN ($in) ORDER BY sort_order ASC, value ASC")
        ->fetchAll(PDO::FETCH_ASSOC);

    $byVar = [];
    foreach ($vals as $v) { $byVar[$v['variable_id']][] = $v; }

    foreach ($vars as &$v) {
        $v['id'] = (int)$v['id'];
        $v['values'] = $byVar[$v['id']] ?? [];
    }
    return $vars;
}

/** The values of one variable, for a dropdown. */
function getVariableValues($db, $variableId)
{
    $stmt = $db->prepare("SELECT id, value FROM product_variable_values
                          WHERE variable_id = ? ORDER BY sort_order ASC, value ASC");
    $stmt->execute([(int)$variableId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * What one product has chosen, as variable_id => value_id.
 * @return array<int, int>
 */
function getProductVariableMap($db, $productId)
{
    $stmt = $db->prepare("SELECT variable_id, value_id FROM product_variable_map
                          WHERE product_id = ?");
    $stmt->execute([(int)$productId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['variable_id']] = (int)$r['value_id'];
    }
    return $out;
}

/**
 * Replace a product's choices with whatever the form sent.
 *
 * Written as delete-then-insert rather than a diff: the form sends every
 * variable every time, and a variable the shop cleared has to lose its old
 * value. A diff would keep it.
 *
 * The Unit variable is skipped because products.unit already holds it.
 *
 * @param array $selections variable_id => value_id, empty value_id means "not set"
 */
function saveProductVariableMap($db, $productId, array $selections)
{
    $productId = (int)$productId;
    $db->prepare("DELETE FROM product_variable_map WHERE product_id = ?")->execute([$productId]);

    if (!$selections) { return 0; }

    // Only accept values that really belong to the variable they were sent under.
    // Otherwise a crafted form could attach a Size to the Unit variable, or a
    // value id from another shop entirely.
    $check = $db->prepare("SELECT id FROM product_variable_values WHERE id = ? AND variable_id = ?");
    $ins = $db->prepare("INSERT IGNORE INTO product_variable_map (product_id, variable_id, value_id)
                          VALUES (?,?,?)");

    $unitSlug = $db->query("SELECT id FROM product_variables WHERE slug = 'unit' LIMIT 1")->fetchColumn();
    $saved = 0;
    foreach ($selections as $varId => $valId) {
        $varId = (int)$varId;
        $valId = (int)$valId;
        if ($valId <= 0) { continue; }          // left blank - optional, not an error
        if ($unitSlug && $varId === (int)$unitSlug) { continue; }  // lives in products.unit
        $check->execute([$valId, $varId]);
        if ($check->fetchColumn()) {
            $ins->execute([$productId, $varId, $valId]);
            $saved++;
        }
    }
    return $saved;
}

/** The Unit variable's values, for the Add Product dropdown. Empty if unset. */
function getUnitVariableValues($db)
{
    $row = $db->query("SELECT v.id FROM product_variables v
                       WHERE v.slug = 'unit' AND v.status = 'active' LIMIT 1")->fetchColumn();
    return $row ? getVariableValues($db, $row) : [];
}
