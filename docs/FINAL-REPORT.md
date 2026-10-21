# Финален технически доклад — 20.10.2026

**Приложението е реализирано и локално проверено. Не са изпълнени всички acceptance критерии: пълният 12-месечен реален backfill не е завършен.** Причината за спиране на тази операция е локалният дисков бюджет, описан по-долу. Няма Git repository, branch, commits, push или merge по изричното указание на възложителя.

## Реализирано

- Laravel 13.37.0, PHP >=8.4, PostgreSQL 17, Blade без JavaScript build/framework.
- 257 града, country polygon validation, conservative Haversine assignment, immutable measurement location snapshots и коректен late import.
- Live collection на всеки 15 минути, Laravel HTTP Client, 3 ограничени опита, timeout/backoff, Retry-After и общ cooldown, shared locks, QC, batch insert и unique защита.
- Monthly ZIP/CSV backfill за всички географски български SDS011 записи, включително спрели сензори; download на диск, потокова обработка, SHA256, member и CSV row checkpoints, resume, queue jobs. Диагностичен daily import по sensor ID.
- Sensor-hour → sensor-city-day → city-day → period ranking. PM2.5 е единствена rank метрика. 75% hourly/day coverage, 23/25-часови дни, missing data, PM10 completeness и всички равни победители.
- Версиониран JSON API с date/range validation и useful HTTP errors; Blade календар, резултат, таблица, изключени градове, source/freshness/методология и изрично предупреждение за непълна история.
- 36-месечно raw archive/restore с gzip JSONL, integrity verification преди delete, запазени daily aggregates, защита от случайно преизчисляване без raw restore.
- nginx, persistent PostgreSQL/storage volumes, scheduler, database queue/cache, health endpoints, protected metrics, Prometheus, 15-panel Grafana dashboard и 4 alert rules.
- Оперативни процедури, backup/restore, startup/test/smoke scripts, benchmark tooling и всички изискани документационни файлове.

## Проверени източници и лицензи

Директни live HTTP 200, реален дневен CSV и реална структура на месечен ZIP, плюс HTTP HEAD 200 за 13 последователни ZIP файла (12 целеви месеца + UTC граничен месец). Source country/day inventory потвърждава 384 дни с BG SDS011 от 01.10.2025 до 19.10.2026. Това **не е локално историческо покритие**.

Sensor.Community сайтът посочва DbCL 1.0 за съдържанието; видима атрибуция е включена. Не е намерена публикувана числена polling квота, затова 1 source request едновременно и минимум 1 секунда между заявки е наш консервативен лимит. GeoNames: CC BY 4.0; Natural Earth: public domain. NSI/OSM са изследвани, но тяхно съдържание не е разпространено в крайния географски extract. Подробности и първични връзки: [DATA-SOURCES](DATA-SOURCES.md).

## База, география и методология

[Migrations и индекси](DATABASE.md) покриват всички изискани entities, плюс dirty-day ledger и transactional dataset catalog. Историческото търсене не сканира raw measurements и не извиква source HTTP. [Архитектура](ARCHITECTURE.md).

Country polygon + Haversine с максимум 10 km; по-малкият town radius зависи консервативно от GeoNames население и започва от 2 km. Няма надеждно внедрени settlement polygons и matching е приблизителен. [Методология](DATA-METHODOLOGY.md).

Равни тегла на часовете, после сензорите, после дните са изрично статистическо решение. Не се смесват PM2.5/PM10 в композитен индекс. Резултатът е за измерени прахови концентрации, не цялостна оценка на въздуха или здравето.

## Реално постигната история и collection

При финалната Compose проверка:

- **3 369 реални raw измервания**, 257 града в географския набор.
- Raw local dates: **18–20.10.2026**.
- Дни с квалифицирана градска статистика: **18–19.10.2026**, от диагностичен архивен импорт на sensor 53351.
- Реална API заявка за тези два дни: Бургас, PM2.5 **1.564**, PM10 **4.462 µg/m³**, 1 сензор, 2 дни, 100% покритие. Само 1 допустим град; 256 са изключени. Това е функционална проверка на **частична извадка**, не доказателство, че Бургас е най-чистият град в България.
- Последната успешна колекция в проверения snapshot е **20.10.2026 09:15:04 UTC / 12:15:04 Europe/Sofia**, изпълнена от работещия scheduler. Този timestamp може да се промени при следващите автоматични колекции.
- Нула завършени пълни monthly imports; `air:verify-history` правилно връща exit code 1.

