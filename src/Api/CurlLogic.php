<?php

namespace Api;

/**
 * Интерфейс для работы с cURL-запросами
 */
interface ICurlLogic
{
    /**
     * Выполняет GET-запрос
     * @param string $url URL запроса
     * @param array $headers Заголовки запроса
     * @param int $timeout Таймаут соединения
     * @return array Результат выполнения запроса
     */
    public function curlGet($url, $headers, $timeout);

    /**
     * Выполняет POST-запрос
     * @param string $url URL запроса
     * @param array $headers Заголовки запроса
     * @param int $timeout Таймаут соединения
     * @param mixed $fields Данные для отправки
     * @return array Результат выполнения запроса
     */
    public function curlPost($url, $headers, $timeout, $fields);

    /**
     * Выполняет PUT-запрос
     * @param string $url URL запроса
     * @param array $headers Заголовки запроса
     * @param int $timeout Таймаут соединения
     * @param mixed $fields Данные для отправки
     * @return array Результат выполнения запроса
     */
    public function curlPut($url, $headers, $timeout, $fields);

    /**
     * Выполняет DELETE-запрос
     * @param string $url URL запроса
     * @param array $headers Заголовки запроса
     * @param int $timeout Таймаут соединения
     * @param mixed $fields Данные для отправки
     * @param bool $headerInOut Флаг возврата заголовков
     * @param bool $returnErrorResult Флаг возврата данных при ошибке
     * @return array Результат выполнения запроса
     */
    public function curlDelete($url, $headers, $timeout, $fields = null, $headerInOut = false, $returnErrorResult = false);

    /**
     * Выполняет REST-запрос к CRM
     * @param string $code Код метода
     * @param string $method Название метода
     * @param array $fields Параметры запроса
     * @return array Результат выполнения запроса
     */
    public function executeREST($code, $method, $fields);
}

/**
 * Реализация работы с cURL-запросами
 */
class CurlLogic implements ICurlLogic
{
    /**
     * Выполняет GET-запрос
     * @param string $url URL запроса
     * @param array $headers Заголовки запроса
     * @param int $timeout Таймаут соединения
     * @param bool $headerInOut Флаг возврата заголовков
     * @param bool $returnErrorResult Флаг возврата данных при ошибке
     * @return array Результат выполнения запроса
     */
    public function curlGet($url, $headers, $timeout, $headerInOut = false, $returnErrorResult = false)
    {
        $options = [
            CURLOPT_POST => 0,
            CURLOPT_HTTPGET => true,
        ];
        return $this->executeCurl($url, $headers, $timeout, null, $options, $headerInOut, $returnErrorResult);
    }

