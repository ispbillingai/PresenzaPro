<?php
/**
 * Admin Users Management
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/countries.php';
requireRole(['admin']);

/**
 * A user's WhatsApp number from the form (prefix country + number), or null
 * when empty. Redirects back with an error when it isn't a valid number.
 */
function userPhoneFromPost(): ?string
{
    $number = trim($_POST['phone'] ?? '');
    if ($number === '') return null;
    $phone = internationalPhone($_POST['phone_country'] ?? 'IT', $number);
    if ($phone === null) {
        header('Location: /admin/users.php?error=bad_phone');
        exit;
    }
    return $phone;
}

$pdo = getDBConnection();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_user') {
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $phone    = userPhoneFromPost();
        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, email, phone, phone_country) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['username'],
            $password,
            $_POST['full_name'],
            $_POST['role'],
            trim($_POST['email'] ?? '') ?: null,
            $phone,
            $phone ? strtoupper($_POST['phone_country'] ?? 'IT') : null,
        ]);
        header('Location: /admin/users.php?success=user_added');
        exit;
    }
    
    // Name, email and WhatsApp number (needed for the password reset).
    if ($action === 'edit_contacts') {
        $email = trim($_POST['email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header('Location: /admin/users.php?error=bad_email');
            exit;
        }
        $phone = userPhoneFromPost();
        $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, phone_country = ? WHERE id = ?")
            ->execute([trim($_POST['full_name'] ?? '') ?: $_POST['username_fallback'], $email ?: null, $phone,
                       $phone ? strtoupper($_POST['phone_country'] ?? 'IT') : null, (int) $_POST['user_id']]);
        logActivity('user_contacts_updated', 'users', (int) $_POST['user_id']);
        header('Location: /admin/users.php?success=contacts_updated');
        exit;
    }

    if ($action === 'toggle_status') {
        $stmt = $pdo->prepare("UPDATE users SET active = NOT active WHERE id = ? AND id != ?");
        $stmt->execute([$_POST['user_id'], $_SESSION['user_id']]);
        header('Location: /admin/users.php?success=status_updated');
        exit;
    }
    
    if ($action === 'reset_password') {
        $password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$password, $_POST['user_id']]);
        header('Location: /admin/users.php?success=password_reset');
        exit;
    }

    if ($action === 'delete_user') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        // Never delete yourself.
        if ($uid && $uid !== (int) ($_SESSION['user_id'] ?? 0)) {
            // Refuse if the user has history (orders/payments) — those records
            // reference the user and must be preserved. Disable instead.
            $stmt = $pdo->prepare(
                "SELECT (SELECT COUNT(*) FROM orders WHERE waiter_id = ?)
                      + (SELECT COUNT(*) FROM payments WHERE received_by = ?) AS refs"
            );
            $stmt->execute([$uid, $uid]);
            $refs = (int) ($stmt->fetch()['refs'] ?? 0);
            if ($refs > 0) {
                header('Location: /admin/users.php?error=user_has_history');
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$uid]);
            logActivity('user_deleted', 'users', $uid);
            header('Location: /admin/users.php?success=user_deleted');
            exit;
        }
        header('Location: /admin/users.php');
        exit;
    }
}

// Get all users
$stmt = $pdo->query("SELECT * FROM users ORDER BY role, full_name");
$users = $stmt->fetchAll();
$countries = phoneCountryOptions();

$pageTitle = t('user_management');

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-users"></i> <?= te('user_management') ?></h1>
    <button class="btn btn-primary" onclick="openModal('addUserModal')">
        <i class="fas fa-user-plus"></i> <?= te('add_user') ?>
    </button>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success mb-lg" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 16px; border-radius: 8px;">
        <i class="fas fa-check-circle"></i>
        <?php
        switch ($_GET['success']) {
            case 'user_added': echo te('msg_user_added'); break;
            case 'status_updated': echo te('msg_status_updated'); break;
            case 'password_reset': echo te('msg_password_reset'); break;
            case 'user_deleted': echo te('msg_user_deleted'); break;
            case 'contacts_updated': echo te('msg_contacts_updated'); break;
        }
        ?>
    </div>
