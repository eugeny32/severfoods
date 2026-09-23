package ru.severfoods.offline;

import android.app.Activity;
import android.content.Intent;

/**
 * Планшетная сборка: удалённого просмотра/управления экраном нет.
 *
 * Заглушка с той же сигнатурой, что и вариант из сборки evotor — см.
 * EvotorIntegration для того же приёма: MainActivity остаётся общей, какой
 * класс попадёт в APK решает флейвор при сборке.
 */
final class RemoteAccess {

    private RemoteAccess() {}

    static boolean hasScreenCapturePermission() {
        return false;
    }

    static void requestScreenCapturePermission(Activity activity) {
    }

    static boolean onActivityResult(Activity activity, int requestCode, int resultCode, Intent data) {
        return false;
    }

    static void startSession(Activity activity, String sessionId, String iceServersJson,
                              String syncEndpoint, String syncToken) {
    }
}
