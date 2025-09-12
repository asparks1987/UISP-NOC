Home AI: Single App Container (PHP + Python)
==========================================

This folder provides a single-container setup that runs both the PHP web app (Apache) and the Python API in one image, managed by Supervisor. MySQL remains a separate service for data persistence and easier upgrades.

Folder layout
-------------
- `Dockerfile`: Builds an all-in-one image from `php:8.2-apache`, installs Python + Supervisor, and copies your app code.
- `supervisord.conf`: Runs Apache (port 80) and your Python server (port 8000) together.
- `docker-compose.yml`: Defines `mysql` and `home-ai` (single app container). Exposes port 8080 for the PHP app.
- `php/`: Put your PHP application code here (copied to `/var/www/html`).
- `python/`: Put your Python backend here (copied to `/opt/home-ai-python`). Include `requirements.txt`.

Usage
-----
1) Copy your code:
   - PHP code → `home-ai-single/php/`
   - Python code → `home-ai-single/python/`
     - Add `home-ai-single/python/requirements.txt` with your dependencies.

2) Secrets: avoid committing secrets. Create `home-ai-single/.env` and put:
   - `HA_TOKEN=your_home_assistant_token`

3) Build and run from the `home-ai-single/` folder:
   - `docker compose build`
   - `docker compose up -d`

4) Visit:
   - Web (PHP): http://localhost:8080
   - Python API (optional exposure): uncomment `8000:8000` mapping in `docker-compose.yml` if needed.

Config Notes
------------
- PHP → Python base URL is set to `http://127.0.0.1:8000` via `PYTHON_API_BASE_URL`. This assumes PHP calls the Python API server-side. If your frontend JS calls the API directly from the browser, proxy `/api` in Apache or expose port 8000 and point the frontend to your host.
- Override the Python entrypoint by setting `PYTHON_START_CMD` in `docker-compose.yml` if your module is not `app.main:app`.
- MySQL stays separate for reliability. Combining it into one container is possible but not recommended (harder upgrades; brittle init).

Troubleshooting
---------------
- If PHP needs more extensions, modify the Dockerfile (e.g., `docker-php-ext-install zip gd`).
- If Python needs system libs (e.g., `libmariadb-dev` for `mysqlclient`), add `apt-get install` lines before `pip install`.
- Ensure your Python app binds `0.0.0.0:8000` (see `PYTHON_START_CMD`).

