<?php

namespace Examples;

/**
 * Class DemoService
 * 
 * Нейтральный мок-сервис для примеров интерпретатора.
 * Имитирует типичные методы продакшен-классов: getById, getList,
 * add, update, статические методы-условия и справочники.
 * 
 * Хранилище — in-memory (static), чтобы add/getById работали
 * в рамках одного запуска runner.php.
 * 
 * @package Examples
 */
class DemoService
{
    /**
     * Каталог клиентов (контакты и компании)
     * 
     * @var array
     */
    private static array $clients = [
        101 => ['ID' => 101, 'LAST_NAME' => 'Иванов', 'NAME' => 'Иван', 'PHONE' => '+79991002030'],
        202 => ['ID' => 202, 'TITLE' => 'ООО Ромашка', 'PHONE' => '+79993034040'],
    ];

    /**
     * Список задач (для demo_card)
     * 
     * @var array
     */
    private static array $tasks = [
        ['ID' => 1, 'TITLE' => 'Позвонить клиенту', 'USER_ID' => 42],
        ['ID' => 2, 'TITLE' => 'Подготовить КП', 'USER_ID' => 42],
        ['ID' => 3, 'TITLE' => 'Чужая задача', 'USER_ID' => 7],
    ];

    /**
     * Счётчик ID «созданных» лидов
     * 
     * @var int
     */
    private static int $lastLeadId = 9000;

    /**
     * Хранилище «созданных» лидов
     * 
     * @var array
     */
    private static array $leads = [];

    /**
     * Имитация getById сущности
     * 
     * @param int $id ID сущности
     * @param array $params Дополнительные параметры (select и т.д.)
     * @return array Данные сущности или [] если не найдена
     */
    public function getById(int $id, array $params = []): array
    {
        return self::$clients[$id] ?? [];
    }

    /**
     * Имитация getList с фильтром по user_id
     * 
     * @param array $filter Фильтр (['user_id' => int])
     * @return array Список задач
     */
    public function getList(array $filter = []): array
    {
        if (!isset($filter['user_id'])) {
            return self::$tasks;
        }

        return array_values(array_filter(
            self::$tasks,
            static fn(array $task): bool => $task['USER_ID'] === (int) $filter['user_id']
        ));
    }

    /**
     * Имитация создания лида
     * 
     * @param array $fields Поля лида (маппинг)
     * @return int ID созданного лида
     */
    public function add(array $fields): int
    {
        $id = ++self::$lastLeadId;
        self::$leads[$id] = $fields + ['ID' => $id];
        return $id;
    }

    /**
     * Имитация обновления лида
     * 
     * @param int $id ID лида
     * @param array $fields Поля для обновления
     * @return array Обновлённые данные
     */
    public function update(int $id, array $fields): array
    {
        self::$leads[$id] = (self::$leads[$id] ?? []) + $fields + ['ID' => $id];
        return self::$leads[$id];
    }

    /**
     * Статический метод-условие: центр неактивен при ID > 1000
     * (демонстрация method-условия в elseif-блоке)
     * 
     * @param int $dealerCenterId ID центра
     * @return bool
     */
    public static function isUnactive(int $dealerCenterId): bool
    {
        return $dealerCenterId > 1000;
    }

    /**
     * Имитация получения текущего пользователя
     * 
     * @return array
     */
    public function getCurrentUser(): array
    {
        return ['ID' => 42, 'NAME' => 'Демо Пользователь'];
    }

    /**
     * Статический справочник брендов
     * (демонстрация static-блока через Class::method)
     * 
     * @return array
     */
    public static function getBrandList(): array
    {
        return ['CHANGAN' => 'Changan', 'TENET' => 'Tenet'];
    }
}
