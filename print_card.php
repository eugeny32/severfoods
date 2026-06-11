<?php
/**
 * Shared card renderer — included by print_qr.php and print_all_qr.php
 *
 * Expects:  $emp    array — employee row
 *           $qrSize int   — QR image px size passed to generateQRCode()
 *
 * Returns:  HTML string via output buffer
 */

function cardColors(string $role = ''): array {
    return match($role) {
        'super_admin' => [
            'bg'       => '#f0fdf4',  // very light green
            'header'   => '#166534',  // dark green
            'badge_bg' => '#bbf7d0',
            'badge_fg' => '#14532d',
            'label'    => 'Супер-администратор',
            'border'   => '#86efac',
        ],
        'admin' => [
            'bg'       => '#fff5f5',  // very light red
            'header'   => '#9b1c1c',
            'badge_bg' => '#fecaca',
            'badge_fg' => '#7f1d1d',
            'label'    => 'Администратор',
            'border'   => '#fca5a5',
        ],
        'operator' => [
            'bg'       => '#fff7ed',  // very light orange
            'header'   => '#c2410c',
            'badge_bg' => '#fed7aa',
            'badge_fg' => '#7c2d12',
            'label'    => 'Оператор',
            'border'   => '#fdba74',
        ],
        default => [
            'bg'       => '#ffffff',
            'header'   => '#003366',
            'badge_bg' => '#dbeafe',
            'badge_fg' => '#1e3a5f',
            'label'    => 'Сотрудник',
            'border'   => '#bfdbfe',
        ],
    };
}

function renderCard(array $emp, int $qrSize = 240): string {
    $valid   = isQrCodeValid($emp);
    $expires = $emp['qr_expires_at'] ? date('d.m.Y', strtotime($emp['qr_expires_at'])) : 'Бессрочно';
    $role    = $emp['role'] ?? '';
    $c       = cardColors($role);
    $qrText  = htmlspecialchars($emp['qr_code'], ENT_QUOTES);
    $fgColor = ltrim($c['header'], '#');

    ob_start(); ?>
<div class="qr-card" style="background:<?= $c['bg'] ?>;border-color:<?= $c['border'] ?>">
    <div class="card-header" style="background:<?= $c['header'] ?>">
        <img src="logo.png" alt="" onerror="this.style.display='none'">
        <div class="header-text">
            <div class="brand-name">СЕВЕР</div>
            <div class="brand-sub">Система контроля питания</div>
        </div>
        <div class="role-badge" style="background:rgba(255,255,255,.18);color:#fff">
            <?= htmlspecialchars($c['label']) ?>
        </div>
    </div>
    <div class="card-body">
        <div class="qr-wrap">
            <canvas class="qr-img" data-qr="<?= $qrText ?>" data-qr-fg="#<?= $fgColor ?>"></canvas>
        </div>
        <div class="emp-info">
            <div class="emp-name"><?= htmlspecialchars($emp['full_name']) ?></div>
            <?php if (!empty($emp['organization'])): ?>
            <div class="emp-meta"><?= htmlspecialchars($emp['organization']) ?></div>
            <?php endif; ?>
            <?php if (!empty($emp['department'])): ?>
            <div class="emp-meta"><?= htmlspecialchars($emp['department']) ?></div>
            <?php endif; ?>
            <?php if (!empty($emp['vjg_type'])): ?>
            <div class="emp-meta">ВЖГ: <?= htmlspecialchars($emp['vjg_type']) ?></div>
            <?php endif; ?>
            <div class="status-row">
                <span class="status-pill <?= $valid ? 'pill-ok' : 'pill-err' ?>"
                      style="<?= $valid ? "background:{$c['badge_bg']};color:{$c['badge_fg']}" : '' ?>">
                    <?= $valid ? '✓ Действителен' : '✗ Недействителен' ?>
                </span>
                <span class="expires">до <?= $expires ?></span>
            </div>
            <div class="qr-code-txt"><?= htmlspecialchars($emp['qr_code']) ?></div>
        </div>
    </div>
</div>
    <?php return ob_get_clean();
}
