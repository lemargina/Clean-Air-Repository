# Проверка на източниците — 20.10.2026

## Sensor.Community

Използвани първични документи:

- [API wiki](https://github.com/opendata-stuttgart/meta/wiki/APIs).
- [Публичен сайт и връзка към лиценза](https://sensor.community/en/).
- [Database Contents License 1.0](https://opendatacommons.org/licenses/dbcl/1-0/).
- [Архив](https://archive.sensor.community/).

Директно проверени с HTTP:

- `https://data.sensor.community/airrohr/v1/filter/country=BG&type=SDS011` — HTTP 200, JSON масив, 1 249 записа при повторната проверка. Само `country=BG` също работи, но включва други типове. Ранното предположение за 404 на комбинирания URL беше грешно: 404 беше от стар дневен архив на неправилен път.
- `sensor.id`, `sensor.sensor_type.name`, `location.id`, `location.latitude/longitude`, `location.country`, `location.indoor`, `timestamp`, `sensordatavalues[].value_type/value` присъстват. В реални записи липсва P2; такива записи се отхвърлят. P1 е PM10, P2 е PM2.5. UTC timestamps без offset се интерпретират като UTC.
- Live v1 обхваща последните около 5 минути, **не** целия 15-минутен интервал. Следователно live polling не събира всички проби. Повторени snapshots и повече от един запис за сензор са нормални. Няма претенция за пълнота на суровите live проби.
- `https://archive.sensor.community/2026-10-19/2026-10-19_sds011_sensor_53351.csv` — HTTP 200, 45 744 bytes, semicolon CSV. Колони: `sensor_id;sensor_type;location;lat;lon;timestamp;P1;durP1;ratioP1;P2;durP2;ratioP2`. Пробите са около 145 секунди една от друга; не се предполага фиксирана честота.
- Стари дневни архиви са в `/{year}/{date}/`, текущата година — в `/{date}/`.
- `csv_per_month/2025-10/2025-10_sds011.zip` — HTTP 200 HEAD, **3 751 985 452 bytes**. Чрез HTTP Range и ZIP central directory е проверен действителният член: **един общ `2025-10_sds011.csv`**, не файлове за отделни сензори. Прочетен е действителният header и началото на CSV. Това наложи checkpoints и вътре в CSV, не само по ZIP член.
- HEAD проверки на всичките 13 monthly ZIP URL от 2025-09 до 2026-09 върнаха HTTP 200. Това включва 12-те целеви завършени месеца плюс предходния UTC месец за граничните Sofia часове. Най-големият от проверените файлове е 3 870 027 552 bytes. Локално доказателство: `storage/research/monthly-availability.json`.
- `sensors_per_country_per_day.json` съдържа **384 дни** с BG SDS011 между 2025-10-01 и 2026-10-19. Първият ден отчита 685 сензора, последният — 612. Това са бройки на източника, не локални импортни резултати. Няма списък с исторически BG sensor IDs в този файл.

Месечните архиви съдържат световните данни. Чете се всеки ред и се филтрира по география, така че отдавна спрели или преместени сензори да не бъдат пропуснати заради липса в днешния live snapshot. Текущият незавършен месец няма гарантиран месечен ZIP: по подразбиране `--months=12` избира последните 12 **завършени** месеца. Заявка до непубликуван месец се отчита като неуспешна, не като завършена история. `--sensor` е диагностичен дневен импорт, не пълен национален backfill.

В проверената API документация не е намерена числена гаранция за разрешена честота на polling. Изрично се изисква разпознаваем User-Agent, за предпочитане с контакт. Използваме един live poll на 15 минути, един последователен source HTTP request едновременно и минимум 1 секунда между заявки. Това е консервативен наш лимит, **не** потвърдена квота от оператора. При 429 се спазва Retry-After (включително HTTP дата); дълъг срок се пази като общ cooldown. Три опита, exponential backoff при network/5xx; постоянните 4xx не се повтарят автоматично.

Сайтът посочва DbCL 1.0 за съдържанието. Този лиценз отличава правата върху отделното съдържание от правата върху база данни; не го представяме като CC0 или като автоматично доказателство за всички права върху производна база. Показваме видима атрибуция. Преди публичен bulk export операторът трябва да провери приложимите database права; приложението не предлага bulk export на източниковата база.

## География

- [GeoNames BG.zip](https://download.geonames.org/export/dump/BG.zip), [readme](https://download.geonames.org/export/dump/readme.txt), [условия](https://www.geonames.org/export/): CC BY 4.0, атрибуция GeoNames. Реално изтеглени BG.txt и admin1/admin2 кодове. Разпространяваният набор от 257 града съдържа само GeoNames имена, координати, административни имена и население. Направени са подбор на градове, избор на български alternate label и нормализиране на регистъра на буквите. `city-geonames-ids.json` фиксира подбора. Населението няма обща референтна година и се използва само за консервативен радиус.
- [НСИ ЕКАТТЕ](https://nrnm.nsi.bg/ekatte/index), JSON ZIP: действително изтеглен за сверяване на статута на 257 града. [Лиценз на НСИ, версия 2.0](https://www.nsi.bg/pages/licenz-za-izpolzvaneto-na-statisticheskata-informaciya-proizvejdana-i-razprostranyavana-ot-nacionalniya-statisticheski-institut-485) съдържа ограничение за производни/сборни произведения. Поради това **не разпространяваме комбиниран NSI/GeoNames extract**; NSI ZIP остава само в игнорираната изследователска директория. Два GeoNames статута са остарели: Momin Prohod е PPLX, Valchidol има старо изписване; подборът е проверен ръчно. Сърница има стар municipality code при GeoNames; тази известна административна неточност остава видима.
- [Natural Earth чрез datasets/geo-countries](https://github.com/datasets/geo-countries): public domain geometry; включен е само BGR Polygon/MultiPolygon. [Условия](https://www.naturalearthdata.com/about/terms-of-use/). Не е кадастрална граница; близки до границата точки могат да бъдат неточно класифицирани.
- OpenStreetMap/Overpass беше проверен като алтернатива: [ODbL и атрибуция](https://www.openstreetmap.org/copyright), 270 върнати nodes/ways/relations. Имаше дублирани/липсващи градове и някои села с town tag. **OSM данни не са включени** в разпространявания набор.

Локалните изследователски HTTP отговори са в `storage/research` (не се включват в Docker образа). Хешовете на разпространяваните географски файлове са в `geography-manifest.json`.
