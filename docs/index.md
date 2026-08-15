# Interpreter DSL

**Декларативный интерпретатор конфигураций API-эндпоинтов для Битрикс24.**

Поведение эндпоинта описывается массивом-конфигом, а не PHP-кодом
в контроллере. Один движок выполняет запросы, маппинг, условия,
итерации, cURL-вызовы и сборку ответа — с единым логированием
и обработкой ошибок.

---

## Почему DSL, а не прямой PHP

| Проблема в прямом коде                                          | Решение в интерпретаторе                               |
| --------------------------------------------------------------- | ------------------------------------------------------ |
| Каждый эндпоинт — свой контроллер со своим curl/try-catch/логом | Один движок, эндпоинт = конфиг                         |
| Логирование пишется вручную и по-разному                        | Единый формат лога: `data + params + computed + error` |
| Ошибки обрабатываются кто во что горазд                         | `on_error`, режимы `partial` / `all_or_nothing`        |
| Тесты требуют сети, БД и Битрикс                                | 130+ unit-тестов на моках                              |
| Новый разработчик читает 10 разных файлов                       | Одна документация по DSL                               |

---

## Быстрый старт

**1. Клонируйте репозиторий:**

```bash
git clone https://github.com/DenisV94-web/interpreter-dsl.git
cd interpreter-dsl
```

**2. Запустите тесты** (режим Standalone, без Битрикс; ожидаемо **131/131**):

```bash
php tests/run_interpreter_tests.php
```

**3. Запустите живой пример** (`demo_lead` и `demo_card`):

```bash
php examples/runner.php
```

---

## Минимальный конфиг

```php
'demo_lead' => [
    'request' => [
        'main'  => 'post',
        'array' => 'lead',
        'extra' => [
            'client_type' => [
                'method'  => 'explode',
                'params'  => ['_', 'field:client_string'],
                'element' => 0,
            ],
        ],
    ],
    'mapping' => [
        'CLIENT_NAME' => 'field:CLIENT.LAST_NAME|field:CLIENT.TITLE',
        'STATUS'      => 'NEW',
    ],
    'execute' => [
        ['check' => 'if',  'filter' => ['field:client_string' => 'func:empty'],
         'actions' => [['skip' => true]]],
        ['check' => 'else', 'actions' => [[
            'method'   => 'add',
            'class'    => \Examples\DemoService::class,
            'params'   => ['mapping'],
            'response' => ['lead_id' => 'result'],
        ]]],
    ],
    'action_logic' => ['request', 'mapping', 'execute'],
],
```

Запуск: `$interpreter->run('demo_lead', 'examples', $inputData, $params)`.

---

## Навигация по разделам

| Раздел                                                        | О чём                                                       |
| ------------------------------------------------------------- | ----------------------------------------------------------- |
| [Быстрый старт: установка](getting-started/installation.md)   | Требования, установка на ПК, запуск на сервере              |
| [Быстрый старт: первый запуск](getting-started/quickstart.md) | Первый конфиг за 5 минут                                    |
| [Язык DSL: обзор](language/index.md)                          | Из чего состоит конфиг, порядок выполнения                  |
| [Выражения](language/field.md)                                | `field:`, цепочки `\|`, шаблоны `{{ }}`, `func:`, `result:` |
| [Условия](language/conditions.md)                             | Равенства, `!`, `func:*`, `method:*`, AND/OR, вложенность   |
| [Блоки: обзор](executors/index.md)                            | `request`, `mapping`, `execute`, `compose` и `action_logic` |
| [request](executors/request.md)                               | `main`, `static`, `extra`, `query`, `curl`, `request_logic` |
| [mapping](executors/mapping.md)                               | Одиночный режим, списочные маппинги, вложенные массивы      |
| [execute](executors/execute.md)                               | if/elseif/else, skip, response-only, on_error               |
| [compose](executors/compose.md)                               | Финальная сборка, корневое выражение                        |
| [Архитектура](components/architecture.md)                     | Компоненты и их связи                                       |
| [Контекст и params](components/context.md)                    | Данные, params, снимки итераций                             |
| [Логирование](components/logging.md)                          | Формат лога, флаг `logging`, `error_response`               |
| [Примеры](examples/demo.md)                                   | Разбор `demo_lead` и `demo_card`                            |
| [Шаблон страницы фичи](extending/doc-template.md)             | Как документировать новые возможности                       |
| [История изменений](changelog.md)                             | Changelog по версиям                                        |

---

## Возможности одной таблицей

| Компонент   | Возможность                                                     | Версия |
| ----------- | --------------------------------------------------------------- | ------ |
| Field       | `field:`, цепочки `\|`, `func:`, `method:Class->/::`, `result:` | 1.0    |
| Field       | шаблоны `{{ }}`, шорткаты `{{LEAD.X}}`, null → `''` в тексте    | 1.0    |
| Method      | `mapping`-плейсхолдер, `element` (вкл. `0.NAME`), `on_error`    | 1.0    |
| Method      | `change_values` (`self`, `name`, списки)                        | 1.0    |
| Request     | `static` (массивы + `Class::method`)                            | 1.1    |
| Request     | `curl` (GET/POST/PUT/DELETE/REST, conditions, on_error)         | 1.1    |
| Request     | `request_logic` — произвольный порядок шагов                    | 1.2    |
| Request     | `extra`: массивы резолвятся рекурсивно                          | 1.2    |
| Mapping     | именованные списочные маппинги, source без mapping              | 1.1    |
| Execute     | response-only действия (ошибка с task_id без method)            | 1.2    |
| Compose     | корневое выражение (`'compose' => 'field:x'`)                   | 1.2    |
| Interpreter | `params` (4-й аргумент run), доступны везде                     | 1.1    |
| Interpreter | флаг `logging` на действие                                      | 1.2    |
| Interpreter | `error_response` — свои поля в ERROR-записи                     | 1.2    |

Текущая версия: **1.2.0** (2026-08-14).

---

> **Правило проекта.** Каждая новая возможность DSL попадает
> в [changelog](changelog.md) и получает страницу (или раздел страницы)
> в документации **в том же коммите**. Фича без документации считается
> недоделанной.

---

## Лицензия

MIT — см. файл `LICENSE` в корне репозитория.
