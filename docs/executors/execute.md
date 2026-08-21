# Блок execute

`execute` — бизнес-логика эндпоинта: ветвления, вызовы методов,
пропуски и формирование ответа. Выполняется после `mapping`.

```php
'execute' => [
    [ 'check' => 'if',     /* условие + действия */ ],
    [ 'check' => 'elseif', /* ... */ ],
    [ 'check' => 'else',   /* ... */ ],
],
```

---

## Цепочка if / elseif / else

- блоки проверяются **сверху вниз**;
- срабатывает **первый истинный** блок, остальные пропускаются;
- `else` — срабатывает, если ни один предыдущий не сработал.

Это позволяет строить исключающие сценарии:
«пропустить → обновить → вернуть ошибку → создать».

---

## Условие блока: два способа

### 1. `filter` — массив условий

```php
[
    'check'  => 'if',
    'filter' => ['field:client_string' => 'func:empty'],
    'actions' => [...],
]
```

Синтаксис — [Условия](../language/conditions.md)
(равенства, `!`, `func:*`, `AND/OR`, вложенность).

### 2. `method` — вызов метода

```php
[
    'check'  => 'elseif',
    'method' => 'isUnactive',
    'class'  => \Examples\DemoService::class,
    'params' => ['field:dealer_center_id'],
    'actions' => [...],
]
```

Результат вызова интерпретируется как булев. Удобно, когда
решение принимает код, а не сравнение полей.

---

## Действия (`actions`)

Блок содержит список действий; они выполняются по порядку.
Типы действий:

