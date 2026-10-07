<?php

declare(strict_types=1);

namespace Lawmatic\CourtMonitor\Tests;

use Lawmatic\CourtMonitor\CourtMonitorClient;
use Lawmatic\CourtMonitor\Exception\CourtMonitorApiErrorException;
use Lawmatic\CourtMonitor\Exception\CourtMonitorException;
use Lawmatic\CourtMonitor\Exception\CourtMonitorInvalidKeyException;
use Lawmatic\CourtMonitor\Exception\CourtMonitorTransportException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Клиент внешнего парсера без сети: запросы уходят в MockHttpClient.
 * Проверяем конверт запроса, разбор ответа и вид ошибки.
 */
final class CourtMonitorClientTest extends TestCase
{
    private const COURTS_URL = 'https://courts.test';
    private const PARSER_URL = 'https://parser.test';

    /** @var list<array{method: string, url: string, headers: list<string>, raw: string, body: mixed}> */
    private array $requests = [];

    public function testSearchSendsFlatBodyWithKeyInHeader(): void
    {
        $client = $this->client([$this->search(['total_urls' => 12, 'total_pages' => 2])]);

        $counts = $client->getTotalCounts(['members' => 'Иванов', 'court_id' => '77RS0001']);

        self::assertSame(['total_urls' => 12, 'total_pages' => 2], $counts);
        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame(self::PARSER_URL . '/v1/urls', $this->requests[0]['url']);
        self::assertContains('x-api-key: secret', $this->requests[0]['headers']);
        self::assertSame([
            'parser_id' => 'moscow',
            'members' => 'Иванов',
            'court_id' => '77RS0001',
        ], $this->requests[0]['body']);
    }

    public function testExplicitParserAndKeyOverrideDefaults(): void
    {
        $client = $this->client([$this->search(['urls' => ['https://case/1']])]);

        $urls = $client->getCasesUrlsFromPage(3, ['members' => 'Иванов'], 'spb_magistrate', 'other-key');

        self::assertSame(['https://case/1'], $urls);
        self::assertContains('x-api-key: other-key', $this->requests[0]['headers']);
        self::assertSame([
            'parser_id' => 'spb_magistrate',
            'page' => 3,
            'members' => 'Иванов',
        ], $this->requests[0]['body']);
    }

    public function testShortCasesComeFromSearchBlock(): void
    {
        $hit = ['url' => 'https://case/1', 'number' => '2-1/2024', 'extra' => []];
        $client = $this->client([$this->search(['cases' => [$hit], 'total_urls' => 1])]);

        self::assertSame([$hit], $client->getShortCasesFromPage(1, ['members' => 'Иванов']));
    }

    public function testMissingSearchBlockBecomesEmptyArray(): void
    {
        $client = $this->client([new MockResponse('{"status":"ok","search":null}')]);

        self::assertSame([], $client->getTotalCounts([]));
    }

    public function testFullCasesAreRequestedInBatchesOfFiveAndMerged(): void
    {
        $urls = ['u1', 'u2', '', 'u3', 'u4', 'u5', 'u6', 'u7'];
        $client = $this->client([
            $this->cases([['url' => 'u1'], ['url' => 'u2']]),
            $this->cases([['url' => 'u6']]),
        ]);

        $cases = $client->getFullCases($urls, 'tverskoy--mos');

        self::assertSame([['url' => 'u1'], ['url' => 'u2'], ['url' => 'u6']], $cases);
        self::assertCount(2, $this->requests);
        self::assertSame(self::PARSER_URL . '/v1/parse', $this->requests[0]['url']);
        self::assertContains('x-api-key: secret', $this->requests[0]['headers']);
        self::assertSame(
            ['parser_id' => 'moscow', 'court_id' => 'tverskoy--mos', 'urls' => ['u1', 'u2', 'u3', 'u4', 'u5']],
            $this->requests[0]['body'],
        );
        self::assertSame(['u6', 'u7'], $this->requests[1]['body']['urls']);
    }

    public function testFullCasesWithoutUrlsDoNotCallService(): void
    {
        $client = $this->client([]);

        self::assertSame([], $client->getFullCases(['', ''], '77RS0001'));
        self::assertSame([], $this->requests);
    }

    public function testFullCaseForUidTakesCourtCodeFromUid(): void
    {
        $client = $this->client([
            $this->search(['urls' => ['https://case/1']]),
            $this->cases([['url' => 'https://case/1']]),
        ]);

        $cases = $client->getFullCaseForUid('77RS0021-02-2026-011276-09');

        self::assertSame([['url' => 'https://case/1']], $cases);
        self::assertSame(self::PARSER_URL . '/v1/urls', $this->requests[0]['url']);
        self::assertSame([
            'parser_id' => 'moscow',
            'court_id' => '77RS0021',
            'unique_number' => '77RS0021-02-2026-011276-09',
        ], $this->requests[0]['body']);
        self::assertSame(self::PARSER_URL . '/v1/parse', $this->requests[1]['url']);
        self::assertSame('77RS0021', $this->requests[1]['body']['court_id']);
        self::assertSame(['https://case/1'], $this->requests[1]['body']['urls']);
    }

    public function testFullCaseForUidStopsWhenSearchFoundNothing(): void
    {
        $client = $this->client([$this->search(['urls' => []])]);

        self::assertSame([], $client->getFullCaseForUid('77RS0021-02-2026-011276-09', '77RS9999'));
        self::assertCount(1, $this->requests);
        self::assertSame('77RS9999', $this->requests[0]['body']['court_id']);
    }

    public function testCourtSearchByCodeAsksCatalogWithToken(): void
    {
        $client = $this->client([new MockResponse('{"items":[{"id":7,"website":"https://sud.test"}],"total":1,"limit":20,"offset":0}')]);

        $courts = $client->searchCourtByCode('77RS0001');

        self::assertSame([['id' => 7, 'website' => 'https://sud.test']], $courts);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame(self::COURTS_URL . '/api/v1/courts/search', $this->requests[0]['url']);
        self::assertContains('x-auth-token: courts-token', $this->requests[0]['headers']);
        self::assertSame([
            'limit' => 20,
            'offset' => 0,
            'code' => ['value' => '77RS0001', 'match' => 'exact'],
        ], $this->requests[0]['body']);
    }

    public function testCourtSearchByNameMatchesPartOfName(): void
    {
        $client = $this->client([new MockResponse('{"items":[],"total":0,"limit":20,"offset":0}')]);

        self::assertSame([], $client->searchCourtByName('Пресненский'));
        self::assertSame(['value' => 'Пресненский', 'match' => 'contains'], $this->requests[0]['body']['name']);
    }

    public function testCourtDetailIsCardWithoutWrapper(): void
    {
        $client = $this->client([new MockResponse('{"id":9912,"code":"77RS0001","hierarchy":[{"code":"77OS0000"}]}')]);

        $card = $client->getCourtDetail(9912);

        self::assertSame(['id' => 9912, 'code' => '77RS0001', 'hierarchy' => [['code' => '77OS0000']]], $card);
        self::assertSame('GET', $this->requests[0]['method']);
        self::assertSame(self::COURTS_URL . '/api/v1/courts/9912', $this->requests[0]['url']);
        self::assertContains('x-auth-token: courts-token', $this->requests[0]['headers']);
    }

