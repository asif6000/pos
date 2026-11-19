<?php
/**
 * Splitting one till basket across the stores that own the goods.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Every sale door used to assume one thing: the cashier's session store is the
 * store the goods came from. That holds while only a shop's own staff rings up a
 * sale. It stops holding the moment the platform Super Admin sells from any
 * store, because the person at the till then has no store of their own, and a
 * basket can hold a shirt from Store A and a pant from Store B. The money, the
 * stock and the reports all have to land on the store that owns the goods, not
 * on whoever typed it in.
 *
 * So this file does the part that must not be guessed: it decides which sale rows
 * a basket becomes, whose owner_id each row carries, and what each row's money
 * is. It is deliberately pure - the caller hands it the cart plus the product and
 * store rows it has already read, and it hands back either groups or one named
 * refusal. Nothing here touches the database, which is what lets the ownership
 * rules be tested against fabricated rows instead of against real sales.
 *
 * THE RULES
 * ---------
 * - A product belongs to a tenant through products.owner_id. There is no
 *   store column on products; the per-store quantity is the store_stocks row.
 *   So "which store" is answered by the store the line was picked from, and the
 *   store must belong to the same tenant as the product. Never the other way
 *   round: a line is refused rather than quietly re-attributed.
 * - Only the platform Super Admin (role admin who owns no shop of their own) may
 *   pick a store that is not theirs. Anyone else is pinned to their own tenant,
 *   and a cashier bound to one store is pinned to that store. That is what keeps
 *   Store A from touching Store B's stock, customers or sales.
 * - A sale is never written under the Super Admin's own account as a tenant:
 *   owner_id always comes from the store's owner, and a store with no owner is
 *   refused. A Super Admin with no store of their own therefore cannot create a
 *   sale "in their name" at all - there is no such row to create.
 * - The money is recomputed here from the lines, never copied from the browser.
 *   A discount or VAT is split by each store's share of the basket, with the
 *   rounding difference carried by the largest group so the store rows add back
 *   up to the basket total exactly.
 *
 * @see admin/api/process-sale.php  the only door that uses this today
 */

/**
 * The platform Super Admin: an admin account that owns no shop.
 *
 * The decision lives in config/db.php (isSuperAdminUser) so the tills, the report
 * pages and this file cannot drift apart - the owner column alone is not enough to
 * tell the platform account from a shop's own keeper, and guessing wrong here opens
 * every other shop's till. That helper reads stores, which is the one database read
 * this deliberately pure file allows itself, through the account in front of it.
 */
function saleIsSuperAdmin(array $currentUser)
{
    return isSuperAdminUser($currentUser);
}

/**
 * Check one basket against the products and stores it names, and group it by store.
 *
 * @param array $currentUser  getCurrentUser(): id, role, store_id, owner_id, raw_owner_id
 * @param array $lines        the posted cart items: product_id, quantity, unit_price,
 *                            product_name, and store_id when the till is allowed to choose
 * @param array $products     product id => row with owner_id, name, sell_price, status
 * @param array $stores       store id => row with id, name, owner_id, status
 * @param int   $defaultStoreId  the store a line falls back to when it names none
 * @return array{ok:bool, error:string, groups:array}  groups are ordered by store id
 */
