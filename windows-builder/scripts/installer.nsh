; Saffron POS — NSIS Installer Customization
; This file is included by electron-builder's NSIS generator.

!macro customInit
  ; Check for existing installation
  ReadRegStr $0 HKCU "Software\Saffron POS" "InstallDir"
  ${If} $0 != ""
    ; Existing installation found — ask if user wants to upgrade
    MessageBox MB_YESNO|MB_ICONQUESTION "A previous version of Saffron POS was found at:$\n$0$\n$\nDo you want to upgrade (preserving your data)?" IDYES customInit_done IDNO customInit_abort
    customInit_abort:
      Abort
    customInit_done:
  ${EndIf}
!macroend

!macro customInstallMode
  ; Default to per-user installation
  StrCpy $isForceCurrentInstallMode "1"
!macroend

!macro customUnInit
  ; Check if user wants to keep data
  MessageBox MB_YESNO|MB_ICONQUESTION "Do you want to keep your Saffron POS business data (products, sales, customers, backups)?" IDYES customUnInit_keep IDNO customUnInit_askdelete

  customUnInit_keep:
    ; Do nothing — data stays in %LOCALAPPDATA%\SaffronPOS
    Goto customUnInit_done

  customUnInit_askdelete:
    MessageBox MB_YESNO|MB_ICONEXCLAMATION "WARNING: This will permanently delete ALL business data including:$\n$\n- Products$\n- Sales$\n- Customers$\n- Customer ledgers$\n- Quotations$\n- Inventory$\n- Backups$\n- Settings$\n$\nThis CANNOT be undone.$\n$\nAre you absolutely sure?" IDYES customUnInit_delete IDNO customUnInit_keep

  customUnInit_delete:
    ; Type confirmation
    MessageBox MB_YESCANCEL|MB_ICONSTOP "Last chance! Type DELETE in the next dialog to confirm permanent deletion." IDYES customUnInit_finaldelete IDNO customUnInit_keep
    customUnInit_finaldelete:
      ; Delete the data directory
      RMDir /r "$LOCALAPPDATA\SaffronPOS"

  customUnInit_done:
!macroend

!macro customInstallFiles
  ; After files are copied, create data directory
  CreateDirectory "$LOCALAPPDATA\SaffronPOS"
  CreateDirectory "$LOCALAPPDATA\SaffronPOS\logs"
  CreateDirectory "$LOCALAPPDATA\SaffronPOS\backups"
  CreateDirectory "$LOCALAPPDATA\SaffronPOS\database"
  CreateDirectory "$LOCALAPPDATA\SaffronPOS\sessions"
!macroend

!macro customUnInstall
  ; Remove shortcuts
  Delete "$DESKTOP\Saffron POS.lnk"
  
  ; Remove startup entry if exists
  DeleteRegValue HKCU "Software\Microsoft\Windows\CurrentVersion\Run" "Saffron POS"
!macroend
