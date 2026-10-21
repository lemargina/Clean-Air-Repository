# Измерена производителност — 20.10.2026

Изпълнени са реални PostgreSQL benchmarks с възпроизводими **синтетични** данни само в `air_benchmark`. Няма synthetic measurements в работните бази. Хардуерът е AMD Ryzen 7 8845HS, 16 logical CPU, около 29 GiB RAM, NVMe, PHP 8.4.26 на host и PostgreSQL 17 Alpine в Docker. Други development/monitoring процеси работеха едновременно. Средата не е изолиран performance lab.

Всяка серия започва с migrate:fresh, 257 града и 3/29/286 синтетични сензора в 3 града. UTC timestamps обхващат година, 15-минутни offsets между повторенията. PM10 >= PM2.5. SQL bulk seed измерва PostgreSQL write/index/FK цената, не HTTP import. Следват реалната Aggregator услуга за всички local dates и по 5 извиквания на CleanestCityService за всеки диапазон. HTTP/nginx latency не е включена.

| Raw записи | 1 ден ms | 7 дни ms | 30 дни ms | 365 дни ms | Агрегация s | PostgreSQL MiB |
|---|---|---|---|---|---|---|
| 100,000 | 5.47 | 4.94 | 4.93 | 6.35 | 2.06 | 30.5 |
| 1,000,000 | 6.04 | 6.93 | 6.94 | 10.16 | 3.11 | 200.8 |
| 10,000,000 | 5.37 | 5.27 | 8.64 | 39.02 | 13.96 | 1918.8 |

Стойностите са median от 5 проби, не p95/p99 и не обещание за production SLA. Peak PHP allocation е 34 MiB във всяка серия. PostgreSQL backend peak memory не е измерена. Bulk seed отне съответно 3.87 / 38.24 / 381.58 секунди. Отделният поток от 5 000 synthetic CSV реда мина през parser, география и ingestion с около 6 049 / 6 344 / 6 278 records/s. Това **не** е измерена скорост за download/decompression/filter на световния monthly ZIP. Пълният реален backfill throughput и време остават неизмерени.

JSON файловете `benchmark-100000.json`, `benchmark-1000000.json`, `benchmark-10000000.json` пазят всички latency проби и EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON). `benchmark-plan-summary.json` обобщава plan nodes. Raw-day query използва индекса за local_date. Planner избира индекс за кратките ranges на daily таблицата и може да избере sequential scan за целия малък daily dataset — това е нормално, не е сканиране на милионите raw measurements.

След първите измервания е премахнат COUNT(*) на raw таблицата от historical requests; dataset_state вече пази counts/bounds транзакционно. Таблицата по-горе е от повторното измерване след тази оптимизация. Годишната заявка расте с броя sensor-days, не с raw sample count.

## Възпроизвеждане

Създайте отделна PostgreSQL база с име `air_benchmark` и същия собственик/права, задайте DB_* към нея и изпълнете:

```sh
DB_DATABASE=air_benchmark php artisan air:benchmark --rows=100000
DB_DATABASE=air_benchmark php artisan air:benchmark --rows=1000000
DB_DATABASE=air_benchmark php artisan air:benchmark --rows=10000000
```

Командата **изтрива тази benchmark база чрез migrate:fresh** и отказва друг database prefix. Не сменяйте guard към работна база. В текущата локална среда benchmark PostgreSQL е на 127.0.0.1:55432; Compose PostgreSQL е отделен и не е изложен на host port. След всяка серия има още 5 000 synthetic CSV записи за throughput измерването; полето rows обозначава основния набор.

Source HTTP calls не участват в benchmark. Производствената 12-месечна история не е завършена, така че тези числа не са реални национални исторически резултати.

След приключване на измерванията benchmark базата е премахната за освобождаване на диск; JSON резултатите и generator кодът са запазени.
