; Автоматически подхватывается electron-builder (build-res/installer.nsh) как
; кастомный хук установщика.
;
; Проблема: до версии 1.5.1 приложение по умолчанию ставилось в C:\Program Files\
; (per-machine, HKLM). Такая установка требует прав администратора на запись,
; а сам процесс запускается без повышения прав — попытка сохранить .env в свою
; же папку падает с EPERM. Начиная с 1.5.1 установка идёт per-user
; (%LOCALAPPDATA%\Programs\SeverFoods), но если пользователь ставит новую версию
; поверх старой (не удаляя её), NSIS может унаследовать старый путь установки.
;
; Решение: перед установкой ищем в реестре HKLM любую предыдущую запись
; "SeverFoods" и тихо запускаем её деинсталлятор — миграция происходит
; автоматически, без ручных действий пользователя. База данных в
; %APPDATA%\SeverFoods\ не трогается (deleteAppDataOnUninstall: false).
!macro customInit
  StrCpy $0 0
  ${Do}
    EnumRegKey $1 HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall" $0
    ${If} $1 == ""
      ${Break}
    ${EndIf}

    ReadRegStr $2 HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\$1" "DisplayName"
    ${If} $2 == "SeverFoods"
      DetailPrint "Найдена предыдущая установка SeverFoods (Program Files) — удаляем перед обновлением..."
      ReadRegStr $4 HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\$1" "QuietUninstallString"
      ${If} $4 == ""
        ReadRegStr $4 HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\$1" "UninstallString"
      ${EndIf}
      ${If} $4 != ""
        ExecWait '$4 /S _?=$TEMP' $5
      ${EndIf}
    ${EndIf}

    IntOp $0 $0 + 1
  ${Loop}
!macroend
