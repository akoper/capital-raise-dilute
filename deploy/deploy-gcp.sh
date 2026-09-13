#!/usr/bin/env bash
set -euo pipefail

# Configuration defaults
PROJECT_ID="${PROJECT_ID:-$(gcloud config get-value project 2>/dev/null)}"
REGION="${REGION:-us-central1}"
REPO_NAME="${REPO_NAME:-edgar-tracker-repo}"
CONNECTOR_NAME="${CONNECTOR_NAME:-edgar-vpc-connector}"
REDIS_NAME="${REDIS_NAME:-edgar-redis}"
REVERB_KEY="${REVERB_KEY:-capital_raise_reverb_key}"
REVERB_SECRET="${REVERB_SECRET:-capital_raise_reverb_secret}"
REVERB_APP_ID="${REVERB_APP_ID:-100001}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
BACKEND_DIR="${ROOT_DIR}/backend"

if [ -z "${PROJECT_ID}" ]; then
  echo "Error: PROJECT_ID is not set. Run 'gcloud config set project <ID>' or pass PROJECT_ID=..."
  exit 1
fi

echo "==> Deploying to Google Cloud (Project: ${PROJECT_ID}, Region: ${REGION})"

# 1. Enable required APIs
echo "==> Enabling required GCP APIs..."
gcloud services enable \
  run.googleapis.com \
  artifactregistry.googleapis.com \
  redis.googleapis.com \
  vpcaccess.googleapis.com \
  cloudscheduler.googleapis.com \
  cloudbuild.googleapis.com \
  --project="${PROJECT_ID}"

# 2. Artifact Registry
echo "==> Ensuring Artifact Registry repository exists..."
if ! gcloud artifacts repositories describe "${REPO_NAME}" --location="${REGION}" --project="${PROJECT_ID}" &>/dev/null; then
  gcloud artifacts repositories create "${REPO_NAME}" \
    --repository-format=docker \
    --location="${REGION}" \
    --description="Docker repository for SEC EDGAR Tracker" \
    --project="${PROJECT_ID}"
fi

# 3. VPC Connector
echo "==> Ensuring Serverless VPC Access connector exists..."
if ! gcloud compute networks vpc-access connectors describe "${CONNECTOR_NAME}" --region="${REGION}" --project="${PROJECT_ID}" &>/dev/null; then
  gcloud compute networks vpc-access connectors create "${CONNECTOR_NAME}" \
    --region="${REGION}" \
    --range="10.8.0.0/28" \
    --network="default" \
    --project="${PROJECT_ID}"
fi

# 4. Redis Memorystore
echo "==> Ensuring Cloud Memorystore for Redis exists..."
if ! gcloud redis instances describe "${REDIS_NAME}" --region="${REGION}" --project="${PROJECT_ID}" &>/dev/null; then
  gcloud redis instances create "${REDIS_NAME}" \
    --size=1 \
    --region="${REGION}" \
    --zone="${REGION}-a" \
    --tier=basic \
    --network="default" \
    --project="${PROJECT_ID}"
fi

REDIS_IP=$(gcloud redis instances describe "${REDIS_NAME}" --region="${REGION}" --project="${PROJECT_ID}" --format="value(host)")
echo "==> Redis host IP: ${REDIS_IP}"

# 5. Build and push backend image
IMAGE_BACKEND="${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPO_NAME}/backend:latest"
echo "==> Building backend container image via Cloud Build..."
gcloud builds submit "${BACKEND_DIR}" --tag "${IMAGE_BACKEND}" --project="${PROJECT_ID}"

# 5b. Build and push frontend image
FRONTEND_DIR="${ROOT_DIR}/frontend"
IMAGE_FRONTEND="${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPO_NAME}/frontend:latest"
if [ -d "${FRONTEND_DIR}" ]; then
  echo "==> Building frontend container image via Cloud Build..."
  gcloud builds submit "${FRONTEND_DIR}" --tag "${IMAGE_FRONTEND}" --project="${PROJECT_ID}"
fi

# 6. Deploy Reverb WebSocket Service to Cloud Run
echo "==> Deploying Reverb WebSocket service..."
gcloud run deploy edgar-reverb \
  --image="${IMAGE_BACKEND}" \
  --platform=managed \
  --region="${REGION}" \
  --allow-unauthenticated \
  --vpc-connector="${CONNECTOR_NAME}" \
  --port=8080 \
  --timeout=3600 \
  --session-affinity \
  --min-instances=1 \
  --command="php","artisan","reverb:start","--host=0.0.0.0","--port=8080" \
  --set-env-vars="APP_ENV=production,REDIS_HOST=${REDIS_IP},REDIS_PORT=6379,CACHE_STORE=redis,SESSION_DRIVER=redis,BROADCAST_CONNECTION=reverb,REVERB_APP_ID=${REVERB_APP_ID},REVERB_APP_KEY=${REVERB_KEY},REVERB_APP_SECRET=${REVERB_SECRET},REVERB_HOST=0.0.0.0,REVERB_PORT=8080,REVERB_SCHEME=https" \
  --project="${PROJECT_ID}"

