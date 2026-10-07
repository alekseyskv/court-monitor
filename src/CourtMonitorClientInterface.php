<?php

namespace Lawmatic\CourtMonitor;

use Lawmatic\CourtMonitor\Exception\CourtMonitorException;

/**
 * Клиент внешнего API мониторинга судебных дел (CourtMonitor).
 *
 * Приложение зависит от интерфейса, а не от {@see CourtMonitorClient}: так его
 * проще подменять в тестах, и он станет публичным контрактом будущего пакета.
 * Любой метод при сбое кидает {@see CourtMonitorException}.
 *
 * Параметры `$parserId` и `$key` необязательны: без них берутся парсер
 * по умолчанию и ключ, заданные при создании клиента.
 */
interface CourtMonitorClientInterface
{
    /**
     * Поиск суда по названию (`data.items` каталога судов).
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchCourtByName(string $name): array;

    /**
     * Поиск суда по коду (например `77RS0001`). Первые 8 символов УИД дела — это код суда.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchCourtByCode(string $code): array;

    /**
     * Детальная карточка суда по его `id` из ответа поиска (с иерархией).
     *
     * @return array<string, mixed>
     */
    public function getCourtDetail(int|string $courtId): array;

    /**
     * Постраничный поиск по каталогу судов с любыми фильтрами каталога
     * (`court_type`, `name`, `code`, `address`, `parent` — как `{value, match}`,
     * `parent_missing`, `requires_attention`). Пустой фильтр — весь каталог.
     *
     * @param array<string, mixed> $filter
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function searchCourts(array $filter = [], int $limit = 50, int $offset = 0): array;

    /**
     * Типы судов каталога: `{code, name, kbk}`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCourtTypes(): array;

    /**
     * По URL сайта суда возвращает {parser_id, court_id, court_id_can_empty, source}.
     *
     * @return array<string, mixed>
     */
    public function getParserFor(string $courtUrl): array;

    /**
     * Подсчёт дел и страниц по фильтру поиска (`total_urls`, `total_pages`, ...).
     *
     * @param array<string, mixed> $params поля поиска (members, date_from,
     *                                      date_to, process_type, court_id, case_number, inn, ...)
     * @return array<string, mixed>
     */
    public function getTotalCounts(array $params, ?string $parserId = null, ?string $key = null): array;

    /**
     * Краткие карточки дел с одной страницы поиска (`url`, `number`, `extra`).
     *
     * @param array<string, mixed> $params поля поиска без `page`
     * @return array<int, array<string, mixed>>
     */
    public function getShortCasesFromPage(int $page, array $params, ?string $parserId = null, ?string $key = null): array;

    /**
     * Список URL дел с одной страницы поиска.
     *
     * @param array<string, mixed> $params поля поиска без `page`
     * @return array<int, string>
     */
    public function getCasesUrlsFromPage(int $page, array $params, ?string $parserId = null, ?string $key = null): array;

    /**
     * URL дел по УИД. Обычно возвращает один URL.
     *
     * @return array<int, string>
     */
    public function getCaseUrlsForUid(string $uid, string $courtId, ?string $parserId = null, ?string $key = null): array;

    /**
     * Полные карточки дел по списку прямых URL (каноничный формат парсера:
     * `case`, `parties`, `events`, `documents`, ...).
     *
     * @param array<int, string> $urls
     * @return array<int, array<string, mixed>>
     */
    public function getFullCases(array $urls, string $courtId = '', ?string $parserId = null, ?string $key = null): array;

    /**
     * Полная карточка дела по УИД. Код суда берётся из первых 8 символов УИД,
     * если `$courtId` не передан.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFullCaseForUid(string $uid, ?string $courtId = null, ?string $parserId = null, ?string $key = null): array;

    /**
     * Проверяет ключ парсера. Без аргумента — ключ, заданный при создании клиента.
     * true — ключ принят, false — сервис его отверг.
     *
     * @throws CourtMonitorException если сервис недоступен или ответил другой ошибкой —
     *                               тогда о ключе ничего не известно
     */
    public function checkKey(?string $key = null, ?string $parserId = null): bool;

    /**
     * Задание search: вся выдача по суду в фоне (POST /v1/jobs). Параметры — как у
     * поиска (members, court_id, date_from, ...); виды производства — `process_types`
     * (или `process_type`, один). `$options`: queue (monitoring|adhoc), max_pages,
     * max_cases, max_captcha. Нужен ключ клиента (cp_…), не общий ключ сервиса.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     * @return array{job_id: string, status: string, duplicate: bool}
     */
    public function createSearchJob(array $params, ?string $parserId = null, array $options = [], ?string $key = null): array;

    /**
     * Задание cards: карточки по ссылкам (до 500). Парсер и суд — по каждой ссылке.
     *
     * @param array<int, string>   $urls
     * @param array<string, mixed> $options queue, max_cases, max_captcha
     * @return array{job_id: string, status: string, duplicate: bool}
     */
    public function createCardsJob(array $urls, array $options = [], ?string $key = null): array;

    /**
     * Статус и прогресс задания: status (queued|running|done|partial|failed|cancelled),
     * progress, errors, note.
     *
     * @return array<string, mixed>
     */
    public function getJob(string $jobId, ?string $key = null): array;

    /**
     * Результаты задания частью: `items` (seq, url, number, data), `next_after` —
     * курсор следующей части, `complete` — больше ничего не будет.
     *
     * @return array<string, mixed>
     */
    public function getJobResults(string $jobId, int $after = 0, int $limit = 100, ?string $key = null): array;

    /**
     * Все результаты задания (по частям до конца). Для завершённого задания.
     *
     * @return list<array<string, mixed>>
     */
    public function getAllJobResults(string $jobId, ?string $key = null): array;

    /**
     * Отменить задание: новые шаги не берутся, идущие дорабатывают.
     *
     * @return array{job_id: string, status: string}
     */
    public function cancelJob(string $jobId, ?string $key = null): array;
}
