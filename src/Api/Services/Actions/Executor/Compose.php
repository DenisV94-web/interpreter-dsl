<?php

namespace Api\Services\Actions\Executor;

use Api\Services\Actions\Context;
use Api\Services\Actions\Resolver\Field;

/**
 * Class Compose
 * 
 * Финальная сборка ответа из готовых данных контекста.
 * Декларативно описывает ЛЮБУЮ итоговую структуру:
 * вложенные объекты, списки (через field: на замаппленные источники),
 * шаблоны {{ }}, статичные значения.
 * 
 * Формат конфигурации:
 * [
 *     'user' => ['id' => 'field:current_user.ID'],
 *     'brand' => 'field:brand',
 *     'tabs' => [
 *         'nav-new-task' => [
 *             'tab-name' => 'nav-new-task',
 *             'tasks' => 'field:mapped_tasks_new'
 *         ]
 *     ]
 * ]
 * 
 * Результат записывается в context.response (становится ответом клиенту)
 * и в context.data['compose'] (доступен через field:compose.xxx).
 * 
 * @package Api\Services\Actions\Executor
 */
class Compose
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
     * Compose constructor.
     * 
     * @param Context $context Контекст
     * @param Field $fieldResolver Резолвер полей
     */
    public function __construct(Context $context, Field $fieldResolver)
    {
        $this->context = $context;
        $this->fieldResolver = $fieldResolver;
    }

    /**
     * Собирает итоговую структуру ответа
     * 
     * Две формы:
     * - карта `['key' => 'field:X', ...]` — рекурсивная сборка по ключам;
     * - строка `'field:X'` (v1.29.0) — корневое выражение: резолвится
     *   целиком и возвращается как есть (без обёртки в ключ). Кейс:
     *   «отдать дерево/список/скаляр прямо как корень ответа».
     * 
     * @param array|string $config Конфигурация compose
     * @return mixed Собранный ответ (карта, список, скаляр, null)
     */
    public function execute(array|string $config): mixed
    {
        $this->context->log('INFO', 'Compose', '=== НАЧАЛО СБОРКИ ОТВЕТА ===');

        // v1.29.0: корневое выражение (строка)
        if (is_string($config)) {
            $result = $this->fieldResolver->resolve($config);
            $this->context->response = $result;
            $this->context->log('SUCCESS', 'Compose', 'Compose завершён (корневое выражение)', [
                'result_type' => gettype($result)
            ]);
            return $result;
        }

        $result = $this->build($config);

        // Доступен через field:compose.xxx
        $this->context->set('compose', $result);

        // Становится ответом клиенту
        $this->context->response = $result;

        $this->context->log('SUCCESS', 'Compose', '=== СБОРКА ОТВЕТА ЗАВЕРШЕНА ===', [
            'result_keys' => array_keys($result)
        ]);

        return $result;
    }

    /**
     * Рекурсивно собирает структуру
     * 
     * Массивы обходятся рекурсивно, скаляры резолвятся через Field
     * (field:, {{ }}, result, литералы). Одиночный плейсхолдер
     * возвращает сырое значение (список/объект/null).
     * 
     * @param array $config Узел конфигурации
     * @return array Собранный узел
     */
    private function build(array $config): array
    {
        $result = [];

        foreach ($config as $key => $value) {
            $result[$key] = is_array($value)
                ? $this->build($value)
                : $this->fieldResolver->resolve($value);
        }

        return $result;
    }
}
