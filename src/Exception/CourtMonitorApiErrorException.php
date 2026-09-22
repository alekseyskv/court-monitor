<?php

namespace Lawmatic\CourtMonitor\Exception;

/**
 * Сервис ответил, но сообщил об ошибке: конверт {"status":"error","error":"..."}.
 * Текст ошибки сервиса — в {@see getApiError()}.
 */
class CourtMonitorApiErrorException extends CourtMonitorException
{
    public function __construct(
        private readonly string $apiError,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('CourtMonitor API error: ' . $apiError, $code, $previous);
    }

    public function getApiError(): string
    {
        return $this->apiError;
    }
}
