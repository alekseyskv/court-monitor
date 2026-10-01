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
 * Покрывает две внешних поверхности (полный справочник — README.md рядом):
 *  - каталог судов  POST https://courts.lawmatic.ru/api/v1/courts/search
 *  - парсеры дел    https://prsr.lawmatic.ru:
 *      POST /v1/resolve — парсер и код суда по URL сайта суда
 *      POST /v1/urls    — поиск дел (URL, краткие карточки, подсчёт)
 *      POST /v1/parse   — полные карточки дел по URL
 *
 * Парсер принимает ключ в заголовке `x-api-key`, плоское JSON-тело и отвечает
 * конвертом {"status","request_id","parser_id","court_id","cases","search","error"} —
 * метод {@see envelope()} разбирает его и кидает {@see CourtMonitorException}
 * при status=error. Неверный ключ — HTTP 401.
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
    public const DEFAULT_COURTS_URL = 'https://courts.lawmatic.ru';
    public const DEFAULT_PARSER_URL = 'https://prsr.lawmatic.ru';

    /** Сколько URL дел отправлять в парсер за один запрос полных карточек. */
    private const FULL_CASES_BATCH = 5;

    /** Сколько судов отдавать из поиска по каталогу. */
    private const COURTS_SEARCH_LIMIT = 20;

    /**
     * @param string $defaultParserId парсер, если вызывающий код не передал свой
     * @param string $courtsToken     токен каталога судов (заголовок X-Auth-Token)
     * @param string $parserUrl       корень сервиса парсеров
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $key,
        private readonly string $defaultParserId,
        private readonly string $courtsToken = '',
        private readonly string $courtsUrl = self::DEFAULT_COURTS_URL,
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
    // 2. Определение парсера по URL суда — prsr.lawmatic.ru/v1/resolve
    // ---------------------------------------------------------------------

    /**
     * По URL сайта суда возвращает {parser_id, court_id, court_id_can_empty, source}.
     * Ключ не нужен.
     *
     * @return array<string, mixed>
     */
    public function getParserFor(string $courtUrl): array
    {
        return $this->json('POST', $this->parserEndpoint('/v1/resolve'), [
            'json' => ['url' => $courtUrl],
        ]);
    }

    // ---------------------------------------------------------------------
    // 3. Поиск дел — prsr.lawmatic.ru/v1/urls
    // ---------------------------------------------------------------------

    /**
     * Подсчёт дел и страниц по фильтру поиска. Возвращает блок `search`
     * (`total_urls`, `total_pages`, `total_captcha`, `page`, ...).
     *
     * @param array<string, mixed> $params поля поиска (members, date_from, date_to,
     *                                      process_type, court_id, case_number, inn, ...)
     * @return array<string, mixed>
     */
    public function getTotalCounts(array $params, ?string $parserId = null, ?string $key = null): array
    {
        return $this->search($params, $parserId, $key);
    }

    /**
     * Краткие карточки дел с одной страницы (`search.cases`: `url`, `number`, `extra`).
     *
     * @param array<string, mixed> $params поля поиска без `page`
     * @return array<int, array<string, mixed>>
     */
    public function getShortCasesFromPage(int $page, array $params, ?string $parserId = null, ?string $key = null): array
    {
        $search = $this->search(['page' => $page] + $params, $parserId, $key);

        return is_array($search['cases'] ?? null) ? $search['cases'] : [];
    }

    /**
     * Список URL дел с одной страницы (`search.urls`).
     *
     * @param array<string, mixed> $params поля поиска без `page`
     * @return array<int, string>
     */
    public function getCasesUrlsFromPage(int $page, array $params, ?string $parserId = null, ?string $key = null): array
    {
        $search = $this->search(['page' => $page] + $params, $parserId, $key);

        return is_array($search['urls'] ?? null) ? $search['urls'] : [];
    }

    /**
     * URL дел по УИД (`unique_number`). Обычно возвращает один URL.
     *
     * @return array<int, string>
     */
    public function getCaseUrlsForUid(string $uid, string $courtId, ?string $parserId = null, ?string $key = null): array
    {
        $search = $this->search([
            'court_id'      => $courtId,
            'unique_number' => $uid,
        ], $parserId, $key);

        return is_array($search['urls'] ?? null) ? $search['urls'] : [];
    }

    // ---------------------------------------------------------------------
    // 4. Полные карточки дел — prsr.lawmatic.ru/v1/parse
    // ---------------------------------------------------------------------

    /**
     * Полные карточки дел по списку прямых URL. URL автоматически бьются на
     * пачки по {@see FULL_CASES_BATCH}; результаты склеиваются.
     *
     * @param array<int, string> $urls
     * @return array<int, array<string, mixed>> каноничные карточки (`case`, `parties`,
     *                                          `events`, `documents`, ...) из всех пачек
     */
    public function getFullCases(array $urls, string $courtId = '', ?string $parserId = null, ?string $key = null): array
    {
        $urls = array_values(array_filter($urls, static fn($u) => is_string($u) && $u !== ''));
        if ($urls === []) {
            return [];
        }

        $cases = [];
        foreach (array_chunk($urls, self::FULL_CASES_BATCH) as $chunk) {
            $response = $this->envelope('/v1/parse', [
                'court_id' => $courtId,
                'urls'     => $chunk,
            ], $parserId, $key);

            if (is_array($response['cases'] ?? null)) {
                array_push($cases, ...$response['cases']);
            }
        }

        return $cases;
    }

    /**
     * Полная карточка дела по УИД: сперва получает URL через поиск, затем
     * тянет карточку. Код суда берётся из первых 8 символов УИД,
     * если `court_id` не передан явно.
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

        return $this->getFullCases($urls, $courtId, $parserId, $key);
    }

    // ---------------------------------------------------------------------
    // 5. Проверка ключа парсера
    // ---------------------------------------------------------------------

    /**
     * Проверяет валидность ключа парсера. Без аргумента — ключ, заданный при создании клиента.
     *
     * Запрос GET /v1/key/check: сайты судов он не дёргает. Неверный ключ — HTTP 401.
     *
     * @throws CourtMonitorException если сервис недоступен или ответил другой ошибкой —
     *                               тогда о ключе ничего не известно
     */
    public function checkKey(?string $key = null, ?string $parserId = null): bool
    {
        try {
            $this->json('GET', $this->parserEndpoint('/v1/key/check'), [
                'headers' => ['x-api-key' => $key ?? $this->key],
            ]);

            return true;
        } catch (CourtMonitorTransportException $e) {
            if ($e->getCode() === 401) {
                return false;
            }

            throw $e;
        }
    }

    // ---------------------------------------------------------------------
    // Внутренняя кухня
    // ---------------------------------------------------------------------

    /**
     * POST /v1/urls: возвращает блок `search` конверта (пустой массив, если его нет).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function search(array $params, ?string $parserId, ?string $key): array
    {
        $response = $this->envelope('/v1/urls', $params, $parserId, $key);

        return is_array($response['search'] ?? null) ? $response['search'] : [];
    }

    private function parserEndpoint(string $path): string
    {
        return rtrim($this->parserUrl, '/') . $path;
    }

    /**
     * POST плоским телом {parser_id, ...params} с ключом в заголовке `x-api-key`.
     * Разбирает конверт ответа и возвращает его целиком; при status!=ok кидает
     * {@see CourtMonitorInvalidKeyException} на отказ в ключе и
     * {@see CourtMonitorApiErrorException} на остальное.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function envelope(string $path, array $params, ?string $parserId, ?string $key): array
    {
        try {
            $response = $this->json('POST', $this->parserEndpoint($path), [
                'headers' => ['x-api-key' => $key ?? $this->key],
                'json'    => ['parser_id' => $parserId ?? $this->defaultParserId] + $params,
            ]);
        } catch (CourtMonitorTransportException $e) {
            // Неверный ключ — HTTP 401 {"detail":"Неверный ключ"}.
            if ($e->getCode() === 401) {
                throw new CourtMonitorInvalidKeyException($e->getMessage(), 401, $e);
            }

            // Не-2xx ответ мог нести конверт {"status":"error","error":"..."} —
            // отдаём тот же вид ошибки, что и при HTTP 200.
            $body = $this->errorBody($e);
            if (($body['status'] ?? null) === 'error') {
                throw $this->envelopeError($body, $e->getCode(), $e);
            }

            throw $e;
        }

        if (($response['status'] ?? null) !== 'ok') {
            throw $this->envelopeError($response);
        }

        return $response;
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

        if (preg_match('/^\s*(incorrect key|invalid key|unauthorized|неверный ключ)\s*$/iu', $error) === 1) {
            return new CourtMonitorInvalidKeyException('CourtMonitor API error: ' . $error, $code, $previous);
        }

        return new CourtMonitorApiErrorException($error, $code, $previous);
    }

    /**
     * Тело не-2xx ответа, если оно разбирается как JSON-объект.
     *
     * @return array<string, mixed>|null
     */
    private function errorBody(CourtMonitorTransportException $e): ?array
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

        return is_array($body) ? $body : null;
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
