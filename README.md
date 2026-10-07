# CourtMonitor — клиент и справочник API

Composer-пакет `lawmatic/court-monitor`: PHP-клиент внешнего сервиса мониторинга
судебных дел — каталог судов (`courts.lawmatic.ru`) и парсеры дел (`prsr.lawmatic.ru`).

## Установка

```bash
composer require lawmatic/court-monitor
```

Нужны PHP 8.2+, любая реализация `symfony/http-client-contracts`
(обычно `symfony/http-client`) и, по желанию, PSR-3 логгер.

Тесты пакета: `composer install && vendor/bin/phpunit`.

## Клиент в PHP

```php
$client = new CourtMonitorClient(
    $httpClient,        // Symfony\Contracts\HttpClient\HttpClientInterface
    $key,               // ключ парсера
    'moscow',           // парсер, если вызывающий код не передал свой
    $courtsToken,       // токен каталога судов (X-Auth-Token); без него §1 не работает
    // адреса сервисов ($courtsUrl, $parserUrl) — необязательно, по умолчанию боевые (§8)
    logger: $logger,    // Psr\Log\LoggerInterface, необязательно
);
```

Зависеть стоит от `CourtMonitorClientInterface`. Методы и разделы справочника:

| Метод | Что делает | Раздел |
|-------|------------|--------|
| `searchCourtByName`, `searchCourtByCode` | Поиск суда в каталоге (до 20 судов) | §1.1, §1.2 |
| `searchCourts` | Постраничный поиск с любыми фильтрами: `{items, total, limit, offset}` | §1.1 |
| `getCourtDetail` | Карточка суда с иерархией | §1.3 |
| `getCourtTypes` | Типы судов `{code, name, kbk}` | §1.4 |
| `getParserFor` | `parser_id` и `court_id` по URL сайта суда (без ключа) | §2 |
| `getTotalCounts` | Сколько дел и страниц найдёт поиск | §3.1 |
| `getShortCasesFromPage` | Краткие карточки с одной страницы | §3.2 |
| `getCasesUrlsFromPage` | URL дел с одной страницы | §3.3 |
| `getCaseUrlsForUid` | URL дела по УИД | §3.4 |
| `getFullCases` | Полные карточки по URL (каноничный формат), пачками по 5 | §4 |
| `getFullCaseForUid` | §3.4 + §4 одним вызовом; код суда — первые 8 символов УИД | §6.3 |
| `checkKey` | Проверка ключа парсера | §5 |
| `createSearchJob`, `createCardsJob` | Задание в фоне: вся выдача по суду / карточки по ссылкам | §5.1 |
| `getJob`, `getJobResults`, `getAllJobResults`, `cancelJob` | Статус, результаты частями или все, отмена | §5.1 |

У методов парсера и транслятора последние аргументы `$parserId` и `$key`
необязательны: без них берутся значения, заданные при создании клиента.

### Ошибки

Любой сбой — наследник `Exception\CourtMonitorException`:

- `CourtMonitorTransportException` — разборчивого ответа нет: сеть, таймаут,
  не-2xx HTTP-код, битый JSON. Код исключения — HTTP-статус (0 — ответа не было);
- `CourtMonitorApiErrorException` — сервис ответил конвертом
  `{"status":"error","error":"…"}`, текст — в `getApiError()`;
- `CourtMonitorInvalidKeyException` — сервис отверг ключ: HTTP 401
  `{"detail":"Неверный ключ"}` (§3.1) или конверт с ошибкой `Неверный ключ` /
  `Incorrect key` / `Invalid key` / `Unauthorized`. У заданий (§5.1) — ещё
  HTTP 403: общий ключ сервиса вместо ключа клиента `cp_…`.

У заданий ошибки сервиса приходят не конвертом, а `{"detail": …}` с HTTP-кодом:
400 (параметры, неизвестный сайт), 404 (задания нет), 503 (база заданий не
настроена) — это `CourtMonitorApiErrorException` с кодом и текстом `detail`.

Текст сообщения — часть контракта, его префиксы менять нельзя: транспортные
ошибки начинаются с `CourtMonitor request to <url> failed:`, ошибки сервиса —
с `CourtMonitor API error:`. Приложение может хранить текст и разбирать его
позже, когда типа исключения уже нет.

### Граница

