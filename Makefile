# Convenience commands. Everything runs inside Docker.
.PHONY: setup up down test test-concurrency feed logs shell

setup: ## One command: build, install, migrate and seed
	docker compose up -d --build
	docker compose exec app composer install --no-interaction
	docker compose exec app sh -c '[ -f .env ] || cp .env.example .env'
	docker compose exec app php artisan key:generate
	docker compose exec app php artisan migrate:fresh --seed --force
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
