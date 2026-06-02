/* ── Shared QR-card styles ── */
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Onest',sans-serif; }

.qr-card {
    border-radius: 16px;
    overflow: hidden;
    border: 1.5px solid #e2e8f0;
    box-shadow: 0 4px 20px rgba(0,0,0,.10);
    page-break-inside: avoid;
    break-inside: avoid;
}

/* ── Header ── */
.card-header {
    padding: 11px 14px 10px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.card-header img {
    height: 38px;
    width: auto;
    max-width: 80px;
    object-fit: contain;
    flex-shrink: 0;
    filter: brightness(0) invert(1);
}
.header-text { flex: 1; min-width: 0; }
.brand-name  { color: #fff; font-size: 15px; font-weight: 800; letter-spacing: .5px; line-height: 1.1; }
.brand-sub   { color: rgba(255,255,255,.65); font-size: 9px; font-weight: 500; margin-top: 1px; }
.role-badge  {
    font-size: 9px; font-weight: 700;
    padding: 3px 7px; border-radius: 20px;
    white-space: nowrap; flex-shrink: 0;
    letter-spacing: .3px;
}

/* ── Body: QR left, info right ── */
.card-body {
    display: flex;
    align-items: center;
    gap: 0;
    padding: 12px 14px 12px 12px;
}
.qr-wrap { flex-shrink: 0; }
.qr-img  {
    display: block;
    width: 108px; height: 108px;
    border-radius: 8px;
    border: 1px solid rgba(0,0,0,.08);
    padding: 4px;
    background: #fff;
}
.emp-info { flex: 1; min-width: 0; padding-left: 12px; }
.emp-name {
    font-size: 13px; font-weight: 800;
    color: #0f172a; line-height: 1.2;
    margin-bottom: 4px;
    word-break: break-word;
}
.emp-meta {
    font-size: 10.5px; color: #475569; font-weight: 500;
    margin-bottom: 2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.status-row {
    display: flex; align-items: center; gap: 6px;
    margin-top: 7px; flex-wrap: wrap;
}
.status-pill {
    font-size: 9.5px; font-weight: 700;
    padding: 2px 8px; border-radius: 20px;
    white-space: nowrap;
}
.pill-err { background: #fee2e2; color: #991b1b; }
.expires  { font-size: 9.5px; color: #64748b; font-weight: 500; }
.qr-code-txt {
    font-size: 8px; color: #94a3b8;
    font-family: monospace;
    margin-top: 5px;
    word-break: break-all;
}
