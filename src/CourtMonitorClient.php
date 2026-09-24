<?php

namespace Lawmatic\CourtMonitor;

use Lawmatic\CourtMonitor\Exception\CourtMonitorApiErrorException;
use Lawmatic\CourtMonitor\Exception\CourtMonitorException;
use Lawmatic\CourtMonitor\Exception\CourtMonitorInvalidKeyException;
use Lawmatic\CourtMonitor\Exception\CourtMonitorTransportException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Клиент внешнего API мониторинга судебных дел (CourtMonitor).
 *
 * Покрывает три внешних поверхности (полный справочник — README.md рядом):
 *  - каталог судов        POST https://courts.lawmatic.ru/api/v1/courts/search
 *  - определение парсера   POST https://translator.lawmatic.ru/
 *  - парсер дел (URL/счёт) POST https://parsers.lawmatic.ru/api/v1/urls
 *  - полные карточки дел   POST https://translator.lawmatic.ru/
 *
 * Запросы к парсеру/транслятору идут конвертом
 *   {"params": {...}, "parser_id": "...", "key": "..."}
 * и отвечают конвертом {"status","error","data"} — метод {@see unwrap()}
 * разворачивает его и кидает {@see CourtMonitorException} при status=error.
 *
 * Пакет не знает о приложении и о DI-контейнере: без атрибутов фреймворка,
 * всё нужное приходит через конструктор.
 * Каталог судов требует свой токен (X-Auth-Token), отдельный от ключа парсера.
 *
 * Ключ, токен, адреса и парсер по умолчанию приходят через конструктор. Каждый публичный метод позволяет передать
 * ключ явно — это нужно для проверки чужого ключа через {@see checkKey()}.
 */
class CourtMonitorClient implements CourtMonitorClientInterface
{
    public const DEFAULT_COURTS_URL     = 'https://courts.lawmatic.ru';
    public const DEFAULT_TRANSLATOR_URL = 'https://translator.lawmatic.ru/';
    public const DEFAULT_PARSER_URL     = 'https://parsers.lawmatic.ru/api/v1/urls';

    /** В коде парсера пакет полных карточек ограничен 5 URL за запрос. */
    private const FULL_CASES_BATCH = 5;

    /** Сколько судов отдавать из поиска по каталогу. */
    private const COURTS_SEARCH_LIMIT = 20;

    /**
     * @param string $defaultParserId парсер, если вызывающий код не передал свой
     * @param string $courtsToken     токен каталога судов (заголовок X-Auth-Token)
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $key,
        private readonly string $defaultParserId,
        private readonly string $courtsToken = '',
        private readonly string $courtsUrl = self::DEFAULT_COURTS_URL,
        private readonly string $translatorUrl = self::DEFAULT_TRANSLATOR_URL,
        private readonly string $parserUrl = self::DEFAULT_PARSER_URL,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    // ---------------------------------------------------------------------
    // 1. Каталог судов — courts.lawmatic.ru
    // ---------------------------------------------------------------------

    /**
     * Поиск суда по части названия. Возвращает найденные суды (`items`),
     * у каждого — иерархия вышестоящих судов.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchCourtByName(string $name): array
    {
        return $this->courtItems(['name' => ['value' => $name, 'match' => 'contains']]);
    }

    /**
     * Поиск суда по коду (например `77RS0001`). Первые 8 символов УИД дела — это код суда
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchCourtByCode(string $code): array
    {
        return $this->courtItems(['code' => ['value' => $code, 'match' => 'exact']]);
    }

    /**
     * Детальная карточка суда по его `id` из ответа поиска (с иерархией).
     * Суда с таким `id` нет — HTTP 404, {@see CourtMonitorTransportException}.
     *
     * @return array<string, mixed>
     */
    public function getCourtDetail(int|string $courtId): array
    {
        return $this->json('GET', $this->courtsUrl . '/api/v1/courts/' . rawurlencode((string) $courtId), [
            'headers' => $this->courtsHeaders(),
        ]);
    }

