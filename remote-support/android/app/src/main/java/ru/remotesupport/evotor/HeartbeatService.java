package ru.remotesupport.evotor;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.os.Build;
import android.os.IBinder;
import android.util.Log;

import androidx.annotation.Nullable;
import androidx.core.app.NotificationCompat;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.HashMap;
import java.util.Map;
import java.util.concurrent.atomic.AtomicBoolean;

/**
 * Раз в 20 секунд отчитывается серверу «я жива» и забирает очередь команд —
 * пока только одна команда: start_session (запустить трансляцию экрана).
 * Тот же принцип, что heartbeat в СеверФудс: устройство само инициирует
 * все запросы, входящего соединения к терминалу не требуется.
 */
public class HeartbeatService extends Service {
    private static final String TAG = "RemoteSupport/HB";
    private static final String CHANNEL_ID = "heartbeat";
    private static final int NOTIF_ID = 4100;
    private static final int INTERVAL_MS = 20000;

    private final AtomicBoolean stopped = new AtomicBoolean(false);
    private Thread loopThread;

    @Override
    public void onCreate() {
        super.onCreate();
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationManager nm = getSystemService(NotificationManager.class);
            nm.createNotificationChannel(new NotificationChannel(CHANNEL_ID, "Связь с сервером", NotificationManager.IMPORTANCE_MIN));
        }
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        Notification notif = new NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("Удалённая поддержка")
            .setContentText("Готово к сеансу поддержки")
            .setSmallIcon(android.R.drawable.stat_sys_download_done)
            .setOngoing(true)
            .build();
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(NOTIF_ID, notif, android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC);
        } else {
            startForeground(NOTIF_ID, notif);
        }

        if (loopThread == null) {
            loopThread = new Thread(this::loop, "RemoteSupportHeartbeat");
            loopThread.start();
        }
        return START_STICKY;
    }

    private void loop() {
        Prefs prefs = new Prefs(this);
        while (!stopped.get()) {
            try {
                if (prefs.isRegistered()) beatOnce(prefs);
            } catch (Exception e) {
                Log.w(TAG, "heartbeat failed: " + e.getMessage());
            }
            try { Thread.sleep(INTERVAL_MS); } catch (InterruptedException ignored) {}
        }
    }

    private void beatOnce(Prefs prefs) throws Exception {
        Map<String, String> headers = new HashMap<>();
        headers.put("X-Device-Token", prefs.deviceToken());
        JSONObject body = new JSONObject().put("device_id", prefs.deviceId());
        String resp = HttpUtil.request("POST", prefs.serverUrl() + "/api/heartbeat", headers, body.toString(), 15000);
        JSONObject json = new JSONObject(resp);
        if (!json.optBoolean("ok", false)) return;

        JSONArray commands = json.optJSONArray("commands");
        if (commands == null) return;
        for (int i = 0; i < commands.length(); i++) {
            JSONObject cmd = commands.getJSONObject(i);
            if ("start_session".equals(cmd.optString("command"))) {
                JSONObject payload = cmd.getJSONObject("payload");
                ScreenCapturePermission.startSession(this, payload.getString("session_id"), payload.getJSONArray("ice_servers").toString());
            }
        }
    }

    @Override
    public void onDestroy() {
        stopped.set(true);
        if (loopThread != null) loopThread.interrupt();
        super.onDestroy();
    }

    @Nullable @Override public IBinder onBind(Intent intent) { return null; }
}
