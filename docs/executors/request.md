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
  справочники не засоряют лог;
- если метод **нестатический** и конструктор принимает аргументы —
  укажите `construct`, см. [Построение экземпляров](#построение-экземпляров-construct-и-new-v160).

---

## extra — вычисляемые поля

Поддерживаются четыре формы выражений и флаги:

### 1. Вызов метода / функции

```php
'client_id' => [
    'method'  => 'explode',
    'params'  => ['_', 'field:client_string'],
    'element' => 1,              // опционально: взять элемент результата
],
```

При вызове метода класса (`method` + `class`) можно указать
`construct` для аргументов конструктора и вложенных экземпляров —
см. [Построение экземпляров](#построение-экземпляров-construct-и-new-v160).

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

### Флаг `no_log` — исключить результат из лога

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
Подробности — в [Логировании](../components/logging.md).

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
  - применяется к каждой строке, если результат — список;
- **`'mapping'` в params** подставляет весь собранный mapping-массив;
- **`construct`** (v1.6.0): аргументы конструктора и вложенные экземпляры —
  см. [Построение экземпляров](#построение-экземпляров-construct-и-new-v160).

---

## Построение экземпляров: construct и new (v1.6.0)

При вызове нестатического метода (`method` + `class`) интерпретатор
создаёт экземпляр класса. Если конструктор принимает аргументы —
опишите их в `construct`:

```php
'HL' => [
    'method'    => 'getList',
    'class'     => \Api\Highload\Main::class,
    'construct' => ['LogApiCRM'],   // <- аргументы конструктора
    'params'    => [[
        'filter' => ['UF_NAME' => 'field:name'],
    ]],
],
```

### Разрешение аргументов

Элементы `construct` резолвятся по тем же правилам, что `params`:

- `field:имя_поля` и `{{ }}` — значения из контекста;
- скаляры — литералы;
- массивы — рекурсивно (`field:` разрешаются внутри);
- `'mapping'` — весь собранный mapping-массив;
- массив с ключом `new` — рекурсивное построение экземпляра (см. ниже).

### Где работает construct

Ключ поддерживается во всех местах, где есть вызов `method` + `class`:

- `request.query` (основное применение);
- `request.extra` — вычисляемые поля через метод;
- `request.static` — нестатические методы загрузки справочников;
- `execute.actions` — методы действий.

Для статических методов `construct` игнорируется (экземпляр не создаётся).

### Вложенные экземпляры через new

Массив с ключом `new` строит экземпляр рекурсивно. Глубина не
ограничена, порядок построения — изнутри наружу (как в PHP):

```php
'REPORT' => [
    'method' => 'generate',
    'class'  => \DesktopManager\Report\ReportBuilder::class,

    'construct' => [
        ['format' => 'pdf', 'locale' => 'ru'],   // обычный массив-аргумент

        [   // уровень 2: экземпляр DataProvider
            'new' => \DesktopManager\Report\DataProvider::class,
            'construct' => [
                [   // уровень 3: экземпляр HighloadMain
                    'new' => \Api\Highload\Main::class,
                    'construct' => ['LogApiCRM'],
                ],
                [   // уровень 3: экземпляр RedisCache
                    'new' => \DesktopManager\Cache\RedisCache::class,
                    'construct' => [
                        [   // уровень 4: экземпляр RedisClient
                            'new' => \DesktopManager\Cache\RedisClient::class,
                            'construct' => ['field:params.redis_host', 6379],
                        ],
                        3600,   // обычный скаляр
                    ],
                ],
            ],
        ],
    ],

    'params' => ['field:tasks'],
],
```

Соответствующий PHP-код:

```php
$instance = new ReportBuilder(
    ['format' => 'pdf', 'locale' => 'ru'],
    new DataProvider(
        new HighloadMain('LogApiCRM'),
        new RedisCache(
            new RedisClient($params['redis_host'], 6379),
            3600
        )
    )
);
$result = $instance->generate($tasks);
```

### Диагностика и лог

- **Без `construct` + обязательные параметры конструктора** — читаемая
  ошибка со списком параметров и подсказкой добавить `construct`
  (вместо непонятного `ArgumentCountError`):

```text
Класс Api\Highload\Main имеет конструктор с обязательными параметрами
($hlBlockName) — укажите 'construct' => [...] в конфигурации вызова.
```

- **При падении в `error_context`** — вся цепочка построения:
  класс и разрешённые аргументы каждого уровня. См.
  [Логирование](../components/logging.md);
- **обратная совместимость**: классы без обязательных параметров
  конструктора работают без `construct` как раньше (`new Class()`).

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
- [Построение экземпляров](#построение-экземпляров-construct-и-new-v160) — `construct` и `new`
