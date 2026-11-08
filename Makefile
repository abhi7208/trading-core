# Convenience commands. Everything runs inside Docker.
.PHONY: setup up down test test-concurrency feed logs shell

setup: ## One command: build, install, migrate, seed, then start everything
	docker compose build
	docker compose up -d mysql redis
	docker compose run --rm --no-deps app composer install --no-interaction
	docker compose run --rm --no-deps app sh -c '[ -f .env ] || cp .env.example .env'
	docker compose run --rm --no-deps app php artisan key:generate
	docker compose run --rm app php artisan migrate:fresh --seed --force
	docker compose up -d
	@echo "API ready on http://localhost:8000  (tokens in README.md)"

up:
	docker compose up -d

down:
	docker compose down

feed: ## Start the price simulator (50 ticks/second)
	docker compose --profile feed up -d simulator

test: ## Unit + feature tests
	docker compose exec app php artisan test --testsuite=Unit,Feature

test-concurrency: ## Real parallel-process tests
	docker compose exec app php artisan test --testsuite=Concurrency

logs:
	docker compose logs -f worker scheduler simulator

shell:
	docker compose exec app bash