Код пакета опирается только на собственное пространство имён, `psr/log`
и `symfony/http-client-contracts`, без атрибутов фреймворка: настройки
передаются через конструктор, а связывать клиент с DI-контейнером — дело
приложения. Это проверяет `tests/CourtMonitorBoundaryTest.php`.

---

## Справочник API

Ниже — сырые запросы к сервису для ручной проверки через curl.

### Переменные для примеров

Это переменные оболочки для curl-примеров ниже, а не настройки приложения.
Перед запуском подставьте свои значения (в bash):

```bash
export PARSER_KEY="ваш_api_ключ"
export COURTS_TOKEN="токен_каталога_судов"
export COURT_URL="https://tverskoy--mos.sudrf.ru"
export CASE_URL="https://tverskoy--mos.sudrf.ru/modules.php?name=sud_delo&..."
export COURT_CODE="77RS0001"
export COURT_ID="12345"
export UID="77RS0001-01-2024-00123456-01"
export COURT_NAME="Тверской районный суд"
```

На Windows (PowerShell) можно задать так:

```powershell
$env:PARSER_KEY = "ваш_api_ключ"
```

---

## 1. Каталог судов — `courts.lawmatic.ru`

Базовый URL: `https://courts.lawmatic.ru`
Все запросы — с заголовком `X-Auth-Token: <токен>`. Без него — HTTP 400
`{"error":"missing X-Data or X-Auth-Token"}`.

### 1.1. Поиск суда по названию

**Метод клиента:** `searchCourtByName` — по части названия (`contains`).

```bash
curl -sS -X POST "https://courts.lawmatic.ru/api/v1/courts/search" \
  -H "X-Auth-Token: ${COURTS_TOKEN}" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d "{\"limit\":20,\"offset\":0,\"name\":{\"value\":\"${COURT_NAME}\",\"match\":\"contains\"}}"
```

`match` — `exact`, `prefix` или `contains`. Ещё поля фильтра: `court_type`,
`address`, `parent` (с тем же `{value, match}`), `parent_missing`,
`requires_attention`.

**Пример ответа (сокращён):**

```json
{
  "items": [
    {
      "id": 10069,
      "code": "77RS0021",
      "name": "Пресненский районный суд города Москвы",
      "court_type": {"code": "RS", "name": "Районный, городской, межрайонный суд"},
      "website": "https://mos-gorsud.ru/rs/presnenskij",
      "parent_id": 8081,
      "hierarchy": [
        {"id": 8081, "code": "77OS0000", "name": "Московский городской суд"}
      ]
    }
  ],
  "total": 1,
  "limit": 20,
  "offset": 0
}
```

В карточке суда есть и реквизиты: адрес, телефон, email, ИНН/КПП, банк и т.д.

---

### 1.2. Поиск суда по коду

**Метод клиента:** `searchCourtByCode` — точное совпадение кода.

```bash
curl -sS -X POST "https://courts.lawmatic.ru/api/v1/courts/search" \
  -H "X-Auth-Token: ${COURTS_TOKEN}" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d "{\"limit\":20,\"offset\":0,\"code\":{\"value\":\"${COURT_CODE}\",\"match\":\"exact\"}}"
```

Ответ — как в §1.1. Код не найден — пустой `items`, `total: 0`.

---

### 1.3. Детальная карточка суда

**Метод клиента:** `getCourtDetail`. `COURT_ID` — поле `id` из ответа поиска.

```bash
curl -sS "https://courts.lawmatic.ru/api/v1/courts/${COURT_ID}" \
  -H "X-Auth-Token: ${COURTS_TOKEN}"
```

Ответ — карточка суда без обёртки (как элемент `items` в §1.1), с `hierarchy`
вплоть до Верховного суда. Суда нет — HTTP 404 `{"error":"court not found"}`.

---

### 1.4. Типы судов

**Метод клиента:** `getCourtTypes` — для фильтра `court_type` в поиске.

```bash
curl -sS "https://courts.lawmatic.ru/api/v1/court-types" \
  -H "X-Auth-Token: ${COURTS_TOKEN}"
```

Ответ — массив `{"code":"RS","name":"...","kbk":"..."}`: код типа, название
и КБК госпошлины по умолчанию.

