SHELL := /bin/bash
COMPOSE := docker compose

.PHONY: help up down restart build logs ps shell-php shell-node shell-mysql composer-install laravel-new vue-new migrate fresh seed test pint

help: ## Mostra i comandi disponibili
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

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
