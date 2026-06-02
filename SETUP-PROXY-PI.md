# Setup Proxy Inverso Raspberry Pi — Guida completa

> Questa guida spiega come hostare **multiple applicazioni** sulla Raspberry Pi usando un proxy inverso Nginx sulla porta 80, con routing basato su dominio locale.

## Architettura finale

```
Internet locale
    ↓
Nginx (porta 80) — proxy inverso
    ├→ http://finance.local → finance_nginx:8080
    ├→ http://app2.local → app2_nginx:8080
    └→ http://app3.local → app3_nginx:8080
```

---

## Passo 1: Configura /etc/hosts sulla Pi

Sul **file host della Pi**, aggiungi i domini locali:

```bash
sudo nano /etc/hosts
```

Aggiungi alla fine del file:

```
# ============ FINANCE APPS ============
127.0.0.1       localhost
192.168.1.100   finance.local
192.168.1.100   app2.local
192.168.1.100   app3.local
```

⚠️ **Sostituisci `192.168.1.100` con l'IP effettivo della tua Pi** (esegui: `hostname -I`)

Salva con `Ctrl+X → Y → Enter`.

---

## Passo 2: Configura /etc/hosts sui client locali

Anche i **client sulla rete locale** (PC, Mac, telefono) devono conoscere i domini.

### **Su Linux/Mac (host locale):**

```bash
sudo nano /etc/hosts
```

Aggiungi:
```
192.168.1.100   finance.local
192.168.1.100   app2.local
192.168.1.100   app3.local
```

### **Su Windows:**
Modifica `C:\Windows\System32\drivers\etc\hosts` (apri come amministratore):
```
192.168.1.100   finance.local
192.168.1.100   app2.local
192.168.1.100   app3.local
```

### **Su telefoni (iOS/Android):**
- **iOS**: Impostazioni → Wi-Fi → Info di rete → DNS personalizzato
- **Android**: Impostazioni → Wi-Fi → Modifica rete → DNS personalizzato

Oppure usa un **DNS locale** (pi-hole, dnsmasq) su Raspberry Pi.

---

## Passo 3: Crea file di configurazione Nginx per il proxy

Nella directory del progetto **finance**, crea una nuova cartella:

```bash
mkdir -p docker/nginx-proxy
```

Crea il file di configurazione del proxy:

```bash
nano docker/nginx-proxy/proxy.conf
```

Incolla il seguente contenuto:

```nginx
# docker/nginx-proxy/proxy.conf
# Proxy inverso per routing domini locali

upstream finance {
    server finance_nginx_pi:80;
}

upstream app2 {
    server app2_nginx:80;
}

# Aggiungi altri app qui:
# upstream app3 {
#     server app3_nginx:80;
# }

# Server di fallback per richieste senza host
server {
    listen 80 default_server;
    server_name _;
    
    location / {
        return 404 "Applicazione non trovata. Domini validi: finance.local, app2.local";
    }
}

# ============ FINANCE ============
server {
    listen 80;
    server_name finance.local;
    
    location / {
        proxy_pass http://finance;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # WebSocket support (se needed)
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}

# ============ APP 2 (template) ============
server {
    listen 80;
    server_name app2.local;
    
    location / {
        proxy_pass http://app2;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}

# Aggiungi qui altri server per app3, app4, ecc.
```

---

## Passo 4: Aggiorna docker-compose.pi.yml

Aggiungi il servizio proxy all'inizio del file `docker-compose.pi.yml`:

```yaml
name: finance-pi

services:
  # ========== PROXY INVERSO (NUOVO) ==========
  nginx-proxy:
    image: nginx:1.27-alpine
    container_name: finance_nginx_proxy
    restart: unless-stopped
    ports:
      # Ascolta su porta 80 e 443 (rete locale)
      - "${PI_LOCAL_IP:-127.0.0.1}:80:80"
      - "${PI_LOCAL_IP:-127.0.0.1}:443:443"
    volumes:
      - ./docker/nginx-proxy/proxy.conf:/etc/nginx/conf.d/default.conf:ro
      # Opzionale: certificati SSL se usi HTTPS
      # - ./docker/nginx-proxy/ssl:/etc/nginx/ssl:ro
    depends_on:
      - nginx
    networks:
      - finance

  # ========== FINANCE (ORIGINALE) ==========
  nginx:
    image: nginx:1.27-alpine
    container_name: finance_nginx_pi
    restart: unless-stopped
    # ⚠️ CAMBIA: Binda su 127.0.0.1 (solo interno), non su PI_LOCAL_IP
    ports:
      - "127.0.0.1:${APP_PORT:-8080}:80"
    volumes:
      - ./backend:/var/www/html:cached
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - php
      - node
    networks:
      - finance
    deploy:
      resources:
        limits:
          memory: 128M
        reservations:
          memory: 64M

  # ... resto dei servizi rimangono invariati ...
```

