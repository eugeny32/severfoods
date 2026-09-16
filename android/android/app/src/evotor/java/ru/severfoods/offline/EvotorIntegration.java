package ru.severfoods.offline;

import android.app.Activity;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.os.Build;
import android.webkit.WebView;

import androidx.core.content.ContextCompat;

/**
 * Встроенный сканер смарт-терминала Эвотор.
 *
 * На планшете код приходит от внешнего сканера, который притворяется
 * клавиатурой, — его ловит сам интерфейс. На Эвоторе сканер принадлежит
 * системе: она рассылает отсканированный код широковещательным сообщением, и
 * получить его можно только приёмником с разрешением SCANNER_RECEIVER.
 *
 * Формат сверен по исходникам официальной библиотеки интеграции
 * (ru.evotor.framework.device.scanner.event.BarcodeReceivedEvent): действие
 * «ru.evotor.devices.ScannedCode», строковый параметр «ScannedCode».
 * Саму библиотеку не подключаем: она написана на Kotlin и притащила бы в
 * Java-проект весь Kotlin stdlib ради одной строки.
 *
 * Приёмник регистрируется динамически, пока экран активен, и снимается при
 * уходе в фон. Приёмник из манифеста был бы хуже: терминал будил бы наше
 * приложение поверх кассы на каждый скан в любом другом приложении.
 */
final class EvotorIntegration {

    private static final String ACTION_SCANNED     = "ru.evotor.devices.ScannedCode";
    private static final String EXTRA_SCANNED_CODE = "ScannedCode";
    private static final String SENDER_PERMISSION  = "ru.evotor.devices.SCANNER_SENDER";

    private static BroadcastReceiver receiver;

    private EvotorIntegration() {}

    /** Работает ли сборка на смарт-терминале (иначе — планшетная сборка). */
    static boolean isEvotor() {
        return true;
    }

    /** Подписка на сканер. Вызывается, когда экран приложения активен. */
    static void attach(final Activity activity, final WebView webView) {
        if (receiver != null) return;

        receiver = new BroadcastReceiver() {
            @Override
            public void onReceive(Context context, Intent intent) {
                if (intent == null) return;
                String code = intent.getStringExtra(EXTRA_SCANNED_CODE);
                if (code == null || code.trim().isEmpty()) return;
                deliver(activity, webView, code.trim());
            }
        };

        IntentFilter filter = new IntentFilter(ACTION_SCANNED);
        // Разрешение отправителя: принимаем сообщение только от системы
        // терминала, а не от любого приложения, решившего его подделать.
        //
        // RECEIVER_EXPORTED обязателен, начиная с Android 14: приложение с
        // targetSdk 34 обязано явно сказать, принимает ли приёмник сообщения
        // от других приложений, иначе регистрация падает с исключением.
        // Здесь сообщение приходит именно извне — от системной службы
        // терминала, поэтому EXPORTED. На старых версиях Android флаг
        // игнорируется, и ContextCompat сам выбирает нужный вызов.
        ContextCompat.registerReceiver(activity, receiver, filter, SENDER_PERMISSION,
                null, ContextCompat.RECEIVER_EXPORTED);
    }

    /** Отписка. Без неё Android ругается на утечку приёмника при закрытии. */
    static void detach(Activity activity) {
        if (receiver == null) return;
        try {
            activity.unregisterReceiver(receiver);
        } catch (IllegalArgumentException ignored) {
            // Приёмник уже снят системой — не повод падать.
        }
        receiver = null;
    }

    /**
     * Передаём код в интерфейс тем же путём, каким туда попадает ввод с
     * внешнего сканера: обработчик в приложении один (onEvotorBarcode в
     * core/evotor.js), и логика проверки сотрудника не раздваивается.
     */
    private static void deliver(Activity activity, final WebView webView, String code) {
        // Экранируем: в коде теоретически может оказаться кавычка, и без
        // экранирования она сломала бы выражение JavaScript.
        final String js = "window.onEvotorBarcode && window.onEvotorBarcode("
                + jsString(code) + ");";
        activity.runOnUiThread(new Runnable() {
            @Override
            public void run() {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.KITKAT) {
                    webView.evaluateJavascript(js, null);
                } else {
                    webView.loadUrl("javascript:" + js);
                }
            }
        });
    }

    private static String jsString(String s) {
        StringBuilder out = new StringBuilder("\"");
        for (int i = 0; i < s.length(); i++) {
            char c = s.charAt(i);
            switch (c) {
                case '"':  out.append("\\\""); break;
                case '\\': out.append("\\\\"); break;
                case '\n': out.append("\\n");  break;
                case '\r': out.append("\\r");  break;
                default:
                    if (c < 0x20) out.append(String.format("\\u%04x", (int) c));
                    else out.append(c);
            }
        }
        return out.append('"').toString();
    }
}
