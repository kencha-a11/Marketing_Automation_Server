# test-laravel-docker.ps1 (Windows, robust)
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host " Laravel Docker Test Script (Windows)" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

# Function to check Docker connectivity
function Test-DockerDaemon {
    try {
        $result = docker info 2>&1
        if ($LASTEXITCODE -ne 0 -or $result -match "error|not running|cannot connect") {
            return $false
        }
        return $true
    } catch {
        return $false
    }
}

# Check if Docker CLI exists
$dockerVersion = docker --version 2>$null
if (-not $dockerVersion) {
    Write-Host "[ERROR] Docker not found. Please install Docker Desktop." -ForegroundColor Red
    Write-Host "        Download: https://www.docker.com/products/docker-desktop/" -ForegroundColor Yellow
    exit 1
}
Write-Host "[OK] Docker CLI found: $dockerVersion" -ForegroundColor Green

# Check if Docker daemon is responsive
Write-Host "[CHECK] Waiting for Docker daemon to respond..." -ForegroundColor Yellow
$maxAttempts = 10
$attempt = 0
$daemonOk = $false

while ($attempt -lt $maxAttempts -and -not $daemonOk) {
    Start-Sleep -Seconds 2
    $daemonOk = Test-DockerDaemon
    $attempt++
    Write-Host "        Attempt $attempt of $maxAttempts..." -ForegroundColor DarkYellow
}

if (-not $daemonOk) {
    Write-Host "`n[ERROR] Docker daemon is not responding." -ForegroundColor Red
    Write-Host "`nPossible fixes:" -ForegroundColor Yellow
    Write-Host "  1. Launch Docker Desktop from Start Menu." -ForegroundColor White
    Write-Host "  2. Right-click the Docker whale icon in system tray and select 'Switch to Linux containers'." -ForegroundColor White
    Write-Host "  3. Wait for the whale icon to become steady (no animation)." -ForegroundColor White
    Write-Host "  4. Run this script again.`n" -ForegroundColor White
    exit 1
}
Write-Host "[OK] Docker daemon is running and responsive.`n" -ForegroundColor Green

# Variables
$imageName = "laravel-test"
$containerName = "laravel-test-container"
$hostPort = 8080

# 1. Build the image
Write-Host "[1/4] Building Docker image '$imageName'..." -ForegroundColor Yellow
$buildLog = docker build -t $imageName . 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Host "[ERROR] Docker build failed." -ForegroundColor Red
    Write-Host "Build output (last 20 lines):" -ForegroundColor Red
    $buildLog | Select-Object -Last 20
    exit 1
}
Write-Host "[OK] Image built successfully.`n" -ForegroundColor Green

# 2. Remove any existing container with the same name
Write-Host "[2/4] Cleaning up old container (if exists)..." -ForegroundColor Yellow
docker rm -f $containerName 2>$null | Out-Null
Write-Host "[OK] Cleanup done.`n" -ForegroundColor Green

# 3. Run the container
Write-Host "[3/4] Starting container '$containerName' on port $hostPort ..." -ForegroundColor Yellow
$runResult = docker run -d --name $containerName -p ${hostPort}:80 $imageName 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Host "[ERROR] Docker run failed." -ForegroundColor Red
    Write-Host $runResult
    exit 1
}
Write-Host "[OK] Container started.`n" -ForegroundColor Green

# 4. Wait a few seconds for Apache to fully boot
Write-Host "[4/4] Waiting 10 seconds for services to start..." -ForegroundColor Yellow
Start-Sleep -Seconds 10

# Check container logs
Write-Host "`n--- Container logs (first 40 lines) ---" -ForegroundColor Cyan
docker logs --tail 40 $containerName

# Test HTTP response
Write-Host "`n--- Testing HTTP connection ---" -ForegroundColor Cyan
try {
    $response = Invoke-WebRequest -Uri "http://localhost:$hostPort" -UseBasicParsing -TimeoutSec 15
    Write-Host "[OK] HTTP request succeeded. Status code: $($response.StatusCode)" -ForegroundColor Green
    Write-Host "     Content-Type: $($response.Headers['Content-Type'])" -ForegroundColor Green
} catch {
    Write-Host "[ERROR] Failed to connect to http://localhost:$hostPort" -ForegroundColor Red
    Write-Host "        $_" -ForegroundColor Red
    Write-Host "`nCheck container logs for errors (e.g., missing .env file or database connection)." -ForegroundColor Yellow
}

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host " Test completed." -ForegroundColor Cyan
Write-Host " Container is running in background." -ForegroundColor Cyan
Write-Host " To stop it: docker stop $containerName" -ForegroundColor Cyan
Write-Host " To remove it: docker rm -f $containerName" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan
