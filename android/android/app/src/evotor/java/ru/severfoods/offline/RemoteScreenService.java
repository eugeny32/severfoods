package ru.severfoods.offline;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.Intent;
import android.graphics.PixelFormat;
import android.hardware.display.DisplayManager;
import android.hardware.display.VirtualDisplay;
import android.media.projection.MediaProjection;
import android.media.projection.MediaProjectionManager;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.util.DisplayMetrics;
import android.util.Log;
import android.view.WindowManager;

import androidx.annotation.Nullable;
import androidx.core.app.NotificationCompat;

import org.json.JSONArray;
import org.json.JSONObject;
import org.webrtc.DataChannel;
import org.webrtc.DefaultVideoDecoderFactory;
import org.webrtc.DefaultVideoEncoderFactory;
import org.webrtc.EglBase;
import org.webrtc.IceCandidate;
import org.webrtc.MediaConstraints;
import org.webrtc.MediaStream;
import org.webrtc.PeerConnection;
import org.webrtc.PeerConnectionFactory;
import org.webrtc.RtpReceiver;
import org.webrtc.ScreenCapturerAndroid;
import org.webrtc.SdpObserver;
import org.webrtc.SessionDescription;
import org.webrtc.SurfaceTextureHelper;
import org.webrtc.VideoSource;
import org.webrtc.VideoTrack;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * Захват и трансляция экрана терминала одному подключившемуся зрителю
 * (веб-страница remote_viewer.php) + приём команд управления обратно через
 * WebRTC DataChannel, эмулируемых через RemoteControlAccessibilityService.
 *
 * Обмен служебными сообщениями WebRTC (offer/answer/ice) идёт HTTP-опросом
 * api/offline_sync.php (действия remote_signal / remote_signal_poll) — тем
 * же принципом, что обычная синхронизация: сервис сам инициирует все
 * запросы, входящих соединений к терминалу не требуется.
 */
public class RemoteScreenService extends Service {

    private static final String TAG = "SeverFoods/RemoteScreen";
    private static final String CHANNEL_ID = "remote_screen";
    private static final int NOTIF_ID = 7302;
    private static final int POLL_INTERVAL_MS = 1000;
    private static final int SIGNAL_TIMEOUT_MS = 15000;

    static final String EXTRA_RESULT_CODE       = "result_code";
    static final String EXTRA_RESULT_DATA       = "result_data";
    static final String EXTRA_SESSION_ID        = "session_id";
    static final String EXTRA_ICE_SERVERS_JSON  = "ice_servers_json";
    static final String EXTRA_SYNC_ENDPOINT     = "sync_endpoint";
    static final String EXTRA_SYNC_TOKEN        = "sync_token";

    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private final AtomicBoolean stopped = new AtomicBoolean(false);
    private final AtomicInteger signalCursor = new AtomicInteger(0);

    private MediaProjection mediaProjection;
    private EglBase eglBase;
    private PeerConnectionFactory factory;
    private PeerConnection peerConnection;
    private ScreenCapturerAndroid capturer;
    private VideoSource videoSource;
    private SurfaceTextureHelper surfaceTextureHelper;
    private DataChannel controlChannel;
    private Thread pollThread;

    private String sessionId;
    private String syncEndpoint;
    private String syncToken;
    private int captureWidthPx, captureHeightPx;

    @Override
    public void onCreate() {
        super.onCreate();
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationManager nm = getSystemService(NotificationManager.class);
            NotificationChannel ch = new NotificationChannel(
                CHANNEL_ID, "Удалённый доступ", NotificationManager.IMPORTANCE_LOW);
            nm.createNotificationChannel(ch);
        }
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (intent == null) { stopSelf(); return START_NOT_STICKY; }

