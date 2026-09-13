# Deployment command in Windows Powershell: .\deploy\deploy-gcp.ps1 -ProjectId "capital-raise-dilute" -Region "us-central1"

param(
    [string]$ProjectId = $(cmd /c "gcloud config get-value project 2>nul"),
    [string]$Region = "us-central1",
    [string]$RepoName = "edgar-tracker-repo",
    [string]$ConnectorName = "edgar-vpc-connector",
    [string]$RedisName = "edgar-redis",
    [string]$ReverbKey = "capital_raise_reverb_key",
    [string]$ReverbSecret = "capital_raise_reverb_secret",
    [string]$ReverbAppId = "100001"
)

$ErrorActionPreference = "Continue"

# Prefer gcloud.cmd on Windows to prevent gcloud.ps1 from treating informational stderr output as NativeCommandError
if (Get-Command gcloud.cmd -ErrorAction SilentlyContinue) {
    Set-Alias -Name gcloud -Value (Get-Command gcloud.cmd).Source -Scope Script
}

if (-not $ProjectId -or $ProjectId.Trim() -eq "") {
    Write-Host "ERROR: Please set a valid GCP Project ID using -ProjectId or 'gcloud config set project <ID>'" -ForegroundColor Red
    exit 1
}

$ProjectId = $ProjectId.Trim()

# Resolve backend directory relative to this script
$ScriptDir = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
$RootDir = (Get-Item $ScriptDir).Parent.FullName
$BackendDir = Join-Path $RootDir "backend"

if (-not (Test-Path $BackendDir)) {
    Write-Host "ERROR: Could not locate backend directory at '$BackendDir'" -ForegroundColor Red
    exit 1
}

Write-Host "==> Deploying to Google Cloud (Project: $ProjectId, Region: $Region)" -ForegroundColor Cyan

# 1. Enable GCP Services
Write-Host "==> Enabling GCP services..." -ForegroundColor Yellow
gcloud services enable run.googleapis.com artifactregistry.googleapis.com redis.googleapis.com vpcaccess.googleapis.com cloudscheduler.googleapis.com cloudbuild.googleapis.com --project=$ProjectId
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Failed to enable GCP services." -ForegroundColor Red
    exit 1
}

# 2. Artifact Registry
Write-Host "==> Ensuring Artifact Registry repository exists..." -ForegroundColor Yellow
cmd /c "gcloud artifacts repositories describe $RepoName --location=$Region --project=$ProjectId >nul 2>nul"
if ($LASTEXITCODE -ne 0) {
    Write-Host "Creating Artifact Registry repository '$RepoName'..." -ForegroundColor Yellow
    gcloud artifacts repositories create $RepoName --repository-format=docker --location=$Region --description="Docker repository for SEC EDGAR Tracker" --project=$ProjectId
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: Failed to create Artifact Registry repository." -ForegroundColor Red
        exit 1
    }
}

# 3. Serverless VPC Access
Write-Host "==> Ensuring Serverless VPC Access connector exists..." -ForegroundColor Yellow
cmd /c "gcloud compute networks vpc-access connectors describe $ConnectorName --region=$Region --project=$ProjectId >nul 2>nul"
if ($LASTEXITCODE -ne 0) {
    Write-Host "Creating VPC connector '$ConnectorName' (this may take 2-3 minutes)..." -ForegroundColor Yellow
    gcloud compute networks vpc-access connectors create $ConnectorName --region=$Region --range="10.8.0.0/28" --network="default" --project=$ProjectId
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: Failed to create VPC connector." -ForegroundColor Red
        exit 1
    }
}

# 4. Redis Memorystore
Write-Host "==> Ensuring Cloud Memorystore for Redis exists..." -ForegroundColor Yellow
cmd /c "gcloud redis instances describe $RedisName --region=$Region --project=$ProjectId >nul 2>nul"
if ($LASTEXITCODE -ne 0) {
    Write-Host "Creating Redis instance '$RedisName' (this can take 3-5 minutes)..." -ForegroundColor Yellow
    gcloud redis instances create $RedisName --size=1 --region=$Region --zone="${Region}-a" --tier=basic --network="default" --project=$ProjectId
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: Failed to create Redis instance." -ForegroundColor Red
        exit 1
    }
}

$RedisIp = (cmd /c "gcloud redis instances describe $RedisName --region=$Region --project=$ProjectId --format=""value(host)"" 2>nul").Trim()
Write-Host "==> Redis host IP: $RedisIp" -ForegroundColor Green

# 5. Build and push backend image
$ImageBackend = "${Region}-docker.pkg.dev/${ProjectId}/${RepoName}/backend:latest"
Write-Host "==> Building and pushing backend container image via Cloud Build ($ImageBackend)..." -ForegroundColor Yellow
gcloud builds submit "$BackendDir" --tag "$ImageBackend" --project=$ProjectId
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Cloud Build failed to build and push image '$ImageBackend'." -ForegroundColor Red
    exit 1
}

# 5b. Build and push frontend image
$FrontendDir = Join-Path $RootDir "frontend"
$ImageFrontend = "${Region}-docker.pkg.dev/${ProjectId}/${RepoName}/frontend:latest"
if (Test-Path $FrontendDir) {
    Write-Host "==> Building and pushing frontend container image via Cloud Build ($ImageFrontend)..." -ForegroundColor Yellow
    gcloud builds submit "$FrontendDir" --tag "$ImageFrontend" --project=$ProjectId
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: Cloud Build failed to build and push image '$ImageFrontend'." -ForegroundColor Red
        exit 1
    }
}

