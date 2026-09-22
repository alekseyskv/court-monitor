<?php

namespace Lawmatic\CourtMonitor\Exception;

/**
 * Ошибка при общении с внешним API мониторинга судебных дел (CourtMonitor):
 * каталог судов courts.lawmatic.ru, парсер parsers.lawmatic.ru,
 * транслятор translator.lawmatic.ru.
 *
 * Базовый класс: ловите его, если причина не важна. Конкретный вид ошибки —
 * в наследниках:
 *  - {@see CourtMonitorTransportException} — не получили разборчивый ответ
 *    (сеть, таймаут, не-2xx HTTP-код, битый JSON);
 *  - {@see CourtMonitorApiErrorException} — сервис ответил конвертом
 *    {"status":"error","error":"...","data":null};
 *  - {@see CourtMonitorInvalidKeyException} — сервис отверг ключ парсера.
 *
 * Текст сообщения — часть контракта: приложение может хранить его в журнале
 * прогонов и разбирать позже, когда типа исключения уже нет. Транспортные
 * ошибки начинаются с «CourtMonitor request to <url> failed:», ответы сервиса
 * с ошибкой — с «CourtMonitor API error:». Менять эти префиксы нельзя.
 */
class CourtMonitorException extends \RuntimeException
{
}
