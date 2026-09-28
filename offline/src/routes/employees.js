const router = require('express').Router();
const db     = require('../db');

// GET /api/employees — list all active employees
router.get('/', (req, res) => {
    const q    = (req.query.q || '').toLowerCase();
    let rows   = db.getAllEmployees();
    if (q) {
        rows = rows.filter(e =>
            e.full_name.toLowerCase().includes(q) ||
            (e.organization || '').toLowerCase().includes(q) ||
            (e.department   || '').toLowerCase().includes(q)
        );
    }
    res.json({ ok: true, employees: rows });
});

// GET /api/employees/scan?qr=... — look up by QR code
//
// Зеркалит проверки processAccess() из src/functions.php (онлайн-версия):
// getEmployeeByQr() фильтрует только is_active, но не qr_status — без этой
// проверки здесь заблокированный (qr_status='blocked') или просроченный
// QR-код сотрудника, который всё ещё is_active, проходил бы офлайн-сканер
// как обычный успешный проход.
router.get('/scan', (req, res) => {
    const qr  = req.query.qr || '';
    const emp = db.getEmployeeByQr(qr);
    if (!emp) return res.status(404).json({ ok: false, error: 'not_found' });
    if (emp.qr_status === 'blocked') {
        return res.status(403).json({ ok: false, error: 'blocked', message: 'QR-код заблокирован' });
    }
    if (emp.qr_expires_at && emp.qr_expires_at < new Date().toISOString().slice(0, 10)) {
        return res.status(403).json({ ok: false, error: 'expired', message: 'Срок действия QR-кода истёк' });
    }
    res.json({ ok: true, employee: emp });
});

module.exports = router;
