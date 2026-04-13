up:
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f app

serve:
	php -S 127.0.0.1:8080 -t public

lint:
	find . -name '*.php' -print0 | xargs -0 -n1 php -l