Постраничный поиск по каталогу — `searchCourts($filter, $limit, $offset)`: фильтры
как в §1.1, `limit` — до 500 (0 — каталог возьмёт 50), ответ — `{items, total, limit, offset}`,
где `total` — сколько судов подходит под фильтр всего. Каталог считает запросы
по токену за сутки и при исчерпании квоты отвечает HTTP 429.

---

## 2. Определение парсера по URL суда

**Endpoint:** `POST https://prsr.lawmatic.ru/v1/resolve` (ключ не нужен)  
**Метод клиента:** `getParserFor`

```bash
curl -sS -X POST "https://prsr.lawmatic.ru/v1/resolve" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d "{\"url\":\"${COURT_URL}\"}"
```

**Пример ответа:**

```json
{
  "parser_id": "federal",
  "court_id": "tverskoy--mos",
  "court_id_can_empty": false,
  "source": "sudrf"
}
```

Список парсеров — `GET https://prsr.lawmatic.ru/v1/parsers` (без ключа): `cassation`,
`federal`, `federal_magistrate`, `kad`, `moscow`, `moscow_magistrate`,
`spb_magistrate`, `tatarstan_magistrate`, `vsrf`.

---

## 3. Поиск дел — `POST https://prsr.lawmatic.ru/v1/urls`

Ключ — в заголовке `x-api-key` (клиент шлёт его так). Тело — плоский JSON:
`parser_id` обязателен, остальные поля — по §7. Заголовок для всех запросов:

```bash
-H "Content-Type: application/json; charset=utf-8" -H "x-api-key: ${PARSER_KEY}"
```

Ответ — конверт:

```json
{
  "status": "ok",
  "request_id": "…",
  "parser_id": "moscow",
  "court_id": "presnenskij",
  "cases": [],
  "search": {
    "urls": [],
    "cases": [],
    "total_urls": 42,
    "total_pages": 5,
    "page": 1,
    "total_captcha": 0
  },
  "error": null
}
```

Клиент возвращает содержимое `search`. `status: "error"` — `CourtMonitorApiErrorException`
с текстом из `error`.

### 3.1. Подсчёт дел и страниц

**Метод клиента:** `getTotalCounts` — возвращает весь блок `search`.

```bash
curl -sS -X POST "https://prsr.lawmatic.ru/v1/urls" \
  -H "Content-Type: application/json; charset=utf-8" \
  -H "x-api-key: ${PARSER_KEY}" \
  -d '{
    "parser_id": "moscow",
    "court_id": "presnenskij",
    "members": "Иванов Иван Иванович",
    "date_from": "01.01.2024",
    "date_to": "31.12.2024",
    "process_type": "2"
  }'
```

**Неверный ключ:** HTTP **401**, `{"detail":"Неверный ключ"}` — клиент кидает
`CourtMonitorInvalidKeyException`. Ключ проверяется раньше содержимого запроса,
но после разбора тела: запрос без `parser_id` даёт 422 при любом ключе.

### 3.2. Краткие дела (одна страница)

**Метод клиента:** `getShortCasesFromPage` — то же, что §3.1, плюс `"page": 1`
(с 1). Возвращает `search.cases`: `{url, number, extra}`.

### 3.3. Список URL дел (одна страница)

**Метод клиента:** `getCasesUrlsFromPage` — запрос как в §3.2, возвращает `search.urls`.

### 3.4. URL дела по УИД

**Метод клиента:** `getCaseUrlsForUid` (первый шаг `getFullCaseForUid`)

```bash
curl -sS -X POST "https://prsr.lawmatic.ru/v1/urls" \
  -H "Content-Type: application/json; charset=utf-8" \
  -H "x-api-key: ${PARSER_KEY}" \
  -d "{\"parser_id\":\"moscow\",\"court_id\":\"presnenskij\",\"unique_number\":\"${UID}\"}"
```

Возвращает `search.urls`.

---

## 4. Полные карточки дел — `POST https://prsr.lawmatic.ru/v1/parse`

**Метод клиента:** `getFullCases` — URL бьются на пачки по 5, результаты склеиваются.

```bash
curl -sS -X POST "https://prsr.lawmatic.ru/v1/parse" \
  -H "Content-Type: application/json; charset=utf-8" \
  -H "x-api-key: ${PARSER_KEY}" \
  -d "{\"parser_id\":\"moscow\",\"court_id\":\"presnenskij\",\"urls\":[\"${CASE_URL}\"]}"
```

