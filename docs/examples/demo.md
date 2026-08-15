# Примеры: demo_lead и demo_card

Два нейтральных примера в папке `examples/` покрывают почти все
возможности DSL. Запускаются на ПК без Битрикс:

```bash
php examples/runner.php
```

Разберём каждый по блокам. Исходный код конфигов вставлен
из репозитория напрямую — правки кода автоматически отражаются в документации.

---

## Исходник конфигов

```php
--8<-- "examples/Config.example.php"
```

Далее — разбор по блокам.

---

## demo_lead — итерационный режим

Имитация создания лидов из списка заявок с четырьмя сценариями.

### request

```text
main=post, array=lead
request_logic=['extra', 'query']
```

**extra** — вычисляемые поля:

| Поле          | Как считается                    | Пример значения         |
| ------------- | -------------------------------- | ----------------------- |
| `client_type` | `explode('_', client_string)[0]` | `'contact'`             |
| `client_id`   | `explode('_', client_string)[1]` | `'101'`                 |
| `date_now`    | `date('d.m.Y H:i:s')`            | `'15.08.2026 18:30:00'` |

**query** — один вызов:

```php
'CLIENT' => [
    'method'     => 'getById',
    'class'      => DemoService::class,
    'params'     => ['field:client_id'],
    'conditions' => ['!field:client_id' => 'func:empty'],
]
```

Ложное условие (пустой `client_id`) → запрос не выполняется,
поле = `null`.

### mapping

```php
'CLIENT_NAME' => 'field:CLIENT.LAST_NAME|field:CLIENT.TITLE',
'PHONE'       => 'field:CLIENT.PHONE|field:backup_phone',
'FULL_NAME'   => '{{CLIENT.LAST_NAME}} {{CLIENT.NAME}}',
'CLIENT_TYPE' => 'field:client_type',
'STATUS'      => 'NEW',
```

Показаны три формы: цепочка `|`, шаблон `{{ }}` со шорткатами, литерал.

### execute — 4 сценария в одной цепочке

| №   | Условие                        | Действие                              |
| --- | ------------------------------ | ------------------------------------- |
| 1   | `client_string` пустой         | `skip`                                |
| 2   | `isUnactive(dealer_center_id)` | `skip` (метод-условие)                |
| 3   | `client_type === 'blocked'`    | response-only (ошибка без создания)   |
| 4   | else                           | `DemoService.add(mapping)` + response |

### Ответ

```json
{
  "status": { "code": "SUCCESS", "message": "" },
  "data": [
    {
      "task_id": "1",
      "lead_id": 9001,
      "client_name": "Иванов Иван",
      "phone": "+79991002030"
    },
    { "status": "SKIPPED", "iteration": 1 },
    { "status": "SKIPPED", "iteration": 2 },
    { "task_id": "4", "error": "Клиент заблокирован: лид не создан" }
  ],
  "global": false
}
```

### Что демонстрирует

- итерации + режим `partial`;
- `request_logic` (кастомный порядок);
- метод-условие (`isUnactive`);
- response-only действие;
- `'mapping'` в `params`;
- цепочки `|` и шаблоны `{{ }}`.

---

## demo_card — одиночный режим со справочниками

### request

**static** — два справочника (сырой массив + `Class::method`);
оба не попадают в `computed` лога, но доступны через `field:`.

**query** — два запроса, второй зависит от первого:

```php
'tasks' => [
    'method' => 'getList',
    'class'  => DemoService::class,
    'params' => [['user_id' => 'field:current_user.ID']],
]
```

### mapping — именованный списочный маппинг

```php
'mapped_tasks' => [
    'source'  => 'tasks',
    'mapping' => [
        'id'    => 'field:tasks.ID',
        'title' => 'field:tasks.TITLE',
    ],
],
```

Путь `tasks.ID` указывает на текущую строку списка.

### compose — финальная сборка

```php
'compose' => [
    'user'           => ['id' => 'field:current_user.ID'],
    'brands'         => 'field:brands',
    'business_lines' => 'field:business_lines',
    'tabs'           => ['all' => ['tab-name' => 'all',
                                   'tasks' => 'field:mapped_tasks']],
],
```

### Ответ

```json
{
  "status": { "code": "SUCCESS", "message": "" },
  "data": {
    "user": { "id": 42, "name": "Демо Пользователь" },
    "brands": { "CHANGAN": "Changan", "TENET": "Tenet" },
    "business_lines": { "NEW_CAR": "Новый автомобиль", "SERVICE": "Сервис" },
    "tabs": {
      "all": {
        "tab-name": "all",
        "tasks": [
          { "id": 1, "title": "Позвонить клиенту" },
          { "id": 2, "title": "Подготовить КП" }
        ]
      }
    }
  },
  "global": false
}
```

---

## Как читать живой код

В страницах документации используется расширение `pymdownx.snippets`:
код-блок с директивой `--8<--` заменяется содержимым файла
на момент сборки. Ниже пример показан **с отступом**, чтобы
ограждения не конфликтовали:

    ```php
    --8<-- "examples/Config.example.php"
    ```

Сборка MkDocs вставляет содержимое файла на момент сборки.
Правка в `examples/` автоматически отражается в документации
(и наоборот — устаревший пример ломает `--strict`-сборку,
так как `check_paths: true`).

---

## Куда дальше

- [Блок request](../executors/request.md)
- [Блок mapping](../executors/mapping.md)
- [Блок execute](../executors/execute.md)
- [Блок compose](../executors/compose.md)
