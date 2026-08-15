# Контекст и params

`Context` — состояние одного запуска `run()`. Все блоки читают
и пишут данные только через него.

---

## Зоны данных

| Зона                 | Что хранит                                                      | Откуда               | В логе               |
| -------------------- | --------------------------------------------------------------- | -------------------- | -------------------- |
| rawRequest           | сырые входные данные                                            | 3-й аргумент `run()` | `data`               |
| params               | служебные параметры запуска                                     | 4-й аргумент `run()` | `params`             |
| computed             | всё вычисленное (extra, query, curl, mapping, static-доступные) | блоки                | `computed`           |
| currentIterationData | данные текущей итерации                                         | `resetForIteration`  | снимок `iteration_N` |
| response             | записи ответа                                                   | execute/compose      | —                    |

`static`-ключи доступны через `field:`, но в `computed` **не попадают**
(помечены как статические).

---

## Чтение и запись

```php
$this->context->set('CLIENT', $row);          // запись
$this->context->get('CLIENT.NAME');           // чтение с точечной нотацией
$this->context->get('missing');               // → null
```

Точечная нотация в `get`/`set` — любая глубина.

---

## params

Служебные параметры запуска — auth, токены, URL внешних API:

```php
$interpreter->run('get_static', 'desktop_manager', $inputData, $params);
```

Доступны **во всех блоках** и переживают сброс итераций:

```php
'field:params.auth'                 // значение
'{{params.api_base_url}}/get'       // внутри шаблона
'Authorization: {{params.api_token}}'
```

Отличие от входных данных: `params` — инфраструктура,
`rawRequest` — бизнес-данные запроса. В логе они разведены
по разным ключам.

---

## Итерации

В режиме `request.array` Interpreter для каждой строки вызывает:

```text
resetForIteration($iterationData, $index)
 ├─ очишает данные прошлой итерации
 ├─ сохраняет общие (вне массива) и params
 └─ iterationIndex = $index
```

После шагов итерации:

- `snapshotIteration()` — копия computed уходит в лог как
  `computed.iteration_N` (видно, на чём упала/прошла итерация);
- `recordIterationError(...)` — ошибка итерации фиксируется
  отдельно и попадает в `error.iteration_errors`.

`iterationTotal` и `iterationIndex` доступны блокам
(например, для `SKIPPED`-записи).

---

## Ошибки

```php
$this->context->setError('request.query.LEAD', 'Запрос не выполнен', $details);
$this->context->hasError();   // bool — блоки останавливаются
```

- `setError` фиксирует путь в конфиге, сообщение и детали;
- после ошибки последующие шаги `request` и блоки не выполняются;
- в `partial`-режиме ошибка итерации изолируется: Interpreter
  пишет запись `ERROR` в ответ и продолжает цикл.

---

## lastResult

После каждого вызова метода (`extra`/`query`/`execute`) контекст
сохраняет результат — он доступен как `result` / `result:key`
в `response` и выражениях.

---

## Куда дальше

- [Логирование](logging.md) — что именно пишется в лог из контекста
- [Выражения](../language/field.md) — что можно читать из контекста
