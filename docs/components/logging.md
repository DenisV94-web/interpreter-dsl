# Логирование

Интерпретатор пишет один структурированный лог на запуск
(в продакшене — через корпоративный `Logger`, в тестах — `TestLogger`).
Формат единый для всех эндпоинтов.

---

## Структура записи (`buildLogRequest`)

```json
{
  "data": { "task_id": "8", "phone_hash": "..." },
  "params": { "api_base_url": "https://...", "auth": { "id": 1 } },
  "computed": {
    "client_string": "contact_555",
    "VALID_PHONE": "+79990000000",
    "iteration_0": { "...": "снимок итерации" }
  },
  "error": { "...": "только при ошибках" },
  "status": "SUCCESS"
}
```

| Ключ       | Содержимое                                                                  |
| ---------- | --------------------------------------------------------------------------- |
| `data`     | сырые входные данные (rawRequest)                                           |
| `params`   | служебные параметры запуска                                                 |
| `computed` | всё вычисленное; пишется **всегда**, включая SUCCESS                        |
| `error`    | путь в конфиге, сообщение, детали; `iteration_errors` в итерационном режиме |
| `status`   | `SUCCESS` / `ERROR`                                                         |

`static`-ключи в `computed` не попадают (справочники не логируются),
но доступны через `field:`.

---

## Флаг `no_log` для extra

Вычисленные поля с `'no_log' => true` доступны через `field:`
во всех блоках, но **не попадают** в `computed` и снимки итераций:

```php
'enriched_tasks' => [
    'method' => 'enrichTasks',
    'class'  => SomeService::class,
    'params' => ['field:tabs_new'],
    'no_log' => true,
],
```

Отличие от `static`:

| Механизм | Для чего                                                                      | Когда загружается |
| -------- | ----------------------------------------------------------------------------- | ----------------- |
| `static` | справочники (сырые массивы, `Class::method`)                                  | до `extra`        |
| `no_log` | вычисляемые поля, нужные в логике, но не в логе (большие обогащённые массивы) | в `extra`         |

Типовой кейс — обогащение списков (`enrichTasks`): результат нужен
в `mapping`/`compose`, но раздувать лог сотнями строк не должен.

---

## Итерационные снимки

Каждая итерация после выполнения снимкуется в `computed.iteration_N` —
видно, на каких данных прошла/упала конкретная строка.
Ошибки итераций собираются в `error.iteration_errors`.

Поля с `no_log` в снимки тоже не попадают (снимок строится из
`getComputedData()`, где скрытые ключи исключены).

---

## Флаг `logging`

```php
'get_static' => [
    'logging' => false,   // не логировать штатную работу
    // ...
],
```

`logging: false` глушит **только штатные** записи. Критические ошибки
(глобальные исключения) пишутся в Logger **всегда**:

| Событие                                                                               | `logging: true`        | `logging: false`   |
| ------------------------------------------------------------------------------------- | ---------------------- | ------------------ |
| Штатный результат (SUCCESS / ошибка контекста)                                        | пишется                | не пишется         |
| Ошибка итерации (`partial`)                                                           | пишется в общей записи | не пишется         |
| Критическая ошибка (`ConfigException`, `ExecutionException`, неожиданный `Throwable`) | пишется                | **пишется всегда** |

Дополнительно: при `logging: true` критическая ошибка даёт ровно одну
запись — `logResult` не дублирует `logGlobalError`.

---

## `error_response` — свои поля в ERROR-записи

```php
'error_response' => [
    'task_id' => 'field:task_id',
],
```

При ошибке итерации в ответ уходит не только `status/message/config_path`,
но и перечисленные поля — например, `task_id`, чтобы внешняя система
могла сопоставить ошибку со своей задачей.

---

## Section и method в логгере

`endpoint` и `actionName` конвертируются из snake_case в CamelCase:

```text
endpoint: desktop_manager  → section: Interpreter:DesktopManager
action:   create_lead      → method:  CreateLead
```

---

## Логи тестов (TestLogger)

Каждый тест-класс пишет собственный файл:

```text
tests/logs/Field_2026-08-15_18-14-32.log
```

Внутри — временные метки, вход/выход каждого assert и JSON-данные.
На сервере путь — `/local/local_logs/interpreter_tests/`.

---

## Умное логирование (v1.4.0)

### Error Context

При ошибке вызова метода в лог попадает `error_context` —
**class**, **method** и **resolved_params** упавшего вызова:

```json
"error": {
    "config_path": "request.query.ENTITIES",
    "message": "Ошибка выполнения запроса 'ENTITIES'",
    "detailed_message": "Argument #3 ($phone) must be of type string, null given",
    "error_context": {
        "class": "DesktopManager\\Main",
        "method": "getEntities",
        "resolved_params": ["555", "contact", null, [12]]
    }
}
```

Применяется ко всем ошибкам методов, независимо от `logging`.
Большие массивы/объекты в параметрах автоматически сокращаются
(скаляры остаются как есть).

### `max_log_size` — предохранитель от переполнения

```php
'action' => [
    'max_log_size' => 65535,  // байт, дефолт = MySQL TEXT
],
```

При превышении лог деградирует в порядке:

1. убрать `computed` (снимки итераций);
2. обрезать `data`;
3. обрезать `response`.

**Никогда не режутся:** `status`, `error`, `error_context`, `params`.
В лог добавляется флаг `_truncated` с `original_size`, `max_size`,
`reason` (`computed_removed` / `data_truncated` / `response_truncated`).

### `log_response` — запись ответа в лог

```php
'action' => [
    'log_response' => null,  // null | true | false
],
```

| Значение           | Поведение                                                |
| ------------------ | -------------------------------------------------------- |
| `null` (умолчание) | **Авто**: итерационные пишут `response`, одиночные — нет |
| `true`             | всегда писать                                            |
| `false`            | никогда не писать                                        |

Кейс авто: для CRUD (add/update/delete) обычно нужно «что пришло и что ушло»,
для GET-справочников — `response` лишний шум.

### `transaction.snapshot_mode` — снимки итераций

```php
'transaction' => [
    'mode' => 'partial',
    'snapshot_mode' => 'errors_only',  // all | errors_only | first_last
],
```

| Режим             | Что сохраняется в `computed.iteration_N` |
| ----------------- | ---------------------------------------- |
| `all` (умолчание) | все итерации (обратная совместимость)    |
| `errors_only`     | только упавшие итерации                  |
| `first_last`      | первая + последняя + ошибки              |

Комбинируется с `max_log_size`: даже при `all` переполнение не случится.

---

## Куда дальше

- [Контекст и params](context.md) — откуда берутся data/params/computed
- [execute](../executors/execute.md) — где рождаются ошибки итераций
