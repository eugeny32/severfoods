/**
 * Настройки подключения — замена файлу .env из Windows-версии.
 *
 * Хранятся в той же базе (таблица sync_meta), а не в localStorage: localStorage
 * вебвью Android может быть очищен системой при нехватке места, и точка тихо
 * потеряла бы адрес сервера и токен.
 */
(function (global) {
    'use strict';

    const DEFAULT_SERVER = 'https://www.severfoods.ru';

    function serverUrl() {
        return (SFDb.getMeta('server_url') || DEFAULT_SERVER).replace(/\/$/, '');
    }

    function syncEndpoint() {
        return serverUrl() + '/api/offline_sync.php';
    }

    function syncToken() {
        return SFDb.getMeta('sync_token') || '';
    }

    function setServerUrl(url) {
        // В интерфейсе поле показывает полный адрес до offline_sync.php, а код
        // синхронизации достраивает путь сам — приводим к голому адресу, иначе
        // получилось бы .../api/offline_sync.php/api/offline_sync.php.
        const base = String(url || '').trim()
            .replace(/\/api\/offline_sync\.php\/?$/, '')
            .replace(/\/$/, '');
        SFDb.setMeta('server_url', base || DEFAULT_SERVER);
    }

    function setSyncToken(t) { SFDb.setMeta('sync_token', String(t || '')); }

    /** Первый запуск: сервер и токен ещё не введены. */
    function isConfigured() { return !!syncToken(); }

    global.SFSettings = {
        DEFAULT_SERVER, serverUrl, syncEndpoint, syncToken,
        setServerUrl, setSyncToken, isConfigured,
    };
})(window);
