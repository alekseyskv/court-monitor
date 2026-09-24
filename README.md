# CourtMonitor — клиент и справочник API

Composer-пакет `lawmatic/court-monitor`: PHP-клиент внешнего сервиса мониторинга
судебных дел — каталог судов (`courts.lawmatic.ru`), парсер (`parsers.lawmatic.ru`)
и транслятор (`translator.lawmatic.ru`).

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
    // адреса сервисов — необязательно, по умолчанию боевые (§8)
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
| `getParserFor` | `parser_id` и `court_id` по URL сайта суда | §2 |
| `getTotalCounts` | Сколько дел и страниц найдёт поиск | §3.1 |
| `getShortCasesFromPage` | Краткие карточки с одной страницы | §3.2 |
| `getCasesUrlsFromPage` | URL дел с одной страницы | §3.3 |
| `getCaseUrlsForUid` | URL дела по УИД | §3.4 |
| `getFullCases` | Полные карточки по URL, пачками по 5 | §4 |
| `getFullCaseForUid` | §3.4 + §4 одним вызовом; код суда — первые 8 символов УИД | §6.3 |
| `checkKey` | Проверка ключа парсера | §5 |

У методов парсера и транслятора последние аргументы `$parserId` и `$key`
необязательны: без них берутся значения, заданные при создании клиента.

### Ошибки

Любой сбой — наследник `Exception\CourtMonitorException`:

- `CourtMonitorTransportException` — разборчивого ответа нет: сеть, таймаут,
  не-2xx HTTP-код, битый JSON. Код исключения — HTTP-статус (0 — ответа не было);
- `CourtMonitorApiErrorException` — сервис ответил конвертом
  `{"status":"error"}`, текст — в `getApiError()`;
- `CourtMonitorInvalidKeyException` — сервис отверг ключ: ошибка `Incorrect key` /
  `Invalid key` / `Unauthorized` в конверте (в том числе пришедшем с HTTP 400)
  или HTTP 400 без конверта на полные карточки (§4.3).

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

**Endpoint:** `POST https://translator.lawmatic.ru/`  
**Метод клиента:** `getParserFor`

```bash
curl -sS -X POST "https://translator.lawmatic.ru/" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d "{\"court_url\":\"${COURT_URL}\"}"
```

Читаемый вариант (файл `body-check-parser.json`):

```json
{
  "court_url": "https://tverskoy--mos.sudrf.ru"
}
```

```bash
curl -sS -X POST "https://translator.lawmatic.ru/" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d @body-check-parser.json
```

**Пример ответа:**

```json
{
  "parser_id": "moscow",
  "court_id": "77RS0001",
  "court_id_can_empty": 0
}
```

---

## 3. Парсер дел — `POST https://parsers.lawmatic.ru/api/v1/urls`

Общие заголовки для всех запросов раздела:

```bash
-H "Content-Type: application/json; charset=utf-8"
```

Общая обёртка тела:

```json
{
  "params": { },
  "parser_id": "moscow",
  "key": "ваш_api_ключ"
}
```

---

### 3.1. Подсчёт дел и страниц

**Метод клиента:** `getTotalCounts`

Сохраните тело в `body-count.json`:

```json
{
  "params": {
    "members": "Иванов Иван Иванович",
    "reg_date_start": "01.01.2024",
    "reg_date_stop": "31.12.2024",
    "process_type": "гражданское",
    "court_id": "77RS0001",
    "unique_number": ""
  },
  "parser_id": "moscow",
  "key": "ваш_api_ключ"
}
```

```bash
curl -sS -X POST "https://parsers.lawmatic.ru/api/v1/urls" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d @body-count.json
```

Inline (подставьте `$PARSER_KEY`):

```bash
curl -sS -X POST "https://parsers.lawmatic.ru/api/v1/urls" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d "{
    \"params\": {
      \"members\": \"Иванов Иван Иванович\",
      \"reg_date_start\": \"01.01.2024\",
      \"reg_date_stop\": \"31.12.2024\",
      \"process_type\": \"гражданское\",
      \"court_id\": \"77RS0001\",
      \"unique_number\": \"\"
    },
    \"parser_id\": \"moscow\",
    \"key\": \"${PARSER_KEY}\"
  }"
```

**Пример ответа (успех):**

```json
{
  "status": "ok",
  "error": null,
  "data": {
    "total_urls": 42,
    "total_pages": 5,
    "total_captcha": 0,
    "page": 0,
    "urls": null,
    "cases": null
  }
}
```

