// Временные учётные данные для coturn по механизму static-auth-secret —
// тот же принцип, что и в severfoods (см. src/functions.php →
// mintTurnCredentials в основном репозитории), просто здесь свой сервис
// не имеет доступа к той кодовой базе, поэтому продублировано локально.
const crypto = require('crypto');

function mintTurnCredentials(ttlSeconds = 3600) {
    const secret = process.env.TURN_SHARED_SECRET || '';
    const host = process.env.TURN_HOST || 'ntrip.host';
    if (!secret) return null;

    const username = `${Math.floor(Date.now() / 1000) + ttlSeconds}:remote-support`;
    const credential = crypto.createHmac('sha1', secret).update(username).digest('base64');

    return [
        { urls: `stun:${host}:3478` },
        { urls: `turn:${host}:3478?transport=udp`, username, credential },
        { urls: `turn:${host}:3478?transport=tcp`, username, credential },
    ];
}

module.exports = { mintTurnCredentials };
