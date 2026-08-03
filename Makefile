.PHONY: install test psalm cs frontend fixtures quality

install:
	composer install --no-interaction
	npm ci

test:
	composer test

psalm:
	composer psalm

cs:
	composer cs:check

frontend:
	npm run lint
	npm run build

fixtures:
	composer fixtures:verify

quality: test psalm cs frontend fixtures