**Пример ответа (ошибка авторизации):**

Фактически (проверено 21.09.2026) парсер отвечает на неверный или пустой ключ
**HTTP 400** с телом:

```json
{"error":"Incorrect key","status":"error"}
```

В прежней версии справочника был такой вариант (HTTP 200):

```json
{
  "status": "error",
  "error": "Unauthorized",
  "data": null
}
```

---

### 3.2. Краткие дела (одна страница)

**Метод клиента:** `getShortCasesFromPage`

`body-short-cases-page1.json`:

```json
{
  "params": {
    "page": 1,
    "members": "Иванов Иван Иванович",
    "reg_date_start": "01.01.2024",
    "reg_date_stop": "31.12.2024",
    "process_type": "гражданское",
    "court_id": "77RS0001",
    "unique_number": ""
  },
  "parser_id": "moscow",
  "key": "ваш_api_ключ"
}
```

```bash
curl -sS -X POST "https://parsers.lawmatic.ru/api/v1/urls" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d @body-short-cases-page1.json
```

Страница 2:

```bash
curl -sS -X POST "https://parsers.lawmatic.ru/api/v1/urls" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d "{
    \"params\": {
      \"page\": 2,
      \"members\": \"Иванов Иван Иванович\",
      \"reg_date_start\": \"01.01.2024\",
      \"reg_date_stop\": \"31.12.2024\",
      \"process_type\": \"гражданское\",
      \"court_id\": \"77RS0001\",
      \"unique_number\": \"\"
    },
    \"parser_id\": \"moscow\",
    \"key\": \"${PARSER_KEY}\"
  }"
```

**Пример ответа:**

```json
{
  "status": "ok",
  "error": null,
  "data": {
    "total_urls": 42,
    "total_pages": 5,
    "total_captcha": 0,
    "page": 1,
    "urls": null,
    "cases": [
      {
        "url": "https://tverskoy--mos.sudrf.ru/modules.php?name=sud_delo&...",
        "number": "2-1234/2024",
        "members": "Иванов И.И. - ответчик",
        "status": "В производстве",
        "judge": "Петров П.П.",
        "article": null,
        "category": "о взыскании задолженности"
      }
    ]
  }
}
```

---

### 3.3. Список URL дел (одна страница)

**Метод клиента:** `getCasesUrlsFromPage`  
Тело запроса **идентично** §3.2 (с полем `page`).

```bash
curl -sS -X POST "https://parsers.lawmatic.ru/api/v1/urls" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d @body-short-cases-page1.json
```

**Пример ответа:**

```json
{
  "status": "ok",
  "error": null,
  "data": {
    "total_urls": 42,
    "total_pages": 5,
    "total_captcha": 0,
    "page": 1,
    "urls": [
      "https://tverskoy--mos.sudrf.ru/modules.php?name=sud_delo&..."
    ],
    "cases": null
  }
}
```

---

### 3.4. URL дела по УИД

**Метод клиента:** `getCaseUrlsForUid` (первый шаг `getFullCaseForUid`)

`body-uid-urls.json`:

```json
{
  "params": {
    "court_id": "77RS0001",
    "process_type": "",
    "unique_number": "77RS0001-01-2024-00123456-01"
  },
  "parser_id": "moscow",
  "key": "ваш_api_ключ"
}
```

```bash
curl -sS -X POST "https://parsers.lawmatic.ru/api/v1/urls" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d @body-uid-urls.json
```

С переменной `$UID`:

```bash
curl -sS -X POST "https://parsers.lawmatic.ru/api/v1/urls" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d "{
    \"params\": {
      \"court_id\": \"77RS0001\",
      \"process_type\": \"\",
      \"unique_number\": \"${UID}\"
    },
    \"parser_id\": \"moscow\",
    \"key\": \"${PARSER_KEY}\"
  }"
```

**Пример ответа:**

```json
{
  "status": "ok",
  "error": null,
  "data": {
    "total_urls": 1,
    "total_pages": 1,
    "total_captcha": 0,
    "page": 1,
    "urls": [
      "https://tverskoy--mos.sudrf.ru/modules.php?name=sud_delo&..."
    ],
    "cases": null
  }
}
```

---

## 4. Полные карточки дел — `POST https://translator.lawmatic.ru/`

