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
    private const TRANSLATOR_URL = 'https://translator.test/';
    private const PARSER_URL = 'https://parser.test/urls';

    /** @var list<array{method: string, url: string, headers: list<string>, raw: string, body: mixed}> */
    private array $requests = [];

    public function testParserRequestSendsEnvelopeWithDefaultParserAndKey(): void
    {
        $client = $this->client([$this->ok(['total_urls' => 12, 'total_pages' => 2])]);

        $counts = $client->getTotalCounts(['members' => 'Иванов', 'court_id' => '77RS0001']);

        self::assertSame(['total_urls' => 12, 'total_pages' => 2], $counts);
        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame(self::PARSER_URL, $this->requests[0]['url']);
        self::assertSame([
            'params' => ['members' => 'Иванов', 'court_id' => '77RS0001'],
            'parser_id' => 'moscow',
            'key' => 'secret',
        ], $this->requests[0]['body']);
    }

    public function testExplicitParserAndKeyOverrideDefaults(): void
    {
        $client = $this->client([$this->ok(['urls' => ['https://case/1']])]);

        $urls = $client->getCasesUrlsFromPage(3, ['members' => 'Иванов'], 'spb', 'other-key');

        self::assertSame(['https://case/1'], $urls);
        self::assertSame([
            'params' => ['page' => 3, 'members' => 'Иванов'],
            'parser_id' => 'spb',
            'key' => 'other-key',
        ], $this->requests[0]['body']);
    }

    public function testEmptyParamsAreSentAsJsonObject(): void
    {
        $client = $this->client([$this->ok([])]);

        $client->getTotalCounts([]);

        self::assertStringContainsString('"params":{}', $this->requests[0]['raw']);
    }

    public function testNullDataBecomesEmptyArray(): void
    {
        $client = $this->client([new MockResponse('{"status":"ok","error":null,"data":null}')]);

        self::assertSame([], $client->getTotalCounts([]));
    }

    public function testFullCasesAreRequestedInBatchesOfFiveAndMerged(): void
    {
        $urls = ['u1', 'u2', '', 'u3', 'u4', 'u5', 'u6', 'u7'];
        $client = $this->client([
            new MockResponse('{"cases":[{"n":1},{"n":2}]}'),
            new MockResponse('{"cases":[{"n":3}]}'),
        ]);

        $cases = $client->getFullCases($urls, '77RS0001', 'civil');

        self::assertSame([['n' => 1], ['n' => 2], ['n' => 3]], $cases);
        self::assertCount(2, $this->requests);
        self::assertSame(self::TRANSLATOR_URL, $this->requests[0]['url']);
        self::assertSame(
            ['court_id' => '77RS0001', 'process_type' => 'civil', 'urls' => ['u1', 'u2', 'u3', 'u4', 'u5']],
            $this->requests[0]['body']['params'],
        );
        self::assertSame(['u6', 'u7'], $this->requests[1]['body']['params']['urls']);
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
            $this->ok(['urls' => ['https://case/1']]),
            new MockResponse('{"cases":[{"n":1}]}'),
        ]);

        $cases = $client->getFullCaseForUid('77RS0021-02-2026-011276-09');

        self::assertSame([['n' => 1]], $cases);
        self::assertSame(self::PARSER_URL, $this->requests[0]['url']);
        self::assertSame([
            'court_id' => '77RS0021',
            'process_type' => '',
            'unique_number' => '77RS0021-02-2026-011276-09',
        ], $this->requests[0]['body']['params']);
        self::assertSame(self::TRANSLATOR_URL, $this->requests[1]['url']);
        self::assertSame('77RS0021', $this->requests[1]['body']['params']['court_id']);
    }

    public function testFullCaseForUidStopsWhenParserFoundNothing(): void
    {
        $client = $this->client([$this->ok(['urls' => []])]);

        self::assertSame([], $client->getFullCaseForUid('77RS0021-02-2026-011276-09', '77RS9999'));
        self::assertCount(1, $this->requests);
        self::assertSame('77RS9999', $this->requests[0]['body']['params']['court_id']);
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

    public function testParserForCourtUrlIsAskedWithoutEnvelope(): void
    {
        $client = $this->client([new MockResponse('{"parser_id":"mos","court_id":"77RS0001"}')]);

        $info = $client->getParserFor('https://mos-gorsud.ru');

        self::assertSame(['parser_id' => 'mos', 'court_id' => '77RS0001'], $info);
        self::assertSame(['court_url' => 'https://mos-gorsud.ru'], $this->requests[0]['body']);
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
        $client = $this->client([new MockResponse('{"status":"error","data":null}')]);

        $this->expectException(CourtMonitorApiErrorException::class);
        $this->expectExceptionMessage('CourtMonitor API error: unknown error');

        $client->getTotalCounts([]);
    }

    public function testHttp400OnFullCardsIsInvalidKey(): void
    {
        $client = $this->client([new MockResponse('', ['http_code' => 400])]);

        try {
            $client->getFullCases(['u1'], '77RS0001');
            self::fail('Ожидалась ошибка ключа');
        } catch (CourtMonitorInvalidKeyException $e) {
            self::assertSame(400, $e->getCode());
            self::assertStringStartsWith('CourtMonitor request to ' . self::TRANSLATOR_URL . ' failed:', $e->getMessage());
            self::assertInstanceOf(CourtMonitorTransportException::class, $e->getPrevious());
        }
    }

    public function testHttp400OnParserIsTransportError(): void
    {
        $client = $this->client([new MockResponse('', ['http_code' => 400])]);

        try {
            $client->getTotalCounts([]);
            self::fail('Ожидалась транспортная ошибка');
        } catch (CourtMonitorTransportException $e) {
            self::assertSame(400, $e->getCode());
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
            self::assertStringStartsWith('CourtMonitor request to ' . self::PARSER_URL . ' failed:', $e->getMessage());
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

    public function testParserRejectsKeyWithHttp400Envelope(): void
    {
        $client = $this->client([new MockResponse('{"error":"Incorrect key","status":"error"}', ['http_code' => 400])]);

        try {
            $client->getTotalCounts(['court_id' => 'mgs']);
            self::fail('Ожидалась ошибка ключа');
        } catch (CourtMonitorInvalidKeyException $e) {
            self::assertSame('CourtMonitor API error: Incorrect key', $e->getMessage());
            self::assertSame(400, $e->getCode());
            self::assertInstanceOf(CourtMonitorTransportException::class, $e->getPrevious());
        }
    }

    public function testOtherHttpErrorEnvelopeIsApiError(): void
    {
        $client = $this->client([new MockResponse('{"error":"Parser is busy","status":"error"}', ['http_code' => 503])]);

        try {
            $client->getTotalCounts([]);
            self::fail('Ожидалась ошибка сервиса');
        } catch (CourtMonitorApiErrorException $e) {
            self::assertSame('Parser is busy', $e->getApiError());
            self::assertSame(503, $e->getCode());
        }
    }

    public function testCheckKeyAsksParser(): void
    {
        self::assertTrue($this->client([$this->ok([])])->checkKey('good'));
        self::assertSame(self::PARSER_URL, $this->requests[0]['url']);
        self::assertSame(['court_id' => ''], $this->requests[0]['body']['params']);
        self::assertSame('good', $this->requests[0]['body']['key']);
    }

    public function testCheckKeyReturnsFalseWhenKeyRejected(): void
    {
        $rejected = new MockResponse('{"error":"Incorrect key","status":"error"}', ['http_code' => 400]);

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
            self::TRANSLATOR_URL,
            self::PARSER_URL,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ok(array $data): MockResponse
    {
        return new MockResponse(json_encode(
            ['status' => 'ok', 'error' => null, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function error(string $error): MockResponse
    {
        return new MockResponse(json_encode(
            ['status' => 'error', 'error' => $error, 'data' => null],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }
}
