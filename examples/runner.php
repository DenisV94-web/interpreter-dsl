<?php

/**
 * Живой пример работы интерпретатора (запуск на ПК, без Битрикс)
 * 
 * Запуск:
 *   php examples/runner.php
 * 
 * Показывает два режима:
 * - demo_lead: итерации (add / skip / response-only ошибка)
 * - demo_card: одиночный (static + query + именованный маппинг + compose)
 */

declare(strict_types=1);

// Логи примеров — в отдельную папку (до подключения bootstrap)
define('INTERPRETER_LOG_DIR', __DIR__ . '/logs');

require_once __DIR__ . '/../tests/bootstrap.php';
require_once __DIR__ . '/DemoService.php';

use Api\Services\Actions\Interpreter;
use Api\Services\ResponseHandler;

$config = require __DIR__ . '/Config.example.php';

$interpreter = new Interpreter($config);

// ============================================================
// 1. DEMO_LEAD: итерационный режим
// ============================================================
echo "==================================================\n";
echo "  demo_lead (итерационный режим, 4 итерации)\n";
echo "==================================================\n";

$leadResponse = $interpreter->run('demo_lead', 'examples', [
    'lead' => [
        // 1: контакт из каталога → создание лида
        ['task_id' => '1', 'client_string' => 'contact_101', 'dealer_center_id' => 5],
        // 2: пустая строка → SKIPPED
        ['task_id' => '2', 'client_string' => '', 'dealer_center_id' => 5],
        // 3: неактивный центр (2000 > 1000) → SKIPPED
        ['task_id' => '3', 'client_string' => 'company_202', 'dealer_center_id' => 2000],
        // 4: заблокированный клиент → response-only ошибка
        ['task_id' => '4', 'client_string' => 'blocked_999', 'dealer_center_id' => 5],
    ],
]);

printResult($leadResponse);

// ============================================================
// 2. DEMO_CARD: одиночный режим
// ============================================================
echo "\n==================================================\n";
echo "  demo_card (одиночный режим: static + compose)\n";
echo "==================================================\n";

$cardResponse = $interpreter->run('demo_card', 'examples', []);

printResult($cardResponse);

// ============================================================
// Утилита вывода
// ============================================================

/**
 * Печатает ResponseHandler в формате, совпадающем с продакшеном
 * 
 * @param ResponseHandler $responseHandler Обработчик ответа
 * @return void
 */
function printResult(ResponseHandler $responseHandler): void
{
    echo json_encode([
        'status' => ['code' => $responseHandler->status, 'message' => $responseHandler->message],
        'data' => $responseHandler->response,
        'global' => $responseHandler->global,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