# 6. Deploy Reverb WebSocket Service
Write-Host "==> Deploying Reverb WebSocket service..." -ForegroundColor Yellow
gcloud run deploy edgar-reverb `
    --image=$ImageBackend `
    --platform=managed `
    --region=$Region `
    --allow-unauthenticated `
    --vpc-connector=$ConnectorName `
    --port=8080 `
    --timeout=3600 `
    --session-affinity `
    --min-instances=1 `
    --command="php","artisan","reverb:start","--host=0.0.0.0","--port=8080" `
    --set-env-vars="APP_ENV=production,REDIS_HOST=$RedisIp,REDIS_PORT=6379,CACHE_STORE=redis,SESSION_DRIVER=redis,BROADCAST_CONNECTION=reverb,REVERB_APP_ID=$ReverbAppId,REVERB_APP_KEY=$ReverbKey,REVERB_APP_SECRET=$ReverbSecret,REVERB_HOST=0.0.0.0,REVERB_PORT=8080,REVERB_SCHEME=https" `
    --project=$ProjectId
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Failed to deploy edgar-reverb." -ForegroundColor Red
    exit 1
}

$ReverbUrl = (cmd /c "gcloud run services describe edgar-reverb --region=$Region --project=$ProjectId --format=""value(status.url)"" 2>nul").Trim()
$ReverbHost = $ReverbUrl -replace '^https?://', ''
Write-Host "==> Reverb Host: $ReverbHost" -ForegroundColor Green

# 7. Deploy Laravel REST API Service
Write-Host "==> Deploying Laravel REST API service..." -ForegroundColor Yellow
gcloud run deploy edgar-api `
    --image=$ImageBackend `
    --platform=managed `
    --region=$Region `
    --allow-unauthenticated `
    --port=8080 `
    --vpc-connector=$ConnectorName `
    --set-env-vars="APP_ENV=production,APP_DEBUG=false,REDIS_HOST=$RedisIp,REDIS_PORT=6379,CACHE_STORE=redis,SESSION_DRIVER=redis,BROADCAST_CONNECTION=reverb,REVERB_APP_ID=$ReverbAppId,REVERB_APP_KEY=$ReverbKey,REVERB_APP_SECRET=$ReverbSecret,REVERB_HOST=$ReverbHost,REVERB_PORT=443,REVERB_SCHEME=https" `
    --project=$ProjectId
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Failed to deploy edgar-api." -ForegroundColor Red
    exit 1
}

$ApiUrl = (cmd /c "gcloud run services describe edgar-api --region=$Region --project=$ProjectId --format=""value(status.url)"" 2>nul").Trim()
Write-Host "==> API URL: $ApiUrl" -ForegroundColor Green

# 8. Deploy Scheduled Cloud Run Job
Write-Host "==> Deploying Cloud Run Job for SEC Edgar feed ingestion..." -ForegroundColor Yellow
cmd /c "gcloud run jobs describe edgar-schedule-job --region=$Region --project=$ProjectId >nul 2>nul"
if ($LASTEXITCODE -ne 0) {
    gcloud run jobs create edgar-schedule-job `
        --image=$ImageBackend `
        --region=$Region `
        --vpc-connector=$ConnectorName `
        --command="php","artisan","schedule:run" `
        --set-env-vars="APP_ENV=production,REDIS_HOST=$RedisIp,REDIS_PORT=6379,CACHE_STORE=redis,SESSION_DRIVER=redis,BROADCAST_CONNECTION=reverb,REVERB_APP_ID=$ReverbAppId,REVERB_APP_KEY=$ReverbKey,REVERB_APP_SECRET=$ReverbSecret,REVERB_HOST=$ReverbHost,REVERB_PORT=443,REVERB_SCHEME=https" `
        --project=$ProjectId
} else {
    gcloud run jobs update edgar-schedule-job `
        --image=$ImageBackend `
        --region=$Region `
        --vpc-connector=$ConnectorName `
        --command="php","artisan","schedule:run" `
        --set-env-vars="APP_ENV=production,REDIS_HOST=$RedisIp,REDIS_PORT=6379,CACHE_STORE=redis,SESSION_DRIVER=redis,BROADCAST_CONNECTION=reverb,REVERB_APP_ID=$ReverbAppId,REVERB_APP_KEY=$ReverbKey,REVERB_APP_SECRET=$ReverbSecret,REVERB_HOST=$ReverbHost,REVERB_PORT=443,REVERB_SCHEME=https" `
        --project=$ProjectId
}
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Failed to deploy edgar-schedule-job." -ForegroundColor Red
    exit 1
}

# 9. Deploy Angular Frontend Service
$FrontendUrl = ""
if (Test-Path $FrontendDir) {
    Write-Host "==> Deploying Angular Frontend service..." -ForegroundColor Yellow
    gcloud run deploy edgar-frontend `
        --image=$ImageFrontend `
        --platform=managed `
        --region=$Region `
        --allow-unauthenticated `
        --port=8080 `
        --project=$ProjectId
    if ($LASTEXITCODE -eq 0) {
        $FrontendUrl = (cmd /c "gcloud run services describe edgar-frontend --region=$Region --project=$ProjectId --format=""value(status.url)"" 2>nul").Trim()
        Write-Host "==> Frontend URL: $FrontendUrl" -ForegroundColor Green
    }
}

Write-Host "==> Deployment to Google Cloud Completed Successfully!" -ForegroundColor Green
if ($FrontendUrl) {
    Write-Host "Frontend App: $FrontendUrl" -ForegroundColor Cyan
}
Write-Host "Backend API: $ApiUrl" -ForegroundColor Cyan
Write-Host "Reverb WebSocket: $ReverbUrl" -ForegroundColor Cyan
