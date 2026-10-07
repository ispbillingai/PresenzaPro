<?php
/**
 * WhatsApp "thank you" to the guests once their bill is paid.
 *
 * A seat bill paid on its own thanks that seat's guest; the whole table paid
 * thanks the table's guest and every seat guest not thanked yet. Once per
 * number per meal. The text (IT / EN, by the phone's country) is set in
 * Settings > Thank-you message; empty = the default text (lang thanks_default).
 * Service message, not marketing: it needs no marketing consent.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/whatsapp_guest.php';

/** ['enabled' => bool, 'it' => text, 'en' => text] ('' = default text). */
function thanksSettings(): array
{
    $s = (array) getSetting('thanks_message', []);
    return ['enabled' => (bool) ($s['enabled'] ?? true), 'it' => (string) ($s['it'] ?? ''), 'en' => (string) ($s['en'] ?? '')];
}

/** The message for one guest, in their language. */
function thanksText(string $lang, ?string $name): string
{
    $lang  = $lang === 'it' ? 'it' : 'en';
    $txt   = trim(thanksSettings()[$lang]) ?: tIn($lang, 'thanks_default');
    $first = trim(strtok((string) $name, ' ') ?: '') ?: tIn($lang, 'thanks_no_name'); // "Gentile cliente"
    return strtr($txt, [
        '{nome}' => $first, '{name}' => $first,
        '{ristorante}' => restaurantName(), '{restaurant}' => restaurantName(),
    ]);
}

/**
 * Thank the guests of a bill that has just been paid ($orderId: the table's
 * order or one seat bill). Returns how many messages were queued.
 */
function thankGuestsForPaidOrder(int $orderId): int
{
    try {
        if (!guestWhatsappEnabled()) return 0;
        $pdo  = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order || $order['status'] !== 'paid') return 0;
        // Online customers: "payment received" + thank-you (Admin > Clienti online).
        if ($order['channel'] === 'online') {
            require_once __DIR__ . '/online_order.php';
            return onlineThankPaid($order);
        }
        // Counter sale with a Clienti cassa customer: the receipt with their QR.
        if ($order['channel'] === 'counter') {
            require_once __DIR__ . '/till.php';
            return tillSendReceipt($order) ? 1 : 0;
        }
        if (!thanksSettings()['enabled'] || $order['channel'] !== 'dine_in') return 0;

        $rootId = (int) ($order['parent_order_id'] ?: $order['id']);
        $guests = [];                                      // phone => [name, country]
        if ($order['parent_order_id']) {
            // One seat paid on its own: that seat's guest.
            $g = orderSeatGuests($rootId)[(int) $order['seat']] ?? null;
            if ($g && $g['customer_phone']) $guests[$g['customer_phone']] = [$g['customer_name'], $g['customer_country']];
        } else {
            // The table paid: its guest and the seat guests.
            if ($order['customer_phone']) $guests[$order['customer_phone']] = [$order['customer_name'], $order['customer_country']];
            foreach (orderSeatGuests($rootId) as $g) {
                if ($g['customer_phone'] && !isset($guests[$g['customer_phone']])) $guests[$g['customer_phone']] = [$g['customer_name'], $g['customer_country']];
            }
        }

        $sent = $pdo->prepare("SELECT 1 FROM whatsapp_outbox WHERE kind = 'thanks' AND order_id = ? AND phone = ? LIMIT 1");
        $n = 0;
        foreach ($guests as $phone => [$name, $country]) {
            $sent->execute([$rootId, $phone]);
            if ($sent->fetchColumn()) continue;           // already thanked for this meal
            queueGuestWhatsapp($rootId, $order['seat'] !== null ? (int) $order['seat'] : null, 'thanks', $phone, thanksText(guestLang($country), $name));
            $n++;
        }
        if ($n) logActivity('guest_thanks_sent', 'orders', $rootId, ['messages' => $n]);
        return $n;
    } catch (Throwable $e) {
        error_log('[thanks] order ' . $orderId . ': ' . $e->getMessage());
        return 0;
    }
}
