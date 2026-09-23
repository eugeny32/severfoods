package ru.severfoods.offline;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.media.projection.MediaProjectionManager;
import android.os.Build;
import android.util.Log;
import android.widget.Toast;

/**
 * Точка входа в удалённый просмотр/управление экраном терминала (WebRTC).
 * Подробности архитектуры и почему это устроено именно через long polling,
 * а не WebSocket — см. api/remote_access.php и api/offline_sync.php на
 * сервере, комментарий в шапке каждого.
 *
 * Разрешение на захват экрана (MediaProjection) Android выдаёт только через
 * системный диалог с явным жестом пользователя — обойти это программно
 * нельзя и не должно быть можно. Поэтому логика в два шага:
 *
 *  1. Оператор ОДИН РАЗ (при настройке терминала) жмёт в приложении «Включить
 *     удалённый доступ» → requestScreenCapturePermission() → системный
 *     диалог → оператор подтверждает.
 *  2. Выданное разрешение (resultCode + Intent data) живёт в статических
 *     полях, то есть пока жив процесс приложения — этого достаточно, чтобы
 *     дальше запускать сколько угодно сеансов ПО КОМАНДЕ С СЕРВЕРА без
 *     нового системного диалога на каждый сеанс (как и работают привычные
 *     приложения удалённой поддержки на Android).
 *
 * Если процесс перезапущен (перезагрузка терминала, «Завершить» в списке
 * приложений) — разрешение теряется, и следующий сеанс снова потребует
 * присутствия человека у терминала. Это ограничение самой платформы, не
 * наше решение.
 */
final class RemoteAccess {

    private static final String TAG = "SeverFoods/Remote";
    static final int REQ_SCREEN_CAPTURE = 7302;

    private static int grantedResultCode = 0;
    private static Intent grantedData;

    // Сеанс, который попросили запустить до того, как разрешение было
    // получено (например, самый первый heartbeat после настройки) — как
    // только оператор подтвердит системный диалог, запускаем именно его.
    private static String pendingSessionId;
    private static String pendingIceServersJson;
    private static String pendingSyncEndpoint;
    private static String pendingSyncToken;

    private RemoteAccess() {}

    static boolean hasScreenCapturePermission() {
        return grantedData != null;
    }

    static void requestScreenCapturePermission(Activity activity) {
        MediaProjectionManager mgr =
            (MediaProjectionManager) activity.getSystemService(Context.MEDIA_PROJECTION_SERVICE);
        activity.startActivityForResult(mgr.createScreenCaptureIntent(), REQ_SCREEN_CAPTURE);
    }

    /** Вызывается из MainActivity.onActivityResult. Возвращает true, если результат был про нас. */
    static boolean onActivityResult(Activity activity, int requestCode, int resultCode, Intent data) {
        if (requestCode != REQ_SCREEN_CAPTURE) return false;

        if (resultCode != Activity.RESULT_OK || data == null) {
            Log.w(TAG, "Оператор не подтвердил захват экрана");
            Toast.makeText(activity, "Удалённая поддержка не включена: захват экрана не разрешён", Toast.LENGTH_LONG).show();
            return true;
        }

        grantedResultCode = resultCode;
        grantedData = data;
        Toast.makeText(activity, "Удалённая поддержка включена", Toast.LENGTH_SHORT).show();

        if (pendingSessionId != null) {
            startSession(activity, pendingSessionId, pendingIceServersJson, pendingSyncEndpoint, pendingSyncToken);
            pendingSessionId = null;
            pendingIceServersJson = null;
            pendingSyncEndpoint = null;
            pendingSyncToken = null;
        }
        return true;
    }

    /**
     * Запуск сеанса по команде с сервера (см. android/core/sync.js →
     * handleRemoteCommand, команда remote_screen). Если разрешение уже
     * выдавалось раньше в этом процессе — сеанс стартует сразу, без диалога.
     */
    static void startSession(Activity activity, String sessionId, String iceServersJson,
                              String syncEndpoint, String syncToken) {
        if (grantedData == null) {
            Log.w(TAG, "Запрошен удалённый экран, но разрешение ещё не выдавалось — откладываю");
            pendingSessionId = sessionId;
            pendingIceServersJson = iceServersJson;
            pendingSyncEndpoint = syncEndpoint;
            pendingSyncToken = syncToken;
            return;
        }

        Intent intent = new Intent(activity, RemoteScreenService.class);
        intent.putExtra(RemoteScreenService.EXTRA_RESULT_CODE, grantedResultCode);
        intent.putExtra(RemoteScreenService.EXTRA_RESULT_DATA, (Intent) grantedData.clone());
        intent.putExtra(RemoteScreenService.EXTRA_SESSION_ID, sessionId);
        intent.putExtra(RemoteScreenService.EXTRA_ICE_SERVERS_JSON, iceServersJson);
        intent.putExtra(RemoteScreenService.EXTRA_SYNC_ENDPOINT, syncEndpoint);
        intent.putExtra(RemoteScreenService.EXTRA_SYNC_TOKEN, syncToken);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            activity.startForegroundService(intent);
        } else {
            activity.startService(intent);
        }
    }
}
