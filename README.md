# UISP NOC Dashboard

Important — Please Read First!
- The Docker Hub image for this project currently tracks an active development branch and may be unstable or change without notice.
- For stable, working versions, use the main branch from the GitHub repository and build locally.

---

Overview
- A self‑hosted Network Operations Center (NOC) dashboard for monitoring Ubiquiti UISP devices (gateways + CPEs).
- Runs as a Dockerized PHP + Apache app with SQLite logging, live alerts, a siren for outages, and an embedded Gotify server for push notifications.

Key Features
- Live polling of UISP API with 10s refresh.
- Online/offline indicators; flashing offline cards for gateways.
- Gateway device metrics: latency, CPU, RAM, temperature, uptime (where available).
- Acknowledgements to temporarily silence sound alerts.
- Siren (buz.mp3) for unacknowledged offline gateways (first at 30s, then every 10 minutes while still unacked).
- Historical metrics (SQLite) with quick history charts (Chart.js) for CPU/RAM/Temp/Latency.
- Embedded Gotify server for push notifications (offline/online transitions) — no separate server required.
- Multi‑arch support (ARMv7/ARM64/x86_64) via build script.

---

Container Details
- Base image: `php:8.2-apache`
- PHP extensions: `pdo`, `pdo_sqlite`
- System packages: `libsqlite3-dev`, `iputils-ping`, `curl`, `ca-certificates`
- Web server: Apache (foreground)
- Embedded services: Gotify server (background) via `start.sh`
- Working dir: `/var/www/html`
- App entrypoint: `start.sh` (starts Gotify, then Apache)
- Exposed ports:
  - `80`: UISP NOC web UI
  - `18080`: Gotify UI/API (embedded)

File/Directory Layout (container)
- `/var/www/html/index.php`: main PHP application
- `/var/www/html/assets/`: CSS/JS assets
- `/var/www/html/buz.mp3`: alert siren
- `/var/www/html/cache/`: persistent cache + database volume
  - `status_cache.json`: app state cache for acks/simulations
  - `metrics.sqlite`: historical metrics DB (created on demand)
  - `gotify/`: embedded Gotify data
    - `data.db`: Gotify SQLite database
  - `gotify_app_token.txt`: optional place to store your Gotify application token
- `/etc/gotify/config.yml`: Gotify server configuration

Start Script
- `start.sh` ensures `/var/www/html/cache/gotify/` exists, starts Gotify (logging to `/var/log/gotify.log`) and then launches Apache in the foreground.

---

Ports
- App UI: map host port to container `80` (default compose maps to `12443:80`).
- Embedded Gotify: map host port to container `18080` if you want to access the Gotify UI/API externally.

Volumes
- The compose file defines a single named volume for persistence:
  - `noc_cache:/var/www/html/cache`
- This volume stores all app cache, SQLite databases (metrics + gotify), and the optional `gotify_app_token.txt`.

Environment Variables
- Required
  - `UISP_URL`: Base URL of your UISP instance (e.g., `https://your-uisp.unmsapp.com`).
  - `UISP_TOKEN`: UISP API token.
- Embedded Gotify (defaults)
  - `GOTIFY_DEFAULTUSER_NAME`: Default Gotify admin username (e.g., `admin`).
  - `GOTIFY_DEFAULTUSER_PASS`: Default Gotify admin password (e.g., `changeme`).
- App → Gotify integration
  - `GOTIFY_TOKEN`: Gotify Application Token. If not set, the app will try to read `/var/www/html/cache/gotify_app_token.txt`.
  - `GOTIFY_URL`: Optional override (default: `http://127.0.0.1:18080`). Use this if you point to an external Gotify server.

Notifications (Embedded Gotify)
- The container includes a Gotify server listening on `0.0.0.0:18080` with data persisted under `/var/www/html/cache/gotify`.
- On first run, log into Gotify using the default user/pass from the environment variables.
- Create an Application in Gotify (e.g., “UISP NOC”) and copy the generated token.
- Provide the token to the app via either:
  1) `GOTIFY_TOKEN` environment variable, or
  2) Saving the token to `/var/www/html/cache/gotify_app_token.txt` (or `./cache/gotify_app_token.txt` on the host when using the compose volume).
- What gets notified:
  - Gateway OFFLINE: sent once when the device first goes offline and stays offline for ~30 seconds.
  - Gateway ONLINE: sent once on recovery.
- Acknowledgements suppress repeating siren alarms in the UI but do not retroactively stop already-sent push notifications.
- To use an external Gotify server, set `GOTIFY_URL` to your server and provide `GOTIFY_TOKEN`.

Quick Start (Docker Compose)
1) Clone the repo and edit the compose file if needed.

```bash
git clone https://github.com/asparks1987/UISP-NOC.git
cd UISP-NOC
docker compose up --build -d
```

App URLs
- UISP NOC: `http://<host-ip>:12443`
- Gotify (optional): `http://<host-ip>:18080`

docker-compose.yml (reference)
```yaml
version: '3.8'

services:
  uisp-noc:
    build: .
    container_name: uisp-noc
    environment:
      - UISP_URL=https://changeme.unmsapp.com
      - UISP_TOKEN=YOUR_API_TOKEN_HERE
      # Embedded Gotify defaults (change on first run)
      - GOTIFY_DEFAULTUSER_NAME=admin
      - GOTIFY_DEFAULTUSER_PASS=changeme
      # Optional: provide your Gotify application token here
      # - GOTIFY_TOKEN=your_gotify_application_token
    ports:
      - "12443:80"
      # Expose Gotify UI/API (optional)
      - "18080:18080"
    volumes:
      - noc_cache:/var/www/html/cache
    restart: unless-stopped

volumes:
  noc_cache:
```

Building Multi‑Arch Images
- Use the helper script to build for ARM + x86 and push to Docker Hub.

```bash
./build-multiarch.sh youruser/uisp-noc:latest
```

Then deploy with:

```yaml
image: youruser/uisp-noc:latest
```

Notes on Stability
- Docker Hub image: tracks development; expect frequent changes and potential breakage.
- GitHub main branch: recommended source for building stable images locally.
- For production, pin to a known-good tag or commit, and run behind a reverse proxy with HTTPS.

Security & Operations
- Pass the UISP API token via environment variables, secrets, or your orchestrator’s secret management (do not hardcode).
- Change the default Gotify admin credentials promptly.
- Recommended: run behind a reverse proxy (Traefik, Nginx) with TLS.
- Persistent data is stored in the `noc_cache` volume; back it up periodically if you rely on historical metrics or Gotify data.

Troubleshooting
- No notifications? Ensure you created a Gotify Application and configured `GOTIFY_TOKEN` (or saved the token file). Check `http://<host-ip>:18080` and verify login.
- No sound? Browsers often require a user gesture. Click “Enable Sound” in the app header once per session.
- High latency or missing metrics? Verify the container can reach device IPs; `iputils-ping` is available in the image; latency uses ICMP from inside the container.
- API failures? Confirm `UISP_URL` and `UISP_TOKEN` are correct and reachable from the container.

Roadmap (High Level)
- Role‑based access (basic auth / login screen)
- Customizable thresholds for alerts
- Export historical data (CSV/JSON)
- Optional Grafana integration for long‑term trending

License
- MIT — free to use, modify, and share.