**Метод клиента:** `getFullCases`

### 4.1. Одно дело по прямой ссылке

`body-full-case-one.json`:

```json
{
  "params": {
    "court_id": "77RS0001",
    "process_type": "",
    "urls": [
      "https://tverskoy--mos.sudrf.ru/modules.php?name=sud_delo&..."
    ]
  },
  "parser_id": "moscow",
  "key": "ваш_api_ключ"
}
```

```bash
curl -sS -X POST "https://translator.lawmatic.ru/" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d @body-full-case-one.json
```

С переменной `$CASE_URL`:

```bash
curl -sS -X POST "https://translator.lawmatic.ru/" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d "{
    \"params\": {
      \"court_id\": \"77RS0001\",
      \"process_type\": \"\",
      \"urls\": [\"${CASE_URL}\"]
    },
    \"parser_id\": \"moscow\",
    \"key\": \"${PARSER_KEY}\"
  }"
```

---

### 4.2. Несколько дел (пакет, клиент шлёт до 5 URL)

`body-full-cases-batch.json`:

```json
{
  "params": {
    "court_id": "77RS0001",
    "process_type": "",
    "urls": [
      "https://tverskoy--mos.sudrf.ru/modules.php?name=sud_delo&case1",
      "https://tverskoy--mos.sudrf.ru/modules.php?name=sud_delo&case2"
    ]
  },
  "parser_id": "moscow",
  "key": "ваш_api_ключ"
}
```

```bash
curl -sS -X POST "https://translator.lawmatic.ru/" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d @body-full-cases-batch.json
```

---

### 4.3. Пример ответа (полное дело)

```json
{
  "cases": [
    {
      "url": "https://tverskoy--mos.sudrf.ru/modules.php?name=sud_delo&...",
      "acts": ["решение"],
      "appeal": null,
      "case_details": {
        "case_id": "77RS0001-01-2024-00123456-01",
        "case_num": "2-1234/2024",
        "category": "о взыскании задолженности",
        "judge": "Петров П.П.",
        "court_name": "Тверской районный суд города Москвы",
        "receipt_date": "15.01.2024",
        "trial_date": "20.03.2024",
        "trial_mark": "",
        "trial_result": "Иск удовлетворён"
      },
      "case_parties": [
        {
          "party_name": "ООО «Ромашка»",
          "party_type": "истец",
          "party_inn": "7701234567",
          "party_kpp": "770101001",
          "party_ogrn": "1027700132195",
          "party_ogrnip": null
        }
      ],
      "case_progress": [],
      "case_documents": [],
      "lower_court_trials": null
    }
  ]
}
```

HTTP **400** на этот запрос означает неверный `key` — клиент кидает `CourtMonitorInvalidKeyException`.

Проверено 21.09.2026: ключ транслятор здесь не проверяет — с неверным ключом
возвращает HTTP 200 и `{"cases":[]}`, как для дела, которое не нашлось.

---

## 5. Проверка API-ключа

**Endpoint:** `POST https://parsers.lawmatic.ru/api/v1/urls`  
**Метод клиента:** `checkKey`

Ключ проверяет только парсер. Транслятор на такой запрос при любом ключе
отвечает `{"error":"Не определены/определены некорректно параметры запроса."}`
(проверено 21.09.2026), хотя Swift-клиент когда-то проверял ключ именно
через `https://translator.lawmatic.ru`. Пустой поиск парсер отрабатывает
сразу, не обходя сайты судов.

`body-check-key.json`:

```json
{
  "params": {
    "court_id": ""
  },
  "parser_id": "moscow",
  "key": "ваш_api_ключ"
}
```

```bash
curl -sS -X POST "https://parsers.lawmatic.ru/api/v1/urls" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d @body-check-key.json
```

```bash
curl -sS -X POST "https://parsers.lawmatic.ru/api/v1/urls" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d "{
    \"params\": { \"court_id\": \"\" },
    \"parser_id\": \"moscow\",
    \"key\": \"${PARSER_KEY}\"
  }"
```

**Ключ валиден (HTTP 200):**

```json
{
  "data": {
    "total_urls": 0,
    "total_pages": 0,
    "total_captcha": 0,
    "page": 1,
    "urls": null,
    "cases": null
  },
  "status": "ok"
}
```

**Ключ невалиден (HTTP 400):**

```json
{"error":"Incorrect key","status":"error"}
```

