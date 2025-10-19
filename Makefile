SHELL := /bin/bash

SAIL ?= ./vendor/bin/sail
ARTISAN ?= $(SAIL) artisan

setup:
	composer install

sail-up:
	$(SAIL) up -d

sail-down:
	$(SAIL) down

sail-restart:
	$(SAIL) restart

migrate:
	$(ARTISAN) migrate

cache-clear:
	$(ARTISAN) optimize:clear

bot:
	$(ARTISAN) bot:poll

logs:
	$(SAIL) logs app -f

test:
	$(SAIL) test

artisan:
ifndef CMD
	@echo "Usage: make artisan CMD='command'" && exit 1
endif
	$(ARTISAN) $(CMD)
