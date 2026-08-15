# Блок mapping

`mapping` собирает из контекста поля сущности (или списков) —
то, что дальше пойдёт в `execute` (`'params' => ['mapping']`)
и/или в `compose`.

```php
'mapping' => [
    'UF_UNIFIED_CLIENT_ID' => 'field:unified_client_id',
    'STATUS_ID'            => 'UC_MTFIW2',                 // литерал
    'FM' => [                                              // вложенность
        'PHONE' => ['n0' => ['VALUE_TYPE' => 'WORK',
                             'VALUE' => 'field:VALID_PHONE']],
    ],
],
```

---

## Одиночный режим

- каждое значение — выражение: `field:`, цепочка `|`, шаблон `{{ }}`,
  литерал (см. [Выражения](../language/field.md));
- вложенные массивы резолвятся **рекурсивно** — так собираются
  множественные поля Битрикс (`FM.PHONE.n0.VALUE`);
- результат пишется в контекст: в `execute` доступно
  `field:FM.PHONE.n0.VALUE`;
- весь собранный массив доступен как плейсхолдер `'mapping'`
  в `params` методов:

```php
'method' => 'add',
'class'  => \Api\Lead\Main::class,
'params' => ['mapping'],
```

- `null`-значения сохраняются как `null` (поле есть, значение пустое).

---

## Именованные списочные маппинги

Элемент mapping-массива с ключами `source` (+ опционально `mapping`)
обрабатывает **список** и пишет результат в контекст под своим именем.

### source + mapping: трансформация каждой строки

```php
'mapping' => [
    'mapped_tasks' => [
        'source'  => 'tasks',                 // список из контекста
        'mapping' => [
            'id'    => 'field:tasks.ID',
            'title' => 'field:tasks.TITLE',
        ],
    ],
],
```

При итерации по строкам `tasks` путь `tasks` в выражениях указывает
на **текущую строку**. Результат:

```json
"mapped_tasks": [
    { "id": 1, "title": "Позвонить клиенту" },
    { "id": 2, "title": "Подготовить КП" }
]
```

Пишется в контекст как `field:mapped_tasks` — обычно для `compose`.

### source без mapping: сырой список

```php
'mapped_tasks_new' => [
    'source' => 'tabs_new',          // или с путём: 'tabs.data.data'
],
```

Список копируется как есть (путь может быть многоуровневым).

---

## Совмещение режимов

В одном блоке могут жить оба режима одновременно:

```php
'mapping' => [
    // одиночные поля
    'UF_DEALER_CENTER_ID' => 'field:dealer_center_id',
    'LAST_NAME'           => 'field:CONTACT.LAST_NAME',

    // списочные
    'mapped_tasks' => ['source' => 'tasks', 'mapping' => [...]],
    'raw_rows'     => ['source' => 'rows.data'],
],
```

Одиночные поля попадают в `'mapping'`-плейсхолдер;
списочные — только в контекст под своими именами.

---

## Где берётся и куда идёт

```text
request (extra/query/curl)  →  контекст
                                    ↓
                              mapping (сборка)
                                    ↓
              контекст: field:FM..., field:mapped_tasks...
                                    ↓
                 execute ('mapping' в params)  /  compose
```

---

## Куда дальше

- [execute](execute.md) — как использовать собранный mapping
- [compose](compose.md) — финальная сборка ответа
