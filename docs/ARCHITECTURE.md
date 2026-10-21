# Архитектура

## Решения

- Laravel 13.37.0, PHP 8.4: текущата инсталирана stable версия и заключени Composer зависимости. PostgreSQL 17 е единствената поддържана SQL платформа; aggregation SQL използва date_trunc и timezone. SQLite не се използва за доказване на PostgreSQL поведение.
- Blade + inline минимален CSS: няма JS build, външни fonts, tiles или frontend framework. Историческото търсене не извиква външна услуга.
- Laravel HTTP Client и streaming sink за архивни downloads; `ZipArchive::getStream` и `fgetcsv` с buffer до 500 български записа. Световните редове извън bbox се пропускат преди по-скъпата географска проверка.
- Database queue/cache: избягваме отделен Redis при малкия write rate на live колектора. Shared DB locks работят между scheduler, worker и web. Един source download едновременно; тежък месечен download може да забави live събирането. Историческите локални заявки продължават да работят. Ако това стане оперативен проблем, source лимитерът трябва да се раздели по hostname с отделен лимит за live.
- Raw таблица с B-tree индекси. Partitioning е отложено: опростява FK, unique constraints и restore. Месечен verified compressed export осигурява 36-месечната retention политика. След наблюдение на реалния обем може да се добави native partitioning; няма фиктивна претенция за вече реализирани partitions.
- Еднакъв immutable geographic association за raw measurement + отделни sensor location snapshots. Сегашният адрес на сензора се обновява само от по-нова/равна source timestamp; късен backfill не премества сензора назад във времето. При еднакъв timestamp конфликтният повторен payload не презаписва първия запис.
- Dirty-day ledger се записва в същата транзакция като raw insert. Aggregation е transactionally replace за един Sofia ден. Updated-at checkpoint не се премахва, ако нов importer го е променил междувременно. Няма неконсистентен partially replaced city-day.
- `dataset_state` пази точния raw count и historical bounds, така че API search да не прави COUNT върху милиони raw rows. Границите остават след архивиране, защото daily aggregates са запазени.
- Rankings са равни тегла на city-days, изрично методологично решение, а не случайно усредняване на несравними sample counts. PM10 никога не влияе на мястото.
- Локален compressed JSONL архив: лесна проверка, възстановимост и ниски зависимости. За production нискоценово object storage може да замени persistent volume, но не е реализирана S3 интеграция.

## Поток

`HTTP/CSV → parser + boundary/quality validation → sensor/location → raw + dirty days → qualified sensor-hour/day → city-day → city-period ranking`.

Source outage се записва в collection_runs; HTTP UI чете само локални агрегати. Infrastructure readiness и свежестта са независими. Metrics failures не прекъсват потребителските отговори.

## Ограничения

Координати от общността могат да са закръглени. Nearest-center matching не е settlement polygon containment. Няма interpolation, humidity correction или calibration; сензорите не са референтни станции. GeoNames административните имена/население може да са остарели. Синтетичните benchmarks не доказват реален национален 12-месечен импорт.