    /**
     * Постраничный поиск по каталогу. Фильтры уходят в каталог как есть, условия
     * объединяются по «И». Каталог сам ограничивает `limit` (не больше 500),
     * а на неверный фильтр отвечает HTTP 400 — {@see CourtMonitorTransportException}.
     *
     * @param array<string, mixed> $filter
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function searchCourts(array $filter = [], int $limit = 50, int $offset = 0): array
    {
        $response = $this->json('POST', $this->courtsUrl . '/api/v1/courts/search', [
            'headers' => $this->courtsHeaders(),
            'json'    => ['limit' => $limit, 'offset' => $offset] + $filter,
        ]);

        return [
            'items'  => is_array($response['items'] ?? null) ? array_values($response['items']) : [],
            'total'  => (int) ($response['total'] ?? 0),
            'limit'  => (int) ($response['limit'] ?? $limit),
            'offset' => (int) ($response['offset'] ?? $offset),
        ];
    }

    /**
     * Типы судов каталога `{code, name, kbk}` — для фильтра по типу.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCourtTypes(): array
    {
        return array_values($this->json('GET', $this->courtsUrl . '/api/v1/court-types', [
            'headers' => $this->courtsHeaders(),
        ]));
    }

    /**
     * @param array<string, array{value: string, match: string}> $filter условия поиска
     * @return array<int, array<string, mixed>>
     */
    private function courtItems(array $filter): array
    {
        return $this->searchCourts($filter, self::COURTS_SEARCH_LIMIT)['items'];
    }

    /**
     * Без токена каталог отвечает HTTP 400 «missing X-Data or X-Auth-Token».
     *
     * @return array<string, string>
     */
    private function courtsHeaders(): array
    {
        return $this->courtsToken !== '' ? ['X-Auth-Token' => $this->courtsToken] : [];
    }

    // ---------------------------------------------------------------------
    // 2. Определение парсера по URL суда — translator.lawmatic.ru
    // ---------------------------------------------------------------------

    /**
     * По URL сайта суда возвращает {parser_id, court_id, court_id_can_empty}.
     *
     * @return array<string, mixed>
     */
    public function getParserFor(string $courtUrl): array
    {
        return $this->json('POST', $this->translatorUrl, [
            'json' => ['court_url' => $courtUrl],
        ]);
    }

    // ---------------------------------------------------------------------
    // 3. Парсер дел — parsers.lawmatic.ru/api/v1/urls
    // ---------------------------------------------------------------------

    /**
     * Подсчёт дел и страниц по фильтру поиска. Возвращает `data`
     * (`total_urls`, `total_pages`, `total_captcha`, ...).
     *
     * @param array<string, mixed> $params поля поиска (members, reg_date_start,
     *                                      reg_date_stop, process_type, court_id, ...)
     * @return array<string, mixed>
     */
    public function getTotalCounts(array $params, ?string $parserId = null, ?string $key = null): array
    {
        return $this->parserRequest($this->parserUrl, $params, $parserId, $key);
    }

    /**
     * Краткие карточки дел с одной страницы (`data.cases`).
     *
     * @param array<string, mixed> $params поля поиска без `page`
     * @return array<int, array<string, mixed>>
     */
    public function getShortCasesFromPage(int $page, array $params, ?string $parserId = null, ?string $key = null): array
    {
        $data = $this->parserRequest($this->parserUrl, ['page' => $page] + $params, $parserId, $key);

        return is_array($data['cases'] ?? null) ? $data['cases'] : [];
    }

    /**
     * Список URL дел с одной страницы (`data.urls`).
     *
     * @param array<string, mixed> $params поля поиска без `page`
     * @return array<int, string>
     */
    public function getCasesUrlsFromPage(int $page, array $params, ?string $parserId = null, ?string $key = null): array
    {
        $data = $this->parserRequest($this->parserUrl, ['page' => $page] + $params, $parserId, $key);

        return is_array($data['urls'] ?? null) ? $data['urls'] : [];
    }

