# AnalyticsPM

Systeme d'analytics complet pour serveurs **PocketMine-MP 5**. Un plugin collecte les donnees de vos joueurs et les envoie a un panel web moderne pour visualiser et analyser le comportement, la retention et l'engagement.

![Theme](https://img.shields.io/badge/theme-violet%2Frose-8b5cf6)
![PocketMine](https://img.shields.io/badge/PocketMine--MP-5.x-green)
![Node.js](https://img.shields.io/badge/Node.js-18%2B-blue)

---

## Architecture

```
AnalyticsPM/
├── web-panel/              # Dashboard web (Node.js + Express)
│   ├── server.js           # Serveur API + routes
│   ├── views/              # Pages EJS (dashboard, joueurs, retention, mondes)
│   ├── public/             # CSS, JS frontend
│   └── data/               # Base SQLite (cree automatiquement)
│
└── mcpe-analytics-plugin/  # Plugin PocketMine-MP 5
    ├── plugin.yml
    ├── resources/config.yml
    └── src/mcpe/analytics/  # Main, EventListener, ApiClient, etc.
```

## Donnees collectees

| Donnee | Description |
|---|---|
| **Sessions** | Connexion, deconnexion, duree, plateforme |
| **Plateforme** | Windows, Android, iOS, Xbox, Switch, PlayStation... |
| **Commandes** | Nom, arguments, monde, timestamp |
| **Mondes** | Visites, duree par monde, changements de monde |
| **Chat** | Messages envoyes (desactivable) |
| **Morts** | Cause, position XYZ, monde |
| **Blocs** | Place/casse (desactivable, genere beaucoup de donnees) |

## Pages du dashboard

- **Dashboard** — KPIs, joueurs actifs/jour, plateformes, heures de pointe (heatmap), churn, commandes populaires, engagement, insights automatiques
- **Joueurs** — Liste complete avec recherche, tri, statut (en ligne/absent/inactif), temps moyen par session
- **Detail joueur** — Fiche complete : historique des sessions, commandes, mondes, morts, messages, insights personnalises (risque de churn, joueur fidele, sessions courtes...)
- **Retention** — Taux J+1 et J+7, entonnoir de retention, tendances, insights automatiques (retention critique, decrochage, etc.)
- **Mondes** — Joueurs uniques par monde, temps total, temps moyen par visite, mondes sous-utilises

---

## Installation

### 1. Serveur web (VPS ou local)

**Prerequis :** Node.js 18+

```bash
cd web-panel
npm install
```

**Lancer :**

```bash
API_KEY="ta-cle-secrete" PORT=3000 node server.js
```

Le dashboard est accessible sur `http://localhost:3000`.

### 2. Plugin PocketMine-MP

Copiez le dossier `mcpe-analytics-plugin` dans le dossier `plugins/` de votre serveur PocketMine-MP 5. Au premier demarrage, le fichier `config.yml` sera genere.

---

## Configuration

### Generer une cle API securisee

```bash
openssl rand -hex 32
```

### Plugin (`plugins/MCPEAnalytics/config.yml`)

```yaml
# URL du panel web
panel-url: "http://ton-vps-ip:3000"

# Cle API (doit etre identique au serveur web)
api-key: "ta-cle-secrete"

# Intervalle d'envoi des events bufferises (secondes)
flush-interval: 30

# Activer/desactiver le tracking
track-chat: true
track-blocks: false
track-deaths: true
track-worlds: true
track-commands: true

# Mode debug (logs verbeux)
debug: false
```

### Serveur web (variables d'environnement)

| Variable | Description | Defaut |
|---|---|---|
| `API_KEY` | Cle API partagee avec le plugin | `mcpe-analytics-secret-key-change-me` |
| `PORT` | Port du serveur web | `3000` |

**La cle API doit etre identique des deux cotes.**

---

## Deploiement en production (VPS)

### Avec systemd

Creez le fichier `/etc/systemd/system/mcpe-analytics.service` :

```ini
[Unit]
Description=MCPE Analytics Panel
After=network.target

[Service]
Type=simple
User=votre-user
WorkingDirectory=/chemin/vers/web-panel
ExecStart=/usr/bin/node server.js
Environment=API_KEY=votre-cle-secrete
Environment=PORT=3000
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable mcpe-analytics
sudo systemctl start mcpe-analytics
```

Verifier : `sudo systemctl status mcpe-analytics`

### Firewall

```bash
sudo ufw allow 3000
```

### Reverse proxy Nginx (optionnel, pour un nom de domaine)

```nginx
server {
    listen 80;
    server_name analytics.votreserveur.com;

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }
}
```

---

## Commandes en jeu

| Commande | Description | Permission |
|---|---|---|
| `/analytics status` | Etat du plugin, buffer, sessions actives | `analytics.admin` |
| `/analytics flush` | Force l'envoi des events bufferises | `analytics.admin` |
| `/analytics stats` | Joueurs en ligne et leur temps de session | `analytics.admin` |

---

## Resume de la configuration

| Parametre | Serveur web (VPS) | Plugin PocketMine |
|---|---|---|
| Cle API | `API_KEY=xxxxx` (env var) | `api-key: "xxxxx"` (config.yml) |
| URL | — | `panel-url: "http://ip:3000"` (config.yml) |
| Port | `PORT=3000` (env var) | — |
