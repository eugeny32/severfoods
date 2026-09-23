// Временные учётные данные для coturn по механизму static-auth-secret —
// тот же принцип, что и в severfoods (см. src/functions.php →
// mintTurnCredentials в основном репозитории), просто здесь свой сервис
// не имеет доступа к той кодовой базе, поэтому продублировано локально.
const crypto = require('crypto');

function mintTurnCredentials(ttlSeconds = 3600) {
    const secret = process.env.TURN_SHARED_SECRET || '';
    const host = process.env.TURN_HOST || 'ntrip.host';
    // Порты — не всегда стандартные 3478/5349 (у coturn это listening-port/tls-listening-port
    // в /etc/turnserver.conf, на конкретном сервере они могут быть переопределены), поэтому
    // настраиваются через .env, а не зашиты в коде.
    const port = process.env.TURN_PORT || '3478';
    const tlsPort = process.env.TURN_TLS_PORT || '5349';
    if (!secret) return null;

    const username = `${Math.floor(Date.now() / 1000) + ttlSeconds}:remote-support`;
    const credential = crypto.createHmac('sha1', secret).update(username).digest('base64');

    return [
        { urls: `stun:${host}:${port}` },
        { urls: `turn:${host}:${port}?transport=udp`, username, credential },
        { urls: `turn:${host}:${port}?transport=tcp`, username, credential },
        { urls: `turns:${host}:${tlsPort}?transport=tcp`, username, credential },
    ];
}

module.exports = { mintTurnCredentials };