<?php endif; ?>

<?php if (in_array($_GET['error'] ?? '', ['bad_phone', 'bad_email'], true)): ?>
    <div class="alert alert-danger mb-lg" style="background: rgba(231,76,60,0.1); color: var(--danger); padding: 16px; border-radius: 8px;">
        <i class="fas fa-exclamation-circle"></i> <?= te($_GET['error'] === 'bad_phone' ? 'cust_bad_phone' : 'err_bad_email') ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'user_has_history'): ?>
    <div class="alert alert-danger mb-lg" style="background: rgba(231,76,60,0.1); color: var(--danger); padding: 16px; border-radius: 8px;">
        <i class="fas fa-exclamation-circle"></i> <?= te('err_user_has_history') ?>
    </div>
<?php endif; ?>

<div class="card">
    <table class="data-table">
        <thead>
            <tr>
                <th><?= te('user') ?></th>
                <th><?= te('username') ?></th>
                <th><?= te('role') ?></th>
                <th><?= te('contact') ?></th>
                <th><?= te('status') ?></th>
                <th><?= te('actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td>
                        <div class="d-flex align-center gap-sm">
                            <div style="width: 40px; height: 40px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                                <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                            </div>
                            <strong><?= htmlspecialchars($user['full_name']) ?></strong>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($user['username']) ?></td>
                    <td>
                        <span class="badge badge-<?= 
                            $user['role'] === 'admin' ? 'danger' : 
                            ($user['role'] === 'waiter' ? 'info' : 
                            ($user['role'] === 'cashier' ? 'success' : 'warning')) 
                        ?>">
                            <?= te('role_' . $user['role']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($user['email']): ?>
                            <div><i class="fas fa-envelope text-muted"></i> <?= htmlspecialchars($user['email']) ?></div>
                        <?php endif; ?>
                        <?php if ($user['phone']): ?>
                            <div class="flag-font"><i class="fab fa-whatsapp" style="color:#25d366;"></i> <?= countryFlag($user['phone_country'] ?: 'IT') ?> <?= htmlspecialchars($user['phone']) ?></div>
                        <?php endif; ?>
                        <?php if (!$user['email'] || !$user['phone']): ?>
                            <div class="text-muted" style="font-size:.75rem;"><i class="fas fa-triangle-exclamation" style="color:var(--warning);"></i> <?= te('user_no_reset') ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($user['active']): ?>
                            <span class="badge badge-success"><?= te('active') ?></span>
                        <?php else: ?>
                            <span class="badge badge-danger"><?= te('inactive') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-sm">
                            <button class="btn btn-sm btn-outline" onclick='openContacts(<?= htmlspecialchars(json_encode([
                                "id" => (int) $user["id"], "username" => $user["username"], "full_name" => $user["full_name"],
                                "email" => $user["email"] ?? "", "country" => $user["phone_country"] ?: "IT",
                                "phone" => $user["phone"] ? nationalPhone($user["phone_country"] ?: "IT", $user["phone"]) : ""]), ENT_QUOTES) ?>)' title="<?= te('user_contacts') ?>">
                                <i class="fas fa-address-card"></i> <?= te('user_contacts') ?>
                            </button>
                            <button class="btn btn-sm btn-outline" onclick="openResetModal(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')" title="<?= te('reset_password') ?>">
                                <i class="fas fa-key"></i> <?= te('reset_password') ?>
                            </button>

                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('<?= $user['active'] ? te('disable_confirm') : te('enable_confirm') ?>');">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $user['active'] ? 'btn-warning' : 'btn-success' ?>">
                                        <i class="fas fa-<?= $user['active'] ? 'ban' : 'check' ?>"></i> <?= $user['active'] ? te('disable') : te('enable') ?>
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('<?= te('delete_user_confirm') ?>');">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="<?= te('delete') ?>">
                                        <i class="fas fa-trash"></i> <?= te('delete') ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Add User Modal -->