`checkKey` возвращает `true`/`false` только когда сервис ответил про ключ;
сбой сети или другая ошибка сервиса — исключение, а не `false`.

---

## 6. Типовые сценарии (цепочки curl)

### 6.1. Поиск дел по участнику и датам

```bash
# Шаг 1: подсчёт
curl -sS -X POST "https://parsers.lawmatic.ru/api/v1/urls" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d @body-count.json

# Шаг 2: URL по страницам (page = 1 .. total_pages)
curl -sS -X POST "https://parsers.lawmatic.ru/api/v1/urls" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d @body-short-cases-page1.json

# Шаг 3: полные дела по полученным URL (пачками до 5)
curl -sS -X POST "https://translator.lawmatic.ru/" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d @body-full-cases-batch.json
```

---

### 6.2. Дело по прямой ссылке

```bash
# Шаг 1: определить parser_id и court_id
curl -sS -X POST "https://translator.lawmatic.ru/" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d "{\"court_url\":\"${COURT_URL}\"}"

# Шаг 2: полная карточка по URL дела
curl -sS -X POST "https://translator.lawmatic.ru/" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d "{
    \"params\": {
      \"court_id\": \"77RS0001\",
      \"process_type\": \"\",
      \"urls\": [\"${CASE_URL}\"]
    },
    \"parser_id\": \"moscow\",
    \"key\": \"${PARSER_KEY}\"
  }"
```

---

### 6.3. Дело по УИД

```bash
# Шаг 1: получить URL по УИД
curl -sS -X POST "https://parsers.lawmatic.ru/api/v1/urls" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d @body-uid-urls.json

# Шаг 2: полная карточка (подставьте URL из ответа шага 1)
curl -sS -X POST "https://translator.lawmatic.ru/" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d @body-full-case-one.json
```

---

### 6.4. Иерархия судов по УИД (первые 8 символов = код суда)

```bash
# Код суда из УИД, например 77RS0001
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
УИД или код суда. Проверено 21.09.2026: `77RS0021` → `https://mos-gorsud.ru/rs/presnenskij`
→ парсер `moscow`, `court_id` `presnenskij`; `50RS0031` → `https://odintsovo.mo.sudrf.ru`
→ парсер `federal`, `court_id` `odintsovo--mo`.

---

## 7. Справка: поля `params` для `/api/v1/urls`

| Поле | Назначение | Пример |
|------|------------|--------|
| `members` | Участник дела | `"Иванов И.И."` |
| `reg_date_start` | Дата регистрации от (`дд.мм.гггг`) | `"01.01.2024"` |
| `reg_date_stop` | Дата регистрации до | `"31.12.2024"` |
| `process_type` | Тип производства | `"гражданское"` |
| `court_id` | ID суда в парсере | `"77RS0001"` |
| `unique_number` | УИД дела | `"77RS0001-01-2024-..."` |
| `page` | Номер страницы (с 1) | `1` |

> Для Swift-клиента: в API уходят `reg_date_start` / `reg_date_stop` из полей `caseDateFrom` / `caseDateTo` структуры `ParserParams`, а не из `regDateStart` / `regDateStop`.

---

## 8. Адреса по умолчанию

| Назначение | Адрес | Аргумент конструктора (константа) |
|------------|-------|-----------------------------------|
| Список URL / подсчёт / краткие дела, проверка ключа | `https://parsers.lawmatic.ru/api/v1/urls` | `$parserUrl` (`DEFAULT_PARSER_URL`) |
| Парсер по URL суда, полные дела | `https://translator.lawmatic.ru/` | `$translatorUrl` (`DEFAULT_TRANSLATOR_URL`) |
| Каталог судов | `https://courts.lawmatic.ru` | `$courtsUrl` (`DEFAULT_COURTS_URL`) |

Закомментированные альтернативы в `ParserManager.swift`:

- `https://api.allcourts.ru/api/v1/urls`
- `https://api.allcourts.ru/`
- `https://parsers.lawmatic.ru/api/v1/parse`

---

## 9. Windows / PowerShell

Для сложных JSON удобнее сохранить тело в файл и вызвать:

```powershell
curl.exe -sS -X POST "https://parsers.lawmatic.ru/api/v1/urls" `
  -H "Content-Type: application/json; charset=utf-8" `
  -d "@body-count.json"
```

Флаг `-sS` скрывает прогресс и показывает ошибки curl. Для отладки добавьте `-v`.
