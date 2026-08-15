<?php

namespace Api\Services\Actions\Executor;

use Api\Services\Actions\Context;
use Api\Services\Actions\Resolver\Field;
use Api\Services\Actions\Resolver\Condition;
use Api\CurlLogic;

/**
 * Class Curl
 * 
 * Экзекьютор cURL-запросов через \Api\CurlLogic.
 * Используется в request.curl (получение данных)
 * и в execute actions (отправка данных).
 * 
 * Формат конфигурации элемента:
 * [
 *     'url' => '{{params.api_base_url}}/tasks/new',  // шаблоны и field: работают
 *     'method' => 'GET',          // GET|POST|PUT|DELETE|REST
 *     'headers' => ['Authorization: Bearer {{params.api_token}}'],
 *     'params' => [...],          // тело для POST/PUT/DELETE, аргументы REST
 *     'timeout' => 30,
 *     'header_in_out' => false,          // (опц.) вернуть заголовки ответа
 *     'return_error_result' => false,    // (опц.) данные даже при ошибке
 *     'conditions' => [...],             // (опц.) запрос по условию
 *     'on_error' => [...],               // (опц.) fallback при ERROR
 *     'rest_code' => '',                 // (для REST) код доступа
 *     'rest_method' => ''                // (для REST) метод CRM
 * ]
 * 
 * Обработка ошибок:
 * - status.code === ERROR и есть on_error → подставляется fallback
 * - status.code === ERROR и нет on_error → ошибка в контекст
 *   (глобальная в одиночном режиме, итерационная в array-режиме)
 * 
 * @package Api\Services\Actions\Executor
 */
class Curl
{
    /**
     * Контекст выполнения
     * 
     * @var Context
     */
    private Context $context;

    /**
     * Резолвер полей
     * 
     * @var Field
     */
    private Field $fieldResolver;

    /**
     * Вычислитель условий
     * 
     * @var Condition
     */
    private Condition $conditionResolver;

    /**
     * Реализация cURL (инжект для тестов, по умолчанию \Api\CurlLogic)
     * 
     * @var CurlLogic|null
     */
    private ?CurlLogic $curlLogic;

    /**
     * Curl constructor.
     * 
     * @param Context $context Контекст
     * @param Field $fieldResolver Резолвер полей
     * @param Condition $conditionResolver Вычислитель условий
     * @param CurlLogic|null $curlLogic Реализация cURL (для тестов)
     */
    public function __construct(
        Context $context,
        Field $fieldResolver,
        Condition $conditionResolver,
        ?CurlLogic $curlLogic = null
    ) {
        $this->context = $context;
        $this->fieldResolver = $fieldResolver;
        $this->conditionResolver = $conditionResolver;
        $this->curlLogic = $curlLogic;
    }

    /**
     * Выполняет серию именованных cURL-запросов (блок request.curl)
     * 
     * @param array $config Карта: ключ => конфиг запроса
     * @param string $pathPrefix Префикс пути для ошибок в логах
     * @return void
     */
    public function execute(array $config, string $pathPrefix = 'request.curl'): void
    {
        $this->context->log('INFO', 'Curl', 'Начало выполнения cURL-запросов', [
            'requests' => array_keys($config)
        ]);

        foreach ($config as $key => $curlConfig) {
            if ($this->context->hasError()) {
                break;
            }

            // Условия выполнения запроса
            if (isset($curlConfig['conditions']) && !empty($curlConfig['conditions'])) {
                if (!$this->conditionResolver->evaluate($curlConfig['conditions'])) {
                    $this->context->set($key, [
                        'status' => ['code' => 'SKIPPED', 'message' => ''],
                        'data' => []
                    ]);

                    $this->context->log('INFO', 'Curl', "Запрос {$key} пропущен (условие false)");
                    continue;
                }
            }

            $result = $this->executeSingle($curlConfig);
            // Обработка ERROR
            if (($result['status']['code'] ?? '') === 'ERROR') {
                if (array_key_exists('on_error', $curlConfig)) {
                    $this->context->log('INFO', 'Curl', "Запрос {$key}: ERROR подавлен через on_error", [
                        'error' => $result['status']['message'] ?? '',
                        'on_error' => $curlConfig['on_error']
                    ]);

                    $result = $curlConfig['on_error'];
                } else {
                    $this->context->set($key, $result);

                    $this->context->setError(
                        "{$pathPrefix}.{$key}",
                        "cURL запрос '{$key}' вернул ERROR",
                        $result['status']['message'] ?? ''
                    );
                    break;
                }
            }

            $this->context->set($key, $result);

            $this->context->log('SUCCESS', 'Curl', "Запрос {$key} выполнен", [
                'status' => $result['status']['code'] ?? ''
            ]);
        }
    }

    /**
     * Выполняет один cURL-запрос и возвращает сырой результат
     * 
     * @param array $curlConfig Конфигурация запроса
     * @return array Результат \Api\CurlLogic: {status, data, resultStr}
     * @throws \RuntimeException Если метод не поддерживается
     */
    public function executeSingle(array $curlConfig): array
    {
        $url = (string) $this->fieldResolver->resolve($curlConfig['url'] ?? '');
        $method = strtoupper((string) ($curlConfig['method'] ?? 'GET'));
        $headers = $this->resolveHeaders($curlConfig['headers'] ?? []);
        $params = $this->fieldResolver->resolveParams($curlConfig['params'] ?? []);
        $timeout = (int) ($curlConfig['timeout'] ?? 30);
        $headerInOut = (bool) ($curlConfig['header_in_out'] ?? false);
        $returnErrorResult = (bool) ($curlConfig['return_error_result'] ?? false);

        $logic = $this->curlLogic ?? new \Api\CurlLogic();

        $this->context->log('INFO', 'Curl', "cURL {$method}: {$url}", [
            'headers' => $headers,
            'params' => $params,
            'timeout' => $timeout
        ]);

        if (count($params) && str_contains(implode(' ', $headers), 'application/json')) {
            $params = json_encode($params, JSON_UNESCAPED_UNICODE);
        }

        switch ($method) {
            case 'GET':
                return $logic->curlGet($url, $headers, $timeout, $headerInOut, $returnErrorResult);

            case 'POST':
                return $logic->curlPost($url, $headers, $timeout, $params, $headerInOut, $returnErrorResult);

            case 'PUT':
                return $logic->curlPut($url, $headers, $timeout, $params, $headerInOut, $returnErrorResult);

            case 'DELETE':
                return $logic->curlDelete($url, $headers, $timeout, $params, $headerInOut, $returnErrorResult);

            case 'REST':
                return $logic->executeREST(
                    $curlConfig['rest_code'] ?? '',
                    $curlConfig['rest_method'] ?? '',
                    $params
                );

            default:
                throw new \RuntimeException("Неподдерживаемый cURL метод: {$method}");
        }
    }

    /**
     * Резолвит заголовки (field: и {{ }} работают)
     * 
     * @param array $headers Массив строк-заголовков
     * @return array Разрешённые заголовки
     */
    private function resolveHeaders(array $headers): array
    {
        $resolved = [];

        foreach ($headers as $header) {
            $resolved[] = (string) $this->fieldResolver->resolve($header);
        }

        return $resolved;
    }
}