function saleValidateCart(array $currentUser, array $lines, array $products, array $stores, $defaultStoreId = 0)
{
    $isSuper = saleIsSuperAdmin($currentUser);
    // NOT simply $currentUser['owner_id']. getCurrentUser() fills owner_id with the
    // user's OWN id when the database column is NULL, which is right for a shop
    // owner (their tenant key is their own id) but means the platform Super Admin
    // arrives holding an owner_id that looks like a shop and is not one. Treating it
    // as a shop here would file an ownerless store's sale under the Super Admin -
    // exactly the "sale in their own name" that requirement 6 forbids.
    $myOwnerId = $isSuper ? 0 : (isset($currentUser['owner_id']) ? (int)$currentUser['owner_id'] : 0);
    $myStoreId = isset($currentUser['store_id']) ? (int)$currentUser['store_id'] : 0;

    $groups = [];

    foreach (array_values($lines) as $i => $line) {
        if (!is_array($line)) {
            return saleCartRefused('Cart line pora jay ni.');
        }

        $productId = (int)($line['product_id'] ?? 0);
        $qty       = (float)($line['quantity'] ?? 0);
        if ($productId <= 0) {
            return saleCartRefused('Ekta item-e product-er number nai.');
        }
        if ($qty <= 0) {
            return saleCartRefused('Ekta item-e quantity 0 er niche.');
        }
        // sale_items.quantity is INT and stock is counted in whole units, so a
        // fractional line would be rounded by MySQL into a different sale than the
        // one the till showed the customer. Refused rather than silently rounded.
        if (abs($qty - round($qty)) > 0.0001) {
            return saleCartRefused('Quantity pura songkhya hote hobe (' . $qty . ').');
        }
        $qty = (int)round($qty);

        $storeId = isset($line['store_id']) && (int)$line['store_id'] > 0
            ? (int)$line['store_id']
            : $defaultStoreId;

        // A Super Admin has no store of their own to fall back to, so on that path
        // the store has to be chosen - which is the point of picking per line.
        if ($storeId <= 0) {
            return saleCartRefused('Kon store theke bikri hocche ta line-e deya jay ni.');
        }

        if (!isset($products[$productId])) {
            return saleCartRefused('Product-ta (#' . $productId . ') ei system-e nai.');
        }
        if (!isset($stores[$storeId])) {
            return saleCartRefused('Store-ta (#' . $storeId . ') nai, othoba delete hoye geche.');
        }

        $product = $products[$productId];
        $store   = $stores[$storeId];

        // A store the till had to be told about has to be a live one. The caller's
        // own default store is exempt, because that is the store the session was
        // built on and the POS page is already listing its stock: refusing it here
        // would stop a shop that sells from a store later marked inactive, which is
        // what every till in this installation does today.
        $isOwnDefault = (int)$defaultStoreId > 0 && $storeId === (int)$defaultStoreId;
        if ($store['status'] !== 'active' && !$isOwnDefault) {
            return saleCartRefused('Store ' . $store['name'] . ' active noi.');
        }

        $storeOwnerId = isset($store['owner_id']) ? (int)$store['owner_id'] : 0;
        $productOwnerId = isset($product['owner_id']) ? (int)$product['owner_id'] : 0;

        // Whose shop the sale is filed under. The store's owner decides, because the
        // goods came out of that store's stock and that is whose money it is. A store
        // that belongs to nobody has only the caller's own shop behind it - and when
        // the caller is the platform Super Admin, who owns no shop, that leaves
        // nobody at all. Which is the one sale this module must never write: a bill
        // in the Super Admin's own name, from a store of their own that does not
        // exist (requirement 6).
        $saleOwnerId = $storeOwnerId > 0 ? $storeOwnerId : $myOwnerId;
        if ($saleOwnerId <= 0) {
            return saleCartRefused('Store ' . $store['name']
                . ' er kono owner nai, ar apnar nijer store-o nai - tai ei sale karo name lekha jabe na.');
        }

        // The goods and the shelf have to belong to the same shop. Never quietly
        // re-attributed to one of the two: a line that does not add up is refused,
        // because the stock it would deduct is somebody else's count.
        if ($productOwnerId > 0 && $storeOwnerId > 0 && $productOwnerId !== $storeOwnerId) {
            return saleCartRefused($product['name'] . ' ei store-r mal na (store: ' . $store['name'] . ').');
        }

        // Requirement 8: the tenant filter is what stops Store A reaching Store B's
        // stock, customers and sales. The Super Admin is the one account exempt from
        // it - and exempt from that only, never from the checks above, which still
        // have to add up.
        if (!$isSuper) {
            if ($storeOwnerId > 0 && $storeOwnerId !== $myOwnerId) {
                return saleCartRefused('Apnar ei store-e bikri korar odhikar nai.');
            }
            if ($productOwnerId > 0 && $productOwnerId !== $myOwnerId) {
                return saleCartRefused($product['name'] . ' apnar shop-er mal na.');
            }
            if ($myStoreId > 0 && $storeId !== $myStoreId) {
                return saleCartRefused('Apni nijer store theke-i bikri korte parben.');
            }
        }

        $unitPrice = round((float)($line['unit_price'] ?? $product['sell_price']), 2);
        if ($unitPrice < 0) {
            return saleCartRefused('Ekta item-er dam bhul.');
        }

        // Group by store, merging duplicate lines for the same product in the same store
        if (!isset($groups[$storeId])) {
            $groups[$storeId] = [
                'store_id'   => $storeId,
                'store_name' => $store['name'],
                'owner_id'   => $saleOwnerId,
                'lines'      => [],
            ];
        }

        if (isset($groups[$storeId]['lines'][$productId])) {
            $groups[$storeId]['lines'][$productId]['quantity'] += $qty;
        } else {
            $groups[$storeId]['lines'][$productId] = [
                'product_id'   => $productId,
                'product_name' => (string)($line['product_name'] ?? $product['name']),
                'quantity'     => $qty,
                'unit_price'   => $unitPrice,
            ];
        }
    }

    // Reprice the merged lines from the quantity now that it is final, and hand
    // back a plain list ordered by store so the invoices come out in a stable
    // order whatever the browser posted.
    $out = [];
    foreach ($groups as $storeId => $group) {
        $lines = [];
        foreach ($group['lines'] as $line) {
            $line['total_price'] = round($line['unit_price'] * $line['quantity'], 2);
            $lines[] = $line;
        }
        $group['lines']      = $lines;
        $group['subtotal']   = round(array_sum(array_column($lines, 'total_price')), 2);
        $out[$storeId]       = $group;
    }
    ksort($out);

    if (empty($out)) {
        return saleCartRefused('Cart khali.');
    }

    return ['ok' => true, 'error' => '', 'groups' => array_values($out)];
}

