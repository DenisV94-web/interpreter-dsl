<?php

namespace Api\Services\Actions\Testing;

/**
 * Class MockCurlLogic
 * 
 * Мок cURL для тестов: возвращает заготовленные ответы из очереди
 * и записывает все вызовы для проверок (url, headers, fields)
 * 
 * @package Api\Services\Actions\Testing
 */
class MockCurlLogic implements \Api\ICurlLogic
{
    /**
     * Запись всех вызовов
     * 
     * @var array
     */
    public array $calls = [];

    /**
     * Очередь заготовленных ответов
     * 
     * @var array
     */
    public array $queue = [];

    public function curlGet($url, $headers, $timeout)
    {
        return $this->record('GET', $url, $headers, func_get_args());
    }

    public function curlPost($url, $headers, $timeout, $fields)
    {
        return $this->record('POST', $url, $headers, func_get_args(), $fields);
    }

    public function curlPut($url, $headers, $timeout, $fields)
    {
        return $this->record('PUT', $url, $headers, func_get_args(), $fields);
    }

    public function curlDelete($url, $headers, $timeout, $fields = null, $headerInOut = false, $returnErrorResult = false)
    {
        return $this->record('DELETE', $url, $headers, func_get_args(), $fields);
    }

    public function executeREST($code, $method, $fields)
    {
        return $this->record('REST', $code . '/' . $method, [], func_get_args(), $fields);
    }

    /**
     * Записывает вызов и возвращает следующий ответ из очереди
     * 
     * @param string $method HTTP-метод
     * @param string $url URL
     * @param array $headers Заголовки
     * @param array $args Все аргументы
     * @param mixed $fields Тело запроса
     * @return array
     */
    private function record(string $method, string $url, array $headers, array $args, $fields = null): array
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'fields' => $fields
        ];

        $next = array_shift($this->queue);

        return $next ?? [
            'status' => ['code' => 'SUCCESS', 'message' => ''],
            'data' => []
        ];
    }
}
