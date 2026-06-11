; SeverFoods Offline — NSIS Installer
; Автор: Пальченков Евгений Иванович · ООО «Север»

Unicode true

!define APP_NAME     "SeverFoods"
!define APP_VERSION  "1.1.0"
!define APP_EXE      "SeverFoods.exe"
!define APP_GUID     "{0E402777-487E-58D5-817C-42180FAB4AE7}"
!define INSTALL_DIR  "$LOCALAPPDATA\SeverFoods"

Name "${APP_NAME} ${APP_VERSION}"
OutFile "dist/SeverFoods-Setup-${APP_VERSION}.exe"
InstallDir "${INSTALL_DIR}"
InstallDirRegKey HKCU "Software\${APP_NAME}" "InstallDir"
RequestExecutionLevel user
SetCompressor /SOLID lzma

; Modern UI
!include "MUI2.nsh"
!include "nsDialogs.nsh"
!include "LogicLib.nsh"

!define MUI_ICON     "public/assets/img/icon.ico"
!define MUI_UNICON   "public/assets/img/icon.ico"
!define MUI_ABORTWARNING
!define MUI_WELCOMEPAGE_TITLE   "Установка ${APP_NAME}"
!define MUI_WELCOMEPAGE_TEXT    "Система учёта питания.$\n$\nАвтор: Пальченков Евгений Иванович$\nООО «Север»$\n$\nНажмите «Далее» для продолжения."
!define MUI_FINISHPAGE_RUN      "$INSTDIR\${APP_EXE}"
!define MUI_FINISHPAGE_RUN_TEXT "Запустить ${APP_NAME}"

; Token page variables
Var Dialog
Var TokenLabel
Var TokenField
Var ServerLabel
Var ServerField
Var SyncToken
Var ServerUrl

!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_DIRECTORY
Page custom TokenPageCreate TokenPageLeave
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH

!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_UNPAGE_FINISH

!insertmacro MUI_LANGUAGE "Russian"

; ── Token page callbacks ─────────────────────────────────────

Function TokenPageCreate
  !insertmacro MUI_HEADER_TEXT "Токен синхронизации" "Введите токен для подключения к серверу SeverFoods."

  nsDialogs::Create 1018
  Pop $Dialog
  ${If} $Dialog == error
    Abort
  ${EndIf}

  ${NSD_CreateLabel} 0 0 100% 20u "Токен синхронизации (выдаётся администратором системы):"
  Pop $TokenLabel

  ${NSD_CreatePassword} 0 22u 100% 14u ""
  Pop $TokenField

  ${NSD_CreateLabel} 0 46u 100% 12u "URL сервера (необязательно, оставьте по умолчанию):"
  Pop $ServerLabel

  ${NSD_CreateText} 0 60u 100% 14u "https://www.severfoods.ru"
  Pop $ServerField

  nsDialogs::Show
FunctionEnd

Function TokenPageLeave
  ${NSD_GetText} $TokenField $SyncToken
  ${NSD_GetText} $ServerField $ServerUrl

  ${If} $SyncToken == ""
    MessageBox MB_OK|MB_ICONEXCLAMATION "Введите токен синхронизации.$\nОн необходим для работы приложения.$\n$\nТокен выдаётся администратором сервера SeverFoods."
    Abort
  ${EndIf}

  ${If} $ServerUrl == ""
    StrCpy $ServerUrl "https://www.severfoods.ru"
  ${EndIf}
FunctionEnd

; ── Install ──────────────────────────────────────────────────
Section "Основные файлы" SecMain
  SectionIn RO
  SetOutPath "$INSTDIR"

  ; Root files
  File "dist/win-unpacked/SeverFoods.exe"
  File "dist/win-unpacked/LICENSE.electron.txt"
  File "dist/win-unpacked/LICENSES.chromium.html"
  File "dist/win-unpacked/chrome_100_percent.pak"
  File "dist/win-unpacked/chrome_200_percent.pak"
  File "dist/win-unpacked/d3dcompiler_47.dll"
  File "dist/win-unpacked/ffmpeg.dll"
  File "dist/win-unpacked/icudtl.dat"
  File "dist/win-unpacked/libEGL.dll"
  File "dist/win-unpacked/libGLESv2.dll"
  File "dist/win-unpacked/resources.pak"
  File "dist/win-unpacked/snapshot_blob.bin"
  File "dist/win-unpacked/v8_context_snapshot.bin"
  File "dist/win-unpacked/vk_swiftshader.dll"
  File "dist/win-unpacked/vk_swiftshader_icd.json"
  File "dist/win-unpacked/vulkan-1.dll"

  ; locales/
  SetOutPath "$INSTDIR\locales"
  File /r "dist/win-unpacked/locales/*"

  ; resources/
  SetOutPath "$INSTDIR\resources"
  File "dist/win-unpacked/resources/app-update.yml"
  File "dist/win-unpacked/resources/app.asar"
  File "dist/win-unpacked/resources/elevate.exe"

  ; resources/app.asar.unpacked/node_modules/...
  SetOutPath "$INSTDIR\resources\app.asar.unpacked"
  File /r "dist/win-unpacked/resources/app.asar.unpacked/"

  ; Back to root for .env and uninstaller
  SetOutPath "$INSTDIR"

  ; Write .env with sync token
  FileOpen $0 "$INSTDIR\.env" w
  FileWrite $0 "OFFLINE_SYNC_TOKEN=$SyncToken$\r$\n"
  FileWrite $0 "SERVER_URL=$ServerUrl$\r$\n"
  FileClose $0

  WriteUninstaller "$INSTDIR\Uninstall.exe"

  ; Registry — Add/Remove Programs
  WriteRegStr   HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_GUID}" "DisplayName"          "${APP_NAME} ${APP_VERSION}"
  WriteRegStr   HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_GUID}" "UninstallString"      '"$INSTDIR\Uninstall.exe"'
  WriteRegStr   HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_GUID}" "InstallLocation"      "$INSTDIR"
  WriteRegStr   HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_GUID}" "DisplayIcon"          "$INSTDIR\${APP_EXE}"
  WriteRegStr   HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_GUID}" "Publisher"            "OOO Sever"
  WriteRegStr   HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_GUID}" "DisplayVersion"       "${APP_VERSION}"
  WriteRegDWORD HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_GUID}" "NoModify"             1
  WriteRegDWORD HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_GUID}" "NoRepair"             1
  WriteRegStr   HKCU "Software\${APP_NAME}" "InstallDir" "$INSTDIR"

  CreateDirectory "$SMPROGRAMS\${APP_NAME}"
  CreateShortcut  "$SMPROGRAMS\${APP_NAME}\${APP_NAME}.lnk" "$INSTDIR\${APP_EXE}" "" "$INSTDIR\${APP_EXE}"
  CreateShortcut  "$SMPROGRAMS\${APP_NAME}\Удалить ${APP_NAME}.lnk" "$INSTDIR\Uninstall.exe"
  CreateShortcut  "$DESKTOP\${APP_NAME}.lnk" "$INSTDIR\${APP_EXE}" "" "$INSTDIR\${APP_EXE}"
SectionEnd

; ── Uninstall ────────────────────────────────────────────────
Section "Uninstall"
  ExecWait 'taskkill /F /IM "${APP_EXE}"' $0

  RMDir /r "$INSTDIR"

  Delete "$DESKTOP\${APP_NAME}.lnk"
  RMDir /r "$SMPROGRAMS\${APP_NAME}"

  DeleteRegKey HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_GUID}"
  DeleteRegKey HKCU "Software\${APP_NAME}"
SectionEnd
