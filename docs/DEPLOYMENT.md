# Deployment

## Пълен локален стек

```sh
./scripts/start
```

Изисквания: Docker Engine и Compose v2, интернет за образи/Composer, свободни localhost портове 8080/9090/3000. Генерираният `.env.docker` съдържа APP_KEY, database и Grafana пароли, metrics token. Не го публикувайте. Няма вградена production парола. PostgreSQL не е изложен извън Compose network; UI/monitoring ports са bind-нати само към localhost.

След успешен init:

```sh
docker compose ps
docker compose exec app php artisan air:collect
docker compose exec app php artisan air:backfill --months=12 --queue
curl http://localhost:8080/health/ready
```

Web изчаква DB health и успешен init. Readiness не чака историята. Scheduler стартира `air:collect` на всеки 15 минути, aggregation на всеки час, archive на 2-ро число в 03:00 UTC. Queue timeout 21 600 секунди е под retry_after 22 000 секунди, за да няма ранно повторно стартиране на един job. Checkpoints позволяват повторно обработване след timeout.

Данните и архивите са в persistent volumes `air_db` и `air_storage`. `docker compose down` ги запазва; не използвайте `down -v` за работна инсталация. `docker compose logs -f app worker scheduler` показва JSON application logs и worker output.

За инфраструктура без monitoring: създайте `.env.docker` чрез start или ръчно и използвайте `docker compose up -d --build`, без profile monitoring. След code update: `docker compose --profile monitoring up -d --build`; init изпълнява pending migrations и идемпотентен geo import.

## Историческа инициализация

По подразбиране на 20.10.2026 се избират 01.10.2025–30.09.2026; предходният UTC месец се обработва за първите Sofia часове. Месечните archives са световни, един ZIP е около 3.75 GB. Необходими са десетки GB за крайния raw dataset плюс ZIP, WAL, индекси и резерв. Продължителността на **пълен** реален backfill не е измерена; планирайте продължителна фонова операция, не моментна готовност. Не обещаваме часове без завършен опит. Точните локални ограничения са във FINAL-REPORT.

```sh
docker compose exec app php artisan air:backfill --from=2025-10-01 --to=2026-09-30 --queue
docker compose exec app php artisan air:aggregate
```

Не задавайте текущ непубликуван monthly archive като завършена история. За функционална проверка с **изрично частични** реални данни:

```sh
docker compose exec app php artisan air:backfill --from=2026-10-18 --to=2026-10-19 --sensor=53351
```

Този режим не доказва национално покритие.

## Публично разгръщане

Настройте DNS/TLS в reverse proxy, APP_URL, разпознаваем AIR_USER_AGENT с контакт и мрежов достъп до мониторинга според организацията. Няма автоматична cloud публикация. Compose използва фиксирани version tags и composer.lock; image digests не са pin-нати, следователно бъдещо rebuild може да вземе patch на базов образ. Архивното хранилище трябва да има backup извън тази машина.

## Backup / restore

```sh
mkdir -p backups
docker compose exec -T db pg_dump -U air -Fc air > backups/air.dump
docker compose exec -T app tar -czf - storage/app/archives > backups/archives.tar.gz
# Отделно, защитено копие на .env.docker / APP_KEY.
```

За възстановяване: спрете worker/scheduler, включете maintenance mode (`php artisan down`), възстановете `pg_restore -U air -d air --clean --if-exists`, възстановете същите archive paths/volume, изпълнете миграции, readiness и `php artisan up`, стартирайте worker/scheduler. PostgreSQL restore се подава със `docker compose exec -T db pg_restore -U air -d air --clean --if-exists < backups/air.dump`. Изпълнен е pg_dump/pg_restore drill от локалната реална Compose база в отделна временна база: 3 369 measurements, същият catalog raw_count и 257 града. Временната база е премахната. Изпълнен е и automated compressed raw round trip.