    public function testCourtSearchPassesFilterAndPagination(): void
    {
        $client = $this->client([new MockResponse('{"items":[{"id":7}],"total":131,"limit":50,"offset":100}')]);

        $page = $client->searchCourts(['court_type' => 'RS', 'name' => ['value' => 'Пресн', 'match' => 'contains']], 50, 100);

        self::assertSame(['items' => [['id' => 7]], 'total' => 131, 'limit' => 50, 'offset' => 100], $page);
        self::assertSame(self::COURTS_URL . '/api/v1/courts/search', $this->requests[0]['url']);
        self::assertContains('x-auth-token: courts-token', $this->requests[0]['headers']);
        self::assertSame([
            'limit' => 50,
            'offset' => 100,
            'court_type' => 'RS',
            'name' => ['value' => 'Пресн', 'match' => 'contains'],
        ], $this->requests[0]['body']);
    }

    public function testCourtSearchWithoutFilterAsksWholeCatalog(): void
    {
        $client = $this->client([new MockResponse('{}')]);

        self::assertSame(['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0], $client->searchCourts());
        self::assertSame(['limit' => 50, 'offset' => 0], $this->requests[0]['body']);
    }

    public function testCourtTypesAreListFromCatalog(): void
    {
        $client = $this->client([new MockResponse('[{"code":"RS","name":"Районный суд","kbk":"18210803010011050110"}]')]);

        self::assertSame([['code' => 'RS', 'name' => 'Районный суд', 'kbk' => '18210803010011050110']], $client->getCourtTypes());
        self::assertSame('GET', $this->requests[0]['method']);
        self::assertSame(self::COURTS_URL . '/api/v1/court-types', $this->requests[0]['url']);
        self::assertContains('x-auth-token: courts-token', $this->requests[0]['headers']);
    }

    public function testCourtCatalogWithoutTokenSendsNoAuthHeader(): void
    {
        $client = $this->client([new MockResponse('{"error":"missing X-Data or X-Auth-Token"}', ['http_code' => 400])], courtsToken: '');

        try {
            $client->searchCourtByCode('77RS0001');
            self::fail('Ожидалась транспортная ошибка');
        } catch (CourtMonitorTransportException $e) {
            self::assertSame(400, $e->getCode());
        }
        self::assertSame([], preg_grep('/^x-auth-token:/i', $this->requests[0]['headers']));
    }

    public function testParserForCourtUrlAsksResolveWithoutKey(): void
    {
        $client = $this->client([new MockResponse('{"parser_id":"moscow","court_id":"presnenskij","court_id_can_empty":true,"source":"mos-gorsud"}')]);

        $info = $client->getParserFor('https://mos-gorsud.ru/rs/presnenskij');

        self::assertSame('moscow', $info['parser_id']);
        self::assertSame('presnenskij', $info['court_id']);
        self::assertSame(self::PARSER_URL . '/v1/resolve', $this->requests[0]['url']);
        self::assertSame(['url' => 'https://mos-gorsud.ru/rs/presnenskij'], $this->requests[0]['body']);
        self::assertSame([], preg_grep('/^x-api-key:/i', $this->requests[0]['headers']));
    }

    public function testHttp401IsInvalidKey(): void
    {
        $client = $this->client([new MockResponse('{"detail":"Неверный ключ"}', ['http_code' => 401])]);

        try {
            $client->getTotalCounts([]);
            self::fail('Ожидалась ошибка ключа');
        } catch (CourtMonitorInvalidKeyException $e) {
            self::assertSame(401, $e->getCode());
            self::assertStringStartsWith('CourtMonitor request to ' . self::PARSER_URL . '/v1/urls failed:', $e->getMessage());
            self::assertInstanceOf(CourtMonitorTransportException::class, $e->getPrevious());
        }
    }

    public function testHttp401OnFullCasesIsInvalidKey(): void
    {
        $client = $this->client([new MockResponse('{"detail":"Неверный ключ"}', ['http_code' => 401])]);

        $this->expectException(CourtMonitorInvalidKeyException::class);

        $client->getFullCases(['u1'], '77RS0001');
    }

    /**
     * @dataProvider invalidKeyErrors
     */
    public function testRejectedKeyInEnvelopeIsInvalidKey(string $error): void
    {
        $client = $this->client([$this->error($error)]);

        try {
            $client->getTotalCounts([]);
            self::fail('Ожидалась ошибка ключа');
        } catch (CourtMonitorInvalidKeyException $e) {
            self::assertSame('CourtMonitor API error: ' . $error, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeyErrors(): iterable
    {
        yield 'неверный ключ' => ['Неверный ключ'];
        yield 'invalid key' => ['Invalid key'];
        yield 'unauthorized' => ['Unauthorized'];
        yield 'incorrect key' => ['Incorrect key'];
    }

    public function testOtherEnvelopeErrorKeepsServiceText(): void
    {
        $client = $this->client([$this->error('Captcha required')]);

        try {
            $client->getTotalCounts([]);
            self::fail('Ожидалась ошибка сервиса');
        } catch (CourtMonitorApiErrorException $e) {
            self::assertSame('Captcha required', $e->getApiError());
            self::assertSame('CourtMonitor API error: Captcha required', $e->getMessage());
        }
    }

    public function testEnvelopeWithoutErrorTextIsUnknownError(): void
    {
        $client = $this->client([new MockResponse('{"status":"error"}')]);

        $this->expectException(CourtMonitorApiErrorException::class);
        $this->expectExceptionMessage('CourtMonitor API error: unknown error');

        $client->getTotalCounts([]);
    }

    public function testValidationErrorIsTransportError(): void
    {
        $client = $this->client([new MockResponse('{"detail":[{"loc":["body","parser_id"],"msg":"Field required"}]}', ['http_code' => 422])]);

        try {
            $client->getTotalCounts([]);
            self::fail('Ожидалась транспортная ошибка');
        } catch (CourtMonitorTransportException $e) {
            self::assertSame(422, $e->getCode());
        }
    }

    /**
     * @dataProvider unreadableResponses
     */
    public function testUnreadableResponseIsTransportErrorWithoutStatus(MockResponse $response): void
    {
        $client = $this->client([$response]);

        try {
            $client->getTotalCounts([]);
            self::fail('Ожидалась транспортная ошибка');
        } catch (CourtMonitorTransportException $e) {
            self::assertSame(0, $e->getCode());
            self::assertStringStartsWith('CourtMonitor request to ' . self::PARSER_URL . '/v1/urls failed:', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function unreadableResponses(): iterable
    {
        yield 'сеть или таймаут' => [new MockResponse('', ['error' => 'Idle timeout reached'])];
        yield 'битый JSON' => [new MockResponse('{oops')];
    }

    public function testHttpErrorWithEnvelopeIsApiError(): void
    {
        $client = $this->client([new MockResponse('{"status":"error","error":"Parser is busy"}', ['http_code' => 503])]);

        try {
            $client->getTotalCounts([]);
            self::fail('Ожидалась ошибка сервиса');
        } catch (CourtMonitorApiErrorException $e) {
            self::assertSame('Parser is busy', $e->getApiError());
            self::assertSame(503, $e->getCode());
        }
    }

    public function testHttpErrorWithoutEnvelopeIsTransportError(): void
    {
        $client = $this->client([new MockResponse('Bad gateway', ['http_code' => 502])]);

        try {
            $client->getTotalCounts([]);
            self::fail('Ожидалась транспортная ошибка');
        } catch (CourtMonitorTransportException $e) {
            self::assertSame(502, $e->getCode());
        }
    }

    public function testCheckKeyAsksKeyCheckEndpoint(): void
    {
        $client = $this->client([new MockResponse('{"status":"ok","auth_required":true}')]);

        self::assertTrue($client->checkKey('good'));
        self::assertSame('GET', $this->requests[0]['method']);
        self::assertSame(self::PARSER_URL . '/v1/key/check', $this->requests[0]['url']);
        self::assertContains('x-api-key: good', $this->requests[0]['headers']);
    }

    public function testCheckKeyReturnsFalseWhenKeyRejected(): void
    {
        $rejected = new MockResponse('{"detail":"Неверный ключ"}', ['http_code' => 401]);

        self::assertFalse($this->client([$rejected])->checkKey('bad'));
    }

    public function testCheckKeyDoesNotHideOutage(): void
    {
        $client = $this->client([new MockResponse('', ['error' => 'Could not resolve host'])]);

        $this->expectException(CourtMonitorTransportException::class);

        $client->checkKey('any');
    }

    public function testEveryErrorIsCourtMonitorException(): void
    {
        foreach ([CourtMonitorTransportException::class, CourtMonitorApiErrorException::class, CourtMonitorInvalidKeyException::class] as $class) {
            self::assertTrue(is_subclass_of($class, CourtMonitorException::class), $class);
        }
    }

    // --- задания /v1/jobs ---

    public function testCreateSearchJobSendsFlatSearchFieldsAndOptions(): void
    {
        $client = $this->client([$this->json(['job_id' => 'j1', 'status' => 'queued', 'duplicate' => false])]);

        $job = $client->createSearchJob(
            ['members' => 'Иванов', 'court_id' => 'odintsovo--mo', 'date_from' => '', 'process_type' => 'criminal'],
            'federal',
            ['max_pages' => 20, 'queue' => 'monitoring', 'unknown' => 'x', 'max_cases' => null],
        );

        self::assertSame(['job_id' => 'j1', 'status' => 'queued', 'duplicate' => false], $job);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame(self::PARSER_URL . '/v1/jobs', $this->requests[0]['url']);
        self::assertContains('x-api-key: secret', $this->requests[0]['headers']);
        // пустые поля и незнакомые опции не уходят; один process_type — список process_types
        // (порядок полей JSON не важен)
        self::assertEquals([
            'type' => 'search',
            'parser_id' => 'federal',
            'members' => 'Иванов',
            'court_id' => 'odintsovo--mo',
            'process_types' => ['criminal'],
            'queue' => 'monitoring',
            'max_pages' => 20,
        ], $this->requests[0]['body']);
    }

    public function testCreateSearchJobDefaultsParserAndAllProcessTypes(): void
    {
        $client = $this->client([$this->json(['job_id' => 'j1', 'status' => 'queued', 'duplicate' => true])]);

        $client->createSearchJob(['members' => 'Иванов', 'process_types' => ['civil', 'criminal']]);

        self::assertSame('moscow', $this->requests[0]['body']['parser_id']);
        self::assertSame(['civil', 'criminal'], $this->requests[0]['body']['process_types']);
    }

    public function testCreateCardsJob(): void
    {
        $client = $this->client([$this->json(['job_id' => 'j2', 'status' => 'queued', 'duplicate' => false])]);

        $client->createCardsJob(['https://a', 'https://b'], ['queue' => 'adhoc'], 'cp_other');

        self::assertSame(['type' => 'cards', 'urls' => ['https://a', 'https://b'], 'queue' => 'adhoc'], $this->requests[0]['body']);
        self::assertContains('x-api-key: cp_other', $this->requests[0]['headers']);
    }

    public function testGetJobResultsAndCancel(): void
    {
        $client = $this->client([
            $this->json(['job_id' => 'j1', 'status' => 'done', 'progress' => ['results' => 2]]),
            $this->json(['items' => [['seq' => 7, 'url' => 'https://a']], 'next_after' => 7, 'complete' => true]),
            $this->json(['job_id' => 'j1', 'status' => 'cancelled']),
        ]);

        self::assertSame('done', $client->getJob('j1')['status']);
        self::assertSame(7, $client->getJobResults('j1', 5, 50)['next_after']);
        self::assertSame('cancelled', $client->cancelJob('j1')['status']);

        self::assertSame(['GET', self::PARSER_URL . '/v1/jobs/j1'], [$this->requests[0]['method'], $this->requests[0]['url']]);
        self::assertSame(self::PARSER_URL . '/v1/jobs/j1/results?after=5&limit=50', $this->requests[1]['url']);
        self::assertSame(['DELETE', self::PARSER_URL . '/v1/jobs/j1'], [$this->requests[2]['method'], $this->requests[2]['url']]);
    }

    public function testGetAllJobResultsWalksPagesUntilShortOne(): void
    {
        $full = array_map(static fn (int $n) => ['seq' => $n, 'url' => "https://c/$n"], range(1, 500));
        $client = $this->client([
            $this->json(['items' => $full, 'next_after' => 500, 'complete' => false]),
            $this->json(['items' => [['seq' => 501, 'url' => 'https://c/501']], 'next_after' => 501, 'complete' => true]),
        ]);

        $items = $client->getAllJobResults('j1');

        self::assertCount(501, $items);
        self::assertSame(self::PARSER_URL . '/v1/jobs/j1/results?after=500&limit=500', $this->requests[1]['url']);
    }

    /**
     * @return iterable<string, array{int, string, class-string, string}>
     */
    public static function jobErrors(): iterable
    {
        yield 'неверный ключ' => [401, '{"detail":"Неверный ключ"}', CourtMonitorInvalidKeyException::class, ''];
        yield 'общий ключ вместо ключа клиента' => [403, '{"detail":"Задания — только по ключу клиента (cp_…)"}', CourtMonitorInvalidKeyException::class, ''];
        yield 'задания нет' => [404, '{"detail":"Задания нет"}', CourtMonitorApiErrorException::class, 'Задания нет'];
        yield 'неизвестный сайт' => [400, '{"detail":{"error":"Неизвестный сайт суда","urls":["https://x"]}}', CourtMonitorApiErrorException::class, '{"error":"Неизвестный сайт суда","urls":["https://x"]}'];
        yield 'база недоступна' => [503, '{"detail":"Задания недоступны: база не настроена"}', CourtMonitorApiErrorException::class, 'Задания недоступны: база не настроена'];
        yield 'прокси без тела' => [502, 'Bad Gateway', CourtMonitorTransportException::class, ''];
    }

    /**
     * @dataProvider jobErrors
     * @param class-string $class
     */
    public function testJobErrors(int $code, string $body, string $class, string $apiError): void
    {
        $client = $this->client([new MockResponse($body, ['http_code' => $code])]);

        try {
            $client->getJob('j1');
            self::fail('ожидалось исключение');
        } catch (CourtMonitorException $e) {
            self::assertInstanceOf($class, $e);
            self::assertSame($code, $e->getCode());
            if ($e instanceof CourtMonitorApiErrorException) {
                self::assertSame($apiError, $e->getApiError());
            }
        }
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses, string $courtsToken = 'courts-token'): CourtMonitorClient
    {
        $this->requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $raw = (string) ($options['body'] ?? '');
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => array_map('strtolower', $options['headers'] ?? []),
                'raw' => $raw,
                'body' => $raw === '' ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR),
            ];

            self::assertNotEmpty($responses, 'Лишний запрос к сервису: ' . $url);

            return array_shift($responses);
        });

        return new CourtMonitorClient(
            $http,
            'secret',
            'moscow',
            $courtsToken,
            self::COURTS_URL,
            self::PARSER_URL,
        );
    }

    /**
     * @param array<string, mixed> $search
     */
    private function search(array $search): MockResponse
    {
        return new MockResponse(json_encode(
            ['status' => 'ok', 'request_id' => 'r1', 'cases' => [], 'search' => $search, 'error' => null],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param list<array<string, mixed>> $cases
     */
    private function cases(array $cases): MockResponse
    {
        return new MockResponse(json_encode(
            ['status' => 'ok', 'request_id' => 'r1', 'cases' => $cases, 'search' => null, 'error' => null],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function error(string $error): MockResponse
    {
        return new MockResponse(json_encode(
            ['status' => 'error', 'error' => $error],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }
}
