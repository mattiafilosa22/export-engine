DC ?= docker compose

.PHONY: up down install key migrate fresh test lint lint-fix analyse bootstrap env

# Crea il .env dal template se manca (docker-compose lo richiede via env_file).
env:
	@test -f .env || cp .env.example .env

up: env
	$(DC) up -d --build

down:
	$(DC) down

install:
	$(DC) run --rm --no-deps app composer install

key:
	$(DC) run --rm --no-deps app php artisan key:generate

migrate:
	$(DC) exec app php artisan migrate

fresh:
	$(DC) exec app php artisan migrate:fresh --seed

test:
	$(DC) exec app composer test

lint:
	$(DC) exec app composer lint

lint-fix:
	$(DC) exec app composer lint:fix

analyse:
	$(DC) exec app composer analyse

# Primo avvio in un comando: build + deps + app key + up.
# Le migrazioni girano come servizio one-shot 'migrate' dentro 'up' (app/worker lo attendono).
bootstrap: env
	$(DC) build
	$(DC) run --rm --no-deps app composer install
	$(DC) run --rm --no-deps app php artisan key:generate
	$(DC) up -d
