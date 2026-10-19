.PHONY: help install update backup seed reset logs shell db-shell pma-up pma-down limits-off limits-on

# Run artisan as the web user: files it creates in storage/ must stay writable by the app server.
ARTISAN = docker compose exec -u www-data app php artisan
# Earlier versions ran artisan (and the scheduler) as root; hand those files back.
FIX_OWNER = docker compose exec app chown -R www-data:www-data storage bootstrap/cache

# Default target
help:
	@echo ""
	@echo "╔══════════════════════════════════════════════════════════════╗"
	@echo "║              Klaklork — Deployment Commands                ║"
	@echo "╠══════════════════════════════════════════════════════════════╣"
	@echo "║  make install     Initial setup (build, migrate, seed)     ║"
	@echo "║  make update      Pull latest code & rebuild containers    ║"
	@echo "║  make backup      Dump database to backups/ directory      ║"
	@echo "║  make seed        Run seeders & assign super-admin         ║"
	@echo "║  make reset       ⚠  Destroy everything & start fresh     ║"
	@echo "║  make logs        Tail all container logs                  ║"
	@echo "║  make shell       Open a shell inside the app container    ║"
	@echo "║  make db-shell    Open a MySQL prompt                      ║"
	@echo "║  make pma-up      Start phpMyAdmin (debug profile)         ║"
	@echo "║  make pma-down    Stop phpMyAdmin                          ║"
	@echo "║  make limits-off  Rate limits off (MIN=60, then auto-on)   ║"
	@echo "║  make limits-on   Rate limits back on                      ║"
	@echo "╚══════════════════════════════════════════════════════════════╝"
	@echo ""

# ---------------------------------------------------------------------------
# install — First-time setup
# ---------------------------------------------------------------------------
install:
	@echo "==> Installing Klaklork..."
	@if [ ! -f .env ]; then \
		cp .env.example .env; \
		echo ""; \
		echo "╔═══════════════════════════════════════════════════════════╗"; \
		echo "║  .env created from .env.example                         ║"; \
		echo "║  Please update passwords & secrets, then re-run:        ║"; \
		echo "║      make install                                       ║"; \
		echo "╚═══════════════════════════════════════════════════════════╝"; \
		echo ""; \
		exit 1; \
	fi
	@echo "==> Building & starting containers..."
	docker compose up -d --build
	@echo "==> Generating APP_KEY..."
	@if grep -q "^APP_KEY=$$" .env || grep -q "^APP_KEY=\"\"" .env; then \
		APP_KEY="base64:$$(openssl rand -base64 32)"; \
		sed -i "s|^APP_KEY=.*|APP_KEY=$$APP_KEY|" .env; \
		echo "==> APP_KEY generated and written to .env"; \
	else \
		echo "==> APP_KEY already set, skipping."; \
	fi
	@echo "==> Waiting for app container to be ready (up to 60s)..."
	@for i in $$(seq 1 12); do \
		if $(ARTISAN) --version > /dev/null 2>&1; then \
			echo "==> App is ready!"; break; \
		fi; \
		echo "  ...waiting ($$i/12)"; sleep 5; \
	done
	@echo "==> Waiting for database to be ready (up to 60s)..."
	@for i in $$(seq 1 12); do \
		if $(ARTISAN) db:show > /dev/null 2>&1; then \
			echo "==> Database is ready!"; break; \
		fi; \
		echo "  ...waiting for DB ($$i/12)"; sleep 5; \
	done
	$(FIX_OWNER)
	$(ARTISAN) migrate --force
	$(ARTISAN) db:seed --force
	$(ARTISAN) storage:link
	$(ARTISAN) optimize
	@echo ""
	@echo "╔═══════════════════════════════════════════════════════════╗"
	@echo "║  ✅  Installation complete!                              ║"
	@echo "║                                                          ║"
	@echo "║  API:       http://localhost:$${APP_PORT:-8000}              ║"
	@echo "║  Frontend:  http://localhost:$${FRONTEND_PORT:-3000}          ║"
	@echo "║  Reverb WS: ws://localhost:$${REVERB_PORT:-8080}             ║"
	@echo "╚═══════════════════════════════════════════════════════════╝"
	@echo ""

