package ru.severfoods.offline;

import android.app.ActivityManager;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.view.WindowManager;
import android.webkit.JavascriptInterface;
import android.webkit.PermissionRequest;
import android.webkit.WebChromeClient;
import android.webkit.WebView;

import com.getcapacitor.BridgeActivity;

/**
 * Терминал раздачи на планшете.
 *
 * Windows-версия работает в режиме киоска (полный экран, рабочий стол
 * заблокирован, выход — секретным жестом). Здесь то же самое делается штатным
 * средством Android — экранным закреплением (screen pinning): планшет не
 * требует предварительной подготовки, а выйти можно только осознанно.
 *
 * Мостик в интерфейс называется window.SFNative и объявлен в core/boot.js —
 * туда уходит выход из закрепления по тому же жесту, что и на Windows
 * (долгое нажатие по логотипу).
 */
public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // Экран не должен гаснуть: оператор не будит планшет перед каждым
        // сканированием, а спящий вебвью не выполняет и синхронизацию.
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);

        WebView webView = getBridge().getWebView();
        webView.addJavascriptInterface(new NativeBridge(), "SFNative");

        // Разрешение на камеру внутри вебвью. Без этого getUserMedia молча
        // отказывает, и запасное сканирование камерой не работает вовсе —
        // системного разрешения приложению для этого НЕ достаточно.
        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onPermissionRequest(final PermissionRequest request) {
                runOnUiThread(() -> request.grant(request.getResources()));
            }
        });

        pinScreen();
    }

    /** Возвращаем закрепление, если оператор вышел и вернулся в приложение. */
    @Override
    protected void onResume() {
        super.onResume();
        pinScreen();
    }

    private void pinScreen() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.LOLLIPOP) return;
        try {
            ActivityManager am = (ActivityManager) getSystemService(Context.ACTIVITY_SERVICE);
            if (am != null && am.getLockTaskModeState() != ActivityManager.LOCK_TASK_MODE_NONE) return;
            startLockTask();
        } catch (Exception e) {
            // На части устройств закрепление отключено политикой — это не повод
            // ронять приложение: терминал должен работать и без него.
            android.util.Log.w("SeverFoods", "Экранное закрепление недоступно: " + e.getMessage());
        }
    }

    private class NativeBridge {

        /** Выход из закрепления — секретным жестом из интерфейса. */
        @JavascriptInterface
        public void unpin() {
            runOnUiThread(() -> {
                try { stopLockTask(); } catch (Exception ignored) {}
            });
        }

        @JavascriptInterface
        public void pin() {
            runOnUiThread(MainActivity.this::pinScreen);
        }

        /**
         * Отдаёт ссылку системе — так скачивается APK обновления, а система
         * сама показывает диалог установки.
         */
        @JavascriptInterface
        public void openUrl(String url) {
            runOnUiThread(() -> {
                try {
                    Intent i = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
                    i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                    startActivity(i);
                } catch (Exception e) {
                    android.util.Log.e("SeverFoods", "Не удалось открыть ссылку: " + e.getMessage());
                }
            });
        }
    }
}