    /**
     * URL дел по УИД (`unique_number`). Обычно возвращает один URL.
     *
     * @return array<int, string>
     */
    public function getCaseUrlsForUid(string $uid, string $courtId, ?string $parserId = null, ?string $key = null): array
    {
        $data = $this->parserRequest($this->parserUrl, [
            'court_id'      => $courtId,
            'process_type'  => '',
            'unique_number' => $uid,
        ], $parserId, $key);

        return is_array($data['urls'] ?? null) ? $data['urls'] : [];
    }

    // ---------------------------------------------------------------------
    // 4. Полные карточки дел — translator.lawmatic.ru
    // ---------------------------------------------------------------------

    /**
     * Полные карточки дел по списку прямых URL. URL автоматически бьются на
     * пачки по {@see FULL_CASES_BATCH}; результаты склеиваются.
     *
     * @param array<int, string> $urls
     * @return array<int, array<string, mixed>> элементы `cases` из всех пачек
     */
    public function getFullCases(array $urls, string $courtId, string $processType = '', ?string $parserId = null, ?string $key = null): array
    {
        $urls = array_values(array_filter($urls, static fn($u) => is_string($u) && $u !== ''));
        if ($urls === []) {
            return [];
        }

        $cases = [];
        foreach (array_chunk($urls, self::FULL_CASES_BATCH) as $chunk) {
            $response = $this->postEnvelope($this->translatorUrl, [
                'court_id'     => $courtId,
                'process_type' => $processType,
                'urls'         => $chunk,
            ], $parserId, $key, fullCardResponse: true);

            if (is_array($response['cases'] ?? null)) {
                array_push($cases, ...$response['cases']);
            }
        }

        return $cases;
    }

    /**
     * Полная карточка дела по УИД: сперва получает URL через парсер, затем
     * тянет карточку через транслятор. Код суда берётся из первых 8 символов
     * УИД, если `court_id` не передан явно.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFullCaseForUid(string $uid, ?string $courtId = null, ?string $parserId = null, ?string $key = null): array
    {
        $courtId ??= substr($uid, 0, 8);

        $urls = $this->getCaseUrlsForUid($uid, $courtId, $parserId, $key);
        if ($urls === []) {
            return [];
        }

        return $this->getFullCases($urls, $courtId, '', $parserId, $key);
    }

    // ---------------------------------------------------------------------
    // 5. Проверка ключа парсера — parsers.lawmatic.ru
    // ---------------------------------------------------------------------

    /**
     * Проверяет валидность ключа парсера. Без аргумента — ключ, заданный при создании клиента.
     *
     * Ключ проверяет только парсер: транслятор на пустой запрос отвечает
     * ошибкой параметров при любом ключе. Пустой поиск парсер отрабатывает
     * сразу, не обходя сайты судов.
     *
     * @throws CourtMonitorException если сервис недоступен или ответил другой ошибкой —
     *                               тогда о ключе ничего не известно
     */
    public function checkKey(?string $key = null, ?string $parserId = null): bool
    {
        try {
            $this->postEnvelope($this->parserUrl, ['court_id' => ''], $parserId, $key);

            return true;
        } catch (CourtMonitorInvalidKeyException) {
            return false;
        }
    }

    // ---------------------------------------------------------------------
    // Внутренняя кухня
    // ---------------------------------------------------------------------

