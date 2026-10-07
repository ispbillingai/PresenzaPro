/**
 * RestoPOS - Main JavaScript
 */

// Global state
const App = {
    currentOrder: null,
    orderItems: [],
    selectedCategory: null,
    notifications: [],
    pollingInterval: null
};

// DOM Ready
document.addEventListener('DOMContentLoaded', function() {
    initNotifications();
    initModals();
    startPolling();
});

// ============================================
// Notifications
// ============================================
function initNotifications() {
    const bell = document.getElementById('notificationsBell');
    const panel = document.getElementById('notificationsPanel');
    
    if (bell && panel) {
        bell.addEventListener('click', function(e) {
            e.stopPropagation();
            panel.classList.toggle('active');
            if (panel.classList.contains('active')) {
                loadNotifications();
            }
        });
        
        document.addEventListener('click', function(e) {
            if (!panel.contains(e.target) && !bell.contains(e.target)) {
                panel.classList.remove('active');
            }
        });
    }
}

async function loadNotifications() {
    try {
        const response = await fetch('/api/notifications.php');
        const data = await response.json();
        
        if (data.success) {
            renderNotifications(data.notifications);
        }
    } catch (error) {
        console.error('Error loading notifications:', error);
    }
}

function renderNotifications(notifications) {
    const list = document.getElementById('notificationsList');
    if (!list) return;
    
    if (notifications.length === 0) {
        list.innerHTML = '<div class="notification-item"><p class="text-muted text-center">No notifications</p></div>';
        return;
    }
    
    // A notification about an order opens it.
    const orderOf = n => { try { return (JSON.parse(n.payload || '{}') || {}).order_id || 0; } catch (e) { return 0; } };
    list.innerHTML = notifications.map(n => `
        <div class="notification-item ${n.read_at ? '' : 'unread'}" data-id="${n.id}"
             ${orderOf(n) && n.type === 'dish_ready' ?   `style="cursor:pointer" onclick="location.href='/waiter/order.php?order=${parseInt(orderOf(n), 10)}'"` : ''}>
            <div class="title">${escapeHtml(n.title)}</div>
            <div class="message">${escapeHtml(n.message)}</div>
            <div class="time">${formatTimeAgo(n.created_at)}</div>
        </div>
    `).join('');
}

async function markAllRead() {
    try {
        await fetch('/api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'mark_all_read' })
        });
        loadNotifications();
        
        const badge = document.querySelector('.notifications-bell .badge');
        if (badge) badge.remove();
    } catch (error) {
        console.error('Error marking notifications as read:', error);
    }
}

// ============================================
// Polling for updates
// ============================================
function startPolling() {
    if (window.APP_EMBED) return;   // shown inside another page, which polls already
    // Poll every 10 seconds for updates
    App.pollingInterval = setInterval(async function() {
        await checkForUpdates();
    }, 10000);
}

async function checkForUpdates() {
    try {
        const response = await fetch('/api/status.php');
        const data = await response.json();
        
        // Update notification count
        if (data.unread_notifications !== undefined) {
            updateNotificationBadge(data.unread_notifications);
        }
        
        // Trigger custom event for page-specific updates
        document.dispatchEvent(new CustomEvent('app:update', { detail: data }));
    } catch (error) {
        console.error('Polling error:', error);
    }
}

function updateNotificationBadge(count) {
    const bell = document.querySelector('.notifications-bell');
    if (!bell) return;
    
    let badge = bell.querySelector('.badge');
    
    if (count > 0) {
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'badge';
            bell.appendChild(badge);
        }
        badge.textContent = count;
    } else if (badge) {
        badge.remove();
    }
}