/** Khali refusal shape, so every caller returns the same thing. */
function saleCartRefused($message)
{
    return ['ok' => false, 'error' => $message, 'groups' => []];
}

/**
 * Turn the groups into one sale row's worth of money each.
 *
 * The discount is chosen once for the whole basket - that is what the cashier types
 * at a till - so it is shared between the stores by each store's share of the
 * subtotal. Sharing by rounding each share independently would leave the store rows
 * a poisha short of the basket total, so the last (or largest) group carries the
 * difference instead. VAT is not shared: each shop charges its own rate on its own
 * goods, which is the number its Settings page holds and its receipt prints.
 *
 * @param array $groups        from saleValidateCart()
 * @param string $discountType 'percent' or 'fixed'
 * @param float  $discountValue
 * @param float  $vatPercent   the caller's own shop rate, used for any group that
 *                             does not carry a vat_percent of its own
 * @param float  $postedTotal  what the till says the customer owes, checked against this
 * @return array{ok:bool, error:string, subtotal:float, discount:float, vat:float,
 *               total:float, groups:array}  each group gains discount/vat/total/
 *               discount_percent and 'index' for the payment map
 */
function saleSplitBasket(array $groups, $discountType, $discountValue, $vatPercent, $postedTotal)
{
    $subtotal = round(array_sum(array_column($groups, 'subtotal')), 2);
    if ($subtotal <= 0) {
        return ['ok' => false, 'error' => 'Basket-er total 0.', 'groups' => []];
    }

    $discount = strtolower((string)$discountType) === 'percent'
        ? $subtotal * ((float)$discountValue / 100)
        : (float)$discountValue;
    if ($discount < 0) {
        $discount = 0;
    }
    if ($discount > $subtotal) {
        $discount = $subtotal;
    }
    $discount = round($discount, 2);

    $shares = [];
    foreach ($groups as $g) {
        $shares[] = $g['subtotal'] / $subtotal;
    }

    $discounts = saleShareMoney($discount, $shares);

    // VAT is charged per shop, on that shop's own discounted share. Two shops in one
    // basket can have different rates (each sets its own in Settings), and the sale
    // row belongs to the shop whose goods were sold, so it is that shop's rate that
    // has to be on it. A basket of one shop lands on exactly the number this code
    // produced before, because there is only one share and one rate.
    $vatTotal   = 0.0;
    $grandTotal = 0.0;
    foreach ($groups as $i => $g) {
        $rate               = isset($g['vat_percent']) ? (float)$g['vat_percent'] : (float)$vatPercent;
        $g['discount']      = $discounts[$i];
        $g['discount_percent'] = $g['subtotal'] > 0
            ? round($discounts[$i] / $g['subtotal'] * 100, 2)
            : 0.0;
        $afterDiscount      = round($g['subtotal'] - $discounts[$i], 2);
        $g['vat']           = round($afterDiscount * ($rate / 100), 2);
        $g['vat_percent']   = $rate;
        $g['total']         = round($afterDiscount + $g['vat'], 2);
        $g['index']         = $i;
        $groups[$i]         = $g;
        $vatTotal           = round($vatTotal + $g['vat'], 2);
        $grandTotal         = round($grandTotal + $g['total'], 2);
    }

    // The till's own arithmetic is the contract with the customer: if the browser
    // says a different number, something is stale or tampered with, and taking
    // money from a customer against a figure the server cannot reproduce is not a
    // rounding argument worth winning. Half a poisha of float drift is allowed.
    if ($postedTotal > 0 && abs((float)$postedTotal - $grandTotal) > 0.05) {
        return [
            'ok'     => false,
            'error'  => 'Total mel khacche (screen: ' . number_format((float)$postedTotal, 2)
                      . ', server: ' . number_format($grandTotal, 2) . '). Ekbar cart refresh korun.',
            'groups' => [],
        ];
    }

    return [
        'ok'       => true,
        'error'    => '',
        'subtotal' => $subtotal,
        'discount' => $discount,
        'vat'      => $vatTotal,
        'total'    => $grandTotal,
        'groups'   => $groups,
    ];
}

