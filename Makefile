PORT ?= 8000

setup:
	composer install

start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public

db-prepare:
	php bin/db-prepare.php