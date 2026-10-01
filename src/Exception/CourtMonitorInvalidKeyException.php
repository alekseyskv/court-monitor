<?php

namespace Lawmatic\CourtMonitor\Exception;

/**
 * Сервис отверг ключ парсера. Приходит двумя путями:
 *  - HTTP 401 `{"detail":"Неверный ключ"}` — код исключения 401, текст как
 *    у транспортной ошибки, а она сама — в getPrevious();
 *  - конвертом с ошибкой «Неверный ключ» / «Incorrect key» / «Invalid key» /
 *    «Unauthorized» — текст сообщения как у {@see CourtMonitorApiErrorException}.
 */
class CourtMonitorInvalidKeyException extends CourtMonitorException
{
}
