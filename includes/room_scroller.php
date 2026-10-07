<?php
/**
 * Room scroller bar (see initRoomScroller in app.js). Set before including:
 *   $scrollerKey   — remembers the chosen room per page
 *   $scrollerRooms — [['id' => , 'name' => , 'count' => , 'count_class'? => , 'title'? => , 'icon'? => ], ...]
 *                    id may be a room id or a special view (e.g. 'occupied');
 *                    count_class 'free' shows the number in green.
 * Each view's content must be wrapped in an element with data-room-panel="<id>".
 */
if (empty($scrollerRooms)) return;
?>
<div class="room-scroller-wrap no-print">
    <button type="button" class="room-scroll-btn" data-room-scroll="-1" aria-label="&lsaquo;"><i class="fas fa-chevron-left"></i></button>
    <div class="room-scroller" data-key="<?= htmlspecialchars($scrollerKey ?? '') ?>" role="tablist">
        <?php foreach ($scrollerRooms as $sr): ?>
            <button type="button" class="room-tab" role="tab" data-room="<?= htmlspecialchars((string) $sr['id']) ?>"
                    <?= !empty($sr['title']) ? 'title="' . htmlspecialchars($sr['title']) . '"' : '' ?>>
                <?php if (!empty($sr['icon'])): ?><i class="fas <?= htmlspecialchars($sr['icon']) ?>"></i><?php endif; ?>
                <?= htmlspecialchars($sr['name']) ?>
                <span class="count <?= htmlspecialchars($sr['count_class'] ?? '') ?>"><?= (int) $sr['count'] ?></span>
            </button>
        <?php endforeach; ?>
    </div>
    <button type="button" class="room-scroll-btn" data-room-scroll="1" aria-label="&rsaquo;"><i class="fas fa-chevron-right"></i></button>
</div>
