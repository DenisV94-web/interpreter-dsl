# Interpreter DSL

**Декларативный интерпретатор конфигураций API-эндпоинтов для Битрикс24.**

Поведение эндпоинта описывается массивом-конфигом, а не PHP-кодом в контроллере:
один движок выполняет запросы, маппинг, условия, итерации, cURL-вызовы
и сборку ответа — с единым логированием и обработкой ошибок.
Конфиг — это данные: его можно хранить в highload-блоке, версионировать,
ревьюить и менять без выкладки кода.

> 📖 Полная документация — в папке [`docs/`](docs/) и на GitHub Pages:
> [`https://denisv94-web.github.io/interpreter-dsl/`](https://denisv94-web.github.io/interpreter-dsl/).
> 🗓 История изменений — [`docs/changelog.md`](docs/changelog.md).

---

## Зачем это нужно

| Проблема в обычном коде                                         | Решение в интерпретаторе                               |
| --------------------------------------------------------------- | ------------------------------------------------------ |
| Каждый эндпоинт — свой контроллер со своим curl/try-catch/логом | Один движок, эндпоинт = конфиг                         |
| Логирование везде разное и пишется вручную                      | Единый формат лога: `data + params + computed + error` |
| Ошибки обрабатываются кто во что горазд                         | `on_error`, режимы `partial` / `all_or_nothing`        |
| Тесты требуют БД, сети и Битрикс                                | 276 unit-тестов на моках, без сети и БД                |
| Новый разработчик читает 10 разных файлов                       | Читает одну документацию по DSL                        |

---

## Ключевые возможности

### Язык выражений

- **`field:a.b.c`** — точечная нотация любой глубины; цепочки
  `field:x|field:y` — первое не-null значение;
- **шаблоны** `{{field:x}}`, шорткаты `{{LEAD.NAME}}`, `null → ''` в тексте;
- **динамические ключи** (v1.7.0): `field:pr_hl[field:index_code]`,
  `field:m[field:r][field:c]`;
- **рекурсивный резолв** (v1.17.0): значения в контексте сами могут быть
  выражениями (`field:`, `date:`, `literal:`) — резолвятся до конца;
- **date-выражения** (v1.17.0): `date:"+1 day"`,
  `date:"@field:appointment_at +2 hours; d.m.Y H:i"`;
- **формулы** (v1.18.0–1.19.0): `field:x++`, `field:price * 1.18`,
  `formula:(field:a + field:b) * 2` — приоритеты, скобки, унарные `+`/`-`;
- **`literal:`** — экранирование текста, который начинается с префикса,
  но вычисляться не должен;
- **`func:` / `method:Class->m` / `result:key`** — ссылки для условий
  и результат последнего вызова.

### Условия

- равенства, отрицание `!`, проверки `func:empty` / `func:is_numeric`,
  `logic: AND|OR`, вложенность;
- операторы Битрикс (v1.15.0): `<=`, `>=`, `>`, `<`, `%` (LIKE),
  `@` (IN), `!@` (NOT IN), `!=`;
- условия из данных (v1.15.0): `conditions` принимает строку — ссылку
  на массив в контексте (декодированный `UF_CONDITIONS` HL-блока).

### Блоки конфигурации

- **`request`**: `main`, `static` (справочники), `extra` (вычисляемые поля),
  `query` (методы классов), `curl` (внешние API);
  `request_logic` — произвольный порядок шагов;
  итерации через `request.array`;
- **`mapping`**: одиночный режим и именованные списочные маппинги;
- **`execute`**: `if / elseif / else`, `switch` (v1.9.0),
  вложенные execute любой глубины (v1.10.0), `set` — запись в контекст
  (v1.12.0), `skip`, бизнес-ошибки `error` (v1.16.2),
  `merge_response` — слияние response (v1.16.0);
- **`compose`**: финальная сборка ответа любой вложенности,
  корневое выражение без обёртки;
- **`params`**: служебные параметры запуска, доступны везде
  как `field:params.x` и `{{params.x}}`.

### Сервисы

- **`ArrayFilter`** (v1.8.0) — фильтрация списков по фильтру в стиле
  Битрикс (`=`, `>`, `<=`, `%`, `@`, `!@`);
- **`ArrayTransformer`**:
  - `toSelectOptions` — списки для select/select2, трансформации
    `fn`+`args`, item-шаблоны `{{item:X}}` (v1.19.1);
  - `toMap` (v1.20.0) — список в карту «ключ → значение»;
  - `applySchema` (v1.21.0) — схема отображения строки:
    `title`, `variant` (словари подмены), `format` (даты), `blank`;
  - **query в applySchema** (v1.22.0) — декларативные запросы к внешним
    сервисам с батчингом: `@batch` собирает уникальные ID, один запрос
    с `IN` на поле вместо N запросов;
  - `applyInstructions` (v1.14.0) — инструкции изменения полей
    (`FIELD++`, `field:x`, литералы);
  - `renameKeys` — переименование ключей.

### Инфраструктура

- **логирование**: единый формат `data + params + computed + error`,
  двухстрочный контракт (v1.5.0); флаг `logging` на действие;
  `no_log` (v1.3.0) — исключение полей из лога;
- **error_context** (v1.4.0): class/method/resolved_params упавшего вызова —
  сразу видно, какой аргумент убил метод;
- **предохранители лога**: `max_log_size`, `snapshot_mode`
  (`all` / `errors_only` / `first_last`), `log_response`;
- **обработка ошибок**: `on_error` на вызов, транзакции
  `partial` / `all_or_nothing`;
- **construct** (v1.6.0) — декларативные аргументы конструктора,
  включая рекурсивное построение вложенных экземпляров.

---

## Требования

| Окружение                 | Что нужно                                                                         |
| ------------------------- | --------------------------------------------------------------------------------- |
| **Автономно (ПК, CI)**    | PHP **8.1+** (CLI). Больше ничего: тесты идут без Битрикс, Git и БД               |
| **Продакшен (Битрикс24)** | Битрикс24, корпоративные `Logger` / `ResponseHandler` подставляются автозагрузкой |

Проверено на: PHP 8.3, Битрикс24 26.150.0.

---

## Быстрый старт

### 1. Клонируйте репозиторий

```bash
git clone https://github.com/DenisV94-web/interpreter-dsl.git
cd interpreter-dsl
```

### 2. Запустите тесты (автономно, без Битрикс)

```bash
php tests/run_interpreter_tests.php
```

### 3. Минимальный пример

Полностью автономный: без Битрикс, сети и БД.

```php
<?php

require_once __DIR__ . '/src/autoload.php';

use Api\Services\Actions\Interpreter;

$config = [
    'greeting' => [
        'request' => [
            'main' => 'post',
            'extra' => [
                // шаблон: склейка полей
                'full_name' => '{{field:last_name}} {{field:first_name}}',
                // формула: арифметика над полями (автодетект " - ")
                'calls_left' => 'field:max_calls - field:call_count',
                // date-выражение: now + 1 день в заданном формате
                'deadline' => 'date:"+1 day; d.m.Y"',
            ],
        ],
        'execute' => [
            [
                'check' => 'if',
                // оператор Битрикса: call_count <= max_calls
                'filter' => ['<=field:call_count' => 'field:max_calls'],
                'actions' => [
                    ['response' => [
                        'message' => '{{field:full_name}}: осталось {{field:calls_left}} звонков, дедлайн {{field:deadline}}',
                    ]],
                ],
            ],
            [
                'check' => 'else',
                'actions' => [
                    ['response' => ['message' => '{{field:full_name}}: лимит звонков исчерпан']],
                ],
            ],
        ],
    ],
];

$interpreter = new Interpreter($config);
$interpreter->setTestMode([
    'last_name'  => 'Иванов',
    'first_name' => 'Иван',
    'call_count' => 1,
    'max_calls'  => 4,
]);

$response = $interpreter->run('greeting', 'readme');
print_r($response->response);
// [ ['message' => 'Иванов Иван: осталось 3 звонков, дедлайн 16.09.2026'] ]
```

### 4. В Битрикс24

Подключите автозагрузчик проекта и корпоративные `Logger` /
`ResponseHandler` — движок подхватит их без правок конфига:

```php
$interpreter = new Interpreter($config);
$interpreter->setLogger(new \Api\Services\Logger([
    'external_system' => $externalSystemId,
]));
$response = $interpreter->run('save_card', 'desktop_manager', null, $params);
```

---

## Примеры боевых паттернов

### Фильтрация списка (ArrayFilter)

```php
'filtered_tasks' => [
    'method' => 'filter',
    'class'  => \Api\Services\ArrayFilter::class,
    'params' => [
        'field:tasks',
        ['STATUS' => 'NEW', '>PRIORITY' => 5],
    ],
],
```

### Схема отображения с батчингом запросов (applySchema + query)

```php
'detail_view' => [
    'method' => 'applySchema',
    'class'  => \Api\Services\ArrayTransformer::class,
    'params' => [
        'field:attempts',
        [
            'manager_id' => [
                'title' => 'Менеджер',
                'query' => [
                    'method' => 'getList',
                    'class'  => \Api\User\Main::class,
                    'params' => [[
                        'filter' => ['ID' => '@batch'],   // один запрос с IN на все строки
                        'select' => ['ID', 'NAME'],
                    ]],
                    'key_field'   => 'ID',
                    'value_field' => 'NAME',
                ],
            ],
            'primary_result_code' => [
                'title'   => 'Результат',
                'variant' => 'field:primary_result_codes',  // карта из toMap
            ],
        ],
        ['blank' => [null, '', '0000-00-00 00:00:00']],
    ],
],
```

### Формулы и даты в execute

```php
'execute' => [
    ['set' => ['total' => 'field:price * field:quantity * 1.18']],
    ['set' => ['next_call' => 'date:"+2 hours; d.m.Y H:i"']],
    [
        'check' => 'if',
        'filter' => ['field:total' => 0],
        'actions' => [['error' => 'Сумма не может быть нулевой']],
    ],
],
```

---

## Архитектура

```
┌───────────────────────────────────────────────┐
│                 Interpreter                   │
│   оркестрация action_logic, валидация,        │
│   транзакции, логирование                     │
└──────────────────────┬────────────────────────┘
          ┌────────────┼────────────┐
     ┌────▼───┐   ┌────▼───┐   ┌────▼────┐
     │Request │   │Mapping │   │ Execute │──► Compose
     │        │   │        │   │         │
     └────┬───┘   └────┬───┘   └────┬────┘
          ────────────┼────────────┘
              ┌────────▼────────┐
              │    Resolvers    │
              │ Field, Condition│
              │ Method, Formula │
              └────────┬────────┘
              ┌────────▼────────┐
              │    Services     │
              │ ArrayFilter,    │
              │ ArrayTransformer│
              └─────────────────┘
```

---

## Тестирование

276 тестов, все на моках — без сети, БД и Битрикс:

| Сюита                     | Тестов |
| ------------------------- | ------ |
| Field Resolver            | 52     |
| Condition Resolver        | 14     |
| Method Resolver           | 23     |
| Formula Resolver          | 23     |
| Request Executor          | 21     |
| Mapping Executor          | 13     |
| Execute Executor          | 7      |
| Interpreter (integr.)     | 69     |
| Curl Executor             | 11     |
| Compose Executor          | 3      |
| Array Filter Service      | 14     |
| Array Transformer Service | 26     |

Запуск: `php tests/run_interpreter_tests.php`; логи прогонов —
`tests/logs/`. Раннер двухрежимный: с Битрикс и автономно.

---

## Версионирование

[SemVer](https://semver.org/lang/ru/) + [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/):
`MAJOR` ломает конфиги, `MINOR` — новая возможность, `PATCH` — исправления.
Каждая возможность DSL попадает в [`docs/changelog.md`](docs/changelog.md)
и в документацию в том же коммите.

---

## Документация

- [Язык выражений (Field Resolver)](docs/language/field.md)
- [Условия (Condition Resolver)](docs/language/conditions.md)
- [Блок request](docs/executors/request.md)
- [Блок execute](docs/executors/execute.md)
- [ArrayFilter](docs/components/array-filter.md)
- [ArrayTransformer](docs/components/array-transformer.md)
- [Changelog](docs/changelog.md)

---

## Вклад в проект

1. Fork и ветка от `main`: `git checkout -b feature/my-feature`;
2. Новая возможность DSL = тесты + секция в `docs/changelog.md`
   - страница/раздел в `docs/` в том же коммите;
3. Прогоните `php tests/run_interpreter_tests.php` — всё зелёное;
4. Pull Request с описанием кейса и примера конфига.
