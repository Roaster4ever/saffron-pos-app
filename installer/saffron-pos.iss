; ═══════════════════════════════════════════════════════════════
; Saffron POS — Inno Setup Installer Script
; ═══════════════════════════════════════════════════════════════
; Build with Inno Setup 6.3+ (https://jrsoftware.org/isinfo.php)
; Place PHP, MariaDB, Nginx runtimes in installer\runtime\ before building
; ═══════════════════════════════════════════════════════════════

[Setup]
AppId={{A3F8B2C1-4D5E-6F78-9A0B-1C2D3E4F5A6B}
AppName=Saffron POS
AppVersion=2.0.0
AppPublisher=Saffron POS
AppPublisherURL=https://github.com/Roaster4ever/saffron-pos-app
DefaultDirName={autopf}\SaffronPOS
DefaultGroupName=Saffron POS
AllowNoIcons=yes
OutputDir=dist
OutputBaseFilename=SaffronPOS-2.0.0-Setup
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
DisableProgramGroupPage=yes
LicenseFile=..\LICENSE
SetupIconFile=..\installer\resources\icon.ico
UninstallDisplayIcon={app}\saffron.exe
CloseApplications=force
RestartApplications=no

; ── Wizard Images ──
WizardImageFile=..\installer\resources\wizard-image.bmp
WizardSmallImageFile=..\installer\resources\wizard-small.bmp

; ── Languages ──
[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

; ── Files to Install ──
[Files]
; PHP runtime
Source: "..\installer\runtime\php\*"; DestDir: "{app}\php"; Flags: ignoreversion recursesubdirs createallsubdirs

; MariaDB runtime
Source: "..\installer\runtime\mariadb\*"; DestDir: "{app}\mariadb"; Flags: ignoreversion recursesubdirs createallsubdirs

; Nginx runtime
Source: "..\installer\runtime\nginx\*"; DestDir: "{app}\nginx"; Flags: ignoreversion recursesubdirs createallsubdirs

; PHP application source
Source: "..\*"; DestDir: "{app}\app"; Flags: ignoreversion recursesubdirs createallsubdirs
Exclude: "..\installer\*","..\*.git\*","..\node_modules\*","..\dist\*","..\*.md","..\LICENSE","..\.env"

; Scripts and config templates
Source: "scripts\*"; DestDir: "{app}\scripts"; Flags: ignoreversion
Source: "resources\nginx.conf.template"; DestDir: "{app}\config"; Flags: ignoreversion

; Launcher executable
Source: "resources\saffron.exe"; DestDir: "{app}"; Flags: ignoreversion

; ── Desktop & Start Menu Shortcuts ──
[Icons]
Name: "{group}\Saffron POS"; Filename: "{app}\saffron.exe"; WorkingDir: "{app}"
Name: "{group}\Start Server"; Filename: "{app}\scripts\start.bat"; WorkingDir: "{app}"
Name: "{group}\Stop Server"; Filename: "{app}\scripts\stop.bat"; WorkingDir: "{app}"
Name: "{group}\Setup Wizard"; Filename: "{app}\scripts\setup-wizard.bat"; WorkingDir: "{app}"
Name: "{group}\Uninstall Saffron POS"; Filename: "{uninstallexe}"
Name: "{autodesktop}\Saffron POS"; Filename: "{app}\saffron.exe"; WorkingDir: "{app}"; Tasks: desktopicon

; ── Tasks ──
[Tasks]
Name: "desktopicon"; Description: "Create desktop shortcut"; GroupDescription: "Additional icons:"; Flags: unchecked

; ── Run Conditions ──
[Run]
; Run setup wizard after install
Filename: "{app}\scripts\setup-wizard.bat"; Description: "Run Setup Wizard to configure database and shop"; Flags: nowait postinstall skipifsilent shellexec

; Start server after install (optional)
Filename: "{app}\scripts\start.bat"; Description: "Start Saffron POS server now"; Flags: nowait postinstall skipifsilent shellexec skipifdidnotinstall

; ── Uninstall Actions ──
[UninstallDelete]
Type: filesandordirs; Name: "{app}\mariadb\data"
Type: filesandordirs; Name: "{app}\logs"
Type: files; Name: "{app}\app\.env"
Type: files; Name: "{app}\app\portable.txt"
Type: filesandordirs; Name: "{app}\app\uploads"
Type: filesandordirs; Name: "{app}\app\tmp"
Type: filesandordirs; Name: "{app}\app\backups"

[UninstallRun]
; Stop server before uninstall
Filename: "{app}\scripts\stop.bat"; Flags: runhidden

; ── Code Section ──
[Code]
var
  ShopName, ShopPhone, ShopAddress, DBPort, HTTPPort: AnsiString;

procedure InitializeWizard;
begin
  { Custom wizard page for shop configuration }
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
begin
  Result := '';
  { Stop any running instance before install/upgrade }
  Exec(ExpandConstant('{app}\scripts\stop.bat'), '', '', SW_HIDE, ewWaitUntilTerminated, 10000);
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  ResultCode: Integer;
begin
  if CurStep = ssPostInstall then
  begin
    { Initialize database if not already set up }
    Exec(ExpandConstant('{app}\scripts\setup-db.bat'), '', '', SW_HIDE, ewWaitUntilTerminated, 60000);
  end;
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
begin
  if CurUninstallStep = usUninstall then
  begin
    { Stop server before uninstall }
    Exec(ExpandConstant('{app}\scripts\stop.bat'), '', '', SW_HIDE, ewWaitUntilTerminated, 10000);
  end;
end;
