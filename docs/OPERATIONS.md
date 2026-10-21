# Оперативни процедури

## Ежедневна проверка

`docker compose ps`, `/health/ready`, `/api/v1/data-coverage`, dashboard, `collection_runs` и `failed_jobs`. Гледайте както sync time, така и source measurement time. Проверката на historical bounds не доказва липса на дупки или достатъчно покритие във всеки град.

## Недостъпен source / пропуснати колекции

Историческото търсене продължава локално. Проверете HTTP status/errors, DNS, User-Agent контакт и Retry-After. Не увеличавайте retry честотата. След възстановяване: `php artisan air:collect`. Пропуснатите live 5-минутни snapshots не могат да бъдат получени от live API по-късно; възстановете периода от публикуван monthly archive и изпълнете `air:aggregate`.

## Незавършен backfill

Повторете **същия диапазон** в `air:backfill`. Уникалният source_file включва range; друг range е друга batch. Complete batches се пропускат. Запазеният ZIP се проверява по SHA256. CSV row checkpoint се записва след committed chunks; при рестарт compressed stream се прочита до checkpoint без повторно валидиране/вмъкване на старите редове. Това е bounded-memory resume, но изисква sequential decompression на предишния prefix. Реалният monthly ZIP има един CSV и това може да отнеме време. Прекъснат download започва отначало; HTTP byte-resume не е реализиран.

Проверете `import_batches.status`, row_checkpoint, processed, inserted, invalid и error. След transient проблем: повторете командата или `queue:retry {id}`. След неочакван crash проверете expiration на lock (до 6 часа). Не премахвайте жив lock. `queue:failed`, логовете и checkpoints помагат да се разграничи активно job от изоставен status=processing.

## Повреден archive download

Невалиден ZIP не се преименува като готов; corrupt local ZIP се премахва при следващото отваряне. `.partial` не е готов файл. При SHA mismatch спрете, сравнете source Last-Modified/ETag и локалния файл. Само след операторска проверка нулирайте checkpoint/row_checkpoint/sha256 за конкретния batch и повторете; unique raw constraint пази от дублиране. Не маркирайте batch complete ръчно.

## Неуспешна агрегация

`php artisan air:aggregate` обработва dirty days. За локален, неархивиран диапазон: `php artisan air:aggregate --from=2026-01-01 --to=2026-01-31`. Повторението е идемпотентно. Транзакцията запазва старите статистики при грешка. Ако raw е архивиран, командата отказва преизчисление до restore, за да не унищожи запазените daily aggregates.

## Retention и възстановяване

`php artisan air:archive`: пълни UTC месеци, чийто край е преди 36-месечния cutoff. Консервативно се запазва текущият cutoff месец до следващия цикъл. Формат JSONL gzip, SHA256 на compressed файла и повторно декомпресиране с digest/row count преди delete. Неуспешен export не изтрива raw. Daily aggregates остават достъпни за исторически ranking.

`php artisan air:archive --restore=2022-01`: проверява SHA256 и count **преди** insert. Възстановява FK към съществуващи sensors/locations/cities, така че database metadata backup е необходим. Inserts и catalog increments са transactional по chunk и replay-safe. При късен import в вече archived month първо restore, после aggregate и rearchive, за да не презапишете пълния стар архив с частичните нови данни.

## Database failure

Публичната readiness става 503, liveness остава 200. Спрете worker/scheduler, възстановете backup и archive volume по DEPLOYMENT.md. Проверете foreign keys, миграции, dataset_state, failed jobs и последни source timestamps. Включете приложението след readiness. Не изпълнявайте migrate:fresh в работна база.

## Диск и капацитет

Следете DB, WAL и archive/download volume. Един monthly ZIP е няколко GB; при <8 GiB свободно нов monthly download отказва. Това е минимална предпазна мярка, не гаранция, че целият импорт се побира. Бенчмаркът измерва actual PostgreSQL bytes, но не заменя conservative sizing за дълги source URI, backups и WAL. При нисък диск преместете persistent storage; не изтривайте raw, преди integrity-verified archive и backup.
