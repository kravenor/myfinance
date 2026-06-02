SHELL := /bin/bash
COMPOSE := docker compose

.PHONY: help bootstrap key-generate up down restart build logs ps shell-php shell-node shell-mysql composer-install laravel-new vue-new migrate fresh seed test pint stan lint type-check check prod-build prod-up prod-down pi-bootstrap pi-up pi-down pi-restart pi-build pi-logs pi-ps pi-shell-php pi-shell-node pi-shell-mysql pi-key-generate pi-migrate pi-fresh pi-seed pi-stats

help: ## Mostra i comandi disponibili
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

bootstrap: ## Setup completo da repo appena clonato (env, build, install, key, migrate)
	@if [ ! -f .env ]; then cp .env.example .env; echo "✓ creato .env (verifica UID/GID con 'id -u' / 'id -g')"; fi
	@if [ ! -f backend/.env ]; then cp backend/.env.example backend/.env; echo "✓ creato backend/.env"; fi
	$(COMPOSE) build
	$(COMPOSE) up -d
	$(COMPOSE) exec php composer install
	$(COMPOSE) exec php php artisan key:generate
	$(COMPOSE) exec php php artisan migrate --seed
	@echo ""
	@echo "✓ Stack pronto su http://localhost:$${APP_PORT:-8080}"
	@echo "  Login demo: demo@finance.local / password"

key-generate: ## Genera APP_KEY Laravel
	$(COMPOSE) exec php php artisan key:generate

up: ## Avvia i container
	$(COMPOSE) up -d

down: ## Ferma i container
	$(COMPOSE) down

restart: ## Riavvia i container
	$(COMPOSE) restart

build: ## Rebuild delle immagini
	$(COMPOSE) build --no-cache

logs: ## Tail dei log
	$(COMPOSE) logs -f --tail=100

ps: ## Stato dei container
	$(COMPOSE) ps

shell-php: ## Apri shell nel container php
	$(COMPOSE) exec php sh

shell-node: ## Apri shell nel container node
	$(COMPOSE) exec node sh

shell-mysql: ## Apri shell mysql
	$(COMPOSE) exec mysql mysql -ufinance -pfinance finance

composer-install: ## composer install nel container php
	$(COMPOSE) exec php composer install

laravel-new: ## Crea progetto Laravel in backend/ (solo se vuoto)
	$(COMPOSE) run --rm php composer create-project laravel/laravel . "^11.0"

vue-new: ## Crea progetto Vue in frontend/ (solo se vuoto)
	$(COMPOSE) run --rm node sh -c "npm create vite@latest . -- --template vue-ts"

migrate: ## Esegue migrazioni
	$(COMPOSE) exec php php artisan migrate

fresh: ## Drop e ricrea db con seed
	$(COMPOSE) exec php php artisan migrate:fresh --seed

seed: ## Esegue i seeder
	$(COMPOSE) exec php php artisan db:seed

test: ## Esegue test PHPUnit
	$(COMPOSE) exec php php artisan test

pint: ## Formatta codice PHP con Pint
	$(COMPOSE) exec php ./vendor/bin/pint

stan: ## Static analysis con Larastan/PHPStan
	$(COMPOSE) exec php ./vendor/bin/phpstan analyse --memory-limit=512M

lint: ## ESLint sul frontend
	$(COMPOSE) exec node npm run lint

type-check: ## vue-tsc type-check
	$(COMPOSE) exec node npm run type-check

check: pint stan test lint type-check ## Esegue tutti i check di qualità

prod-build: ## Build stack produzione (richiede .env.production)
	$(COMPOSE) -f docker-compose.prod.yml --env-file .env.production build

prod-up: ## Avvia stack produzione
	$(COMPOSE) -f docker-compose.prod.yml --env-file .env.production up -d

prod-down: ## Ferma stack produzione
	$(COMPOSE) -f docker-compose.prod.yml --env-file .env.production down

# ============================================================================
# RASPBERRY PI TARGETS (usa docker-compose.pi.yml + .env.pi)
# ============================================================================

pi-bootstrap: ## Setup completo Raspberry Pi (env, build, install, key, migrate)
	@if [ ! -f .env.pi ]; then cp .env.pi.example .env.pi; echo "✓ creato .env.pi (MODIFICA i valori PI_LOCAL_IP, DB_PASSWORD, path dati)"; fi
	@if [ ! -f backend/.env ]; then cp backend/.env.example backend/.env; echo "✓ creato backend/.env"; fi
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi build
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi up -d
	@echo "⏳ Attendendo che MySQL sia pronto..."
	@sleep 15
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi exec php composer install
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi exec php php artisan key:generate
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi exec php php artisan migrate:fresh --seed
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi exec node npm ci
	@echo ""
	@echo "✓ Stack Raspberry Pi pronto!"
	@echo "  Accedi da: http://$$(grep PI_LOCAL_IP .env.pi | cut -d= -f2):$$(grep APP_PORT .env.pi | cut -d= -f2 || echo 8080)"
	@echo "  Login demo: demo@finance.local / password"

pi-up: ## Avvia i container Raspberry Pi
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi up -d
	@echo "✓ Stack avviato"

pi-down: ## Ferma i container Raspberry Pi
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi down
	@echo "✓ Stack fermato"

pi-restart: ## Riavvia i container Raspberry Pi
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi restart
	@echo "✓ Stack riavviato"

pi-build: ## Rebuild immagini Raspberry Pi
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi build --no-cache

pi-logs: ## Tail dei log Raspberry Pi
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi logs -f --tail=100

pi-ps: ## Stato dei container Raspberry Pi
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi ps

pi-stats: ## Monitoraggio risorse Raspberry Pi (CPU/RAM real-time)
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi stats

pi-shell-php: ## Apri shell nel container php (Pi)
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi exec php sh

pi-shell-node: ## Apri shell nel container node (Pi)
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi exec node sh

pi-shell-mysql: ## Apri client mysql (Pi)
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi exec mysql mysql -u$$(grep 'DB_USERNAME' .env.pi | cut -d= -f2 || echo finance) -p$$(grep 'DB_PASSWORD' .env.pi | cut -d= -f2 || echo finance) $$(grep 'DB_DATABASE' .env.pi | cut -d= -f2 || echo finance)

pi-key-generate: ## Genera APP_KEY Laravel (Pi)
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi exec php php artisan key:generate

pi-migrate: ## Esegue migrazioni (Pi)
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi exec php php artisan migrate

pi-fresh: ## Drop e ricrea db con seed (Pi)
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi exec php php artisan migrate:fresh --seed

pi-seed: ## Esegue i seeder (Pi)
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi exec php php artisan db:seed

pi-test: ## Esegue test PHPUnit (Pi)
	$(COMPOSE) -f docker-compose.pi.yml --env-file .env.pi exec php php artisan test
