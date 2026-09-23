#!/usr/bin/env python3
"""
Массовое включение специальных возможностей «Удалённой поддержки» через ADB —
без похода в Настройки на каждом терминале вручную.

Обойти диалог захвата экрана (MediaProjection) так нельзя и не должно — это
защита самой Android, не наше ограничение (см. обсуждение в чате/коммитах).
Этот скрипт закрывает только вторую половину: включение AccessibilityService,
которое ADB действительно умеет делать напрямую через настройки secure —
никакого специального разрешения для этого не требуется, adb shell по
умолчанию имеет доступ к WRITE_SECURE_SETTINGS.

Использование:
    python3 provision_accessibility.py <терминал> [<терминал> ...]
    python3 provision_accessibility.py --file terminals.txt

Каждый <терминал> — это то, что понимает `adb -s`:
  - серийник USB-подключённого устройства (см. `adb devices`)
  - ip:port для терминала в сети (скрипт сам выполнит `adb connect`)

Файл со списком терминалов (--file) — по одному на строку, пустые строки
и строки с # игнорируются.

Требует установленный adb в PATH. Терминал должен быть в режиме разработчика
(см. android/EVOTOR.md в основном проекте — там же предупреждение, что
включённый режим разработчика ломает установку через Эвотор.Маркет: для
устройств, которые уже вышли в публикацию, выключайте его обратно после
провижининга).
"""
import argparse
import subprocess
import sys

PACKAGE = "ru.remotesupport.evotor"
SERVICE = f"{PACKAGE}/{PACKAGE}.RemoteControlAccessibilityService"


def run(args, timeout=15):
    try:
        r = subprocess.run(args, capture_output=True, text=True, timeout=timeout)
        return r.returncode, r.stdout.strip(), r.stderr.strip()
    except subprocess.TimeoutExpired:
        return 1, "", "таймаут"
    except FileNotFoundError:
        print("adb не найден в PATH — установите Android platform-tools.", file=sys.stderr)
        sys.exit(1)


def provision_one(target: str) -> bool:
    print(f"\n== {target} ==")

    if ":" in target and not target.count(":") > 1:
        # похоже на ip:port — терминал в сети, а не по USB
        code, out, err = run(["adb", "connect", target])
        print(f"  adb connect: {out or err}")
        if code != 0 or "failed" in (out + err).lower():
            print("  ✗ не удалось подключиться — пропускаю")
            return False

    # Включаем нашу службу в списке разрешённых (не затирая уже включённые
    # службы других приложений — дописываем через ':', как делает сама
    # система при ручном включении через Настройки).
    code, existing, err = run(["adb", "-s", target, "shell", "settings", "get", "secure", "enabled_accessibility_services"])
    if code != 0:
        print(f"  ✗ устройство не отвечает: {err or existing}")
        return False

    existing = existing.strip()
    services = set(s for s in existing.split(":") if s and s != "null")
    services.add(SERVICE)
    new_value = ":".join(sorted(services))

    code, out, err = run(["adb", "-s", target, "shell", "settings", "put", "secure",
                           "enabled_accessibility_services", new_value])
    if code != 0:
        print(f"  ✗ не удалось записать enabled_accessibility_services: {err}")
        return False

    code, out, err = run(["adb", "-s", target, "shell", "settings", "put", "secure",
                           "accessibility_enabled", "1"])
    if code != 0:
        print(f"  ✗ не удалось записать accessibility_enabled: {err}")
        return False

    # Проверка
    code, check, err = run(["adb", "-s", target, "shell", "settings", "get", "secure", "enabled_accessibility_services"])
    if SERVICE in check:
        print(f"  ✓ служба включена: {check}")
        return True
    else:
        print(f"  ✗ проверка не прошла, текущее значение: {check}")
        return False


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("targets", nargs="*", help="серийники или ip:port терминалов")
    parser.add_argument("--file", help="файл со списком терминалов, по одному на строку")
    args = parser.parse_args()

    targets = list(args.targets)
    if args.file:
        with open(args.file, encoding="utf-8") as f:
            targets += [line.strip() for line in f if line.strip() and not line.strip().startswith("#")]

    if not targets:
        parser.print_help()
        sys.exit(1)

    results = {t: provision_one(t) for t in targets}

    print("\n" + "=" * 40)
    ok = sum(1 for v in results.values() if v)
    print(f"Готово: {ok}/{len(results)} терминалов")
    for t, v in results.items():
        print(f"  {'✓' if v else '✗'} {t}")

    if ok < len(results):
        sys.exit(1)


if __name__ == "__main__":
    main()
