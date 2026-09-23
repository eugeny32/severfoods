package ru.remotesupport.evotor;

import android.app.Activity;
import android.content.Intent;
import android.os.Bundle;
import android.provider.Settings;
import android.widget.Button;
import android.widget.EditText;
import android.widget.TextView;
import android.widget.Toast;

import org.json.JSONObject;

import java.util.HashMap;
import java.util.Map;

public class MainActivity extends Activity {

    private Prefs prefs;
    private TextView statusText;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);
        prefs = new Prefs(this);

        statusText = findViewById(R.id.statusText);
        EditText serverUrlInput = findViewById(R.id.serverUrlInput);
        serverUrlInput.setText(prefs.serverUrl());

        findViewById(R.id.saveServerBtn).setOnClickListener(v -> {
            String url = serverUrlInput.getText().toString().trim();
            if (url.isEmpty()) { Toast.makeText(this, "Укажите адрес сервера", Toast.LENGTH_SHORT).show(); return; }
            prefs.setServerUrl(url);
            registerIfNeeded();
        });

        findViewById(R.id.enableCaptureBtn).setOnClickListener(v -> {
            if (prefs.serverUrl().isEmpty()) { Toast.makeText(this, "Сначала укажите адрес сервера", Toast.LENGTH_SHORT).show(); return; }
            ScreenCapturePermission.request(this);
        });

        findViewById(R.id.accessibilityBtn).setOnClickListener(v ->
            startActivity(new Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)));

        updateStatus();
        if (prefs.isRegistered()) {
            startService(new Intent(this, HeartbeatService.class));
        } else if (!prefs.serverUrl().isEmpty()) {
            registerIfNeeded();
        }
    }

    private void updateStatus() {
        String s = "Сервер: " + (prefs.serverUrl().isEmpty() ? "не задан" : prefs.serverUrl()) + "\n"
            + "Устройство: " + (prefs.isRegistered() ? "зарегистрировано (" + prefs.deviceId() + ")" : "не зарегистрировано") + "\n"
            + "Захват экрана: " + (ScreenCapturePermission.isGranted() ? "разрешён" : "не разрешён");
        statusText.setText(s);
    }

    private void registerIfNeeded() {
        if (prefs.isRegistered()) { updateStatus(); startService(new Intent(this, HeartbeatService.class)); return; }
        new Thread(() -> {
            try {
                String name = android.os.Build.MODEL + " (" + android.os.Build.SERIAL + ")";
                JSONObject body = new JSONObject().put("name", name);
                Map<String, String> headers = new HashMap<>();
                String resp = HttpUtil.request("POST", prefs.serverUrl() + "/api/register", headers, body.toString(), 15000);
                JSONObject json = new JSONObject(resp);
                if (json.optBoolean("ok", false)) {
                    prefs.setDevice(json.getString("device_id"), json.getString("device_token"));
                    runOnUiThread(() -> {
                        Toast.makeText(this, "Устройство зарегистрировано", Toast.LENGTH_SHORT).show();
                        updateStatus();
                        startService(new Intent(this, HeartbeatService.class));
                    });
                } else {
                    runOnUiThread(() -> Toast.makeText(this, "Не удалось зарегистрироваться: " + json.optString("error", "?"), Toast.LENGTH_LONG).show());
                }
            } catch (Exception e) {
                runOnUiThread(() -> Toast.makeText(this, "Ошибка сети: " + e.getMessage(), Toast.LENGTH_LONG).show());
            }
        }).start();
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (ScreenCapturePermission.onActivityResult(this, requestCode, resultCode, data)) updateStatus();
    }

    @Override
    protected void onResume() {
        super.onResume();
        updateStatus();
    }
}
