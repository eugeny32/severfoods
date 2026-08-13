package ru.severfoods.offline;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/**
 * Автозапуск после включения планшета — аналог автозапуска Windows-версии
 * (app.setLoginItemSettings в offline/main.js). Терминал раздачи должен
 * подниматься сам: рядом с ним нет никого, кто запустит приложение вручную.
 */
public class BootReceiver extends BroadcastReceiver {

    @Override
    public void onReceive(Context context, Intent intent) {
        String action = intent != null ? intent.getAction() : null;
        if (action == null) return;
        if (!Intent.ACTION_BOOT_COMPLETED.equals(action)
                && !"android.intent.action.QUICKBOOT_POWERON".equals(action)) {
            return;
        }

        Intent launch = new Intent(context, MainActivity.class);
        launch.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        context.startActivity(launch);
    }
}