// ============================================
// Modals
// ============================================
function initModals() {
    // Close modal when clicking overlay
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal(this.id);
            }
        });
    });
    
    // Close buttons
    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = this.closest('.modal-overlay');
            if (modal) closeModal(modal.id);
        });
    });
    
    // ESC key to close
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const activeModal = document.querySelector('.modal-overlay.active');
            if (activeModal) closeModal(activeModal.id);
        }
    });
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// ============================================
// Toast Notifications
// ============================================
function showToast(message, type = 'info', duration = 3000) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    
    toast.innerHTML = `
        <i class="fas ${icons[type] || icons.info}"></i>
        <span>${escapeHtml(message)}</span>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideIn 0.3s ease reverse';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// ============================================
// API Helpers
// ============================================
async function apiCall(url, method = 'GET', data = null) {
    const options = {
        method,
        headers: {
            'Content-Type': 'application/json'
        }
    };
    
    if (data && method !== 'GET') {
        options.body = JSON.stringify(data);
    }
    
    try {
        const response = await fetch(url, options);
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'API request failed');
        }
        
        return result;
    } catch (error) {
        showToast(error.message, 'error');
        throw error;
    }
}

// ============================================
// Order Functions
// ============================================
async function createOrder(tableId, numberOfPeople) {
    return await apiCall('/api/orders.php', 'POST', {
        action: 'create',
        table_id: tableId,
        number_of_people: numberOfPeople
    });
}

async function addItemToOrder(orderId, menuItemId, quantity = 1, notes = '', modifications = [], seat = null) {
    return await apiCall('/api/orders.php', 'POST', {
        action: 'add_item',
        seat: seat,
        order_id: orderId,
        menu_item_id: menuItemId,
        quantity: quantity,
        notes: notes,
        modifications: modifications
    });
}

async function updateItemQuantity(orderItemId, quantity) {
    return await apiCall('/api/orders.php', 'POST', {
        action: 'update_quantity',
        order_item_id: orderItemId,
        quantity: quantity
    });
}

async function removeItem(orderItemId) {
    return await apiCall('/api/orders.php', 'POST', {
        action: 'remove_item',
        order_item_id: orderItemId
    });
}

async function sendToKitchen(orderId) {
    return await apiCall('/api/orders.php', 'POST', {
        action: 'send_to_kitchen',
        order_id: orderId
    });
}

async function requestBill(orderId, tillId = null) {
    const body = { action: 'request_bill', order_id: orderId };
    if (tillId) body.till_id = tillId;
    return await apiCall('/api/orders.php', 'POST', body);
}

// ============================================
// Kitchen Functions
// ============================================
async function updateKitchenStatus(orderItemId, status) {
    return await apiCall('/api/kitchen.php', 'POST', {
        action: 'update_status',
        order_item_id: orderItemId,
        status: status
    });
}

// ============================================
// Payment Functions
// ============================================
async function applyDiscount(orderId, type, value, reason = '') {
    return await apiCall('/api/payments.php', 'POST', {
        action: 'apply_discount',
        order_id: orderId,
        discount_type: type,
        discount_value: value,
        reason: reason
    });
}

async function processPayment(orderId, method, amount, reference = '') {
    return await apiCall('/api/payments.php', 'POST', {
        action: 'process_payment',
        order_id: orderId,
        method: method,
        amount: amount,
        reference: reference
    });
}

// ============================================
// Utility Functions
// ============================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatCurrency(amount) {
    return '$' + parseFloat(amount).toFixed(2);
}

function formatTimeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const seconds = Math.floor((now - date) / 1000);
    
    if (seconds < 60) return 'Just now';
    if (seconds < 3600) return Math.floor(seconds / 60) + ' min ago';
    if (seconds < 86400) return Math.floor(seconds / 3600) + ' hr ago';
    return Math.floor(seconds / 86400) + ' days ago';
}

function formatTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleTimeString('en-US', { 
        hour: '2-digit', 
        minute: '2-digit',
        hour12: true 
    });
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Confirm action helper
function confirmAction(message) {
    return new Promise((resolve) => {
        if (confirm(message)) {
            resolve(true);
        } else {
            resolve(false);
        }
    });
}

// ============================================
// Guests' QR requests (bill / call waiter / change a dish)
// Shown as a bar in every staff area, fed by the same /api/status.php poll.
// ============================================
// Known request ids survive the page reloads some screens do (kitchen), so a
// reload doesn't swallow the beep for a request that arrived meanwhile.
const TableRequests = { known: new Set(), loaded: false };
try {
    const saved = sessionStorage.getItem('trKnown');
    if (saved !== null) { JSON.parse(saved).forEach(id => TableRequests.known.add(id)); TableRequests.loaded = true; }
} catch (e) {}

function trText(r) {
    const L = window.REQ_I18N || {};
    if (r.type === 'bill') return L.bill || 'Asks for the bill';
    if (r.type === 'waiter') return L.waiter || 'Calls the waiter';
    const seat = r.seat ? ' (' + (L.seat || 'Seat') + ' ' + r.seat + ')' : '';
    if (r.replacement_name) {
        return (L.swap || 'Swap') + ': ' + (r.item_name || '') + seat + ' → ' + r.replacement_name;
    }
    return (L.change || 'Change to') + ' ' + (r.item_name || '') + seat;
}

function trBeep() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        [0, 0.25].forEach(delay => {
            const o = ctx.createOscillator(), g = ctx.createGain();
            o.frequency.value = 880; o.connect(g); g.connect(ctx.destination);
            g.gain.setValueAtTime(0.25, ctx.currentTime + delay);
            o.start(ctx.currentTime + delay); o.stop(ctx.currentTime + delay + 0.18);
        });
    } catch (e) { /* no sound allowed yet — the bar still shows */ }
}

function renderTableRequests(list) {
    if (!Array.isArray(list)) return;
    const L = window.REQ_I18N || {};
    let bar = document.getElementById('tableRequestsBar');
    if (!bar) {
        bar = document.createElement('div');
        bar.id = 'tableRequestsBar';
        bar.className = 'table-requests-bar';
        document.body.appendChild(bar);
    }

    let fresh = false;
    list.forEach(r => { if (!TableRequests.known.has(r.id)) { TableRequests.known.add(r.id); if (TableRequests.loaded) fresh = true; } });
    if (fresh) { trBeep(); showToast(L.new_request || 'New table request', 'warning', 5000); }
    TableRequests.loaded = true;
    try { sessionStorage.setItem('trKnown', JSON.stringify(list.map(r => r.id))); } catch (e) {}

    const icons = { bill: 'fa-receipt', waiter: 'fa-hand', change: 'fa-pen' };
    bar.innerHTML = list.map(r => {
        const mins = Math.max(0, Math.floor((r.age_seconds || 0) / 60));
        return `
        <div class="tr-card tr-${r.type} ${r.status === 'seen' ? 'tr-seen' : ''}">
            <div class="tr-head">
                <i class="fas ${icons[r.type] || 'fa-bell'}"></i>
                <strong>${escapeHtml((L.table || 'Table') + ' ' + r.table_number)}</strong>
                <span class="tr-age">${mins < 1 ? (L.now || 'now') : mins + ' min'}</span>
            </div>
            <div class="tr-text">${escapeHtml(trText(r))}</div>
            ${r.message ? `<div class="tr-msg">“${escapeHtml(r.message)}”</div>` : ''}
            ${r.status === 'seen' ? `<div class="tr-by"><i class="fas fa-check"></i> ${escapeHtml((L.taken_by || 'Taken by') + ' ' + (r.seen_by_name || ''))}</div>` : ''}
            <div class="tr-actions">
                ${r.status === 'open' ? `<button class="btn btn-sm btn-primary" onclick="tableRequestAction(${r.id}, 'seen')">${escapeHtml(L.take || 'On my way')}</button>` : ''}
                <button class="btn btn-sm btn-success" onclick="tableRequestAction(${r.id}, 'done')"><i class="fas fa-check"></i> ${escapeHtml(L.done || 'Done')}</button>
            </div>
        </div>`;
    }).join('');
}

async function tableRequestAction(id, action) {
    try {
        const r = await apiCall('/api/table-requests.php', 'POST', { id, action });
        renderTableRequests(r.requests);
    } catch (e) { /* apiCall already showed the reason */ }
}

document.addEventListener('app:update', e => renderTableRequests(e.detail && e.detail.table_requests));
// Show waiting requests straight away instead of after the first 10 s poll.
document.addEventListener('DOMContentLoaded', () => { if (window.REQ_I18N) checkForUpdates(); });

// ============================================
// Room scroller: a horizontally scrolling bar of rooms; picking one shows only
// that room's panel. Markup: .room-scroller[data-key] > [data-room] chips,
// panels [data-room-panel="<id>"]. Remembers the choice (?room= in the URL,
// else the last one picked on this device).
// ============================================
function initRoomScroller() {
    const bar = document.querySelector('.room-scroller');
    if (!bar) return;
    const chips  = [...bar.querySelectorAll('[data-room]')];
    const panels = [...document.querySelectorAll('[data-room-panel]')];
    if (!chips.length) return;
    const key = 'room-scroller-' + (bar.dataset.key || location.pathname);

    function select(id, scroll = true) {
        id = String(id);
        if (!chips.some(c => c.dataset.room === id)) id = chips[0].dataset.room;
        chips.forEach(c => c.classList.toggle('active', c.dataset.room === id));
        panels.forEach(p => p.classList.toggle('is-hidden', p.dataset.roomPanel !== id));
        const active = chips.find(c => c.dataset.room === id);
        if (scroll && active) active.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
        try { localStorage.setItem(key, id); } catch (e) {}
        const url = new URL(location.href);
        url.searchParams.set('room', id);
        ['success', 'error', 'done'].forEach(p => url.searchParams.delete(p)); // one-off messages: not again on reload
        history.replaceState(null, '', url);
    }

    chips.forEach(c => c.addEventListener('click', () => select(c.dataset.room)));
    document.querySelectorAll('[data-room-scroll]').forEach(b => b.addEventListener('click', () => {
        bar.scrollBy({ left: parseInt(b.dataset.roomScroll, 10) * bar.clientWidth * 0.7, behavior: 'smooth' });
    }));

    let start = new URLSearchParams(location.search).get('room');
    if (!start) { try { start = localStorage.getItem(key); } catch (e) {} }
    select(start || chips[0].dataset.room, false);
    const active = bar.querySelector('.active');
    if (active) bar.scrollLeft = active.offsetLeft - bar.clientWidth / 2 + active.clientWidth / 2;
}
document.addEventListener('DOMContentLoaded', initRoomScroller);

// Floor plans: tables asking for the bill blink — kept live from the status poll.
document.addEventListener('app:update', e => {
    const ids = e.detail && e.detail.bill_tables;
    if (!Array.isArray(ids)) return;
    const on = new Set(ids.map(String));
    document.querySelectorAll('.table-card.table-visual[data-table-id]').forEach(card => {
        card.classList.toggle('bill-alert', on.has(card.dataset.tableId));
    });
});

// ============================================
// "Dish ready" alert for waiters: a banner at the top with a sound, on any
// page, for each new ready notification (who gets it: Settings > Dish ready
// alert, or the order's own choice). OK / Open mark it read.
// ============================================
let readyAudio = null;
function readyChime() {
    try {
        readyAudio = readyAudio || new (window.AudioContext || window.webkitAudioContext)();
        if (readyAudio.state === 'suspended') readyAudio.resume();
        [0, 0.18, 0.36].forEach((t, i) => {
            const o = readyAudio.createOscillator(), g = readyAudio.createGain();
            o.type = 'sine';
            o.frequency.value = [880, 1175, 1568][i];
            g.gain.setValueAtTime(0.0001, readyAudio.currentTime + t);
            g.gain.exponentialRampToValueAtTime(0.35, readyAudio.currentTime + t + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, readyAudio.currentTime + t + 0.45);
            o.connect(g).connect(readyAudio.destination);
            o.start(readyAudio.currentTime + t);
            o.stop(readyAudio.currentTime + t + 0.5);
        });
    } catch (e) { /* no audio on this device */ }
}
// Browsers only play sound after the user touched the page once.
document.addEventListener('click', () => {
    try {
        readyAudio = readyAudio || new (window.AudioContext || window.webkitAudioContext)();
        if (readyAudio.state === 'suspended') readyAudio.resume();
    } catch (e) {}
}, { once: true });

function readyAlertSeen() {
    try { return JSON.parse(sessionStorage.getItem('ready-alerts-seen') || '[]'); } catch (e) { return []; }
}
function showReadyAlerts(alerts) {
    if (!Array.isArray(alerts) || !alerts.length) return;
    const seen = readyAlertSeen();
    const fresh = alerts.filter(a => !seen.includes(a.id));
    if (!fresh.length) return;
    try { sessionStorage.setItem('ready-alerts-seen', JSON.stringify(seen.concat(fresh.map(a => a.id)).slice(-50))); } catch (e) {}

    let box = document.getElementById('readyAlerts');
    if (!box) {
        box = document.createElement('div');
        box.id = 'readyAlerts';
        box.className = 'ready-alerts';
        document.body.appendChild(box);
    }
    const L = window.REQ_I18N || {};
    fresh.reverse().forEach(a => {
        const el = document.createElement('div');
        el.className = 'ready-alert' + (a.type === 'table_free' ? ' table-free' : '');
        el.innerHTML = `
            <i class="fas ${a.type === 'table_free' ? 'fa-broom' : 'fa-bell-concierge'}"></i>
            <div class="ra-text"><strong>${escapeHtml(a.title)}</strong><span>${escapeHtml(a.message)}</span></div>
            ${a.takeable ? `<button class="btn btn-sm ra-take" type="button">${escapeHtml(L.take_it || "I'll take it")}</button>` : ''}
            ${a.order_id ? `<a class="btn btn-sm" href="/waiter/order.php?order=${a.order_id}">${escapeHtml(L.open_order || 'Open')}</a>` : ''}
            ${a.type === 'table_free' ? `<a class="btn btn-sm" href="/waiter/index.php">${escapeHtml(L.tables || 'Tables')}</a>` : ''}
            <button class="btn btn-sm" type="button">OK</button>`;
        const done = () => {
            fetch('/api/notifications.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_read', notification_id: a.id }) }).catch(() => {});
            el.remove();
        };
        el.dataset.alertId = a.id;
        el.querySelector('button:not(.ra-take)').addEventListener('click', done);
        const take = el.querySelector('.ra-take');
        if (take) take.addEventListener('click', async () => {
            take.disabled = true;
            try {
                await apiCall('/api/orders.php', 'POST', { action: 'take_guest_order', order_id: a.order_id });
                showToast(L.taken || 'OK', 'success');
                done();
            } catch (e) { done(); }   // a colleague was quicker: apiCall showed who
        });
        const link = el.querySelector('a');
        if (link) link.addEventListener('click', done);
        box.appendChild(el);
    });
    readyChime();
    if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
}
document.addEventListener('app:update', e => {
    const alerts = e.detail && e.detail.ready_alerts;
    // Pop-ups no longer waiting (taken by a colleague, read elsewhere) go away.
    if (Array.isArray(alerts)) {
        const live = new Set(alerts.map(a => String(a.id)));
        document.querySelectorAll('#readyAlerts .ready-alert[data-alert-id]').forEach(el => { if (!live.has(el.dataset.alertId)) el.remove(); });
    }
    showReadyAlerts(alerts);
});

// Phones: the scrolling menu rows start at the page that is open.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.admin-sidebar a.active, .nav-links a.active').forEach(a => {
        const bar = a.parentElement;
        if (bar.scrollWidth > bar.clientWidth) bar.scrollLeft = a.offsetLeft - bar.offsetLeft - (bar.clientWidth - a.offsetWidth) / 2;
    });
});

// Paid table cleared and laid again (floor plans of waiter, cashier, admin).
async function tableLaid(tableId, btn) {
    btn.disabled = true;
    try {
        await apiCall('/api/orders.php', 'POST', { action: 'table_laid', table_id: tableId });
        const card = btn.closest('.table-card, .lay-row');
        if (card) {
            card.classList.remove('needs-reset');
            card.querySelector('.tv-reset')?.remove();
            if (card.classList.contains('lay-row')) card.remove();
        }
        btn.remove();
        if (window.LAY_WATCH) window.LAY_WATCH.shown = window.LAY_WATCH.shown.filter(id => id !== tableId);
        showToast((window.REQ_I18N || {}).laid_done || 'OK', 'success');
    } catch (e) { btn.disabled = false; }
}
// A table paid (to lay) or laid by a colleague: reload the floor plan.
document.addEventListener('app:update', e => {
    const w = window.LAY_WATCH;
    const ids = e.detail && e.detail.reset_tables;
    if (!w || !Array.isArray(ids)) return;
    const now = ids.filter(id => w.scope === null || w.scope.includes(id));
    const changed = now.length !== w.shown.length || now.some(id => !w.shown.includes(id));
    if (changed && !document.querySelector('.modal-overlay.active')) location.reload();
});

// Header icons PC / tablet / phone: the chosen layout is filled; when none is
// chosen (automatic) the one matching this screen is outlined. Clicking the
// chosen one again goes back to automatic.
document.addEventListener('DOMContentLoaded', () => {
    const w = window.innerWidth;
    const detected = w <= 768 ? 'phone' : w <= 1024 ? 'tablet' : 'desktop';
    const chosen = window.layoutMode || null;
    document.querySelectorAll('.layout-switch [data-layout]').forEach(b => {
        b.classList.toggle('active', b.dataset.layout === chosen);
        b.classList.toggle('auto', !chosen && b.dataset.layout === detected);
        if (b.dataset.layout === chosen) b.title += ' — ' + ((window.REQ_I18N || {}).layout_auto || '');
    });
});