⚠️ **Cosa cambia**: la porta che era su `${PI_LOCAL_IP}:8080` ora è su `127.0.0.1:8080` (solo interno). Il proxy esterno ascolta su porta 80.

---

## Passo 5: Avvia il proxy

```bash
# Ricostruisci con il nuovo servizio
make pi-build

# Avvia lo stack
make pi-up

# Verifica che il proxy sia in running
make pi-ps
```

Attendi ~10 secondi che i container siano pronti.

---

## Passo 6: Test accesso

Dall'host locale, prova ad accedere:

```bash
# Da Linux/Mac
curl http://finance.local
curl http://app2.local  # (ancora vuoto, è solo il template)

# Oppure apri il browser:
# http://finance.local
```

Se vedi la pagina di finance, **il proxy funziona!** ✓

---

## Passo 7: Aggiungere un secondo applicativo

Quando cloni il secondo progetto (es. `app2`), integra nel docker-compose.pi.yml:

### **Opzione A: Stack separato (consigliato)**

Crea un `docker-compose-app2.pi.yml` nella cartella `app2/`:

```yaml
name: app2-pi

services:
  nginx:
    image: nginx:1.27-alpine
    container_name: app2_nginx
    restart: unless-stopped
    # Ascolta solo su localhost (il proxy lo raggiunge)
    ports:
      - "127.0.0.1:8081:80"
    volumes:
      - ./backend:/var/www/html:cached
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - php
      - node
    networks:
      - app2
  
  php:
    # ... config ...
  
  # ... resto servizi ...

networks:
  app2:
    driver: bridge
```

Avvia da cartella `app2/`:

```bash
cd app2/
docker compose -f docker-compose-app2.pi.yml --env-file .env.pi up -d
```

Aggiorna `docker/nginx-proxy/proxy.conf` nella cartella finance per aggiungere:

```nginx
upstream app2 {
    server app2_nginx:80;
}

server {
    listen 80;
    server_name app2.local;
    
    location / {
        proxy_pass http://app2;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Ricarica Nginx:

```bash
cd finance/
docker compose -f docker-compose.pi.yml --env-file .env.pi exec nginx-proxy nginx -s reload
```

---

## Troubleshooting

### **Non riesco ad accedere a finance.local**

```bash
# Verifica che il proxy sia in running
docker ps | grep nginx-proxy

# Leggi i log del proxy
docker logs finance_nginx_proxy

# Verifica la risoluzione DNS
ping finance.local
nslookup finance.local

# Verifica che /etc/hosts sia configurato
cat /etc/hosts | grep finance.local
```

### **Errore "502 Bad Gateway"**

Significa che il proxy non raggiunge l'app interna. Verifica:

```bash
# Assicurati che finance_nginx_pi sia in running
docker ps | grep finance_nginx_pi

# Verifica che i container siano sulla stessa network
docker network inspect finance
```

### **App2 raggiungibile da Pi ma non da altri client**

I domini in `/etc/hosts` devono essere aggiunti su **tutti i client**. Vedi Passo 2.

---

## Comandi utili

```bash
# Riavvia solo il proxy (senza spegnere gli app)
docker compose -f docker-compose.pi.yml --env-file .env.pi exec nginx-proxy nginx -s reload

# Vedi log del proxy
docker compose -f docker-compose.pi.yml --env-file .env.pi logs -f nginx-proxy

# Traccia una richiesta DNS dalla Pi
nslookup finance.local
dig finance.local

# Test connettività tra container
docker exec finance_nginx_proxy ping app2_nginx
```

---

## Prossimi passi (opzionali)

### **Usa DNS locale (pi-hole/dnsmasq)**

Invece di modificare `/etc/hosts` su ogni client, installa un DNS locale sulla Pi che risolve i domini locali automaticamente.

### **Aggiungi HTTPS (Let's Encrypt)**

Per dominio pubblico, usa Certbot. Per dominio locale, auto-firma certificati:

```bash
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout docker/nginx-proxy/ssl/private.key \
  -out docker/nginx-proxy/ssl/certificate.crt \
  -subj "/CN=finance.local"
```

Aggiungi a `proxy.conf`:

```nginx
server {
    listen 443 ssl;
    server_name finance.local;
    
    ssl_certificate /etc/nginx/ssl/certificate.crt;
    ssl_certificate_key /etc/nginx/ssl/private.key;
    
    # ... resto config ...
}
```

---

## Domande finali

- Vuoi che aggiunga **auto-reload della config Nginx** quando modifichi `proxy.conf`?
- Preferisci **DNS locale** (pi-hole) anziché `/etc/hosts` manuale?
- Necessiti di **load balancing** se un app ha più container?
