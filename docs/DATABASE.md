# PostgreSQL схема

- `cities`: GeoNames идентификатор, българско/латинско име, координати decimal(10,7), административни имена, население и произход.
- `sensors`: unique external_sensor_id, текуща позиция/assignment и first/last source timestamps.
- `sensor_locations`: unique(sensor_id,fingerprint). Fingerprint е SHA256 на source location ID и координатите до 7 знака. Историческите first/last seen са диапазон на наблюдение, не доказателство за непрекъснат престой. Връщане към стар адрес използва същия snapshot, raw timestamp определя кое наблюдение е било там.
- `measurements`: unique(sensor_id,measured_at), FK към snapshot и city, source URI, UTC измерено/ingested време, local_date, decimal(10,3) PM стойности, QC flag. Индекси measured_at и (local_date,city_id).
- `sensor_daily_statistics`: unique(date,city_id,sensor_id), PM средни, samples, valid/expected hours, PM10 hours, qualifies.
- `city_daily_statistics`: unique(date,city_id), средни, sensor/sample/hour counts.
- `collection_runs`: начало/край, source HTTP status, processed/inserted/duplicates/invalid, duration и error.
- `import_batches`: уникален source URI + exact requested range, ZIP member checkpoint и CSV row_checkpoint, SHA256, status, counts/errors. Един общ CSV в реалния monthly ZIP е поддържан с вътрешни checkpoints.
- `data_quality_events`: причини за отхвърляне/подозрителност при live; историческият поток съхранява invalid counts в import_batches, без милиони отделни error rows.
- `aggregation_days`: durable dirty dates.
- `archived_measurement_partitions`: month, compressed path, SHA256, row_count, archived/restored status. Името е каталог на месечни експорти, не native SQL partitions.
- `dataset_state`: singleton bounds, точен активен raw_count и последна source timestamp. Всички writes минават през IngestionService/ArchiveService; ръчен SQL import трябва изрично да възстанови каталога.
- `metric_counters`, стандартните Laravel cache/jobs/failed_jobs таблици.

UTC вътрешно, Europe/Sofia за дата. PostgreSQL е избран за timezone SQL, correctness и EXPLAIN ANALYZE. Не е нужна PostGIS extension за сегашния малък списък с градове.

Raw insert, dirty day и dataset catalog са в обща транзакция. Batch inserts са до 500 реда; sensor metadata се обновяват по групи местоположение. `air-raw-maintenance` lock сериализира ingestion и archival export, така че не се изтриват непопаднали в експорта concurrent inserts. Archive restore използва отделен archive operation lock; unique constraints правят replay безопасен.

Daily statistics остават при архивиране и историческите търсения **не изискват restore**. За ново преизчисление на архивиран период първо възстановете raw; не преизчислявайте празен raw месец, защото това би изтрило запазените дневни агрегати.