/**
 * Split $amount across shares so the parts add back up to the whole.
 *
 * Rounding each part on its own loses or invents a poisha, and the shop's own
 * daily total is the sum of the parts - so the difference is carried by the
 * largest share, which is the line least changed by it.
 */
function saleShareMoney($amount, array $shares)
{
    $count = count($shares);
    if ($count === 0) {
        return [];
    }
    if ($count === 1) {
        return [round((float)$amount, 2)];
    }

    $parts = [];
    $used = 0.0;
    $biggest = 0;
    $biggestAt = 0;
    foreach ($shares as $i => $share) {
        $parts[$i] = round((float)$amount * (float)$share, 2);
        $used += $parts[$i];
        if ($parts[$i] >= $biggest) {
            $biggest = $parts[$i];
            $biggestAt = $i;
        }
    }

    $parts[$biggestAt] = round($parts[$biggestAt] + ((float)$amount - round($used, 2)), 2);

    return $parts;
}

/**
 * Decide what each store's sale was paid with.
 *
 * One store is the old case and behaves exactly as it always did. Several stores
 * is a different situation on the counter: the cash physically goes into
 * different tills, so the tender has to be said per store rather than guessed by
 * splitting one number across them. A missing store payment is refused with the
 * store named, which is a useable message for whoever is standing at the till.
 *
 * @param array      $groups        money-filled groups from saleSplitBasket()
 * @param float      $postedPaid    the single paid amount the till posted
 * @param array|null $storePayments store_id => paid, required when more than one store
 * @return array{ok:bool, error:string, payments:array}  payments indexed like groups
 */
function saleSplitPayments(array $groups, $postedPaid, $storePayments = null)
{
    $payments = [];

    if (count($groups) === 1) {
        $total = $groups[0]['total'];
        // Exactly what the door did before: nothing tendered is a credit sale, so
        // it stays unpaid here rather than being assumed paid in full.
        $paid  = round(max(0.0, (float)$postedPaid), 2);
        $payments[] = [
            'paid'   => $paid,
            'change' => round(max(0, $paid - $total), 2),
            'status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
        ];
        return ['ok' => true, 'error' => '', 'payments' => $payments];
    }

    if (!is_array($storePayments) || empty($storePayments)) {
        $names = implode(', ', array_column($groups, 'store_name'));
        return [
            'ok'     => false,
            'error'  => 'Dui ta store-r mal ek sathe bikri hocche - proti store-r jonno alada '
                      . 'payment likhun (' . $names . ').',
            'payments' => [],
        ];
    }

    foreach ($groups as $g) {
        $storeId = (int)$g['store_id'];
        if (!isset($storePayments[$storeId]) || !is_numeric($storePayments[$storeId])) {
            return [
                'ok'     => false,
                'error'  => $g['store_name'] . ' store-r payment deya hoye ni.',
                'payments' => [],
            ];
        }
        $paid  = round((float)$storePayments[$storeId], 2);
        $total = $g['total'];
        if ($paid < 0) {
            return ['ok' => false, 'error' => $g['store_name'] . ' er payment 0 er niche.', 'payments' => []];
        }
        $payments[] = [
            'paid'   => $paid,
            'change' => round(max(0, $paid - $total), 2),
            'status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
        ];
    }

    return ['ok' => true, 'error' => '', 'payments' => $payments];
}
