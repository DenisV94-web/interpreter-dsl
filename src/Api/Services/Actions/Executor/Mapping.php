<?php

namespace Api\Services\Actions\Executor;

use Api\Services\Actions\Context;
use Api\Services\Actions\Resolver\Field;
use Api\Services\Actions\Resolver\Condition;

/**
 * Class Mapping
 * 
 * Экзекьютор блока mapping конфигурации интерпретатора.
 * Формирует итоговые данные из вычисленного контекста.
 * 
 * Поддерживает три режима работы (определяются автоматически по структуре конфига):
 * 
 * ============================================================================
 * РЕЖИМ 1: ОДИНОЧНЫЙ
 * ============================================================================
 * Плоский массив целевое_поле => выражение.
 * Все значения записываются в контекст и в context.mapping (для 'mapping' placeholder в execute).
 * 
 * Конфиг:
 * [
 *     'UF_FIELD_ID' => 'field:field_id',
 *     'PHONE' => 'field:CONTACT.PHONE|field:COMPANY_PHONE.VALUE',
 *     'FM' => [
 *         'PHONE' => ['n0' => ['VALUE_TYPE' => 'WORK', 'VALUE' => 'field:CONTACT_PHONE.VALUE']]
 *     ]
 * ]
 * 
 * Результат: массив mapping, доступен через 'mapping' в execute
 * 
 * ============================================================================
 * РЕЖИМ 2: ИМЕНОВАННЫЕ СПИСОЧНЫЕ МАППИНГИ
 * ============================================================================
 * Массив именованных маппингов, каждый со своим source.
 * Каждый результат записывается в контекст под своим именем.
 * Используется для построения ответа через compose.
 * 
 * Конфиг:
 * [
 *     'mapped_tasks_new' => [
 *         'source' => 'tabs_new.data',
 *         'mapping' => [
 *             'id' => 'field:tabs_new.data.ID',
 *             'client_name' => '{{tabs_new.data.LAST_NAME}} {{tabs_new.data.NAME}}'
 *         ]
 *     ],
 *     'mapped_tasks_my_open' => [
 *         'source' => 'tabs_my_open.data'   // БЕЗ mapping — вернётся сырой список
 *     ]
 * ]
 * 
 * Результат: mapped_tasks_new и mapped_tasks_my_open доступны через field:mapped_tasks_new
 * 
 * ============================================================================
 * РЕЖИМ 3: СМЕШАННЫЙ (на будущее)
 * ============================================================================
 * Именованные списочные маппинги + обычные одиночные поля в одном конфиге.
 * 
 * Конфиг:
 * [
 *     'mapped_tasks_new' => ['source' => 'tabs_new.data', 'mapping' => [...]],
 *     'total_count' => 'field:stats.total'   // одиночное поле
 * ]
 * 
 * Логика обработки:
 * - Если значение — массив с ключом 'source' → списочный маппинг
 * - Иначе → одиночное поле (резолвится через Field)
 * 
 * @package Api\Services\Actions\Executor
 */
