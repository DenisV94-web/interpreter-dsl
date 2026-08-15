# Блок compose

`compose` — финальная сборка ответа клиенту. Выполняется последним
(если указан в `action_logic`). Читает контекст, пишет в `response`.

В отличие от `mapping`, `compose` не собирает сущность для методов —
он формирует **форму ответа** любой вложенности.

---

## Режим 1: объект (массив ключей)

```php
'compose' => [
    'user' => [
        'id'         => 'field:current_user.ID',
        'department' => 'field:current_user.UF_DEPARTMENT',
    ],
    'brand'          => 'field:brand',
    'business_lines' => 'field:business_lines',
    'tabs' => [
        'nav-new-task' => [
            'tab-name' => 'nav-new-task',
            'tasks'    => 'field:mapped_tasks_new',
        ],
    ],
],
```

- вложенность любая — резолвится рекурсивно;
- значения — любые выражения: `field:`, цепочки, `{{ }}`, литералы;
- списки из именованных маппингов подставляются как есть
  (`field:mapped_tasks_new`).

Результат уходит в `data` ответа **объектом** (не списком).

---

## Режим 2: корневое выражение

```php
'compose' => 'field:merged_card',
```

Если `compose` — строка, она разрешается и отдаётся **как есть**,
без обёртки и без списка вокруг. Кейс: ответ внешнего API,
дополненный полями через `array_merge` в `extra`:

```php
'extra' => [
    'merged_card' => [
        'method' => 'array_merge',
        'params' => [
            'field:get_custom.data.data',     // оригинал из curl
            'field:additional_fields',        // добавленные поля
        ],
    ],
],
// ...
'compose' => 'field:merged_card',
```

---

## Что попадает в ответ

| Режим `compose`  | Форма `data`                                     |
| ---------------- | ------------------------------------------------ |
| массив ключей    | объект `{...}`                                   |
| строка-выражение | то, что вернуло выражение (объект/список/скаляр) |
| не указан        | `data` = записи `response` из `execute` (список) |

Без `compose` ответ формируется из записей `response` действий
`execute` — по записи на действие/итерацию.

---

## Типичная схема «справочники + вкладки»

```php
'action_logic' => ['request', 'mapping', 'compose'],

// request: static (словари) + query (юзер) + curl (вкладки)
// mapping: именованные списочные маппинги строк вкладок
// compose: итоговый объект дашборда
```

Полный живой пример — в [Примерах](../examples/demo.md#demo_card).

---

## Куда дальше

- [Примеры: demo_lead и demo_card](../examples/demo.md)
- [Контекст и params](../components/context.md)