| Тип           | Как выглядит                  | Что делает                                                                 |
| ------------- | ----------------------------- | -------------------------------------------------------------------------- |
| Вызов метода  | `method` + `class` + `params` | Вызывает метод, пишет `lastResult`                                         |
| `skip`        | `['skip' => true]`            | Запись `SKIPPED` в ответ, конец блока                                      |
| response-only | только `response`             | Пишет запись в ответ без вызова метода                                     |
| curl-действие | `curl`                        | HTTP-запрос (синтаксис как в [request.curl](request.md#curl-http-запросы)) |

### Вызов метода с response

```php
[
    'method'   => 'add',
    'class'    => \Api\Lead\Main::class,
    'params'   => ['mapping'],
    'response' => [
        'task_id'    => 'field:task_id',
        'lead_id'    => 'result',              // результат метода
        'date_create'=> 'field:datetime_now',
    ],
]
```

### skip

```php
'actions' => [['skip' => true]],
```

В ответ уходит `{"status": "SKIPPED", "iteration": N}`;
в итерационном режиме цикл переходит к следующей итерации.

### response-only (без метода)

```php
'actions' => [
    [
        'response' => [
            'task_id' => 'field:task_id',
            'error'   => 'Невалидный номер телефона',
        ],
    ],
],
```

Кейс: валидация не прошла — лид создавать нельзя, но в ответе
нужна запись с `task_id` и причиной. Метод не вызывается.

### Условия на отдельное действие

```php
[
    'conditions' => ['field:do_not_contact_flag' => 1],
    'method'     => 'update',
    'class'      => \Api\Contact\Main::class,
    'params'     => ['field:CONTACT.ID', [...]],
],
```

Ложное условие — действие пропускается, следующие выполняются.

---

## Выражения в `response`

| Выражение     | Значение                         |
| ------------- | -------------------------------- |
| `'result'`    | весь результат последнего метода |
| `'result:ID'` | поле результата                  |
| `'field:x'`   | поле контекста                   |
| литерал       | как есть                         |

Каждое действие с `response` добавляет **свою запись** в ответ
(в итерационном режиме — по записи на итерацию).

---

## Полный пример цепочки

```php
'execute' => [
    // 1. Пустые обязательные поля → пропуск
    [
        'check'   => 'if',
        'filter'  => ['logic' => 'OR',
                      'field:unified_client_id' => 'func:empty',
                      'field:segment_code'      => 'func:empty'],
        'actions' => [['skip' => true]],
    ],
    // 2. Неактивный ДЦ → пропуск (method-условие)
    [
        'check'   => 'elseif',
        'method'  => 'isUnactive',
        'class'   => \Examples\DemoService::class,
        'params'  => ['field:dealer_center_id'],
        'actions' => [['skip' => true]],
    ],
    // 3. Лид уже есть → обновить
    [
        'check'   => 'elseif',
        'filter'  => ['!field:LEAD.ID' => 'func:empty'],
        'actions' => [/* update + response */],
    ],
    // 4. Невалидный телефон → ошибка с task_id, без создания
    [
        'check'   => 'elseif',
        'filter'  => ['field:VALID_PHONE' => false],
        'actions' => [[
            'response' => ['task_id' => 'field:task_id',
                           'error'   => 'Невалидный номер телефона'],
        ]],
    ],
    // 5. Иначе → создать
    [
        'check'   => 'else',
        'actions' => [/* add + response */],
    ],
],
```

---

## Ошибки

- исключение в действии без `on_error` → ошибка итерации
  (в `partial` — запись `ERROR` в ответ, цикл продолжается)
  или глобальная ошибка в одиночном режиме;
- `on_error` у метода-действия работает так же, как в query.

---

## switch — ветвление по значению (v1.9.0)

Блок `switch` сравнивает разрешённое значение выражения с ключами
`cases` и выполняет actions совпавшего case (или `default`):

```php
'execute' => [
    [
        'check' => 'switch',
        'expression' => 'field:client_type',
        'cases' => [
            'contact' => [
                'actions' => [
                    ['method' => 'update', 'class' => \Api\Contact\Main::class, 'params' => [...]],
                ],
            ],
            'company' => [
                'actions' => [ /* ... */ ],
            ],
            'default' => [
                'actions' => [ /* ... */ ],
            ],
        ],
    ],
],
```

### Семантика

- `expression` резолвится через Field Resolver (`field:`, `{{ }}`, литералы);
- case проверяются **в порядке конфига**, сравнение слабое (`==`):
  строка `'1'` совпадает с числом `1`;
- **без fall-through**: выполнился один case — switch завершён;
- `default` выполняется, если ни один case не совпал;
- ни один case не совпал и нет `default` → switch «не сработал»:
  в цепочке `if/elseif/else` управление идёт следующему `elseif`/`else`;
- совпавший case прерывает цепочку — последующие `elseif`/`else`
  не выполняются.

### Обратная совместимость

Старые блоки `if` / `elseif` / `else` (и блоки без `check`)
работают без изменений.

---

## Независимые условия и вложенность (v1.10.0)

### independent — if вне цепочки

Блок с `'independent' => true` проверяется **всегда**, независимо от
цепочки if/elseif/else/switch, и сам её не прерывает:

```php
'execute' => [
    ['check' => 'if', 'independent' => true,
     'filter' => ['field:need_log' => 1], 'actions' => [...]],
    ['check' => 'if', 'independent' => true,
     'filter' => ['field:need_notify' => 1], 'actions' => [...]],
    // цепочка ниже работает как раньше
    ['check' => 'if', 'filter' => [...], 'actions' => [...]],
    ['check' => 'else', 'actions' => [...]],
],
```

- несколько независимых if подряд — срабатывают **все** истинные;
- независимый блок срабатывает, даже если цепочка уже выполнена;
- `'check' => 'switch'` с `independent` тоже работает;
- `'else'` с `independent` — не поддерживается (warning, блок пропущен).

### Вложенность execute

Action может содержать ключ `'execute' => [...]` — вложенный блок
ветвлений. Глубина не ограничена:

```php
'actions' => [
    ['response' => ['level' => 1]],
    [
        'execute' => [                          // уровень 2
            ['check' => 'switch', 'expression' => 'field:type', 'cases' => [
                'contact' => ['actions' => [
                    ['execute' => [             // уровень 3
                        ['check' => 'if', 'filter' => ['field:vip' => 1],
                         'actions' => [...]],
                        ['check' => 'else', 'actions' => [...]],
                    ]],
                ]],
            ]],
        ],
    ],
],
```

- вложенный execute работает везде, где разрешены actions:
  в if/elseif/else, в switch-case, в другом вложенном execute;
- `conditions` на action с `execute` учитываются;
- ошибка внутри вложенного блока прерывает весь `execute`.

---

## set — запись в контекст из execute (v1.12.0)

Действие с ключом `set` записывает разрешённые значения в контекст —
так execute дополняет и перезаписывает данные, подготовленные
в `request`:

```php
'actions' => [
    ['set' => ['final_dep_id' => 'field:filtered_dep.ID|field:dep_new_check.ID']],
],
```

- выражения резолвятся как обычно: `field:`, цепочки `|`, `{{ }}`,
  литералы, массивы с `field:`;
- `conditions` на действии учитываются: условие ложно → запись
  не выполняется;
- значение доступно в следующих блоках и действиях как `field:имя`;
- `set` комбинируется с `method`/`response` в одном действии;
- действие только с `set` не считается пустым (без warning).

Кейс: цепочка запросов возвращает несколько кандидатов, а решение
«какой взять и применять ли» принимается в execute — результат
пишется в контекст и используется в update.

---

## Куда дальше

- [compose](compose.md) — финальная сборка
- [Логирование](../components/logging.md) — как ошибки попадают в лог