<div class="modal-overlay" id="addUserModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('add_new_user') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_user">

                <div class="form-group">
                    <label class="form-label"><?= te('full_name') ?></label>
                    <input type="text" name="full_name" class="form-control" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('username') ?></label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('password') ?></label>
                        <input type="password" name="password" class="form-control" required minlength="6">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('role') ?></label>
                    <select name="role" class="form-control" required>
                        <option value="waiter"><?= te('role_waiter') ?></option>
                        <option value="cashier"><?= te('role_cashier') ?></option>
                        <option value="kitchen"><?= te('role_kitchen') ?></option>
                        <option value="till"><?= te('role_till') ?></option>
                        <option value="admin"><?= te('role_admin') ?></option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('email_optional') ?></label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fab fa-whatsapp" style="color:#25d366;"></i> <?= te('user_whatsapp') ?></label>
                        <div class="d-flex gap-sm">
                            <select name="phone_country" class="form-control flag-font" style="max-width:8.5rem;">
                                <?php foreach ($countries as $c): ?><option value="<?= $c['iso'] ?>"><?= $c['flag'] ?> <?= $c['dial'] ?></option><?php endforeach; ?>
                            </select>
                            <input type="tel" name="phone" class="form-control" placeholder="333 123 4567">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addUserModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= te('add_user') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Contacts: name, email, WhatsApp (the password reset needs email + WhatsApp) -->
<div class="modal-overlay" id="contactsModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-address-card"></i> <?= te('user_contacts') ?> — <span id="ctUsername"></span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_contacts">
                <input type="hidden" name="user_id" id="ctId">
                <input type="hidden" name="username_fallback" id="ctFallback">
                <p class="text-muted" style="margin-top:0;font-size:.85rem;"><?= te('user_contacts_hint') ?></p>
                <div class="form-group">
                    <label class="form-label"><?= te('full_name') ?></label>
                    <input type="text" name="full_name" id="ctName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= te('email_optional') ?></label>
                    <input type="email" name="email" id="ctEmail" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fab fa-whatsapp" style="color:#25d366;"></i> <?= te('user_whatsapp') ?></label>
                    <div class="d-flex gap-sm">
                        <select name="phone_country" id="ctCountry" class="form-control flag-font" style="max-width:11.5rem;">
                            <?php foreach ($countries as $c): ?><option value="<?= $c['iso'] ?>"><?= $c['flag'] ?> <?= htmlspecialchars($c['name']) ?> <?= $c['dial'] ?></option><?php endforeach; ?>
                        </select>
                        <input type="tel" name="phone" id="ctPhone" class="form-control" placeholder="333 123 4567">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('contactsModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= te('save') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="resetPasswordModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('reset_password') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetUserId">

                <p class="mb-md"><?= te('reset_password_for') ?> <strong id="resetUsername"></strong></p>

                <div class="form-group">
                    <label class="form-label"><?= te('new_password') ?></label>
                    <input type="password" name="new_password" class="form-control" required minlength="6">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('resetPasswordModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-warning"><?= te('reset_password') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function openContacts(u) {
    document.getElementById('ctId').value = u.id;
    document.getElementById('ctUsername').textContent = u.username;
    document.getElementById('ctFallback').value = u.full_name;
    document.getElementById('ctName').value = u.full_name;
    document.getElementById('ctEmail').value = u.email;
    document.getElementById('ctCountry').value = u.country || 'IT';
    document.getElementById('ctPhone').value = u.phone;
    openModal('contactsModal');
}
</script>
<script>
function openResetModal(userId, username) {
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUsername').textContent = username;
    openModal('resetPasswordModal');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
