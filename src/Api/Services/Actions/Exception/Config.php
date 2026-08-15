<?php

namespace Api\Services\Actions\Exception;

/**
 * Class Config
 * 
 * Исключение для ошибок конфигурации интерпретатора.
 * Выбрасывается когда конфиг содержит ошибки:
 * - Отсутствуют обязательные ключи
 * - Неверный формат данных
 * - Несуществующие классы/методы в конфиге
 * 
 * @package Api\Services\Actions\Exception
 */
class Config extends \RuntimeException
{
    /**
     * Путь в конфиге где произошла ошибка
     * 
     * @var string
     */
    private string $configPath;

    /**
     * Config constructor.
     * 
     * @param string $message Текст ошибки
     * @param string $configPath Путь в конфиге
     * @param int $code Код ошибки
     * @param \Throwable|null $previous Предыдущее исключение
     */
    public function __construct(
        string $message,
        string $configPath = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $this->configPath = $configPath;

        // Формируем полное сообщение с путём в конфиге
        $fullMessage = $configPath !== ''
            ? "Ошибка конфигурации в '{$configPath}': {$message}"
            : "Ошибка конфигурации: {$message}";

        parent::__construct($fullMessage, $code, $previous);
    }

    /**
     * Возвращает путь в конфиге где произошла ошибка
     * 
     * @return string
     */
    public function getConfigPath(): string
    {
        return $this->configPath;
    }
}
