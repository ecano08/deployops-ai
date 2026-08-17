# DeployOps AI

Foundation monorepo for DeployOps AI: a Laravel API, React frontend, and FastAPI AI service.

## Architecture

```
React (web/)  →  Laravel API (repo root)  →  FastAPI (ai-service/)
                      ↓
                 MySQL (operational DB)
                      ↓
                 Redis (queues / async)
```

PostgreSQL with pgvector is provisioned via Docker for future RAG work. It is **not** the Laravel application database.

## Stack

| Layer | Technology |
| --- | --- |
| API | Laravel (PHP 8.4+) |
| Frontend | React, TypeScript, Vite |
| AI service | FastAPI (Python 3.13+) |
| Operational DB | MySQL |
| Queues | Redis |
| Future RAG store | PostgreSQL + pgvector (Docker) |

## Local setup

### Prerequisites

- PHP 8.4+, Composer
- Node.js 24+, npm
- Python 3.13+
- MySQL
- Docker (for Redis and PostgreSQL/pgvector)

### 1. Laravel API

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Configure MySQL in `.env` (`DB_CONNECTION=mysql`, host, database, credentials). Queues use Redis (`QUEUE_CONNECTION=redis`).

### 2. Infrastructure (Redis + PostgreSQL)

```bash
docker compose up -d
```

- Redis: `127.0.0.1:6379`
- PostgreSQL (pgvector): `127.0.0.1:5432` — reserved for future RAG, not Laravel

### 3. AI service

```bash
cd ai-service
python -m venv .venv
source .venv/bin/activate   # Windows: .venv\Scripts\activate
pip install -r requirements.txt
uvicorn main:app --reload --port 8001
```

Set `AI_SERVICE_URL=http://127.0.0.1:8001` in the root `.env`.

### 4. React frontend

```bash
cd web
cp .env.example .env
npm install
npm run dev
```

Set `VITE_API_URL` to your Laravel API base URL (e.g. `http://deployops-ai.test`).

## Health endpoints

| Endpoint | Service | Success |
| --- | --- | --- |
| `GET /api/health` | Laravel API | `200` — `{"status":"ok","service":"api"}` |
| `GET /api/health/ai` | Laravel → FastAPI | `200` when AI service is reachable; `503` on timeout, connection failure, or upstream error |
| `GET /health` | FastAPI | `200` — `{"status":"ok","service":"ai-service"}` |

## Tests

```bash
# Laravel (Pest)
php artisan test

# React
cd web && npm run lint && npm run build

# FastAPI
cd ai-service && python -m pytest

# Docker Compose validation
docker compose config
```

## CI

GitHub Actions workflow (`.github/workflows/ci.yml`) runs on push and pull requests to `master`:

- **Laravel Tests** — migrations against MySQL, `php artisan test`
- **React Lint & Build** — ESLint and production build in `web/`
- **Python Tests** — pytest in `ai-service/`

## PR1 status — Foundation / Core

| Item | Status |
| --- | --- |
| Laravel API at repo root | Done |
| React + TypeScript + Vite in `/web` | Done |
| FastAPI in `/ai-service` | Done |
| MySQL operational DB | Done |
| Redis queues (`QUEUE_CONNECTION=redis`) | Done |
| PostgreSQL + pgvector (Docker, future RAG) | Done |
| `/api/health` | Done |
| `/api/health/ai` with `503` hardening | Done |
| FastAPI `/health` | Done |
| Pest tests (success + unavailable AI) | Done |
| Python pytest health test | Done |
| React lint/build | Done |
| CI workflow | Done |
| README and env examples | Done |

**Not in PR1:** authentication, workspaces, RBAC, OpenAI, RAG, agents, GitHub integration, UI redesign, deployment.
