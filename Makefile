DC ?= docker compose

.PHONY: up down install key migrate fresh test lint lint-fix analyse bootstrap env

# Create .env from the template if missing (docker-compose needs it via env_file).
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

# First boot in one command: build + deps + app key + up.
# Migrations run as the one-shot 'migrate' service inside 'up' (app/worker wait for it).
bootstrap: env
	$(DC) build
	$(DC) run --rm --no-deps app composer install
	$(DC) run --rm --no-deps app php artisan key:generate
	$(DC) up -d
