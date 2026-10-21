# Наблюдение

Prometheus scrape на 60 секунди, защитен `/metrics` с bearer token от `.metrics-token`. Grafana provisioning създава Prometheus datasource и dashboard Clean Air Bulgaria. Startup профилът monitoring включва и двата сервиса. Metrics не съдържат sensor IDs като labels и избягват неограничена cardinality.

| Сигнал | Метрика/източник |
|---|---|
| HTTP брой/средна продължителност | air_http_requests_total, air_http_request_seconds_sum |
| Historical queries | air_historical_queries_total, air_historical_query_seconds_sum |
| Колекции | air_collection_success_total, air_collection_failure_total, collection_runs.duration_seconds |
| Свежест | air_last_collection_timestamp, air_source_measurement_timestamp |
| Сензори | air_sensors, air_active_sensors (наблюдавани през 7 дни) |
| Измервания/качество | air_measurements, air_valid_measurements, air_invalid_measurement_ratio |
| Backfill | air_import_backlog, air_import_complete, air_backfill_rows_processed, air_backfill_rows_invalid, import_batches checkpoints |
| Queue | air_queue_pending, air_queue_failures |
| Архив | air_archive_errors_total, archived_measurement_partitions |
| Агрегации | air_aggregation_backlog |
| PostgreSQL | air_database_up, readiness |

HTTP и query metrics са sum/count, не histogram; отчетената latency е средна, не p95. Benchmark JSON съдържа отделните проби. Source failure е structured Log::error с run/batch ID; чувствителни credentials не се логват. Точната грешка се пази в административните DB таблици и CLI output, не в публичното API.

Alert rules в deploy/prometheus/alerts.yml: 1 час без успешна collection или source measurement + 15 минути устойчивост; >=3 collection failures/час; DB или metrics down за 2 минути; failed queue/archive errors. Правилата се зареждат от Prometheus; Alertmanager/външно изпращане на нотификации не е конфигурирано, тоест тук има видими alerts, не изпращане на съобщения.

Readiness: SELECT 1 и cache write/read. Старите данни дават HTTP 200 с `data_state=degraded`, не 503. Liveness няма DB dependency. Метриките използват DB queries и не са заместител на независим PostgreSQL exporter/host disk monitoring. При голяма реална база добавете exporter и filesystem alerts; disk free guard преди monthly download изисква 8 GiB.

Collection/import counts са durable DB данни. Приложните counters са DB-backed; reset при rebuild на база се интерпретира от Prometheus rate/increase като counter reset.
