# Clean Air Finder — Bulgaria

Laravel 13 / PHP 8.4 / PostgreSQL 17, Blade, database queue/cache, nginx, Prometheus и Grafana. Класира градове по измерена PM2.5 с проверки за покритие. Само реални измервания в работните бази; synthetic данните са ограничени до тестове и отделна benchmark база.

## Стартиране

```sh
При операционна система Линукс може да стартирате: 
./scripts/start

При Уиндоус в папката на  проекта:
./scripts/start.ps1

# http://localhost:8080

# Първоначално реално събиране:
docker compose exec app php artisan air:collect

# Последните 12 завършени месеца, фоново:
docker compose exec app php artisan air:backfill --months=12 --queue
```

`start` генерира локални тайни в `.env.docker`, изгражда image, стартира PostgreSQL, изпълнява миграциите и географския импорт, web, scheduler, worker и monitoring. Не стартира многогигабайтовия исторически импорт автоматично. Може да се търси, когато реалните данни имат достатъчно валидни часове/дни. Празна инсталация няма победител. Мониторинг: http://localhost:9090 и http://localhost:3000; Grafana admin паролата е в `.env.docker`.

Пълен backfill е продължителна операция и **не е завършен в тази локална доставка**. Не приемайте диагностичната извадка за национално покритие. Вижте [финалния доклад](docs/FINAL-REPORT.md), [източниците](docs/DATA-SOURCES.md) и [операциите](docs/OPERATIONS.md).

## Локално без web контейнери

Нужни са PHP 8.4 с pdo_pgsql, zip, intl, mbstring, curl, xml, bcmath, Composer и PostgreSQL 17. Изпълнете `composer install`, копирайте `.env.example` в `.env`, настройте собствените PostgreSQL credentials, след това:

```sh
php artisan key:generate
php artisan migrate --force
php artisan air:geo-import
php artisan air:collect
php artisan serve --host=127.0.0.1 --port=8000
# в други терминали:
php artisan schedule:work
php artisan queue:work --timeout=21600 --tries=3
```

При ръчно стартиране създайте базата предварително; задайте `DB_QUEUE_RETRY_AFTER=22000`. Production средата трябва да използва `APP_DEBUG=false` и User-Agent с операторски контакт.

## Проверки

```sh
composer install
./scripts/test --compact
vendor/bin/pint --test
```

Тестовият скрипт стартира временен PostgreSQL на localhost:55433 и го премахва след тестовете. PHPUnit не използва production базата. Benchmark командата отказва да работи извън база с префикс `air_benchmark`; подробности в [PERFORMANCE](docs/PERFORMANCE.md).

## Документация

[Архитектура](docs/ARCHITECTURE.md) · [База](docs/DATABASE.md) · [Методология](docs/DATA-METHODOLOGY.md) · [API](docs/API.md) · [Deployment](docs/DEPLOYMENT.md) · [Мониторинг](docs/OBSERVABILITY.md) · [Operations](docs/OPERATIONS.md) · [План](docs/IMPLEMENTATION-PLAN.md).

Източник: [Sensor.Community](https://sensor.community/), DbCL 1.0. География: [GeoNames](https://www.geonames.org/), CC BY 4.0, и [Natural Earth](https://www.naturalearthdata.com/), public domain. Това е сравнение на измерени прахови концентрации, а не цялостна здравна или градска оценка. Git не е инициализиран и няма commits по изричното указание на възложителя.
