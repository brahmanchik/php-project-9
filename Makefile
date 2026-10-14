PORT ?= 8000

setup:
	composer install

start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public

db-prepare:
	php bin/db-prepare.php

.PHONY: setup start db-prepare test lint

test:
	php tests/run.php

lint:
	./vendor/bin/phpcs --standard=PSR12 src public/index.php bin tests
