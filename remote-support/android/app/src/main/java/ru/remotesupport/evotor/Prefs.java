package ru.remotesupport.evotor;

import android.content.Context;
import android.content.SharedPreferences;

/** Небольшая обёртка над SharedPreferences — адрес сервера и учётные данные устройства. */
final class Prefs {
    private static final String FILE = "remote_support_prefs";

    private final SharedPreferences sp;

    Prefs(Context ctx) {
        sp = ctx.getSharedPreferences(FILE, Context.MODE_PRIVATE);
    }

    String serverUrl() { return sp.getString("server_url", ""); }
    void setServerUrl(String v) { sp.edit().putString("server_url", v.replaceAll("/+$", "")).apply(); }

    String deviceId() { return sp.getString("device_id", ""); }
    String deviceToken() { return sp.getString("device_token", ""); }
    void setDevice(String id, String token) {
        sp.edit().putString("device_id", id).putString("device_token", token).apply();
    }
    boolean isRegistered() { return !deviceId().isEmpty() && !deviceToken().isEmpty(); }
}
