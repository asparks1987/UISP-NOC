<div align="center">

# UISP NOC

Monitoring that feels crisp, clear, and loud when it matters.

🖥️ Gateways • 📡 CPEs • 🔔 Live Alerts • 📈 History • 📬 Push Notifications

</div>

---

Table of Contents

- What Is This?
- Feature Highlights
- How It Works
- Quick Start
- Configuration
- Data & Persistence
- Security Notes
- Operations & Troubleshooting
- Customization
- Development
- FAQ
- License

---

What Is This?

UISP NOC is a self‑hosted, lightweight Network Operations Center (NOC) dashboard for Ubiquiti UISP environments. It runs as a single Dockerized PHP + Apache app, tracks live device status, plays a siren for critical outages, stores short‑term metrics in SQLite, and pushes notifications via an embedded Gotify server.

Perfect for a wallboard, a small NOC, or a home lab where you want a zero‑hassle view of gateways and CPEs.

---

Feature Highlights

- Live UISP Polling: Queries UISP every ~10s for device status and metrics.
- Gateways + CPEs: Two tabs with quick device cards and at‑a‑glance health.
- Offline‑First Sorting (CPE): CPE list surfaces offline devices first, then A–Z.
- Siren Alerts: Plays a buzzer for unacknowledged offline gateways.
  - First alert ~30s after initial offline, then periodic repeats.
  - One‑click Acknowledge (30m, 1h, 6h, 8h, 12h) to temporarily silence.
- Push Notifications: Embedded Gotify server for OFFLINE/ONLINE events.
- History: SQLite time‑series for gateway CPU/RAM/Temp/Latency with charts.
- Ping Scheduling (CPE): Pings up to 10 CPEs every 3 minutes, ensuring any one CPE is not pinged more than once per hour.
- Simple Deploy: Single container, self‑contained cache and DB.

---

How It Works

- Architecture
  - Runtime: PHP 8.2 on Apache (containerized)
  - Storage: SQLite database in `cache/metrics.sqlite`
  - Push: Embedded Gotify server listening on port `18080`
  - Assets: Plain JS (Chart.js) and CSS in `assets/`

- Polling & Metrics
  - Every ~10s the app calls the UISP API for device state and basic metrics.
  - Gateways are pinged at most once per minute and metrics are recorded.
  - CPE pinging is batched: up to 10 devices every 3 minutes, chosen randomly from CPEs that have not been pinged within the last hour.

- Alerts & Acknowledgements
  - Gateways changing to OFFLINE trigger a siren after a short threshold.
  - Repeats occur while the gateway remains offline and unacknowledged.
  - Acknowledging a device temporarily suppresses siren repeats.

- UI Notes
  - Gateways: Status, CPU, RAM, Temp, Latency, Uptime, ACK controls.
  - CPEs: Offline‑first sorting; recent CPE latency shown where available.
  - History modal: CPU/RAM/Temp/Latency charts per device (gateways).

---

Quick Start

Using Docker Compose (build from source – recommended)

```yaml
version: '3.8'

services:
  uisp-noc:
    build: .
    container_name: uisp-noc
    environment:
      UISP_URL: https://changeme.unmsapp.com
      UISP_TOKEN: YOUR_API_TOKEN_HERE
      GOTIFY_DEFAULTUSER_NAME: admin
      GOTIFY_DEFAULTUSER_PASS: changeme
      # Optional if you prefer to pass the app token directly
      # GOTIFY_TOKEN: your_gotify_application_token
      # GOTIFY_URL: http://127.0.0.1:18080  # use if targeting an external Gotify
    ports:
      - "12443:80"        # UISP NOC UI
      # - "18080:18080"   # (optional) expose embedded Gotify UI/API
    volumes:
      - noc_cache:/var/www/html/cache
    restart: unless-stopped

volumes:
  noc_cache:
```

Steps

1) Clone and start

```bash
git clone https://github.com/asparks1987/UISP-NOC.git
cd UISP-NOC
docker compose up -d
```

2) Open the UI at `http://<host-ip>:12443/`

3) Configure Gotify (embedded)

- Option A – UI login: Temporarily expose `18080:18080`, visit `http://<host-ip>:18080`, sign in with the defaults, create an Application, copy its token, and set `GOTIFY_TOKEN` in your compose file (or save it to `cache/gotify_app_token.txt`).
- Option B – Token file only: Place the application token in `cache/gotify_app_token.txt`. The app auto‑detects it on boot.