Клиент возвращает `cases` конверта. Каждая карточка — каноничная:

| Поле | Содержимое |
|------|------------|
| `schema_version`, `source`, `parser_id`, `url`, `parsed_at` | служебные |
| `case` | `uid`, `number`, `instance_id`, `type`, `category`, `court_id`, `court_name`, `judge`, `receipt_date`, `decision_date`, `result`, `consideration`, `current_state`, `updated_at`, `extra` |
| `parties[]` | `role`, `role_raw`, `name`, `inn`, `kpp`, `ogrn`, `ogrnip`, `address`, `id`, `extra` |
| `events[]` | `name`, `date`, `time`, `place`, `result`, `reason`, `comment`, `published_at`, `extra` |
| `documents[]` | `type`, `date`, `url`, `text`, `extra` |
| `appeals[]`, `lower_court`, `writs[]`, `instances[]`, `extra` | остальное |

Схема — `/openapi.json` сервиса (`CanonicalCase`).

---

## 5. Проверка API-ключа

**Метод клиента:** `checkKey`

Клиент шлёт `GET https://prsr.lawmatic.ru/v1/key/check` с заголовком `x-api-key`;
сайты судов сервис не дёргает. HTTP 200 — ключ принят (`true`), HTTP 401 — неверный (`false`).
Сбой сети или другая ошибка сервиса —
исключение, а не `false`.

### 5.1. Задания — поиск и карточки в фоне (`/v1/jobs`)

Долгий поиск (все страницы, дробление периода при выдаче, обрезанной на 1500) и
пачки карточек сервис выполняет сам, в фоне: не нужно держать HTTP-запрос
минутами. Только по **ключу клиента** `cp_…` (общий ключ сервиса — 403).

```php
// поиск: поля как у §3 (members, court_id, date_from, ...), виды производства — process_types
$job = $client->createSearchJob(
    ['members' => 'Иванов И.И.', 'court_id' => 'presnenskij', 'process_types' => ['2']],
    'moscow',
    ['queue' => 'monitoring', 'max_pages' => 20, 'max_cases' => 300],
);
// $job = ['job_id' => '…', 'status' => 'queued', 'duplicate' => false]

$view = $client->getJob($job['job_id']);          // status: queued|running|done|partial|failed|cancelled
if (!in_array($view['status'], ['queued', 'running'], true)) {
    $items = $client->getAllJobResults($job['job_id']);
    // [['seq' => 1, 'url' => '…', 'number' => '…', 'data' => ['key' => 'mos-gorsud:presnenskij:…', 'extra' => […]]], …]
}

$cards = $client->createCardsJob(['https://…', 'https://…']);   // до 500 ссылок; data.case — карточка как в §4
```

- `queue`: `monitoring` (по расписанию) или `adhoc` (разовые) — по умолчанию очередь клиента.
- `max_pages` — страниц **на каждый период** (обрезанная выдача делится по датам);
  общий объём держит `max_cases` (по умолчанию 2000), `max_captcha` — 20.
  Упёрлись в лимит — статус `partial`, причина — в `note`.
- `data.key` — ключ дела «сайт:суд:номер»: тот же у строки выдачи и у карточки;
  по нему известное дело узнаётся без открытия карточки. УИДа в выдаче почти
  нигде нет — только в карточке.
- Те же параметры от того же клиента в течение 10 минут — то же задание (`duplicate: true`).
- Результаты завершённых заданий хранятся 7 дней.

---

## 6. Типовые сценарии

### 6.1. Поиск дел по участнику и датам

1. §3.1 — подсчёт (`total_pages`);
2. §3.3 (или §3.2) для `page = 1 .. total_pages`;
3. §4 — полные дела по полученным URL.

### 6.2. Дело по прямой ссылке

1. §2 — `parser_id` и `court_id` по URL сайта суда;
2. §4 — полная карточка по URL дела.

### 6.3. Дело по УИД

1. §3.4 — URL по УИД;
2. §4 — полная карточка. `getFullCaseForUid` делает оба шага; код суда берёт
   из первых 8 символов УИД, если `court_id` не передан. Для парсеров с
   другим видом `court_id` (например `federal`: `odintsovo--mo`) передайте его явно.
   У `moscow` код из УИД портал не распознаёт и ищет УИД по всем судам Москвы:
   дело находится, но для поиска по участнику передавайте `court_id` из §2.

