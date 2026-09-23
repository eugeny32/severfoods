package ru.severfoods.offline;

import android.accessibilityservice.AccessibilityService;
import android.accessibilityservice.GestureDescription;
import android.graphics.Path;
import android.util.Log;
import android.view.accessibility.AccessibilityEvent;

/**
 * Эмулирует касания на экране терминала по командам от RemoteScreenService
 * (которые, в свою очередь, приходят от зрителя через WebRTC DataChannel).
 *
 * Включается оператором вручную один раз: Настройки Android → Специальные
 * возможности → СеверФудс (см. android/EVOTOR.md, раздел «Удалённый
 * доступ») — программно включить AccessibilityService приложение не может,
 * это осознанное ограничение платформы против шпионских приложений, и мы
 * его не обходим.
 *
 * Никаких событий экрана эта служба не читает (canRetrieveWindowContent
 * выключен в конфиге) — только dispatchGesture на вход.
 */
public class RemoteControlAccessibilityService extends AccessibilityService {

    private static final String TAG = "SeverFoods/RemoteCtl";

    private static RemoteControlAccessibilityService instance;

    private Path currentPath;
    private long strokeStartTime;
    private float lastX, lastY;

    static RemoteControlAccessibilityService getInstance() {
        return instance;
    }

    @Override
    protected void onServiceConnected() {
        super.onServiceConnected();
        instance = this;
        Log.i(TAG, "Служба эмуляции касаний подключена");
    }

    @Override
    public void onDestroy() {
        if (instance == this) instance = null;
        super.onDestroy();
    }

    @Override public void onAccessibilityEvent(AccessibilityEvent event) {}
    @Override public void onInterrupt() {}

    /** Начало касания — новый штрих. */
    void strokeDown(float x, float y) {
        currentPath = new Path();
        currentPath.moveTo(x, y);
        strokeStartTime = System.currentTimeMillis();
        lastX = x; lastY = y;
    }

    /** Протяжка пальца — добавляем точку в текущий штрих (для скролла/свайпа). */
    void strokeMove(float x, float y) {
        if (currentPath == null) { strokeDown(x, y); return; }
        currentPath.lineTo(x, y);
        lastX = x; lastY = y;
    }

    /** Отпускание — отправляем накопленный штрих терминалу одним жестом. */
    void strokeUp(float x, float y) {
        if (currentPath == null) { strokeDown(x, y); }
        currentPath.lineTo(x, y);

        long duration = Math.max(16, System.currentTimeMillis() - strokeStartTime);
        // Жест не может длиться дольше пары секунд — иначе dispatchGesture
        // его просто отклонит; долгая пауза между down и up у зрителя (он
        // отвлёкся, не отпуская мышь) не должна ронять всю сессию.
        duration = Math.min(duration, 2000);

        GestureDescription.Builder builder = new GestureDescription.Builder();
        builder.addStroke(new GestureDescription.StrokeDescription(currentPath, 0, duration));

        dispatchGesture(builder.build(), null, null);
        currentPath = null;
    }
}