---

Configuration

Environment Variables (app)

- `UISP_URL` (required): Base URL of your UISP (e.g., `https://your-uisp.unmsapp.com`).
- `UISP_TOKEN` (required): UISP API token.
- `GOTIFY_TOKEN` (optional): Gotify Application token used by the app to send notifications.
- `GOTIFY_URL` (optional): Gotify endpoint. Defaults to the embedded server `http://127.0.0.1:18080`.

Environment Variables (embedded Gotify)

- `GOTIFY_DEFAULTUSER_NAME`: Initial Gotify admin username.
- `GOTIFY_DEFAULTUSER_PASS`: Initial Gotify admin password.

Ports

- `80` (container): UISP NOC web UI (map to any host port, default in examples is `12443`).
- `18080` (container): Embedded Gotify UI/API; expose only if you need external access.

Volumes

- `noc_cache:/var/www/html/cache` – single persistent volume holding:
  - `status_cache.json` (app state: ACKs, simulation flags, ping metadata)
  - `metrics.sqlite` (gateway history)
  - `gotify/` (embedded server data)
  - `gotify_app_token.txt` (optional place to store the app token)

---

Data & Persistence

- Metrics Retention: Data is appended to `metrics.sqlite`. Size depends on device count and polling. Back up as needed.
- Cache Behavior: `status_cache.json` tracks last‑seen state (ACKs, offline since, ping times) and is updated frequently.
- Removal: Removing the volume clears history, ACKs, and Gotify data.

---

Security Notes

- Treat `UISP_TOKEN` like a secret. Prefer orchestrator secrets or environment injection; avoid committing tokens.
- Change Gotify defaults immediately, or use an external Gotify service.
- Run behind HTTPS (reverse proxy: Nginx, Traefik, Caddy) for production.

---

Operations & Troubleshooting

- No notifications
  - Verify an Application exists in Gotify and the app has a valid token.
  - Check `GOTIFY_URL` if using an external server.

- No sound
  - Browsers often block autoplay; click the “Enable Sound” button once per session.

- High latency / missing pings
  - The container performs ICMP; ensure it can reach device IPs and that `iputils-ping` is present (it is in the image).

- API failures
  - Confirm `UISP_URL`/`UISP_TOKEN` and network reachability from the container.

- History charts empty
  - History persists for gateways based on the per‑minute ping cadence; give it a few minutes.

---

Customization

- CPE Sorting
  - Default: offline‑first, then A–Z. Implemented client‑side in `assets/app.js`.

- CPE Ping Rate
  - Default: up to 10 CPEs every 3 minutes, with a per‑CPE minimum of 1 hour between pings.
  - Located in `index.php` around the “CPE ping batch” section.
  - To change the window or batch size, adjust the `intdiv($now, 180)` granularity and the `array_slice(..., 0, 10)` limit accordingly.

- Siren Threshold & Repeat
  - Threshold for first alert is defined in `index.php` (`$FIRST_OFFLINE_THRESHOLD`).
  - Repeat cadence is managed in the frontend logic (`assets/app.js`).

---

Development

- Stack
  - PHP 8.2 + Apache, SQLite (PDO), basic cURL
  - Frontend: Vanilla JS + Chart.js

- Layout
  - `index.php` – backend + HTML template + API proxy
  - `assets/` – JS, CSS
  - `cache/` – runtime state and databases (created on first run)
  - `start.sh` – launches embedded Gotify then Apache

- Scripts
  - `build-multiarch.sh` – helper for building and pushing images for multiple architectures.

- Tips
  - Keep the `cache/` directory writable.
  - When iterating on styles or JS, file mtimes are used for cache‑busting via `?v=` query params.

---

FAQ

- Does this replace UISP?
  - No. It visualizes and alerts using UISP data; UISP remains the source of truth.

- Can I use an external Gotify?
  - Yes. Set `GOTIFY_URL` and provide a valid `GOTIFY_TOKEN`. You can disable port `18080` if not needed.

- Can I change the ping cadence?
  - Yes, edit `index.php` where the CPE batch is created and the gateway per‑minute logic is enforced. See Customization above.

---

License

MIT – free to use, modify, and share.

