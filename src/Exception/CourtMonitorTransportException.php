<?php

namespace Lawmatic\CourtMonitor\Exception;

/**
 * Разборчивого ответа от сервиса не получили: сеть, таймаут, не-2xx HTTP-код
 * или тело, которое не разбирается как JSON.
 *
 * Код исключения — HTTP-статус ответа, если он был; 0 — ответа не было вовсе
 * (сеть, таймаут) или он не разобрался. Исходная ошибка HTTP-клиента — в getPrevious().
 */
class CourtMonitorTransportException extends CourtMonitorException
{
}
