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
; Сохранить настройки точки до того, как установщик снесёт старую версию.
;
; Токен синхронизации и адрес сервера годами лежали в .env рядом с exe, а
; каталог установки при обновлении удаляется целиком. Точка теряла настройки и
; после обновления открывала окно первичной настройки вместо работы. Начиная с
; 1.7.5 приложение хранит .env в %APPDATA%\SeverFoods (там же база, её
; установщик не трогает), но при ОДНОМ обновлении — том, которым приезжает
; 1.7.5, — файл ещё лежит по-старому. Копируем его заранее: новая версия
; подхватит настройки и продолжит работать без вмешательства оператора.
!macro customInit
  ; Каталог прежней установки ищем перебором записей деинсталляции: $INSTDIR на
  ; этом шаге ещё не определён, а имя ключа electron-builder составляет из GUID,
  ; поэтому обратиться к нему напрямую нельзя. Установка пользовательская
  ; (perMachine: false), значит запись в HKCU.
  ${IfNot} ${FileExists} "$APPDATA\SeverFoods\.env"
    StrCpy $7 0
    ${Do}
      EnumRegKey $8 HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall" $7
      ${If} $8 == ""
        ${Break}
      ${EndIf}
      ReadRegStr $9 HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\$8" "DisplayName"
      ${If} $9 == "SeverFoods"
        ReadRegStr $6 HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\$8" "InstallLocation"
        ${If} $6 != ""
        ${AndIf} ${FileExists} "$6\.env"
          CreateDirectory "$APPDATA\SeverFoods"
          CopyFiles /SILENT "$6\.env" "$APPDATA\SeverFoods\.env"
          DetailPrint "Настройки точки сохранены в $APPDATA\SeverFoods"
          ${Break}
        ${EndIf}
      ${EndIf}
      IntOp $7 $7 + 1
    ${Loop}
  ${EndIf}

  StrCpy $0 0
  ${Do}
    EnumRegKey $1 HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall" $0
    ${If} $1 == ""
      ${Break}
    ${EndIf}

    ReadRegStr $2 HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\$1" "DisplayName"
    ${If} $2 == "SeverFoods"
      DetailPrint "Найдена предыдущая установка SeverFoods (Program Files) — удаляем перед обновлением..."
      ReadRegStr $4 HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\$1" "UninstallString"
      ReadRegStr $3 HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\$1" "InstallLocation"

      ${If} $4 != ""
        ${If} $3 != ""
          ; _?= задаёт деинсталлятору его каталог установки И заставляет
          ; ExecWait реально дождаться завершения (без этого параметра
          ; деинсталлятор копирует себя во временную папку и сразу возвращает
          ; управление). Раньше здесь стояло _?=$TEMP — деинсталлятор считал
          ; своим каталогом %TEMP% и чистил его, а установка в Program Files
          ; оставалась на месте.
          ExecWait '$4 /S _?=$3' $5
          DetailPrint "Деинсталлятор вернул код: $5"
          ; С параметром _?= деинсталлятор себя не удаляет — убираем остатки.
          Delete "$3\Uninstall SeverFoods.exe"
          RMDir "$3"
        ${Else}
          ; Каталог в реестре не записан — запускаем как есть. Дождаться
          ; завершения в этом случае нельзя, поэтому даём немного времени.
          DetailPrint "InstallLocation не найден — удаление без ожидания"
          Exec '$4 /S'
          Sleep 5000
        ${EndIf}
      ${EndIf}
    ${EndIf}

    IntOp $0 $0 + 1
  ${Loop}
!macroend
