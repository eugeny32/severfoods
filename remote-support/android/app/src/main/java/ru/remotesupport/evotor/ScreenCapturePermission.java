package ru.remotesupport.evotor;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.media.projection.MediaProjectionManager;
import android.os.Build;
import android.util.Log;
import android.widget.Toast;

/**
 * Разрешение на захват экрана Android выдаёт только через системный диалог
 * с явным жестом пользователя — обойти это программно нельзя. Логика в два
 * шага: оператор один раз подтверждает диалог, дальше разрешение живёт, пока
 * жив процесс приложения, и все следующие сеансы стартуют по команде с
 * сервера без нового диалога. После перезапуска процесса разрешение
 * пропадает — это ограничение платформы, не наше решение.
 */
final class ScreenCapturePermission {
    private static final String TAG = "RemoteSupport";
    static final int REQ_CODE = 4101;

    private static int grantedResultCode = 0;
    private static Intent grantedData;

    private static String pendingSessionId;
    private static String pendingIceServersJson;

    private ScreenCapturePermission() {}

    static boolean isGranted() { return grantedData != null; }

    static void request(Activity activity) {
        MediaProjectionManager mgr =
            (MediaProjectionManager) activity.getSystemService(Context.MEDIA_PROJECTION_SERVICE);
        activity.startActivityForResult(mgr.createScreenCaptureIntent(), REQ_CODE);
    }

    static boolean onActivityResult(Activity activity, int requestCode, int resultCode, Intent data) {
        if (requestCode != REQ_CODE) return false;
        if (resultCode != Activity.RESULT_OK || data == null) {
            Toast.makeText(activity, "Удалённая поддержка не включена: захват экрана не разрешён", Toast.LENGTH_LONG).show();
            return true;
        }
        grantedResultCode = resultCode;
        grantedData = data;
        Toast.makeText(activity, "Удалённая поддержка включена", Toast.LENGTH_SHORT).show();

        if (pendingSessionId != null) {
            startSession(activity, pendingSessionId, pendingIceServersJson);
            pendingSessionId = null;
            pendingIceServersJson = null;
        }
        return true;
    }

    static void startSession(Context ctx, String sessionId, String iceServersJson) {
        if (grantedData == null) {
            Log.w(TAG, "Запрошен сеанс, но разрешение ещё не выдавалось — откладываю");
            pendingSessionId = sessionId;
            pendingIceServersJson = iceServersJson;
            return;
        }
        Intent intent = new Intent(ctx, RemoteScreenService.class);
        intent.putExtra(RemoteScreenService.EXTRA_RESULT_CODE, grantedResultCode);
        intent.putExtra(RemoteScreenService.EXTRA_RESULT_DATA, (Intent) grantedData.clone());
        intent.putExtra(RemoteScreenService.EXTRA_SESSION_ID, sessionId);
        intent.putExtra(RemoteScreenService.EXTRA_ICE_SERVERS_JSON, iceServersJson);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            ctx.startForegroundService(intent);
        } else {
            ctx.startService(intent);
        }
    }
}
