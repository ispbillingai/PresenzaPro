<?php
/**
 * One customer, one card. Clienti online and Clienti cassa are linked by phone
 * (till_customers.online_customer_id): every online customer has a card (a
 * Clienti cassa code, C482913, with its QR) to show at the till, and a customer
 * of the till who signs up online (or at the counter, online.php?tessera=1)
 * keeps the card they had. Name and date of birth are kept in step.
 */

require_once __DIR__ . '/till.php';

/**
 * The card of an online customer: the linked Clienti cassa customer, else the
 * one with the same phone (then linked), else a new one. $welcome: a new card
 * goes to them on WhatsApp with its QR (not for cards made in bulk).
 */
function customerCardFor(array $oc, bool $welcome = false): ?array
{
    $pdo = getDBConnection();
    $st  = $pdo->prepare("SELECT * FROM till_customers WHERE online_customer_id = ? ORDER BY active DESC, id LIMIT 1");
    $st->execute([(int) $oc['id']]);
    $tc = $st->fetch() ?: null;
    if (!$tc) {
        $st = $pdo->prepare("SELECT * FROM till_customers WHERE phone = ? AND online_customer_id IS NULL ORDER BY active DESC, id LIMIT 1");
        $st->execute([$oc['mobile']]);
        $tc = $st->fetch() ?: null;
    }
    if ($tc) {
        $complete = (int) $tc['online_customer_id'] === (int) $oc['id'] && (string) $tc['first_name'] !== ''
            && (!empty($tc['birth_date']) || empty($oc['birth_date'])) && (!empty($oc['birth_date']) || empty($tc['birth_date']));
        if ($complete) return $tc;   // (the online page asks every 10 s: no write when nothing changes)
        // Linked, and whatever the card lacks comes from the online sign-up.
        $pdo->prepare("UPDATE till_customers SET online_customer_id = ?, first_name = COALESCE(NULLIF(first_name, ''), ?),
                       last_name = COALESCE(NULLIF(last_name, ''), ?), birth_date = COALESCE(birth_date, ?) WHERE id = ?")
            ->execute([(int) $oc['id'], $oc['first_name'] ?: null, $oc['last_name'] ?: null, $oc['birth_date'] ?: null, (int) $tc['id']]);
        if (empty($oc['birth_date']) && !empty($tc['birth_date'])) {
            $pdo->prepare("UPDATE online_customers SET birth_date = ? WHERE id = ?")->execute([$tc['birth_date'], (int) $oc['id']]);
        }
        return tillCustomerById((int) $tc['id']);
    }
    $pdo->prepare("INSERT INTO till_customers (code, first_name, last_name, phone, country, birth_date, online_customer_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([tillNewCustomerCode(), $oc['first_name'] ?: null, $oc['last_name'] ?: null, $oc['mobile'], $oc['mobile_country'] ?: 'IT',
                   $oc['birth_date'] ?: null, (int) $oc['id']]);
    $tc = tillCustomerById((int) $pdo->lastInsertId());
    logActivity('till_customer_created', 'till_customers', (int) $tc['id'], ['from' => 'online']);
    if ($welcome) tillCustomerWelcome($tc);
    return $tc;
}

/** Every online customer gets their card (made silently: no WhatsApp). Returns how many were made or linked. */
function customerCardsSync(): int
{
    $rows = getDBConnection()->query("
        SELECT oc.* FROM online_customers oc
        WHERE NOT EXISTS (SELECT 1 FROM till_customers tc WHERE tc.online_customer_id = oc.id)
    ")->fetchAll();
    foreach ($rows as $oc) customerCardFor($oc, false);
    return count($rows);
}

/** The online profile changed: the card gets the same name and date of birth. */
function customerCardFromOnline(array $oc): void
{
    getDBConnection()->prepare("UPDATE till_customers SET first_name = ?, last_name = ?, birth_date = COALESCE(?, birth_date) WHERE online_customer_id = ?")
        ->execute([$oc['first_name'] ?: null, $oc['last_name'] ?: null, $oc['birth_date'] ?: null, (int) $oc['id']]);
}

/**
 * A card saved at the till with a phone: linked to the online customer with
 * that phone (if any), each filling the other's missing date of birth.
 */
function customerCardFromTill(array $tc): void
{
    if (empty($tc['phone'])) return;
    $oc = onlineCustomerByMobile($tc['phone']);
    if (!$oc) return;
    $pdo = getDBConnection();
    if (empty($tc['online_customer_id'])) {
        $st = $pdo->prepare("SELECT 1 FROM till_customers WHERE online_customer_id = ? AND id <> ?");
        $st->execute([(int) $oc['id'], (int) $tc['id']]);
        if (!$st->fetchColumn()) $pdo->prepare("UPDATE till_customers SET online_customer_id = ? WHERE id = ?")->execute([(int) $oc['id'], (int) $tc['id']]);
    }
    if (empty($oc['birth_date']) && !empty($tc['birth_date'])) {
        $pdo->prepare("UPDATE online_customers SET birth_date = ? WHERE id = ?")->execute([$tc['birth_date'], (int) $oc['id']]);
    } elseif (!empty($oc['birth_date']) && empty($tc['birth_date'])) {
        $pdo->prepare("UPDATE till_customers SET birth_date = ? WHERE id = ?")->execute([$oc['birth_date'], (int) $tc['id']]);
    }
}

/** The counter's QR: the page where a customer makes (or opens) their card. */
function customerCardSignupUrl(): string
{
    require_once __DIR__ . '/menu_pdf.php';
    return publicUrl('online.php?tessera=1');
}
