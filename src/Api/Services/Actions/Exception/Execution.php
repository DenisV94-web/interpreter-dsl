<?php

namespace Api\Services\Actions\Exception;

/**
 * Class Execution
 * 
 * Исключение для ошибок выполнения интерпретатора.
 * Выбрасывается когда во время выполнения действий происходит ошибка:
 * - Метод выбросил исключение
 * - Класс не найден
 * - Ошибка транзакции
 * - Другая ошибка runtime
 * 
 * @package Api\Services\Actions\Exception
 */
class Execution extends \RuntimeException
{
    /**
     * Путь в конфиге где произошла ошибка
     * 
     * @var string
     */
    private string $configPath;

    /**
     * Итерация на которой произошла ошибка (null если одиночный режим)
     * 
     * @var int|null
     */
    private ?int $iteration;

    /**
     * Контекст ошибки: class/method/resolved_params для отладки.
     * Заполняется при ошибках вызовов методов, пишется в лог без обрезания.
     * 
     * @var array|null
     */
    private ?array $errorContext;

    /**
     * Execution constructor.
     * 
     * @param string $message Текст ошибки
     * @param string $configPath Путь в конфиге
     * @param int|null $iteration Индекс итерации
     * @param int $code Код ошибки
     * @param \Throwable|null $previous Предыдущее исключение
     * @param array|null $errorContext Контекст ошибки (class/method/resolved_params)
     */
    public function __construct(
        string $message,
        string $configPath = '',
        ?int $iteration = null,
        int $code = 0,
        ?\Throwable $previous = null,
        ?array $errorContext = null
    ) {
        $this->configPath = $configPath;
        $this->iteration = $iteration;
        $this->errorContext = $errorContext;

        // Формируем полное сообщение
        $fullMessage = "Ошибка выполнения";

        if ($configPath !== '') {
            $fullMessage .= " в '{$configPath}'";
        }

        if ($iteration !== null) {
            $fullMessage .= " (итерация #{$iteration})";
        }

        $fullMessage .= ": {$message}";

        parent::__construct($fullMessage, $code, $previous);
    }

    /**
     * Возвращает путь в конфиге
     * 
     * @return string
     */
    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    /**
     * Возвращает индекс итерации
     * 
     * @return int|null
     */
    public function getIteration(): ?int
    {
        return $this->iteration;
    }

    /**
     * Возвращает контекст ошибки (class/method/resolved_params)
     * 
     * @return array|null
     */
    public function getErrorContext(): ?array
    {
        return $this->errorContext;
    }
}
