<?php

namespace Api\Services;

/**
 * Class ResponseHandler
 * 
 * Минимальная standalone-версия для автономного репозитория.
 * В продакшене Битрикс подменяется корпоративной реализацией
 * через автозагрузчик (имя класса и API совпадают).
 * 
 * @package Api\Services
 */
class ResponseHandler
{
    /** @var string SUCCESS|ERROR */
    public string $status = 'SUCCESS';

    /** @var string Текст ошибки */
    public string $message = '';

    /** @var mixed Данные ответа */
    public $response = [];

    /** @var bool Глобальная ошибка */
    public bool $global = false;

    /** @var array HTTP-заголовки при ошибке */
    public array $errorHeaders = [];

    /**
     * Устанавливает данные ответа
     * 
     * @param mixed $response Данные
     * @param bool $global Флаг глобальности
     * @return void
     */
    public function setResponse($response, bool $global = false): void
    {
        $this->response = $response;
        $this->global = $global;
    }

    /**
     * Устанавливает ошибку
     * 
     * @param string $message Короткое сообщение
     * @param string $detailed Подробное сообщение
     * @return void
     */
    public function setError(string $message, string $detailed = ''): void
    {
        $this->status = 'ERROR';
        $this->message = $detailed !== '' ? $detailed : $message;
    }

    /**
     * Выводит JSON (форма совпадает с продакшеном)
     * 
     * @return void
     */
    public function output(): void
    {
        if (!defined('INTERPRETER_CLI')) {
            header('Content-Type: application/json; charset=utf-8');
            foreach ($this->errorHeaders as $header) {
                header($header);
            }
        }

        echo json_encode([
            'status' => ['code' => $this->status, 'message' => $this->message],
            'data' => $this->response,
            'global' => $this->global
        ], JSON_UNESCAPED_UNICODE);
    }
}
