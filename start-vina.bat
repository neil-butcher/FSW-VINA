@echo off
setlocal
set "VINA_SETUP_FILE=%~f0"
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -Command "$source = [IO.File]::ReadAllText($env:VINA_SETUP_FILE); $body = ($source -split '(?m)^# POWERSHELL SETUP\r?\n', 2)[1]; & ([scriptblock]::Create($body))"
set "VINA_SETUP_EXIT=%ERRORLEVEL%"
echo.
if not "%VINA_SETUP_EXIT%"=="0" echo Setup failed. See the error above; your saved data has been kept.
pause
exit /b %VINA_SETUP_EXIT%
# POWERSHELL SETUP
$ErrorActionPreference = 'Stop'
$setupLock = $null

function Invoke-Docker {
    param([string[]] $DockerArgs)
    & docker @DockerArgs
    if ($LASTEXITCODE -ne 0) {
        throw "Docker failed (exit $LASTEXITCODE). Check the output above."
    }
}

function Invoke-Compose {
    param([string[]] $ComposeArgs)
    Invoke-Docker -DockerArgs (@('compose', '--project-name', 'vina-local', '--file', $script:composeFile) + $ComposeArgs)
}

function New-RandomSecret {
    param([switch] $Base64)
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    if ($Base64) { return [Convert]::ToBase64String($bytes) }
    return ([BitConverter]::ToString($bytes)).Replace('-', '').ToLowerInvariant()
}

function Write-SetupFile {
    param([string] $Path, [string] $Content)
    [IO.File]::WriteAllText($Path, $Content.Replace("`r`n", "`n"), (New-Object Text.UTF8Encoding $false))
}

try {
    $repo = Split-Path -Parent $env:VINA_SETUP_FILE
    Set-Location -LiteralPath $repo
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw 'Install Docker Desktop, select Linux containers, and start it before running this file.'
    }
    Write-Host 'Checking Docker Desktop...'
    $dockerOS = Invoke-Docker -DockerArgs @('info', '--format', '{{.OSType}}')
    if (($dockerOS -join '').Trim() -ne 'linux') {
        throw 'Docker Desktop must be running Linux containers.'
    }
    Invoke-Docker -DockerArgs @('compose', 'version')

    $state = Join-Path $repo '.vina-local'
    New-Item -ItemType Directory -Path $state -Force | Out-Null
    $setupLock = [IO.File]::Open((Join-Path $state 'setup.lock'), 'OpenOrCreate', 'ReadWrite', 'None')
    $runtimeEnv = Join-Path $state 'runtime.env'
    $databaseEnv = Join-Path $state 'database.env'
    $script:composeFile = Join-Path $state 'compose.yaml'

    if ((Test-Path -LiteralPath $runtimeEnv) -xor (Test-Path -LiteralPath $databaseEnv)) {
        throw 'A saved configuration file is missing from .vina-local. Restore it from backup before continuing; credentials will not be regenerated.'
    }
    if (-not (Test-Path -LiteralPath $runtimeEnv)) {
        $existingDatabase = Invoke-Docker -DockerArgs @('volume', 'ls', '--filter', 'name=^vina-local_database$', '--format', '{{.Name}}')
        if ($existingDatabase) {
            throw 'The VINA database volume already exists but its saved configuration is missing. Restore .vina-local from backup before continuing.'
        }
        Write-Host 'Creating application key and database credentials...'
        $appKey = 'base64:' + (New-RandomSecret -Base64)
        $dbPassword = New-RandomSecret
        $rootPassword = New-RandomSecret
        Write-SetupFile -Path $databaseEnv -Content @"
MARIADB_DATABASE=vina
MARIADB_USER=vina
MARIADB_PASSWORD=$dbPassword
MARIADB_ROOT_PASSWORD=$rootPassword
"@
        Write-SetupFile -Path $runtimeEnv -Content @"
APP_NAME=VINA
APP_ENV=production
APP_DEBUG=false
APP_KEY=$appKey
APP_URL=http://localhost:8080
LOG_CHANNEL=stderr
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=vina
DB_USERNAME=vina
DB_PASSWORD=$dbPassword
CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
MAIL_MAILER=log
"@
    } else {
        Write-Host 'Using saved configuration and existing data.'
    }

    # Paths are relative to this generated Compose file, inside .vina-local.
    Write-SetupFile -Path $script:composeFile -Content @'