class Mapping
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
     * Вычислитель условий (для построчной фильтрации в списочном режиме)
     * 
     * @var Condition|null
     */
    private ?Condition $conditionResolver;

    /**
     * Ключ под которым сохраняется результат одиночного маппинга
     * Используется в execute для 'params' => ['mapping']
     * 
     * @var string
     */
    private const CONTEXT_MAPPING_KEY = 'mapping';

    /**
     * Mapping constructor.
     * 
     * @param Context $context Контекст
     * @param Field $fieldResolver Резолвер полей
     * @param Condition|null $conditionResolver Вычислитель условий
     */
    public function __construct(Context $context, Field $fieldResolver, ?Condition $conditionResolver = null)
    {
        $this->context = $context;
        $this->fieldResolver = $fieldResolver;
        $this->conditionResolver = $conditionResolver;
    }

    /**
     * Выполняет маппинг (точка входа)
     * 
     * Автоматически определяет режим по структуре конфига:
     * - Если есть хотя бы одно значение с ключом 'source' → именованный списочный
     * - Иначе → одиночный
     * 
     * @param array $config Конфигурация маппинга
     * @return array Результат маппинга
     */
    public function execute(array $config): array
    {
        if ($this->hasNestedMappings($config)) {
            return $this->executeNamedMappings($config);
        }

        return $this->executeSingleMode($config);
    }

    /**
     * Проверяет, содержит ли конфиг именованные списочные маппинги
     * 
     * Признак: хотя бы одно значение — массив с ключом 'source'
     * 
     * @param array $config Конфигурация
     * @return bool
     */
    private function hasNestedMappings(array $config): bool
    {
        foreach ($config as $value) {
            if (is_array($value) && array_key_exists('source', $value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * РЕЖИМ 2/3: Именованные маппинги (списочные + возможно одиночные поля)
     * 
     * Обрабатывает каждый элемент конфига:
     * - Если есть 'source' → списочный маппинг, результат пишется в контекст
     * - Иначе → одиночное поле, резолвится через Field
     * 
     * ВАЖНО: НЕ перезаписывает context.response — это работа compose.
     * 
     * @param array $config Конфигурация
     * @return array Результаты всех маппингов (имя => результат)
     */
    private function executeNamedMappings(array $config): array
    {
        $this->context->log('INFO', 'Mapping', '=== РЕЖИМ ИМЕНОВАННЫХ МАППИНГОВ ===', [
            'mappings' => array_keys($config)
        ]);

        $allResults = [];

        foreach ($config as $mappingName => $mappingConfig) {
            $this->context->log('INFO', 'Mapping', "Обработка маппинга: {$mappingName}", [
                'config' => $mappingConfig
            ]);

            try {
                // Списочный маппинг (с source)
                if (is_array($mappingConfig) && array_key_exists('source', $mappingConfig)) {
                    $result = $this->executeListMapping($mappingName, $mappingConfig);
                }
                // Одиночное поле (строка или вложенный массив без source)
                else {
                    $result = $this->resolveSingleField($mappingName, $mappingConfig);
                }

                $allResults[$mappingName] = $result;

                // Записываем результат в контекст под именем маппинга
                // (будет доступен через field:mapped_tasks_new, field:total_count и т.д.)
                $this->context->set($mappingName, $result);
            } catch (\Throwable $e) {
                $this->context->log('ERROR', 'Mapping', "Ошибка маппинга: {$mappingName}", [
                    'error' => $e->getMessage(),
                    'config' => $mappingConfig
                ]);

                $this->context->setError(
                    "mapping.{$mappingName}",
                    "Ошибка обработки маппинга '{$mappingName}'",
                    $e->getMessage()
                );

                // При ошибке записываем null и продолжаем
                $allResults[$mappingName] = null;
                $this->context->set($mappingName, null);
            }
        }

        $this->context->log('SUCCESS', 'Mapping', '=== ИМЕНОВАННЫЕ МАППИНГИ ЗАВЕРШЕНЫ ===', [
            'result_keys' => array_keys($allResults)
        ]);

        return $allResults;
    }

    /**
     * Выполняет один списочный маппинг
     * 
     * @param string $mappingName Имя маппинга (для логов)
     * @param array $mappingConfig Конфигурация с ключом 'source'
     * @return array Список замаппленных строк (или сырой список если нет mapping)
     */
    private function executeListMapping(string $mappingName, array $mappingConfig): array
    {
        $source = $mappingConfig['source'];
        $mappingRules = $mappingConfig['mapping'] ?? [];
        $conditions = $mappingConfig['conditions'] ?? null;

        // Нормализуем source (строка или массив)
        $sourceConfig = $this->normalizeSource($source);
        $key = $sourceConfig['key'];
        $list = $this->context->get($key);

        $this->context->log('INFO', 'Mapping', "Списочный маппинг: {$mappingName}", [
            'source' => $key,
            'rows' => is_array($list) ? count($list) : 0,
            'has_mapping' => !empty($mappingRules),
            'has_conditions' => $conditions !== null
        ]);

        if (!is_array($list)) {
            $this->context->setError(
                "mapping.{$mappingName}",
                "Источник '{$key}' не является массивом",
                'List mapping requires array source'
            );
            return [];
        }

        // ВАЖНО: если нет mapping — возвращаем сырой список
        // (кейс: 'mapped_tasks_my_open' => ['source' => 'tabs_my_open.data'])
        if (empty($mappingRules)) {
            $this->context->log('INFO', 'Mapping', "Источник {$key} возвращается без маппинга", [
                'count' => count($list)
            ]);
            return $list;
        }

        // Сохраняем оригинальный список — вернём после обработки
        $original = $list;
        $mapped = [];

        foreach ($list as $index => $row) {
            if (!is_array($row)) {
                $this->context->log('INFO', 'Mapping', "Строка #{$index} не массив — пропущена");
                continue;
            }

            // Подставляем текущую строку вместо источника в контексте.
            // Теперь field:tabs_new.data.ID и {{tabs_new.data.NAME}} указывают
            // на поля текущей строки, а не всего списка.
            $this->context->set($key, $row);

            // Объединённые условия: source-level + построчные
            // (sourceConfig['conditions'] и $conditions объединяются,
            //  но source-level уже применён в normalizeSource)
            $rowConditions = $conditions ?? $sourceConfig['conditions'];

            if (
                $rowConditions !== null
                && $this->conditionResolver !== null
                && !$this->conditionResolver->evaluate($rowConditions)
            ) {
                $this->context->log('INFO', 'Mapping', "Строка #{$index} пропущена по условию");
                continue;
            }

            // Применяем правила маппинга к текущей строке
            $mappedRow = [];
            foreach ($mappingRules as $target => $expression) {
                $mappedRow[$target] = is_array($expression)
                    ? $this->fieldResolver->resolveParams($expression)
                    : $this->fieldResolver->resolve($expression);
            }

            $mapped[] = $mappedRow;
        }

        // Возвращаем оригинальный список в контекст
        // (чтобы другие маппинги/блоки могли его использовать)
        $this->context->set($key, $original);

        $this->context->log('SUCCESS', 'Mapping', "Маппинг {$mappingName} завершён", [
            'mapped_count' => count($mapped)
        ]);

        return $mapped;
    }

    /**
     * Резолвит одиночное поле в смешанном режиме
     * 
     * @param string $fieldName Имя поля
     * @param mixed $expression Выражение (строка или вложенный массив)
     * @return mixed Разрешённое значение
     */
    private function resolveSingleField(string $fieldName, $expression)
    {
        $this->context->log('INFO', 'Mapping', "Одиночное поле: {$fieldName}", [
            'expression' => $expression
        ]);

        $resolved = is_array($expression)
            ? $this->fieldResolver->resolveParams($expression)
            : $this->fieldResolver->resolve($expression);

        $this->context->log('SUCCESS', 'Mapping', "Поле разрешено: {$fieldName}", [
            'resolved' => $resolved
        ]);

        return $resolved;
    }

    /**
     * РЕЖИМ 1: Одиночный маппинг (create_lead)
     * 
     * Плоский массив целевое_поле => выражение.
     * Результат записывается в context.mapping (для использования в execute).
     * 
     * @param array $config Конфигурация
     * @return array Результат маппинга
     */
    private function executeSingleMode(array $config): array
    {
        $this->context->log('INFO', 'Mapping', '=== ОДИНОЧНЫЙ РЕЖИМ MAPPING ===', [
            'mapping_keys' => array_keys($config),
            'mapping_count' => count($config)
        ]);

        $result = [];

        foreach ($config as $targetField => $sourceExpression) {
            $this->context->log('INFO', 'Mapping', "Обработка поля: {$targetField}", [
                'source' => $sourceExpression
            ]);

            try {
                // Вложенные массивы (множественные поля Битрикс типа PHONE)
                // разрешаются РЕКУРСИВНО через resolveParams
                if (is_array($sourceExpression)) {
                    $resolvedValue = $this->fieldResolver->resolveParams($sourceExpression);
                } else {
                    $resolvedValue = $this->fieldResolver->resolve($sourceExpression);
                }

                $result[$targetField] = $resolvedValue;

                // Также записываем в контекст для доступа через field:...
                $this->context->set($targetField, $resolvedValue);

                $this->context->log('SUCCESS', 'Mapping', "Поле разрешено: {$targetField}", [
                    'source' => $sourceExpression,
                    'resolved' => $resolvedValue
                ]);
            } catch (\Throwable $e) {
                $this->context->log('ERROR', 'Mapping', "Ошибка разрешения поля: {$targetField}", [
                    'source' => $sourceExpression,
                    'error' => $e->getMessage()
                ]);

                $this->context->setError(
                    "mapping.{$targetField}",
                    "Ошибка разрешения поля '{$targetField}'",
                    $e->getMessage()
                );

                $result[$targetField] = null;
                $this->context->set($targetField, null);
            }
        }

        // КЛЮЧЕВОЕ: сохраняем весь маппинг под ключом 'mapping' в контексте.
        // Это нужно для execute, где 'params' => ['mapping'] передаёт
        // весь маппинг в метод add/update.
        $this->context->set(self::CONTEXT_MAPPING_KEY, $result);

        $this->context->log('SUCCESS', 'Mapping', '=== ОДИНОЧНЫЙ MAPPING ЗАВЕРШЁН ===', [
            'result_keys' => array_keys($result),
            'result_count' => count($result)
        ]);

        return $result;
    }

    /**
     * Нормализует конфигурацию источника
     * 
     * Поддерживает две формы:
     * - Строка: 'LEAD' → ['key' => 'LEAD', 'conditions' => null]
     * - Массив: ['key' => 'LEAD', 'conditions' => [...]] → как есть
     * 
     * @param string|array $source Источник
     * @return array ['key' => string, 'conditions' => array|null]
     */
    private function normalizeSource($source): array
    {
        if (is_string($source)) {
            return ['key' => $source, 'conditions' => null];
        }

        return [
            'key' => $source['key'] ?? null,
            'conditions' => $source['conditions'] ?? null
        ];
    }
}