В отделната локална development база също има само реални source observations; данните ѝ не се смесват с Compose или benchmark базата. Подробен snapshot: [deployment-verification.json](deployment-verification.json).

## Тестове и deployment проверки

- **47 теста, 112 assertions, всички успешни**, реален временен PostgreSQL, външният HTTP е mocked/stray requests забранени. Включени са parser/QC, timeout/429/JSON, repeats, migration/location history, completeness, ties, DST, API, source outage с local query, CSV/member resume, archive failure/recovery, corrupt restore, liveness без DB queries и реален CSV regression fixture. [test-results.json](test-results.json).
- Pint успешно; composer validate --strict успешно; composer audit без известни security advisories при проверката.
- Laravel стартира; PostgreSQL миграциите минават от празна база; nginx връща HTML и JSON; app/db/web healthchecks успешни.
- `/health/ready` = ready/fresh при наличната актуална source snapshot; protected `/metrics` работи; Prometheus target up; promtool валидира config и 4 rules; Grafana API потвърждава provisioning на dashboard с 15 panels.
- pg_dump/pg_restore drill в отделна временна база: 3 369 measurements = catalog raw_count, 257 cities; временната база е премахната. Gzip raw export/restore е допълнително автоматизирано тестван.
- Коригиран е race в test bootstrap: PostgreSQL readiness вече чака TCP, вместо временния initialization Unix socket.

## Производителност

[PERFORMANCE](PERFORMANCE.md) съдържа измерените median service latencies и JSON EXPLAIN ANALYZE за 100 000 / 1 000 000 / **10 000 000** synthetic raw rows. При 10 милиона: около **5.37 ms за 1 ден**, **39.02 ms за 365 дни**, **13.96 s** aggregation и **34 MiB** peak PHP allocation. Тези числа са локален synthetic benchmark, не production SLA. Реален full-world ZIP download/filter throughput и време за завършен 12-месечен импорт не са измерени.

След запазване на всички резултати изолираната 10-милионна benchmark база е премахната, за да освободи диск. Възпроизводимият generator остава в проекта.

## Блокер и оставащи ограничения

1. **Реалната 12-месечна локална история остава незавършена.** Capacity assessment използва 247 184 source sensor-days за целевата година, предположение 593 проби/пълен sensor-day и измерения bytes/row с добавка за по-дълъг source URI. Прогнозата е около **48.5 GB** с ZIP, WAL и резерв. Свободните са били **39.4 GB**, а след премахване на benchmark базата — около **41.3 GB**. Това е консервативна прогноза, не доказан краен размер; остава прогнозен недостиг около **7.2 GB**. Не е безопасно автономно да се запълва останалият диск или да се трият други проекти. Осигурете поне **60 GB свободно** за комфортен първи backfill и отделен backup бюджет. [capacity-assessment.json](capacity-assessment.json).
2. Current incomplete month няма гарантиран monthly ZIP; пълният backfill по подразбиране работи по последните 12 **завършени** месеца. Изричен range до непубликуван месец ще fail-не открито. Diagnostic `--sensor` не е национален fallback.
3. Interrupted ZIP downloads започват отначало; CSV resume декомпресира prefix до checkpoint. Не е реализиран HTTP byte-resume.
4. Nearest-city matching е приблизителен; GeoNames population/admin metadata може да са остарели. Исторически CSV няма indoor статус. Live snapshots на 15 минути не съдържат всички проби между polling моментите.
5. Няма облачно публично разгръщане, TLS reverse proxy, S3 storage или Alertmanager notification routing. Има възпроизводим локален Compose deployment и видими Prometheus alerts.

## Точни команди

```sh
# Стартиране на пълния стек:
./scripts/start
# Интерфейс: http://localhost:8080

# Реално live събиране:
docker compose exec app php artisan air:collect

# След осигуряване на достатъчно свободен диск:
docker compose exec app php artisan air:backfill --months=12 --queue
# Проследяване и финална проверка:
docker compose logs -f worker
docker compose exec app php artisan air:aggregate
docker compose exec app php artisan air:verify-history

# Автоматизирани тестове (PHP 8.4, Composer и Docker):
composer install
./scripts/test --compact
vendor/bin/pint --test

# Проверка на работещото локално разгръщане:
python3 scripts/verify-deployment.py
```

Всички source промени са локални. Няма commits; [FILES.txt](FILES.txt) изброява доставените application/deployment/docs/test файлове. Оригиналният prompt и PDF не са променяни.
