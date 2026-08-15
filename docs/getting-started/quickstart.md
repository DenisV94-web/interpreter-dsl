# Первый запуск: конфиг за 5 минут

Соберём минимальный сквозной пример:
**входные данные → вычисляемые поля → маппинг → «создание» → ответ**.

Цель — понять четыре вещи: `request.extra`, `mapping`, `execute`
и `action_logic`. Всё остальное — надстройки над этой базой.

---

## Шаг 1. Создай файл `quickstart.php` в корне репозитория

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/tests/bootstrap.php';
require_once __DIR__ . '/examples/DemoService.php';

use Api\Services\Actions\Interpreter;

$config = [
    'quickstart_demo' => [
        // --------------------------------------------------
        // 1. REQUEST: входные данные + вычисляемые поля
        // --------------------------------------------------
        'request' => [
            'main' => 'post',
            'extra' => [
                // Шаблон {{ }} с шорткатами: склеиваем ФИО
                'full_name' => '{{last_name}} {{first_name}}',
                // PHP-функция над полем контекста
                'upper_status' => [
                    'method' => 'strtoupper',
                    'params' => ['field:status'],
                ],
                // Литерал из функции
                'date_now' => ['method' => 'date', 'params' => ['d.m.Y']],
            ],
        ],

        // --------------------------------------------------
        // 2. MAPPING: собираем поля «сущности»
        // --------------------------------------------------
        'mapping' => [
            'NAME'         => 'field:full_name',
            'STATUS'       => 'field:upper_status',
            'CREATED_DATE' => 'field:date_now',
        ],

        // --------------------------------------------------
        // 3. EXECUTE: бизнес-логика (if / else)
        // --------------------------------------------------
        'execute' => [
            // 3a. Пустое ФИО → ошибка без создания
            [
                'check'   => 'if',
                'filter'  => ['field:full_name' => 'func:empty'],
                'actions' => [
                    ['response' => ['error' => 'Не указано имя клиента']],
                ],
            ],
            // 3b. Иначе → «создаём» из маппинга
            [
                'check'   => 'else',
                'actions' => [
                    [
                        'method'   => 'add',
                        'class'    => \Examples\DemoService::class,
                        'params'   => ['mapping'],
                        'response' => [
                            'lead_id' => 'result',
                            'client'  => 'field:full_name',
                        ],
                    ],
                ],
            ],
        ],

        // --------------------------------------------------
        // 4. ПОРЯДОК ВЫПОЛНЕНИЯ
        // --------------------------------------------------
        'action_logic' => ['request', 'mapping', 'execute'],
    ],
];

$interpreter = new Interpreter($config);

// Входные данные (в продакшене пришли бы из php://input)
$response = $interpreter->run('quickstart_demo', 'docs', [
    'first_name' => 'Иван',
    'last_name'  => 'Иванов',
    'status'     => 'new',
]);

echo json_encode([
    'status' => $response->status,
    'data'   => $response->response,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
```

---

## Шаг 2. Запусти

```bash
php quickstart.php
```

Результат:

```json
{
  "status": "SUCCESS",
  "data": [
    {
      "lead_id": 9001,
      "client": "Иванов Иван"
    }
  ]
}
```

Проверь ветку ошибки: убери `first_name` из входных данных —
получишь `{"error": "Не указано имя клиента"}` без создания лида.

---

## Что произошло по шагам

| Шаг | Блок       | Что сделал движок                                                                                                                            |
| --- | ---------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `request`  | Положил входные данные в контекст; вычислил `extra`: `full_name` = «Иванов Иван» (шаблон), `upper_status` = «NEW» (`strtoupper`), `date_now` |
| 2   | `mapping`  | Собрал массив сущности: `NAME`, `STATUS`, `CREATED_DATE` — каждое значение разрешено из контекста                                            |
| 3   | `execute`  | Проверил `if`: `full_name` не пуст → блок пропущен; попал в `else`: вызвал `DemoService::add(маппинг)`, вернул ID                            |
| 4   | `response` | Записал в ответ `lead_id` (результат метода) и `client` (поле контекста)                                                                     |

---

## Пять конструкций, которые ты уже использовал

| Конструкция          | Пример                         | Смысл                                              |
| -------------------- | ------------------------------ | -------------------------------------------------- |
| `field:путь`         | `field:full_name`              | Достать значение из контекста (точечная нотация)   |
| `{{путь}}`           | `{{last_name}} {{first_name}}` | Шаблон строки; шорткат без `field:`                |
| `func:имя`           | `func:empty`                   | Проверка в условии (`empty`, `is_numeric`)         |
| `'mapping'` в params | `'params' => ['mapping']`      | Подставить весь собранный маппинг одним аргументом |
| `result` в response  | `'lead_id' => 'result'`        | Результат вызванного метода                        |

Полный справочник — в разделах
[Выражения](../language/field.md) и [Условия](../language/conditions.md).

---

## Мини-упражнения

1. Добавь в `extra` поле `phone_clean`:
   `['method' => 'explode', 'params' => ['-', 'field:phone'], 'element' => 0]`
   и передай во вход `'phone' => '7999-123-45'`.
2. Добавь цепочку альтернатив в mapping:
   `'PHONE' => 'field:phone_clean|field:backup_phone'`.
3. Добавь `elseif`-блок с условием
   `['field:upper_status' => 'ARCHIVE']` и `skip`-действием.

---

## Что дальше

- [Обзор языка DSL](../language/index.md) — из чего состоит конфиг целиком
- [Блок request](../executors/request.md) — static, query, curl, request_logic
- [Примеры demo_lead / demo_card](../examples/demo.md) — итерации и compose
