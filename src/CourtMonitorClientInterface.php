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
}
