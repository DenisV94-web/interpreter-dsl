# Блок request

`request` — входная точка эндпоинта: принимает данные, загружает
справочники, вычисляет поля, ходит в БД/CRM и внешние API.

```php
'request' => [
    'main'          => 'post',      // источник входных данных
    'array'         => 'lead',      // опционально: итерационный режим
    'request_logic' => ['extra', 'curl', 'query'],  // опционально: свой порядок

    'static' => [ /* справочники */ ],
    'extra'  => [ /* вычисляемые поля */ ],
    'query'  => [ /* вызовы методов */ ],
    'curl'   => [ /* HTTP-запросы */ ],
],
```

---

## main — источник данных

| Значение    | Откуда данные (продакшен) |
| ----------- | ------------------------- |
| `'post'`    | `$_POST`                  |
| `'get'`     | `$_GET`                   |
| `'request'` | `$_REQUEST`               |

JSON из `php://input` имеет приоритет над суперглобальными.
В Standalone-режиме данные приходят третьим аргументом `run()`.

---

## array — итерационный режим

```php
'main' => 'post',
'array' => 'lead',
```

- цикл по `$input['lead']`; каждая строка проходит все шаги;
- данные **вне** массива — общие для всех итераций;
- режим транзакции — в ключе `transaction` (`partial` / `all_or_nothing`);
- подробности про контекст и снимки — в [Контекст и params](../components/context.md).

---

## static — справочники

```php
'static' => [
    // сырой массив
    'business_lines' => ['NEW_CAR' => 'Новый автомобиль', 'SERVICE' => 'Сервис'],
    // загрузка через статический метод
    'brand' => '\App\StaticData::getBrandList',
],
```

- доступны как `field:business_lines` во всех блоках;
- **не попадают в `log.computed`** (помечаются как статические) —
  справочники не засоряют лог.

---

## extra — вычисляемые поля

Поддерживаются четыре формы выражений:

### 1. Вызов метода / функции

```php
'client_id' => [
    'method'  => 'explode',
    'params'  => ['_', 'field:client_string'],
    'element' => 1,              // опционально: взять элемент результата
],
```

### 2. Условное значение

```php
'assigned_by' => [
    'conditions'  => ['field:manager_active_flag' => 1],
    'check_true'  => 'field:recommended_manager_id',
    'check_false' => 112,
],
```

Ветки принимают любые выражения, включая `method`-вызовы.

### 3. Массив с рекурсивным резолвом

```php
'additional_fields' => [
    'unified_client_id_decrypt' => 'field:client_string',   // разрешится
    'full_name'                 => 'field:unified_client_full_name',
],
```

`field:` внутри массива разрешаются рекурсивно (`resolveParams`).

### 4. Строка-выражение

```php
'full_name' => '{{last_name}} {{first_name}}',   // шаблон с шорткатами
'status'    => 'field:raw_status',               // поле
'const'     => 'NEW',                            // литерал
```

### 5. Флаг `no_log` — исключить результат из лога

Любая форма extra-выражения может нести флаг `no_log`:

```php
'enriched_tasks' => [
    'method' => 'enrichTasks',
    'class'  => \DesktopManager\TaskEnrichmentService::class,
    'params' => ['field:tabs_new'],
    'no_log' => true,
],
```

Значение пишется в контекст и доступно через `field:` во всех блоках,
но исключается из `log.computed` и снимков итераций.
Подробности — в [Логировании](../components/logging.md#flag-no_log-dlya-extra).

### Цепочки зависимостей

Поле пишется в контекст **сразу**, поэтому следующие `extra`
видят предыдущие:

```php
'client_string' => ['method' => 'deterministicDecrypt', ...],
'client_id'     => ['method' => 'explode', 'params' => ['_', 'field:client_string'], ...],
//                                                        ^ видит предыдущее поле
```

---

## query — вызовы методов классов

```php
'LEAD' => [
    'method' => 'getList',
    'class'  => \Api\Lead\Main::class,
    'params' => [
        ['filter' => ['UF_UNIFIED_CLIENT_ID' => 'field:unified_client_id'],
         'select' => ['ID', 'DATE_CREATE'], 'limit' => 1],
    ],

    'conditions' => ['!field:client_id' => 'func:empty'],  // false → SKIPPED
    'on_error'   => false,                                  // fallback при ошибке
    'element'    => 0,                                      // взять строку результата
    'change_values' => [                                    // трансформация результата
        'DATE_CREATE' => [
            'method' => 'format', 'class' => 'self', 'params' => ['d.m.Y H:i:s'],
        ],
    ],
],
```

Ключевые моменты:

- **последовательность**: каждый следующий query видит результаты предыдущих;
- **`element`**: индекс (`0`), ключ (`'CODE'`) или путь (`'1.CODE'`);
  отсутствующий элемент → `null`;
- **`conditions`**: ложное условие → запрос не выполняется, поле = `null`;
- **`on_error`**: значение или `false` вместо исключения;
- **`change_values`**:
  - `'class' => 'self'` — метод вызывается на самом объекте значения
    (например, `DateTime::format`);
  - `'name' => 'X_FORMATTED'` — результат пишется в **новое** поле,
    исходное не трогается;
  - PHP-функции (`strtoupper`);
  - применяется к каждой строке, если результат — список.
- **`'mapping'` в params** подставляет весь собранный mapping-массив.

---

## curl — HTTP-запросы

```php
'tabs_new' => [
    'url'     => '{{params.api_base_url}}/get',
    'method'  => 'POST',                       // GET/POST/PUT/DELETE/REST
    'headers' => [
        'Authorization: {{params.api_token}}',
        'Content-Type: application/json',
    ],
    'params'  => ['table' => 'queue', 'where' => ['!id_lead' => null]],
    'timeout' => 30,

    'conditions' => ['field:need_tabs' => 1],  // false → SKIPPED
    'on_error'   => [
        'status' => ['code' => 'ERROR', 'message' => 'Не удалось получить задачи'],
        'data'   => [],
    ],
],
```

- `{{params.*}}` работают в `url` и `headers`;
- для POST/PUT тело — JSON из `params`;
- результат кладётся в контекст под именем ключа (`field:tabs_new.data.data`);
- ошибка без `on_error` → ошибка шага (останавливает последующие шаги).

---

## request_logic — произвольный порядок

```php
'request_logic' => ['curl', 'query', 'extra'],
```

- шаги выполняются в указанном порядке; `main` — всегда первым;
- допустимые имена: `static`, `extra`, `query`, `curl`;
- **без ключа** — дефолтный порядок `static → extra → query → curl`
  (обратная совместимость).

Кейс: получить данные по curl → расшифровать поле → сделать query
по расшифрованному значению.

---

## Ошибки

Ошибка любого шага (исключение без `on_error`) останавливает
**последующие шаги** блока `request`. В итерационном режиме
с `partial` останавливается только текущая итерация.

---

## Куда дальше

- [mapping](mapping.md) — что собирать из вычисленного
- [execute](execute.md) — решения и действия
- [Условия](../language/conditions.md) — синтаксис `conditions`