REVERB_URL=$(gcloud run services describe edgar-reverb --region="${REGION}" --project="${PROJECT_ID}" --format="value(status.url)")
REVERB_HOST=$(echo "${REVERB_URL}" | sed 's|https://||')
echo "==> Reverb URL: ${REVERB_URL} (Host: ${REVERB_HOST})"

# 7. Deploy Laravel REST API Service to Cloud Run
echo "==> Deploying Laravel REST API service..."
gcloud run deploy edgar-api \
  --image="${IMAGE_BACKEND}" \
  --platform=managed \
  --region="${REGION}" \
  --allow-unauthenticated \
  --port=8080 \
  --vpc-connector="${CONNECTOR_NAME}" \
  --set-env-vars="APP_ENV=production,APP_DEBUG=false,REDIS_HOST=${REDIS_IP},REDIS_PORT=6379,CACHE_STORE=redis,SESSION_DRIVER=redis,BROADCAST_CONNECTION=reverb,REVERB_APP_ID=${REVERB_APP_ID},REVERB_APP_KEY=${REVERB_KEY},REVERB_APP_SECRET=${REVERB_SECRET},REVERB_HOST=${REVERB_HOST},REVERB_PORT=443,REVERB_SCHEME=https" \
  --project="${PROJECT_ID}"

API_URL=$(gcloud run services describe edgar-api --region="${REGION}" --project="${PROJECT_ID}" --format="value(status.url)")
echo "==> API URL: ${API_URL}"

# 8. Deploy Scheduled Cloud Run Job for Feed Ingestion
echo "==> Deploying Cloud Run Job for SEC Edgar feed polling..."
if ! gcloud run jobs describe edgar-schedule-job --region="${REGION}" --project="${PROJECT_ID}" &>/dev/null; then
  gcloud run jobs create edgar-schedule-job \
    --image="${IMAGE_BACKEND}" \
    --region="${REGION}" \
    --vpc-connector="${CONNECTOR_NAME}" \
    --command="php","artisan","schedule:run" \
    --set-env-vars="APP_ENV=production,REDIS_HOST=${REDIS_IP},REDIS_PORT=6379,CACHE_STORE=redis,SESSION_DRIVER=redis,BROADCAST_CONNECTION=reverb,REVERB_APP_ID=${REVERB_APP_ID},REVERB_APP_KEY=${REVERB_KEY},REVERB_APP_SECRET=${REVERB_SECRET},REVERB_HOST=${REVERB_HOST},REVERB_PORT=443,REVERB_SCHEME=https" \
    --project="${PROJECT_ID}"
else
  gcloud run jobs update edgar-schedule-job \
    --image="${IMAGE_BACKEND}" \
    --region="${REGION}" \
    --vpc-connector="${CONNECTOR_NAME}" \
    --command="php","artisan","schedule:run" \
    --set-env-vars="APP_ENV=production,REDIS_HOST=${REDIS_IP},REDIS_PORT=6379,CACHE_STORE=redis,SESSION_DRIVER=redis,BROADCAST_CONNECTION=reverb,REVERB_APP_ID=${REVERB_APP_ID},REVERB_APP_KEY=${REVERB_KEY},REVERB_APP_SECRET=${REVERB_SECRET},REVERB_HOST=${REVERB_HOST},REVERB_PORT=443,REVERB_SCHEME=https" \
    --project="${PROJECT_ID}"
fi

# 9. Deploy Angular Frontend Service to Cloud Run
FRONTEND_URL=""
if [ -d "${FRONTEND_DIR}" ]; then
  echo "==> Deploying Angular Frontend service..."
  if gcloud run deploy edgar-frontend \
    --image="${IMAGE_FRONTEND}" \
    --platform=managed \
    --region="${REGION}" \
    --allow-unauthenticated \
    --port=8080 \
    --project="${PROJECT_ID}"; then
    FRONTEND_URL=$(gcloud run services describe edgar-frontend --region="${REGION}" --project="${PROJECT_ID}" --format="value(status.url)")
    echo "==> Frontend URL: ${FRONTEND_URL}"
  fi
fi

echo "==> Deployment Complete!"
if [ -n "${FRONTEND_URL}" ]; then
  echo "Frontend App: ${FRONTEND_URL}"
fi
echo "Backend API: ${API_URL}"
echo "Reverb WebSocket URL: ${REVERB_URL}"
