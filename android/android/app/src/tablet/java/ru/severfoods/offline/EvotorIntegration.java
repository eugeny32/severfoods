package ru.severfoods.offline;

import android.app.Activity;
import android.webkit.WebView;

/**
 * Планшетная сборка: интеграции с Эвотором нет.
 *
 * Заглушка с той же сигнатурой, что и вариант из сборки evotor. Так
 * MainActivity остаётся общей и не обрастает проверками «а на чём мы сейчас»:
 * какой класс попадёт в APK, решает флейвор при сборке.
 */
final class EvotorIntegration {

    private EvotorIntegration() {}

    static boolean isEvotor() {
        return false;
    }

    static void attach(Activity activity, WebView webView) {
        // На планшете код приходит от внешнего сканера как ввод с клавиатуры —
        // его ловит сам интерфейс, подписываться не на что.
    }

    static void detach(Activity activity) {
    }
}
