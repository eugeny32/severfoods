package ru.severfoods.offline;

import android.Manifest;
import android.app.ActivityManager;
import android.content.Context;
import android.content.pm.PackageManager;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.view.View;
import android.view.WindowManager;
import android.webkit.JavascriptInterface;
import android.webkit.PermissionRequest;
import android.webkit.WebChromeClient;
import android.webkit.WebView;

import androidx.annotation.NonNull;
import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;

import com.getcapacitor.BridgeActivity;

import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.Iterator;

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
 *
 * Сборка для смарт-терминала Эвотор (флейвор evotor) ведёт себя иначе:
 * терминал принадлежит кассиру, а не нам. Экран не захватывается, системные
 * панели не прячутся — человек обязан в любой момент вернуться в меню
 * Эвотора. Зато подключается встроенный сканер терминала, который на планшете
 * не нужен. Различия собраны в EvotorIntegration и флаге BuildConfig.EVOTOR.
 */
public class MainActivity extends BridgeActivity {

    private static final int REQ_CAMERA = 7301;
    private PermissionRequest pendingCameraRequest;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // Экран не должен гаснуть: оператор не будит планшет перед каждым
        // сканированием, а спящий вебвью не выполняет и синхронизацию.
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);

        // Приложение занимает экран целиком. Экранное закрепление само по себе
        // строку состояния и кнопки навигации не убирает — сверху оставалась
        // пустая полоса, в которой всё равно ничего не показывалось. Заряд и
        // сеть вместо неё выводятся в шапке приложения (core/status.js).
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            getWindow().getAttributes().layoutInDisplayCutoutMode =
                WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES;
        }
        if (!BuildConfig.EVOTOR) hideSystemBars();

        WebView webView = getBridge().getWebView();
        webView.addJavascriptInterface(new NativeBridge(), "SFNative");

        // Разрешение на камеру внутри вебвью.
        //
        // Разрешений здесь ДВА, и их часто путают. Вебвью спрашивает своё —
        // «можно ли странице обратиться к камере», и его мы выдаём сами. Но
        // выдать можно только то, что есть у самого приложения, а системное
        // разрешение на камеру, начиная с Android 6, надо ещё и запросить у
        // пользователя в момент использования. Без этого шага getUserMedia
        // молча отказывает, и запасное сканирование камерой не работает
        // вообще — ни на планшете, ни на терминале.
        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onPermissionRequest(final PermissionRequest request) {
                runOnUiThread(() -> handleWebPermission(request));
            }
        });

        if (BuildConfig.EVOTOR) {
            EvotorIntegration.attach(this, webView);
        } else {
            pinScreen();
        }
    }

    /** Возвращаем закрепление, если оператор вышел и вернулся в приложение. */
    @Override
    public void onResume() {
        super.onResume();
        if (BuildConfig.EVOTOR) {
            // Подписка живёт, только пока экран активен: иначе мы ловили бы
            // чужие сканы, пока кассир работает в другом приложении.
            EvotorIntegration.attach(this, getBridge().getWebView());
            return;
        }
        pinScreen();
        hideSystemBars();
    }

    @Override
    public void onPause() {
        super.onPause();
        if (BuildConfig.EVOTOR) EvotorIntegration.detach(this);
    }

    /**
     * Возврат в полноэкранный режим после того, как системные панели показались.
     * Свайп от края временно выводит их даже в «липком» режиме — без этого
     * обработчика полоса могла остаться на экране до перезапуска.
     */
    @Override
    public void onWindowFocusChanged(boolean hasFocus) {
        super.onWindowFocusChanged(hasFocus);
        if (hasFocus && !BuildConfig.EVOTOR) hideSystemBars();
    }

    /**
     * Прячет строку состояния и кнопки навигации.
     *
     * Флаги устарели в пользу WindowInsetsController, но продолжают работать на
     * всех версиях, включая старые планшеты (minSdk 22), и не требуют
     * дополнительных зависимостей. IMMERSIVE_STICKY возвращает панели обратно
     * сам, если оператор случайно вызвал их свайпом.
     */
    private void hideSystemBars() {
        getWindow().getDecorView().setSystemUiVisibility(
                View.SYSTEM_UI_FLAG_LAYOUT_STABLE
              | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
              | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
              | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
              | View.SYSTEM_UI_FLAG_FULLSCREEN
              | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY);
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

    /**
     * Запрос вебвью на доступ к камере. Если системного разрешения ещё нет —
     * спрашиваем его у пользователя и отвечаем странице уже по результату.
     */
    private void handleWebPermission(PermissionRequest request) {
        boolean wantsCamera = false;
        for (String r : request.getResources()) {
            if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(r)) wantsCamera = true;
        }

        if (!wantsCamera
                || ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA)
                   == PackageManager.PERMISSION_GRANTED) {
            request.grant(request.getResources());
            return;
        }

        pendingCameraRequest = request;
        ActivityCompat.requestPermissions(this,
                new String[]{ Manifest.permission.CAMERA }, REQ_CAMERA);
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, @NonNull String[] permissions,
                                           @NonNull int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode != REQ_CAMERA || pendingCameraRequest == null) return;

        boolean granted = grantResults.length > 0
                && grantResults[0] == PackageManager.PERMISSION_GRANTED;
        // Отказ тоже надо сообщить явно: иначе страница будет ждать ответа
        // вечно и камера просто не откроется без всякого объяснения.
        if (granted) pendingCameraRequest.grant(pendingCameraRequest.getResources());
        else         pendingCameraRequest.deny();
        pendingCameraRequest = null;
    }

    private class NativeBridge {

        /**
         * Где мы работаем. Интерфейс по этому флагу убирает то, чего на
         * смарт-терминале быть не должно: жесты выхода из киоска и
         * самообновление своим APK (на Эвоторе обновления приходят из
         * Эвотор.Маркета).
         */
        @JavascriptInterface
        public boolean isEvotor() {
            return BuildConfig.EVOTOR;
        }

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

        /**
         * Запрос к серверу в обход сетевого стека WebView (Chromium/Cronet).
         *
         * На живом терминале Эвотор 7.3 подтверждено: обычный интернет на
         * устройстве есть (работает удалённый доступ, штатные функции
         * терминала), а из WebView не проходит вообще ни один HTTPS-запрос —
         * ни к нашему серверу, ни к google.com. Настройка проксирования в
         * кабинете Эвотора (вкладка «Интеграция») на это не повлияла. Это
         * значит, что дело именно в сетевом стеке WebView, а не в самом
         * устройстве — java.net.HttpURLConnection идёт другим путём.
         *
         * Ответ уходит обратно в JS асинхронно через evaluateJavascript,
         * потому что JavascriptInterface не может возвращать Promise.
         */
        @JavascriptInterface
        public void httpRequest(String reqId, String method, String url,
                                 String headersJson, String body, int timeoutMs) {
            new Thread(() -> {
                String resultJson;
                try {
                    resultJson = doHttpRequest(method, url, headersJson, body, timeoutMs);
                } catch (Exception e) {
                    try {
                        resultJson = new JSONObject()
                            .put("ok", false)
                            .put("error", e.getClass().getSimpleName() + ": " + e.getMessage())
                            .toString();
                    } catch (Exception inner) {
                        resultJson = "{\"ok\":false,\"error\":\"internal\"}";
                    }
                }
                final String js = "window.__evotorNetCallback && window.__evotorNetCallback("
                    + JSONObject.quote(reqId) + "," + resultJson + ")";
                runOnUiThread(() -> {
                    WebView wv = getBridge().getWebView();
                    if (wv != null) wv.evaluateJavascript(js, null);
                });
            }).start();
        }

        private String doHttpRequest(String method, String urlStr, String headersJson,
                                      String body, int timeoutMs) throws Exception {
            HttpURLConnection conn = (HttpURLConnection) new URL(urlStr).openConnection();
            try {
                conn.setRequestMethod(method);
                conn.setConnectTimeout(timeoutMs);
                conn.setReadTimeout(timeoutMs);

                JSONObject headers = new JSONObject(headersJson == null ? "{}" : headersJson);
                Iterator<String> keys = headers.keys();
                while (keys.hasNext()) {
                    String k = keys.next();
                    conn.setRequestProperty(k, headers.getString(k));
                }

                if (body != null && !body.isEmpty()) {
                    conn.setDoOutput(true);
                    try (OutputStream os = conn.getOutputStream()) {
                        os.write(body.getBytes(StandardCharsets.UTF_8));
                    }
                }

                int status = conn.getResponseCode();
                InputStream is = (status >= 200 && status < 400) ? conn.getInputStream() : conn.getErrorStream();
                String respBody = is == null ? "" : readAll(is);

                return new JSONObject()
                    .put("ok", true)
                    .put("status", status)
                    .put("body", respBody)
                    .toString();
            } finally {
                conn.disconnect();
            }
        }

        private String readAll(InputStream is) throws Exception {
            ByteArrayOutputStream buf = new ByteArrayOutputStream();
            byte[] chunk = new byte[4096];
            int n;
            while ((n = is.read(chunk)) != -1) buf.write(chunk, 0, n);
            return buf.toString("UTF-8");
        }
    }
}