    /**
     * Запрос к парсеру/транслятору конвертом {params, parser_id, key} с разбором
     * ответа {status, error, data}. Возвращает содержимое `data`.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function parserRequest(string $url, array $params, ?string $parserId, ?string $key): array
    {
        return $this->postEnvelope($url, $params, $parserId, $key);
    }

    /**
     * POST конвертом {params, parser_id, key}.
     *
     * @param array<string, mixed> $params
     * @param bool $fullCardResponse у транслятора полные карточки лежат прямо в
     *                               корне (`{"cases":[...]}`) — без обёртки
     *                               {status, error, data}; HTTP 400 = неверный ключ.
     * @return array<string, mixed>
     */
    private function postEnvelope(string $url, array $params, ?string $parserId, ?string $key, bool $fullCardResponse = false): array
    {
        $body = [
            'params'    => (object) $params,
            'parser_id' => $parserId ?? $this->defaultParserId,
            'key'       => $key ?? $this->key,
        ];

        if ($fullCardResponse) {
            // Транслятор отдаёт {"cases":[...]} напрямую; 400 трактуем как неверный ключ.
            try {
                return $this->json('POST', $url, ['json' => $body]);
            } catch (CourtMonitorTransportException $e) {
                if ($e->getCode() === 400) {
                    throw new CourtMonitorInvalidKeyException($e->getMessage(), 400, $e);
                }

                throw $e;
            }
        }

        try {
            return $this->unwrap($this->json('POST', $url, ['json' => $body]));
        } catch (CourtMonitorTransportException $e) {
            // Парсер отвечает на неверный ключ HTTP 400 с конвертом
            // {"status":"error","error":"Incorrect key"} — разбираем его, чтобы
            // вызывающий код получил тот же вид ошибки, что и при HTTP 200.
            $envelope = $this->errorEnvelope($e);
            if ($envelope !== null) {
                throw $this->envelopeError($envelope, $e->getCode(), $e);
            }

            throw $e;
        }
    }

    /**
     * Разворачивает конверт {status, error, data}: при status!=ok кидает
     * {@see CourtMonitorInvalidKeyException} на отказ в ключе и
     * {@see CourtMonitorApiErrorException} на остальное, иначе возвращает
     * `data` (или [] для null).
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function unwrap(array $response): array
    {
        if (($response['status'] ?? null) !== 'ok') {
            throw $this->envelopeError($response);
        }

        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    /**
     * Ошибка по конверту {status: error, error: "..."}: отказ в ключе —
     * {@see CourtMonitorInvalidKeyException}, остальное — {@see CourtMonitorApiErrorException}.
     *
     * @param array<string, mixed> $response
     */
    private function envelopeError(array $response, int $code = 0, ?\Throwable $previous = null): CourtMonitorException
    {
        $error = is_string($response['error'] ?? null) ? $response['error'] : 'unknown error';

        // Тексты отказа в ключе: «Incorrect key» — фактический ответ парсера,
        // «Invalid key» и «Unauthorized» — из README.md (§3.1, §5).
        if (preg_match('/^\s*(incorrect key|invalid key|unauthorized)\s*$/i', $error) === 1) {
            return new CourtMonitorInvalidKeyException('CourtMonitor API error: ' . $error, $code, $previous);
        }

        return new CourtMonitorApiErrorException($error, $code, $previous);
    }

    /**
     * Конверт {status: error} из тела не-2xx ответа, если он там есть.
     *
     * @return array<string, mixed>|null
     */
    private function errorEnvelope(CourtMonitorTransportException $e): ?array
    {
        $previous = $e->getPrevious();
        if (!$previous instanceof HttpExceptionInterface) {
            return null;
        }

        try {
            $body = json_decode($previous->getResponse()->getContent(false), true);
        } catch (HttpClientExceptionInterface) {
            return null;
        }

        return is_array($body) && ($body['status'] ?? null) === 'error' ? $body : null;
    }

    /**
     * Выполняет HTTP-запрос и декодирует JSON-тело в массив. Любая ошибка
     * транспорта, не-2xx статус или битый JSON превращаются в
     * {@see CourtMonitorTransportException} с HTTP-статусом в коде.
     *
     * @param array<string, mixed> $options опции Symfony HttpClient (json/query)
     * @return array<string, mixed>
     */
    private function json(string $method, string $url, array $options = []): array
    {
        try {
            $response = $this->httpClient->request($method, $url, $options);

            return $response->toArray();
        } catch (HttpClientExceptionInterface $e) {
            $this->logger?->error('CourtMonitor request failed', [
                'method'  => $method,
                'url'     => $url,
                'message' => $e->getMessage(),
            ]);

            throw new CourtMonitorTransportException(
                sprintf('CourtMonitor request to %s failed: %s', $url, $e->getMessage()),
                $e instanceof HttpExceptionInterface ? $e->getResponse()->getStatusCode() : 0,
                $e,
            );
        }
    }
}