### 6.4. Иерархия судов по УИД (первые 8 символов = код суда)

```bash
export COURT_CODE_FROM_UID="${UID:0:8}"

# Шаг 1: поиск суда по коду — в ответе уже есть website и hierarchy
curl -sS -X POST "https://courts.lawmatic.ru/api/v1/courts/search" \
  -H "X-Auth-Token: ${COURTS_TOKEN}" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d "{\"limit\":1,\"offset\":0,\"code\":{\"value\":\"${COURT_CODE_FROM_UID}\",\"match\":\"exact\"}}"

# Шаг 2 (по желанию): полная карточка по id из ответа
curl -sS "https://courts.lawmatic.ru/api/v1/courts/${COURT_ID}" \
  -H "X-Auth-Token: ${COURTS_TOKEN}"
```

Так можно найти сайт суда для §2, когда в запросе мониторинга есть только
УИД или код суда.

---

## 7. Справка: поля поиска `/v1/urls`

| Поле | Назначение | Пример |
|------|------------|--------|
| `parser_id` | Парсер (обязательно) | `"moscow"` |
| `court_id` | ID суда в парсере — тот, что вернул §2 по сайту суда (`presnenskij`, `odintsovo--mo`), а не код из каталога. Код вроде `77RS0021` mos-gorsud.ru молча пропускает и ищет по всем судам Москвы | `"presnenskij"` |
| `case_number` | Номер дела | `"2-1234/2024"` |
| `unique_number` | УИД дела | `"77RS0001-01-2024-..."` |
| `members` | Участник дела | `"Иванов И.И."` |
| `inn`, `ogrn` | ИНН / ОГРН участника | `"7701234567"` |
| `judge` | Судья | `"Петров П.П."` |
| `date_from`, `date_to` | Дата регистрации от / до (`дд.мм.гггг`) | `"01.01.2024"` |
| `case_final_date_from`, `case_final_date_to` | Дата окончания дела от / до | `"01.01.2024"` |
| `process_type` | Вид производства, значения — у каждого парсера свои (ниже). Пусто — по умолчанию парсера | `"criminal"` |
| `page` | Номер страницы (с 1) | `1` |

Значения `process_type`. Неизвестное значение — ошибка запроса, а не поиск «по умолчанию».

| Парсер | Значения | Пусто |
|--------|----------|-------|
| `federal` | `civil`, `admin` (КАС), `criminal`, `adm_offense` (КоАП), `materials`, `civil_appeal`, `criminal_appeal`, `adm_offense_appeal` | `civil` |
| `federal_magistrate` | `civil`, `criminal`, `adm_offense` | `civil` |
| `cassation` | `civil` | `civil` |
| `moscow` | код формы поиска mos-gorsud.ru (`2` — гражданские, …) | все виды |
| `moscow_magistrate` | код формы поиска mos-sud.ru (`2` — гражданские, …) | `2` |
| `spb_magistrate` | `civil`, … как на mirsud.spb.ru | `civil` |

Поля `reg_date_start` / `reg_date_stop` старого API больше не действуют —
вместо них `date_from` / `date_to`.

---

## 8. Адреса по умолчанию

| Назначение | Адрес | Аргумент конструктора (константа) |
|------------|-------|-----------------------------------|
| Парсеры: `/v1/resolve`, `/v1/urls`, `/v1/parse` | `https://prsr.lawmatic.ru` | `$parserUrl` (`DEFAULT_PARSER_URL`) |
| Каталог судов | `https://courts.lawmatic.ru` | `$courtsUrl` (`DEFAULT_COURTS_URL`) |

---

## 9. Windows / PowerShell

Для сложных JSON удобнее сохранить тело в файл и вызвать:

```powershell
curl.exe -sS -X POST "https://prsr.lawmatic.ru/v1/urls" `
  -H "Content-Type: application/json; charset=utf-8" `
  -H "x-api-key: $env:PARSER_KEY" `
  -d "@body-count.json"
```

Флаг `-sS` скрывает прогресс и показывает ошибки curl. Для отладки добавьте `-v`.
