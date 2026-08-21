# ArrayTransformer — трансформация списков объектов

`ArrayTransformer` — отдельный сервис для преобразования списков
объектов: перегруппировка, переименование полей, применение
PHP-функций и методов классов к отдельным полям.

Отдельный сервис рядом с `ArrayFilter` — не смешиваем «фильтрацию»
и «перегруппировку» (SRP).

---

## toSelectOptions — список опций с трансформацией

Превращает список объектов в список опций для select/select2.
Каждое поле выхода описывается **декларативно** — новые трансформации
добавляются без правок сервиса (OCP).

### Формы правил

```php
'primary_results' => [
    'method' => 'toSelectOptions',
    'class'  => \Api\Services\ArrayTransformer::class,
    'params' => [
        'field:pr_hl',
        [
            // 1. Прямая копия поля
            'value'  => 'UF_CODE',
            'label'  => 'UF_REFLECTED_VALUE',

            // 2. PHP-функция
            'showIf' => ['fn' => 'json_decode', 'args' => ['item:UF_SHOW_IF', true]],
            'code'   => ['fn' => 'strtoupper', 'args' => ['item:UF_CODE']],

            // 3. Метод класса (статический)
            'custom' => ['fn' => [\App\Utils::class, 'fmt'], 'args' => ['item:NAME']],

            // 4. Цепочка трансформаций (рекурсия)
            'full'   => [
                'fn' => 'trim',
                'args' => [['fn' => 'strtoupper', 'args' => ['item:NAME']]],
            ],
        ],
    ],
],
```

### Доступ к полям текущей строки

`item:ИМЯ_ПОЛЯ` — значение поля из текущей строки итерации.
Это внутренний префикс `ArrayTransformer` (не DSL): он **не резолвится**
Field Resolver'ом и доходит до сервиса как литерал, а сервис уже
подставляет `$item['ИМЯ_ПОЛЯ']` на каждой итерации.

> ⚠️ Не путать с `result:X` — это DSL-префикс «поле из результата
> последнего вызова метода», который резолвится **один раз до**
> передачи в сервис и для итерационных трансформаций не подходит.

### Правила маппинга

| Форма                                          | Что делает                        |
| ---------------------------------------------- | --------------------------------- |
| `'ИМЯ_ПОЛЯ'` (строка)                          | прямая копия поля как есть        |
| `['fn' => 'func', 'args' => [...]]`            | вызов PHP-функции с аргументами   |
| `['fn' => [Class, 'method'], 'args' => [...]]` | статический метод                 |
| `['fn' => 'Class::method', 'args' => [...]]`   | статический метод (сахар)         |
| массив `['fn' => ...]` внутри `args`           | вложенная трансформация (цепочка) |

### Обработка ошибок

- отсутствующее поле в источнике → `null` в результате;
- исключение из `fn`/метода → `null` в поле (весь список не падает);
- невалидный JSON в `json_decode` → `null`.

### Результат

```json
[
  {
    "value": "NO_ANSWER",
    "label": "Нет ответа",
    "showIf": { "field": "contact_occurred", "operator": "eq", "value": "0" }
  }
]
```

---

## renameKeys — переименование ключей

```php
'renamed' => [
    'method' => 'renameKeys',
    'class'  => \Api\Services\ArrayTransformer::class,
    'params' => [
        'field:items',
        ['ID' => 'id', 'NAME' => 'name'],
    ],
],
```

Ключи, не указанные в алиасах, сохраняются как есть.

---

## applyInstructions — инструкции изменения полей (v1.14.0)

Применяет инструкции из декодированного JSON-маппинга
(например, `UF_JSON_MAPPING_FIELDS` HL-блока) к текущим значениям:

```php
'lead_update' => [
    'method' => 'applyInstructions',
    'class'  => \Api\Services\ArrayTransformer::class,
    'params' => [
        'field:decoded_mapping',   // json_decode(UF_JSON_MAPPING_FIELDS, true)
        'field:params.lead',       // текущие значения лида
        'field:mapping_values',    // значения для инструкций field:*
    ],
],
```

| Инструкция         | Пример                                   | Результат                  |
| ------------------ | ---------------------------------------- | -------------------------- |
| инкремент          | `"UF_CALL_COUNT": "UF_CALL_COUNT++"`     | `(int) current + 1`        |
| значение контекста | `"UF_CALL_DATE": "field:next_action_at"` | `values['next_action_at']` |
| литерал            | `"UF_DO_NOT_CONTACT": "1"`               | `'1'`                      |

- инструкции — это **данные**, а не DSL-выражения: конфликтов с `field:` нет;
- `null`-инструкции (пустой JSON) → пустой массив;
- дальше payload дособирается через `array_merge` (например, с условными
  полями подразделения из `dep_fields`) и уходит в `execute` на update.

---

## Где живёт

- класс: `src/Api/Services/ArrayTransformer.php` (`namespace Api\Services`);
- тесты: `src/Api/Services/Actions/Testing/ArrayTransformerTest.php`;
- не зависит от интерпретатора — можно использовать в корпоративных
  сервисах напрямую.