services:
  db:
    image: mariadb:11.4
    restart: unless-stopped
    env_file: database.env
    volumes:
      - database:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 3s
      timeout: 5s
      retries: 40
      start_period: 15s
  web:
    image: vina:local-runtime
    build:
      context: ..
      dockerfile: docker/runtime.dockerfile
    restart: unless-stopped
    env_file: runtime.env
    ports:
      - "127.0.0.1:8080:80"
    volumes:
      - storage:/var/www/html/storage
    depends_on:
      db:
        condition: service_healthy
  scheduler:
    image: vina:local-runtime
    restart: unless-stopped
    env_file: runtime.env
    command: ["php", "artisan", "schedule:work"]
    volumes:
      - storage:/var/www/html/storage
    depends_on:
      db:
        condition: service_healthy
volumes:
  database:
  storage:
'@

    Write-Host 'Building VINA (the first build may take several minutes)...'
    Invoke-Compose -ComposeArgs @('build', 'web')
    Invoke-Compose -ComposeArgs @('stop', 'web', 'scheduler')
    Write-Host 'Starting MariaDB and waiting for it to be ready...'
    Invoke-Compose -ComposeArgs @('up', '-d', '--wait', '--wait-timeout', '180', 'db')
    Write-Host 'Applying database migrations...'
    Invoke-Compose -ComposeArgs @('run', '--rm', '--no-deps', 'web', 'php', 'artisan', 'migrate', '--force')
    Write-Host 'Installing missing survey text and interface translations...'
    Invoke-Compose -ComposeArgs @('run', '--rm', '--no-deps', 'web', 'php', 'artisan', 'db:seed', '--class=TranslationsTableSeeder', '--force')
    Write-Host 'Starting the web server...'
    Invoke-Compose -ComposeArgs @('up', '-d', 'web')

    Write-Host 'Waiting for the login page...'
    $ready = $false
    for ($attempt = 0; $attempt -lt 45; $attempt++) {
        try {
            $response = Invoke-WebRequest -Uri 'http://localhost:8080/login' -UseBasicParsing -TimeoutSec 5
            if ($response.StatusCode -eq 200) { $ready = $true; break }
        } catch { }
        Start-Sleep -Seconds 2
    }
    if (-not $ready) {
        Invoke-Compose -ComposeArgs @('logs', '--tail', '40', 'web')
        throw 'The login page did not become ready. Check the web logs above and whether port 8080 is already in use.'
    }

    Write-Host ''
    Write-Host 'Create an administrator or set the password for an existing administrator.'
    do {
        $adminEmail = Read-Host 'Administrator email'
        $validEmail = $adminEmail -match '^[a-zA-Z0-9.!#$%&''*+/=?^_`{|}~-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$'
        if (-not $validEmail) { Write-Host 'Enter a valid email address.' }
    } until ($validEmail)
    Write-Host 'Enter and confirm a password of 12 to 72 characters when prompted.'
    Invoke-Compose -ComposeArgs @('exec', '--user', 'www-data', 'web', 'php', 'artisan', 'app:local-user', $adminEmail, '--name=Administrator', '--admin')

    Write-Host 'Starting the scheduled jobs...'
    Invoke-Compose -ComposeArgs @('up', '-d', 'scheduler')
    Write-Host ''
    Write-Host 'VINA is ready: http://localhost:8080/login' -ForegroundColor Green
    Write-Host "Sign in with $adminEmail and the password you just entered."
    Write-Host 'Configuration is saved in .vina-local; keep it private and backed up.'
    Write-Host 'Database and survey files are stored in persistent Docker volumes.'
    Write-Host 'After login, visit /install-questions and /settingtable to configure the questionnaire.'
    try { Start-Process 'http://localhost:8080/login' }
    catch { Write-Host 'Open http://localhost:8080/login in your browser to sign in.' }
} catch {
    Write-Host ''
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
} finally {
    if ($null -ne $setupLock) { $setupLock.Dispose() }
}