    /**
     * Выполняет POST-запрос
     * @param string $url URL запроса
     * @param array $headers Заголовки запроса
     * @param int $timeout Таймаут соединения
     * @param mixed $fields Данные для отправки
     * @param bool $headerInOut Флаг возврата заголовков
     * @param bool $returnErrorResult Флаг возврата данных при ошибке
     * @return array Результат выполнения запроса
     */
    public function curlPost($url, $headers, $timeout, $fields, $headerInOut = false, $returnErrorResult = false)
    {
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
        ];
        return $this->executeCurl($url, $headers, $timeout, $fields, $options, $headerInOut, $returnErrorResult);
    }

    /**
     * Выполняет PUT-запрос
     * @param string $url URL запроса
     * @param array $headers Заголовки запроса
     * @param int $timeout Таймаут соединения
     * @param mixed $fields Данные для отправки
     * @param bool $headerInOut Флаг возврата заголовков
     * @param bool $returnErrorResult Флаг возврата данных при ошибке
     * @return array Результат выполнения запроса
     */
    public function curlPut($url, $headers, $timeout, $fields, $headerInOut = false, $returnErrorResult = false)
    {
        $options = [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $fields,
        ];
        return $this->executeCurl($url, $headers, $timeout, $fields, $options, $headerInOut, $returnErrorResult);
    }

    /**
     * Выполняет DELETE-запрос
     * @param string $url URL запроса
     * @param array $headers Заголовки запроса
     * @param int $timeout Таймаут соединения
     * @param mixed $fields Данные для отправки
     * @param bool $headerInOut Флаг возврата заголовков
     * @param bool $returnErrorResult Флаг возврата данных при ошибке
     * @return array Результат выполнения запроса
     */
    public function curlDelete($url, $headers, $timeout, $fields = null, $headerInOut = false, $returnErrorResult = false)
    {
        $options = [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
        ];
        // Добавляем данные, если они переданы
        if ($fields !== null) {
            $options[CURLOPT_POSTFIELDS] = $fields;
        }
        return $this->executeCurl($url, $headers, $timeout, $fields, $options, $headerInOut, $returnErrorResult);
    }

    /**
     * Выполняет REST-запрос к CRM
     * @param string $code Код метода
     * @param string $method Название метода
     * @param array $fields Параметры запроса
     * @return array Результат выполнения запроса
     */
    public function executeREST($code, $method, $fields, $baseUrl = 'https://crm.example.ru/rest/1/')
    {
        $url = $baseUrl . $code . '/' . $method . '.json';
        $queryData = http_build_query($fields);
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_POST => 1,
            CURLOPT_HEADER => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $url,
            CURLOPT_POSTFIELDS => $queryData,
        ]);

        $result = curl_exec($ch);
        $output = [];

        if (curl_errno($ch)) {
            $output = [
                "status" => [
                    "code" => 'ERROR',
                    "message" => curl_error($ch)
                ],
                "data" => []
            ];
        } else {
            $output = [
                "status" => [
                    "code" => 'SUCCESS',
                    "message" => ''
                ],
                "data" => json_decode($result, true)
            ];
        }

        curl_close($ch);
        return $output;
    }

    /**
     * Обрабатывает заголовки ответа
     * @param string $header Строка заголовков
     * @param bool $returnData Флаг возврата конкретного заголовка
     * @param bool $returnDetail Флаг детализированного разбора
     * @return array|string Разобранные заголовки
     */
    protected function readHeader($header, $returnData = false, $returnDetail = false)
    {
        $rsHeader = array_values(array_diff(explode("\r\n", $header), ['']));
        $arHeader = [];

        if (is_array($rsHeader) && $returnData) {
            foreach ($rsHeader as $v) {
                if (strpos($v, $returnData) === 0) {
                    return str_replace($returnData . ': ', '', $v);
                }
            }
        } else {
            if ($returnDetail) {
                foreach ($rsHeader as $i => $line) {
                    if ($i === 0) {
                        $arHeader['http_code'] = $line;
                    } else {
                        [$key, $value] = explode(': ', $line, 2);
                        $arHeader[$key] = $value;
                    }
                }
            } else {
                $arHeader = $rsHeader;
            }
        }
        return $arHeader;
    }

    /**
     * Выполняет cURL-запрос
     * @param string $url URL запроса
     * @param array $headers Заголовки запроса
     * @param int $timeout Таймаут соединения
     * @param mixed $fields Данные для отправки
     * @param array $options Дополнительные опции cURL
     * @param bool $headerInOut Флаг возврата заголовков
     * @param bool $returnErrorResult Флаг возврата данных при ошибке
     * @return array Результат выполнения запроса
     */
    private function executeCurl(
        $url,
        $headers,
        $timeout,
        $fields,
        array $options,
        $headerInOut,
        $returnErrorResult
    ) {
        $ch = curl_init();
        $baseOptions = [
            CURLOPT_URL => $url,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => $headerInOut,
        ];

        curl_setopt_array($ch, $baseOptions + $options);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $output = [];

        // Обработка ответа с заголовками
        if ($headerInOut && $result) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $responseHeaders = substr($result, 0, $headerSize);
            $resultBody = substr($result, $headerSize);
            $parsedHeaders = $this->readHeader($responseHeaders, false, true);
        }

        // Проверка на ошибки
        $isError = curl_errno($ch) || ($httpCode != 200 && $httpCode != 201);
        $logData = mb_strimwidth($result, 0, 200, '...') . $url .
            json_encode($headers, JSON_UNESCAPED_UNICODE) .
            ($fields ? json_encode($fields, JSON_UNESCAPED_UNICODE) : '');

        if ($isError) {
            $output = [
                "status" => [
                    "code" => 'ERROR',
                    "message" => $error
                ],
                "resultStr" => $logData,
                "data" => []
            ];

            if ($returnErrorResult) {
                $data = $headerInOut ? json_decode($resultBody, true) : json_decode($result, true);
                $output['data'] = $data ?: [$headerInOut ? $resultBody : $result];
            }

            if ($headerInOut) {
                $output['header'] = $parsedHeaders;
            }
        } else {
            $output = [
                "status" => [
                    "code" => 'SUCCESS',
                    "message" => ''
                ],
                "resultStr" => $logData,
            ];

            if ($headerInOut) {
                $output['data'] = json_decode($resultBody, true);
                $output['header'] = $parsedHeaders;
            } else {
                $output['data'] = json_decode($result, true);
            }
        }

        curl_close($ch);
        return $output;
    }
}
