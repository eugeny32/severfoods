; SeverFoods Offline — NSIS Installer
; Автор: Пальченков Евгений Иванович · ООО «Север»

Unicode true

!define APP_NAME     "SeverFoods"
!define APP_VERSION  "1.0.0"
!define APP_EXE      "SeverFoods.exe"
!define APP_GUID     "{0E402777-487E-58D5-817C-42180FAB4AE7}"
!define INSTALL_DIR  "$LOCALAPPDATA\SeverFoods"

Name "${APP_NAME} ${APP_VERSION}"
OutFile "dist\SeverFoods-Setup-${APP_VERSION}.exe"
InstallDir "${INSTALL_DIR}"
InstallDirRegKey HKCU "Software\${APP_NAME}" "InstallDir"
RequestExecutionLevel user
SetCompressor /SOLID lzma

; Modern UI
!include "MUI2.nsh"
!include "nsDialogs.nsh"
!include "LogicLib.nsh"

!define MUI_ICON     "public\assets\img\icon.ico"
!define MUI_UNICON   "public\assets\img\icon.ico"
!define MUI_ABORTWARNING
!define MUI_WELCOMEPAGE_TITLE   "Установка ${APP_NAME}"
!define MUI_WELCOMEPAGE_TEXT    "Система учёта питания.$\n$\nАвтор: Пальченков Евгений Иванович$\nООО «Север»$\n$\nНажмите «Далее» для продолжения."
!define MUI_FINISHPAGE_RUN      "$INSTDIR\${APP_EXE}"
!define MUI_FINISHPAGE_RUN_TEXT "Запустить ${APP_NAME}"
!define MUI_FINISHPAGE_SHOWREADME ""
!define MUI_FINISHPAGE_SHOWREADME_NOTCHECKED

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

!insertmacro MUI_UNPAGE_WELCOME
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

  ; Token label
  ${NSD_CreateLabel} 0 0 100% 24u "Токен синхронизации (выдаётся администратором):"
  Pop $TokenLabel

  ; Token input (password style)
  ${NSD_CreatePassword} 0 26u 100% 14u ""
  Pop $TokenField
  ; Pre-fill from existing .env if upgrading
  StrCpy $SyncToken ""

  ; Server URL label
  ${NSD_CreateLabel} 0 52u 100% 12u "URL сервера (необязательно):"
  Pop $ServerLabel

  ; Server URL input
  ${NSD_CreateText} 0 66u 100% 14u "https://www.severfoods.ru"
  Pop $ServerField

  nsDialogs::Show
FunctionEnd

Function TokenPageLeave
  ${NSD_GetText} $TokenField $SyncToken
  ${NSD_GetText} $ServerField $ServerUrl

  ; Token is required
  ${If} $SyncToken == ""
    MessageBox MB_OK|MB_ICONEXCLAMATION "Введите токен синхронизации. Он необходим для работы приложения.$\n$\nТокен можно найти в настройках сервера SeverFoods."
    Abort
  ${EndIf}

  ; Default server URL
  ${If} $ServerUrl == ""
    StrCpy $ServerUrl "https://www.severfoods.ru"
  ${EndIf}
FunctionEnd

; ── Install ──────────────────────────────────────────────────
Section "Основные файлы" SecMain
  SectionIn RO
  SetOutPath "$INSTDIR"

  ; Copy all files from win-unpacked
  File /r "dist\win-unpacked\*.*"

  ; Write .env with sync token collected on token page
  FileOpen $0 "$INSTDIR\.env" w
  FileWrite $0 "OFFLINE_SYNC_TOKEN=$SyncToken$\r$\n"
  FileWrite $0 "SERVER_URL=$ServerUrl$\r$\n"
  FileClose $0

  ; Write uninstaller
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

  ; Shortcuts
  CreateDirectory "$SMPROGRAMS\${APP_NAME}"
  CreateShortcut  "$SMPROGRAMS\${APP_NAME}\${APP_NAME}.lnk" "$INSTDIR\${APP_EXE}" "" "$INSTDIR\${APP_EXE}"
  CreateShortcut  "$SMPROGRAMS\${APP_NAME}\Удалить ${APP_NAME}.lnk" "$INSTDIR\Uninstall.exe"
  CreateShortcut  "$DESKTOP\${APP_NAME}.lnk" "$INSTDIR\${APP_EXE}" "" "$INSTDIR\${APP_EXE}"
SectionEnd

; ── Uninstall ────────────────────────────────────────────────
Section "Uninstall"
  ; Stop the app if running
  ExecWait 'taskkill /F /IM "${APP_EXE}"' $0

  ; Remove files
  RMDir /r "$INSTDIR"

  ; Remove shortcuts
  Delete "$DESKTOP\${APP_NAME}.lnk"
  RMDir /r "$SMPROGRAMS\${APP_NAME}"

  ; Remove registry
  DeleteRegKey HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_GUID}"
  DeleteRegKey HKCU "Software\${APP_NAME}"
SectionEnd
