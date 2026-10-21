# HTTP API v1

Всички business GET endpoints имат лимит 120 заявки/минута/IP. Дати са ISO `YYYY-MM-DD`, включителни граници в Europe/Sofia. Само локални данни, без source API calls.

| Endpoint | Резултат |
|---|---|
| `/api/v1/cities` | `data[]`, GeoNames атрибуция |
| `/api/v1/data-coverage` | historical bounds, raw count, qualified days, pending aggregation, imports, sync/source freshness |
| `/api/v1/air-quality/cleanest?from=2026-10-18&to=2026-10-19` | winners[], ranking[], excluded[], coverage и методология |
| `/api/v1/air-quality/ranking?from=...&to=...` | същият консистентен response contract |
| `/api/v1/air-quality/cities/{id}?from=...&to=...` | city result, daily[], coverage и методология |
| `/health/live` | 200 alive, без DB dependency |
| `/health/ready` | 200 ready + fresh/degraded data_state; 503 при DB/cache failure |
| `/metrics` | Prometheus text; Bearer METRICS_TOKEN, иначе 403 |

Ranking row: city_id, city, pm25, pm10|null, pm10_days, sensors, days_covered, period_days, coverage_percent, rank. Excluded row вместо rank съдържа reason `no_qualifying_observations` или `insufficient_day_coverage`.

- 200 + `status=insufficient_data`, празни winners/ranking при недостатъчни или липсващи данни; липсата на наблюдения не е инфраструктурна грешка.
- 422 JSON validation errors при липсващи/невалидни дати, reversed/future range или диапазон извън локалните history bounds. При напълно празен dataset валидна минала заявка връща insufficient_data.
- 404 за неизвестен city ID, 429 за rate limit, 500 за вътрешен проблем с generic production JSON. `APP_DEBUG=false` е задължително.

Пример:

```sh
curl 'http://localhost:8080/api/v1/air-quality/cleanest?from=2026-10-18&to=2026-10-19'
curl http://localhost:8080/api/v1/data-coverage
curl http://localhost:8080/health/ready
```

`coverage.from/to` са действителни локални граници, не обещание за непрекъснато национално покритие. `qualified_days` е брой дни с поне един допустим град; per-city coverage се проверява отделно. Imports status показва дали е налична само част от поисканата история.