        Notification notif = new NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("СеверФудс — удалённый доступ")
            .setContentText("Идёт сеанс удалённой поддержки")
            .setSmallIcon(android.R.drawable.presence_video_online)
            .setOngoing(true)
            .build();
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            startForeground(NOTIF_ID, notif,
                android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PROJECTION);
        } else {
            startForeground(NOTIF_ID, notif);
        }

        sessionId    = intent.getStringExtra(EXTRA_SESSION_ID);
        syncEndpoint = intent.getStringExtra(EXTRA_SYNC_ENDPOINT);
        syncToken    = intent.getStringExtra(EXTRA_SYNC_TOKEN);
        int resultCode   = intent.getIntExtra(EXTRA_RESULT_CODE, 0);
        Intent resultData = intent.getParcelableExtra(EXTRA_RESULT_DATA);
        String iceServersJson = intent.getStringExtra(EXTRA_ICE_SERVERS_JSON);

        if (sessionId == null || resultData == null) {
            Log.e(TAG, "Недостаточно данных для запуска сеанса");
            stopSelf();
            return START_NOT_STICKY;
        }

        try {
            start(resultCode, resultData, iceServersJson);
        } catch (Exception e) {
            Log.e(TAG, "Не удалось запустить сеанс: " + e.getMessage(), e);
            stopSelf();
        }
        return START_NOT_STICKY;
    }

    private void start(int resultCode, Intent resultData, String iceServersJson) throws Exception {
        MediaProjectionManager mgr =
            (MediaProjectionManager) getSystemService(Context.MEDIA_PROJECTION_SERVICE);
        mediaProjection = mgr.getMediaProjection(resultCode, resultData);

        DisplayMetrics dm = new DisplayMetrics();
        WindowManager wm = (WindowManager) getSystemService(Context.WINDOW_SERVICE);
        wm.getDefaultDisplay().getRealMetrics(dm);
        captureWidthPx  = dm.widthPixels;
        captureHeightPx = dm.heightPixels;
        int dpi = dm.densityDpi;

        eglBase = EglBase.create();
        PeerConnectionFactory.initialize(
            PeerConnectionFactory.InitializationOptions.builder(getApplicationContext())
                .createInitializationOptions());
        factory = PeerConnectionFactory.builder()
            .setVideoEncoderFactory(new DefaultVideoEncoderFactory(eglBase.getEglBaseContext(), true, true))
            .setVideoDecoderFactory(new DefaultVideoDecoderFactory(eglBase.getEglBaseContext()))
            .createPeerConnectionFactory();

        videoSource = factory.createVideoSource(true /* isScreencast */);
        surfaceTextureHelper = SurfaceTextureHelper.create("SeverFoodsRemoteCapture", eglBase.getEglBaseContext());

        capturer = new ScreenCapturerAndroid(resultData, new MediaProjection.Callback() {
            @Override public void onStop() {
                Log.w(TAG, "MediaProjection остановлен системой");
                stopSelf();
            }
        });
        capturer.initialize(surfaceTextureHelper, getApplicationContext(), videoSource.getCapturerObserver());
        // 12 fps достаточно для просмотра/поддержки, не для игр — экономит
        // канал и CPU терминала, которому ещё раздачу питания обслуживать.
        capturer.startCapture(captureWidthPx, captureHeightPx, 12);

        VideoTrack videoTrack = factory.createVideoTrack("severfoods-screen", videoSource);

        List<PeerConnection.IceServer> iceServers = parseIceServers(iceServersJson);
        PeerConnection.RTCConfiguration rtcConfig = new PeerConnection.RTCConfiguration(iceServers);
        rtcConfig.sdpSemantics = PeerConnection.SdpSemantics.UNIFIED_PLAN;

        peerConnection = factory.createPeerConnection(rtcConfig, new SimplePcObserver());
        if (peerConnection == null) throw new IllegalStateException("createPeerConnection вернул null");

        peerConnection.addTrack(videoTrack, java.util.Collections.singletonList("severfoods-stream"));

        DataChannel.Init dcInit = new DataChannel.Init();
        controlChannel = peerConnection.createDataChannel("control", dcInit);
        controlChannel.registerObserver(new ControlChannelObserver());

        MediaConstraints offerConstraints = new MediaConstraints();
        offerConstraints.mandatory.add(new MediaConstraints.KeyValuePair("OfferToReceiveVideo", "false"));
        offerConstraints.mandatory.add(new MediaConstraints.KeyValuePair("OfferToReceiveAudio", "false"));

        peerConnection.createOffer(new SdpObserverAdapter() {
            @Override public void onCreateSuccess(SessionDescription sdp) {
                peerConnection.setLocalDescription(new SdpObserverAdapter(), sdp);
                sendSignal("offer", sdpToJson(sdp));
            }
            @Override public void onCreateFailure(String error) {
                Log.e(TAG, "createOffer failed: " + error);
                stopSelf();
            }
        }, offerConstraints);

        startPolling();
    }

    private List<PeerConnection.IceServer> parseIceServers(String json) {
        List<PeerConnection.IceServer> result = new ArrayList<>();
        try {
            JSONArray arr = new JSONArray(json);
            for (int i = 0; i < arr.length(); i++) {
                JSONObject o = arr.getJSONObject(i);
                PeerConnection.IceServer.Builder b = PeerConnection.IceServer.builder(o.getString("urls"));
                if (o.has("username")) b.setUsername(o.getString("username"));
                if (o.has("credential")) b.setPassword(o.getString("credential"));
                result.add(b.createIceServer());
            }
        } catch (Exception e) {
            Log.e(TAG, "Не удалось разобрать ice_servers: " + e.getMessage());
        }
        return result;
    }

    // ── сигналинг (HTTP long polling) ──────────────────────────────────

    private void startPolling() {
        pollThread = new Thread(() -> {
            while (!stopped.get()) {
                try {
                    pollOnce();
                    Thread.sleep(POLL_INTERVAL_MS);
                } catch (InterruptedException ignored) {
                } catch (Exception e) {
                    Log.w(TAG, "Ошибка опроса сигналов: " + e.getMessage());
                    try { Thread.sleep(POLL_INTERVAL_MS); } catch (InterruptedException ignored) {}
                }
            }
        }, "SeverFoodsRemoteSignalPoll");
        pollThread.start();
    }

    private void pollOnce() throws Exception {
        String url = syncEndpoint + "?action=remote_signal_poll&session_id=" + sessionId
            + "&after=" + signalCursor.get();
        JSONObject resp = new JSONObject(httpRequest("GET", url, null));
        if (!resp.optBoolean("ok", false)) {
            Log.w(TAG, "Сеанс не найден на сервере — завершаю");
            stopSelf();
            return;
        }
        String status = resp.optString("status", "");
        if ("ended".equals(status)) { stopSelf(); return; }

        JSONArray signals = resp.optJSONArray("signals");
        if (signals == null) return;
        for (int i = 0; i < signals.length(); i++) {
            JSONObject sig = signals.getJSONObject(i);
            signalCursor.set(Math.max(signalCursor.get(), sig.getInt("id")));
            String type = sig.getString("type");
            JSONObject payload = sig.getJSONObject("payload");
            if ("answer".equals(type)) {
                SessionDescription answer = new SessionDescription(
                    SessionDescription.Type.fromCanonicalForm(payload.getString("type")),
                    payload.getString("sdp"));
                peerConnection.setRemoteDescription(new SdpObserverAdapter(), answer);
            } else if ("ice".equals(type)) {
                IceCandidate cand = new IceCandidate(
                    payload.optString("sdpMid", ""),
                    payload.optInt("sdpMLineIndex", 0),
                    payload.getString("candidate"));
                peerConnection.addIceCandidate(cand);
            } else if ("bye".equals(type)) {
                stopSelf();
            }
        }
    }

    private void sendSignal(String type, JSONObject payload) {
        new Thread(() -> {
            try {
                JSONObject body = new JSONObject();
                body.put("session_id", sessionId);
                body.put("type", type);
                body.put("payload", payload);
                httpRequest("POST", syncEndpoint + "?action=remote_signal", body.toString());
            } catch (Exception e) {
                Log.w(TAG, "Не удалось отправить сигнал " + type + ": " + e.getMessage());
            }
        }, "SeverFoodsRemoteSignalSend").start();
    }

    private JSONObject sdpToJson(SessionDescription sdp) {
        JSONObject o = new JSONObject();
        try {
            o.put("sdp", sdp.description);
            o.put("type", sdp.type.canonicalForm());
        } catch (Exception ignored) {}
        return o;
    }

    private String httpRequest(String method, String urlStr, @Nullable String body) throws Exception {
        HttpURLConnection conn = (HttpURLConnection) new URL(urlStr).openConnection();
        try {
            conn.setRequestMethod(method);
            conn.setConnectTimeout(SIGNAL_TIMEOUT_MS);
            conn.setReadTimeout(SIGNAL_TIMEOUT_MS);
            conn.setRequestProperty("X-Sync-Token", syncToken);
            conn.setRequestProperty("Content-Type", "application/json");
            conn.setRequestProperty("Accept", "application/json");
            if (body != null) {
                conn.setDoOutput(true);
                try (OutputStream os = conn.getOutputStream()) {
                    os.write(body.getBytes(StandardCharsets.UTF_8));
                }
            }
            int status = conn.getResponseCode();
            InputStream is = (status >= 200 && status < 400) ? conn.getInputStream() : conn.getErrorStream();
            return is == null ? "{}" : readAll(is);
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

    // ── управление (DataChannel → AccessibilityService) ────────────────

    private class ControlChannelObserver implements DataChannel.Observer {
        @Override public void onBufferedAmountChange(long l) {}
        @Override public void onStateChange() {}

        @Override
        public void onMessage(DataChannel.Buffer buffer) {
            byte[] bytes = new byte[buffer.data.remaining()];
            buffer.data.get(bytes);
            try {
                JSONObject msg = new JSONObject(new String(bytes, StandardCharsets.UTF_8));
                String type = msg.optString("type", "");

                if ("clipboard_set".equals(type)) { setClipboard(msg.optString("text", "")); return; }
                if ("clipboard_get".equals(type)) { sendClipboard(); return; }

                float x = (float) (msg.optDouble("x", 0) * captureWidthPx);
                float y = (float) (msg.optDouble("y", 0) * captureHeightPx);

                RemoteControlAccessibilityService svc = RemoteControlAccessibilityService.getInstance();
                if (svc == null) {
                    Log.w(TAG, "Специальные возможности не включены — команда управления проигнорирована");
                    return;
                }
                switch (type) {
                    case "down": svc.strokeDown(x, y); break;
                    case "move": svc.strokeMove(x, y); break;
                    case "up":   svc.strokeUp(x, y);   break;
                }
            } catch (Exception e) {
                Log.w(TAG, "Некорректное сообщение управления: " + e.getMessage());
            }
        }
    }

    /**
     * Буфер обмена — в обе стороны через тот же DataChannel, без отдельного
     * запроса к серверу. Запись работает всегда; чтение (sendClipboard) на
     * Android 10+ может вернуть пусто, если система считает наш фоновый
     * сервис "не в фокусе" — это ограничение платформы (защита от фоновых
     * приложений, подглядывающих чужой буфер), а не баг здесь.
     */
    private void setClipboard(String text) {
        mainHandler.post(() -> {
            try {
                ClipboardManager cm = (ClipboardManager) getSystemService(Context.CLIPBOARD_SERVICE);
                cm.setPrimaryClip(ClipData.newPlainText("severfoods-remote", text));
            } catch (Exception e) {
                Log.w(TAG, "Не удалось записать буфер обмена: " + e.getMessage());
            }
        });
    }

    private void sendClipboard() {
        mainHandler.post(() -> {
            String text = "";
            try {
                ClipboardManager cm = (ClipboardManager) getSystemService(Context.CLIPBOARD_SERVICE);
                if (cm.hasPrimaryClip() && cm.getPrimaryClip().getItemCount() > 0) {
                    CharSequence t = cm.getPrimaryClip().getItemAt(0).coerceToText(this);
                    text = t != null ? t.toString() : "";
                }
            } catch (Exception e) {
                Log.w(TAG, "Не удалось прочитать буфер обмена: " + e.getMessage());
            }
            if (controlChannel != null && controlChannel.state() == DataChannel.State.OPEN) {
                try {
                    JSONObject msg = new JSONObject();
                    msg.put("type", "clipboard_data");
                    msg.put("text", text);
                    byte[] bytes = msg.toString().getBytes(StandardCharsets.UTF_8);
                    controlChannel.send(new DataChannel.Buffer(java.nio.ByteBuffer.wrap(bytes), false));
                } catch (Exception e) {
                    Log.w(TAG, "Не удалось отправить буфер обмена зрителю: " + e.getMessage());
                }
            }
        });
    }

    // ── наблюдатель PeerConnection ──────────────────────────────────────

    private class SimplePcObserver implements PeerConnection.Observer {
        @Override public void onIceCandidate(IceCandidate c) {
            JSONObject p = new JSONObject();
            try {
                p.put("candidate", c.sdp);
                p.put("sdpMid", c.sdpMid);
                p.put("sdpMLineIndex", c.sdpMLineIndex);
            } catch (Exception ignored) {}
            sendSignal("ice", p);
        }
        @Override public void onConnectionChange(PeerConnection.PeerConnectionState state) {
            Log.i(TAG, "connectionState=" + state);
            if (state == PeerConnection.PeerConnectionState.FAILED
                    || state == PeerConnection.PeerConnectionState.CLOSED) {
                stopSelf();
            }
        }
        @Override public void onIceConnectionChange(PeerConnection.IceConnectionState s) {}
        @Override public void onIceConnectionReceivingChange(boolean b) {}
        @Override public void onIceGatheringChange(PeerConnection.IceGatheringState s) {}
        @Override public void onIceCandidatesRemoved(IceCandidate[] c) {}
        @Override public void onAddStream(MediaStream s) {}
        @Override public void onRemoveStream(MediaStream s) {}
        @Override public void onDataChannel(DataChannel dc) {}
        @Override public void onRenegotiationNeeded() {}
        @Override public void onSignalingChange(PeerConnection.SignalingState s) {}
        @Override public void onAddTrack(RtpReceiver receiver, MediaStream[] streams) {}
    }

    // ── пустой SdpObserver-адаптер, чтобы не реализовывать все 4 метода каждый раз ──
    private static class SdpObserverAdapter implements SdpObserver {
        @Override public void onCreateSuccess(SessionDescription sdp) {}
        @Override public void onSetSuccess() {}
        @Override public void onCreateFailure(String s) { Log.w(TAG, "SDP create failed: " + s); }
        @Override public void onSetFailure(String s) { Log.w(TAG, "SDP set failed: " + s); }
    }

    @Override
    public void onDestroy() {
        stopped.set(true);
        if (pollThread != null) pollThread.interrupt();
        try { if (capturer != null) capturer.stopCapture(); } catch (Exception ignored) {}
        if (surfaceTextureHelper != null) surfaceTextureHelper.dispose();
        if (videoSource != null) videoSource.dispose();
        if (peerConnection != null) peerConnection.close();
        if (factory != null) factory.dispose();
        if (mediaProjection != null) mediaProjection.stop();
        if (eglBase != null) eglBase.release();
        super.onDestroy();
    }

    @Nullable @Override public IBinder onBind(Intent intent) { return null; }
}
