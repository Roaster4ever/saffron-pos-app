; ═══════════════════════════════════════════════════════════════
; Saffron POS — Inno Setup Installer Script
; ═══════════════════════════════════════════════════════════════
; Build: Run build.bat (auto-downloads PHP/MariaDB/Nginx)
; Or compile manually: iscc saffron-pos.iss
; ═══════════════════════════════════════════════════════════════

#define MyAppName "Saffron POS"
#define MyAppVersion "2.0.0"
#define MyAppPublisher "Saffron POS"
#define MyAppURL "https://github.com/Roaster4ever/saffron-pos-app"

[Setup]
AppId={{A3F8B2C1-4D5E-6F78-9A0B-1C2D3E4F5A6B}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL={#MyAppURL}
DefaultDirName={autopf}\SaffronPOS
DefaultGroupName={#MyAppName}
AllowNoIcons=yes
OutputDir=dist
OutputBaseFilename=SaffronPOS-{#MyAppVersion}-Setup
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
DisableProgramGroupPage=yes
SetupIconFile=resources\icon.ico
UninstallDisplayIcon={app}\start-saffron.bat
CloseApplications=force
RestartApplications=no
VersionInfoVersion={#MyAppVersion}.0
VersionInfoCompany={#MyAppPublisher}
VersionInfoDescription={#MyAppName} Installer

; ── Languages ──
[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

; ── Files to Install ──
[Files]
; PHP runtime (downloaded by build.bat)
Source: "runtime\php\*"; DestDir: "{app}\php"; Flags: ignoreversion recursesubdirs createallsubdirs

; MariaDB runtime (downloaded by build.bat)
Source: "runtime\mariadb\*"; DestDir: "{app}\mariadb"; Flags: ignoreversion recursesubdirs createallsubdirs

; Nginx runtime (downloaded by build.bat)
Source: "runtime\nginx\*"; DestDir: "{app}\nginx"; Flags: ignoreversion recursesubdirs createallsubdirs

; PHP application source (prepared by build.bat)
Source: "app_build\*"; DestDir: "{app}\app"; Flags: ignoreversion recursesubdirs createallsubdirs

; Server management scripts
Source: "scripts\start-server.bat"; DestDir: "{app}"; Flags: ignoreversion
Source: "scripts\stop-server.bat"; DestDir: "{app}"; Flags: ignoreversion
Source: "scripts\setup-wizard.bat"; DestDir: "{app}"; Flags: ignoreversion

; Nginx config
Source: "resources\nginx.conf"; DestDir: "{app}\nginx\conf"; Flags: ignoreversion

; Launcher
Source: "scripts\start-saffron.bat"; DestDir: "{app}"; Flags: ignoreversion

; ── Desktop & Start Menu Shortcuts ──
[Icons]
Name: "{group}\Saffron POS"; Filename: "{app}\start-saffron.bat"; WorkingDir: "{app}"
Name: "{group}\Start Server"; Filename: "{app}\start-server.bat"; WorkingDir: "{app}"
Name: "{group}\Stop Server"; Filename: "{app}\stop-server.bat"; WorkingDir: "{app}"
Name: "{group}\Setup Wizard"; Filename: "{app}\setup-wizard.bat"; WorkingDir: "{app}"
Name: "{group}\Uninstall"; Filename: "{uninstallexe}"
Name: "{autodesktop}\Saffron POS"; Filename: "{app}\start-saffron.bat"; WorkingDir: "{app}"; Tasks: desktopicon

; ── Tasks ──
[Tasks]
Name: "desktopicon"; Description: "Create desktop shortcut"; GroupDescription: "Additional icons:"; Flags: unchecked

; ── Post-Install ──
[Run]
; Run setup wizard after install
Filename: "{app}\setup-wizard.bat"; Description: "Run Setup Wizard (recommended)"; Flags: nowait postinstall skipifsilent shellexec

; ── Uninstall Cleanup ──
[UninstallDelete]
Type: filesandordirs; Name: "{app}\mariadb\data"
Type: filesandordirs; Name: "{app}\logs"
Type: files; Name: "{app}\app\.env"
Type: filesandordirs; Name: "{app}\app\uploads"
Type: filesandordirs; Name: "{app}\app\tmp"
Type: filesandordirs; Name: "{app}\app\backups"

[UninstallRun]
Filename: "{app}\stop-server.bat"; Flags: runhidden

; ── Pascal Code ──
[Code]
function PrepareToInstall(var NeedsRestart: Boolean): String;
begin
  Result := '';
  Exec(ExpandConstant('{app}\stop-server.bat'), '', '', SW_HIDE, ewWaitUntilTerminated, 10000);
end;

procedure CurStepChanged(CurStep: TSetupStep);
begin
  if CurStep = ssPostInstall then
  begin
    Exec(ExpandConstant('{app}\setup-wizard.bat'), '', '', SW_HIDE, ewWaitUntilTerminated, 60000);
  end;
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
begin
  if CurUninstallStep = usUninstall then
  begin
    Exec(ExpandConstant('{app}\stop-server.bat'), '', '', SW_HIDE, ewWaitUntilTerminated, 10000);
  end;
end;
