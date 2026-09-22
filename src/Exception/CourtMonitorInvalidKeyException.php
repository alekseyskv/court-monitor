<?php

namespace Lawmatic\CourtMonitor\Exception;

/**
 * Сервис отверг ключ парсера. Приходит двумя путями:
 *  - конвертом с ошибкой «Incorrect key» / «Invalid key» / «Unauthorized» —
 *    текст сообщения как у {@see CourtMonitorApiErrorException}. Парсер шлёт
 *    такой конверт с HTTP 400: код исключения — 400, транспортная ошибка — в getPrevious();
 *  - HTTP 400 без конверта на запрос полных карточек — тогда текст как
 *    у транспортной ошибки, а она сама — в getPrevious().
 */
class CourtMonitorInvalidKeyException extends CourtMonitorException
{
}
