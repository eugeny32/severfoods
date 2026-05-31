/**
 * =====================================================
 *  QR INPUT HELPER  v2.1
 *  ─────────────────────────────────────────────────
 *  1. Автоперевод RU QWERTY → EN (keydown/input/paste)
 *  2. Автофокус на QR-поле через 5 сек без активности
 *  3. Floating-пилюли под шапкой (layout + обратный
 *     отсчёт). На мобильных idle-счётчик скрыт через CSS
 * =====================================================
 */
'use strict';

// ── RU QWERTY → EN ───────────────────────────────────
const RU_TO_EN = {
    'й':'q','ц':'w','у':'e','к':'r','е':'t','н':'y','г':'u','ш':'i','щ':'o','з':'p',
    'х':'[','ъ':']','ф':'a','ы':'s','в':'d','а':'f','п':'g','р':'h','о':'j','л':'k',
    'д':'l','ж':';','э':"'",'я':'z','ч':'x','с':'c','м':'v','и':'b','т':'n','ь':'m',
    'б':',','ю':'.','Й':'Q','Ц':'W','У':'E','К':'R','Е':'T','Н':'Y','Г':'U','Ш':'I',
    'Щ':'O','З':'P','Х':'{','Ъ':'}','Ф':'A','Ы':'S','В':'D','А':'F','П':'G','Р':'H',
    'О':'J','Л':'K','Д':'L','Ж':':','Э':'"','Я':'Z','Ч':'X','С':'C','М':'V','И':'B',
    'Т':'N','Ь':'M','Б':'<','Ю':'>',
};

function convertLayout(str) {
    if (!str) return str;
    return str.split('').map(ch => RU_TO_EN[ch] ?? ch).join('');
}

// ── Навешивает конвертер ──────────────────────────────
function attachLayoutConverter(input) {
    if (!input || input._qrLayout) return;
    input._qrLayout = true;

    // keydown: перехватываем RU-символ до записи в поле
    input.addEventListener('keydown', function (e) {
        const ch = e.key;
        if (!ch || ch.length !== 1 || !RU_TO_EN[ch]) return;
        e.preventDefault();
        const s  = this.selectionStart ?? this.value.length;
        const x  = this.selectionEnd   ?? this.value.length;
        const en = RU_TO_EN[ch];
        this.value = this.value.slice(0, s) + en + this.value.slice(x);
        const p = s + 1;
        try { this.setSelectionRange(p, p); } catch (_) {}
        this.dispatchEvent(new Event('input', { bubbles: true }));
        showLayoutPill();
    });

    // input: страховка для IME / автодополнения
    input.addEventListener('input', function () {
        const converted = convertLayout(this.value);
        if (converted !== this.value) {
            const p = this.selectionStart;
            this.value = converted;
            try { this.setSelectionRange(p, p); } catch (_) {}
            showLayoutPill();
        }
    });

    // paste: вставка из буфера
    input.addEventListener('paste', function (e) {
        e.preventDefault();
        const raw       = (e.clipboardData || window.clipboardData).getData('text');
        const converted = convertLayout(raw.trim());
        const s = this.selectionStart ?? 0;
        const x = this.selectionEnd   ?? this.value.length;
        this.value = this.value.slice(0, s) + converted + this.value.slice(x);
        const p = s + converted.length;
        try { this.setSelectionRange(p, p); } catch (_) {}
        this.dispatchEvent(new Event('input', { bubbles: true }));
        if (raw !== converted) showLayoutPill();
    });
}

// ── Floating: Layout pill ─────────────────────────────
let _layoutTimer = null;
function showLayoutPill() {
    const el = document.getElementById('qsfLayout');
    if (!el) return;
    el.classList.add('visible');
    clearTimeout(_layoutTimer);
    _layoutTimer = setTimeout(() => el.classList.remove('visible'), 2500);
}

// ── Floating: Idle countdown pill ────────────────────
const IDLE_MS  = 5000;
let _idleMain  = null;
let _idleTick  = null;
let _idleTarget = null;

function _getIdleEl()    { return document.getElementById('qsfIdle'); }

function _clearCountdown() {
    clearInterval(_idleTick);
    _idleTick = null;
    const el = _getIdleEl();
    if (el) el.classList.remove('visible');
}

function _startCountdown() {
    _clearCountdown();
    const el = _getIdleEl();
    if (!el) return;
    let rem = Math.ceil(IDLE_MS / 1000);
    el.textContent = '⏱ ' + rem + 'с';
    el.classList.add('visible');
    _idleTick = setInterval(() => {
        rem--;
        if (rem <= 0) { _clearCountdown(); }
        else { el.textContent = '⏱ ' + rem + 'с'; }
    }, 1000);
}

function _doFocus() {
    _clearCountdown();
    if (!_idleTarget) return;
    const el = typeof _idleTarget.getEl === 'function'
        ? _idleTarget.getEl() : _idleTarget.el;
    if (!el || !el.offsetParent) return;
    if (document.activeElement === el) return;
    const cond = _idleTarget.condition;
    if (typeof cond === 'function' && !cond()) return;
    const tag = (document.activeElement?.tagName || '').toLowerCase();
    if (['input','textarea','select'].includes(tag)) return;
    el.focus({ preventScroll: true });
}

function _resetIdle() {
    _clearCountdown();
    clearTimeout(_idleMain);
    // Запускаем отсчёт чуть позже — чтобы не мигало при обычной работе
    _idleMain = setTimeout(() => {
        _startCountdown();
        // Сам фокус — через IDLE_MS после начала отсчёта
        setTimeout(_doFocus, IDLE_MS);
    }, 200);
}

function startIdleWatcher(target) {
    _idleTarget = target;
    const EVTS = ['mousemove','mousedown','touchstart','touchmove',
                  'keydown','keyup','scroll','wheel','click'];
    EVTS.forEach(ev => document.addEventListener(ev, _resetIdle, { passive: true }));
    _resetIdle();
}

// ── DOMContentLoaded ─────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

    // ═══ index.php ══════════════════════════════════
    const qrInput = document.getElementById('qrInput');
    if (qrInput) {
        attachLayoutConverter(qrInput);
        startIdleWatcher({
            getEl:     () => document.getElementById('qrInput'),
            condition: () => !document.querySelector('.modal-overlay.open')
                          && !document.querySelector('.camera-overlay.open'),
        });
    }

    // ═══ login.php ══════════════════════════════════
    if (document.getElementById('formOperator')) {
        ['formOperator','formAdmin'].forEach(fId => {
            const form = document.getElementById(fId);
            if (!form) return;
            form.querySelectorAll('input').forEach(inp => {
                if (inp.type !== 'password') attachLayoutConverter(inp);
            });
        });
        startIdleWatcher({
            getEl: () => {
                const f = document.querySelector('.form.active');
                return f ? f.querySelector('input[name=login]') : null;
            },
            condition: () => true,
        });
    }
});

window.QRInput = { convertLayout, attachLayoutConverter };