# ---------------------------------------------------------------------------
# update — Pull latest & rebuild
# ---------------------------------------------------------------------------
update:
	@bash backup.sh
	@echo "==> Updating Klaklork..."
	git pull
	docker compose up -d --build
	@echo "==> Waiting for app container to be ready..."
	@for i in $$(seq 1 12); do \
		if $(ARTISAN) --version > /dev/null 2>&1; then \
			echo "==> App is ready!"; break; \
		fi; \
		echo "  ...waiting ($$i/12)"; sleep 5; \
	done
	$(FIX_OWNER)
	$(ARTISAN) migrate --force
	$(ARTISAN) storage:link
	$(ARTISAN) config:cache
	$(ARTISAN) route:cache
	$(ARTISAN) view:cache
	@bash notify_update.sh
	@echo "==> Update complete!"

# ---------------------------------------------------------------------------
# limits-off / limits-on — Lift API rate limits for a load test
#   make limits-off            # off for 60 min, then back on by itself
#   make limits-off MIN=30
# ---------------------------------------------------------------------------
limits-off:
	$(ARTISAN) limits:off $(or $(MIN),60)

limits-on:
	$(ARTISAN) limits:on

# ---------------------------------------------------------------------------
# backup — Dump the database
# ---------------------------------------------------------------------------
backup:
	@bash backup.sh

# ---------------------------------------------------------------------------
# seed — Re-seed the database
# ---------------------------------------------------------------------------
seed:
	@echo "==> Seeding database..."
	$(ARTISAN) db:seed --force
	@echo "==> Seeding complete!"

# ---------------------------------------------------------------------------
# reset — Nuclear option: destroy everything and rebuild
# ---------------------------------------------------------------------------
reset:
	@echo ""
	@echo "  ⚠  WARNING: This will destroy ALL data and volumes!"
	@echo ""
	@read -p "  Are you sure? [y/N] " ans; \
	if [ "$$ans" = "y" ] || [ "$$ans" = "Y" ]; then \
		echo "==> Destroying containers and volumes..."; \
		docker compose down -v; \
		echo "==> Rebuilding from scratch..."; \
		docker compose up -d --build; \
		echo "==> Generating fresh APP_KEY..."; \
		APP_KEY="base64:$$(openssl rand -base64 32)"; \
		sed -i "s|^APP_KEY=.*|APP_KEY=$$APP_KEY|" .env; \
		echo "==> APP_KEY written to .env"; \
		echo "==> Waiting for app & DB to be ready (up to 60s)..."; \
		for i in $$(seq 1 12); do \
			if $(ARTISAN) db:show > /dev/null 2>&1; then \
				echo "==> Ready!"; break; \
			fi; \
			echo "  ...waiting ($$i/12)"; sleep 5; \
		done; \
		$(ARTISAN) migrate:fresh --seed --force; \
		$(ARTISAN) storage:link; \
		echo ""; \
		echo "==> ✅ Reset complete!"; \
	else \
		echo "==> Reset aborted."; \
	fi

# ---------------------------------------------------------------------------
# Convenience targets
# ---------------------------------------------------------------------------
logs:
	docker compose logs -f --tail=100

shell:
	docker compose exec app sh

db-shell:
	docker compose exec db sh -c 'MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" exec mysql -u root "$$MYSQL_DATABASE"'

pma-up:
	docker compose --profile debug up -d phpmyadmin
	@echo "==> phpMyAdmin running at http://localhost:$${PMA_PORT:-8081}"

pma-down:
	docker compose --profile debug stop phpmyadmin
