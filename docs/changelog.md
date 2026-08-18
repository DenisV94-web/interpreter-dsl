# История изменений

Формат — [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/),
версионирование — [SemVer](https://semver.org/lang/ru/).

> Правило проекта: каждая новая возможность DSL попадает в этот файл
> и получает страницу (или раздел страницы) в `docs/` в том же коммите.

---

## [1.2.0] — 2026-08-14

### Добавлено

- **`request_logic`** — произвольный порядок шагов блока `request`
  (например `['extra', 'curl', 'query']`). Без ключа действует
  дефолтный порядок `static → extra → query → curl` (обратная совместимость).
- **`response`-only действия** в `execute`: действие без `method` пишет
  запись в response (кейс «ошибка валидации с `task_id` без создания лида»).
- **`error_response`** — свои поля в ERROR-записи итерации
  (например `task_id` рядом с `status/message/config_path`).
- **`logging`** — флаг на действие: `false` отключает запись в логгер
  (`get_static` не пишет в `UF_REQUEST`, `create_lead` пишет).
- **`compose` с корневым выражением**: `'compose' => 'field:x'` возвращает
  объект без обёртки и без списка вокруг.
- **`extra`: рекурсивный резолв массивов** — `field:` внутри массивов-значений
  теперь разрешаются (`resolveParams`); ветки `check_true`/`check_false`
  принимают любые выражения, включая `method`.
- **Инфраструктура автономного репозитория**: standalone-автозагрузчик
  `src/autoload.php`, `tests/bootstrap.php`, standalone-версии
  `Logger`/`ResponseHandler`, двухрежимный раннер тестов
  (Bitrix / Standalone), папка `examples/` с нейтральными примерами,
  workflow авто-сборки документации.

### Изменено

- `static`-ключи исключены из `log.computed` (справочники не логируются,
  но доступны через `field:`).

---

## [1.1.0] — 2026-08-10

### Добавлено

- **Блок `static`** в `request`: сырые массивы-справочники и загрузка
  через `'Class::method'`.
- **Блок `curl`** в `request` и curl-действия в `execute`:
  GET/POST/PUT/DELETE/REST через `ICurlLogic`, `conditions`,
  `on_error`, шаблоны `{{params.*}}` в `url` и `headers`,
  статус `SKIPPED` при ложном условии.
- **`params`** — 4-й аргумент `Interpreter::run()`: доступны во всех блоках
  как `field:params.x` и `{{params.x}}`, переживают сброс итераций,
  видны в `buildLogRequest().params`.
- **Именованные списочные маппинги** в `mapping`: элементы с `source`
  и `mapping` (трансформация каждой строки списка), а также `source`
  без `mapping` (сырой список). Результаты пишутся в контекст под своими
  именами для `compose`.
- **`buildLogRequest()`**: единая структура лога — `data` + `params` +
  `computed` (всегда, включая SUCCESS) + `error` (при ошибках);
  в итерационном режиме — снимки `iteration_N` для всех итераций.
- **Снимки итераций** в `Context` (`snapshotIteration`,
  `recordIterationError`) для полного лога итерационных прогонов.
- Конвертация `endpoint`/`actionName` из snake_case в CamelCase
  для секции и метода логгера (`Interpreter:DesktopManager / CreateLead`).

---

## [1.0.0] — 2026-08-07

Первый релиз интерпретатора.

### Язык выражений (Field Resolver)

- `field:a.b.c` — точечная нотация любой глубины;
- цепочки альтернатив `field:a|field:b` — первое найденное значение;
- `func:empty`, `func:is_numeric` — структуры для условий;
- `method:Class->method` / `method:Class::method` — ссылки на методы
  с определением `static` через Reflection;
- `result` / `result:key` — результат последнего действия;
- шаблоны `{{field:x}}`, шорткаты `{{LEAD.NAME}}`, `null → ''` в тексте,
  одиночный плейсхолдер возвращает сырое значение с типом;
- литералы и неизвестные префиксы возвращаются как есть.

### Условия (Condition Resolver)

- равенства (нестрогие), отрицание `!field:x`,
- проверки `func:*` и `method:*`,
- `logic: AND|OR`, вложенные условия.

### Методы (Method Resolver)

- PHP-функции и методы классов (Reflection, static и instance);
- `element`: индекс, ключ, многоуровневый путь `'0.CODE'`;
- `mapping`-плейсхолдер в `params` (включая вложенные массивы);
- `on_error` — fallback при исключении или `false`;
- `change_values`: `class: 'self'` (метод на самом объекте),
  `name` (недеструктивная трансформация), PHP-функции, списки,
  null-источник без падения.

### Блоки

- `request`: `main` (post/get/request), `extra` (method / conditions +
  check_true/check_false, цепочки зависимостей), `query` (conditions,
  on_error), итерационный режим `array`;
- `mapping`: одиночный режим, вложенные массивы (множественные поля
  Битрикс) с рекурсивным резолвом;
- `execute`: `if / elseif / else`, `skip`, условия на отдельных actions,
  `response` с `result` / `result:key` / `field:`.

### Интерпретатор

- оркестрация `action_logic`, валидация конфига (`ConfigException`
  с путём в конфиге), `ExecutionException`;
- транзакции: `partial` (итерационная ошибка не прерывает цикл,
  запись `ERROR` в response[i]) и `all_or_nothing`;
- `ResponseHandler` с единой формой ответа
  `{status: {code, message}, data, global}`.

### Тестирование

- `TestLogger` (файловые логи с временными метками и JSON-данными);
- тест-классы: Field, Condition, Method, Request, Mapping, Execute,
  Interpreter; раннер `local/tests/run_interpreter_tests.php`.

---

## Как вести changelog

1. Новая возможность → секция `### Добавлено` будущей версии.
2. Изменение поведения → `### Изменено`, поломка совместимости →
   `### Изменено` + пометка **Breaking**.
3. Версии: `MAJOR` — ломает конфиги, `MINOR` — новая возможность,
   `PATCH` — исправления.
4. Дата — день слияния в `main`.

## [1.3.0] — 2026-08-16

### Добавлено

- **`no_log`** — флаг на `extra`-поле: значение остаётся доступным
  через `field:` во всех блоках, но исключается из `log.computed`
  и снимков итераций. Кейс — обогащённые списки (`enrichTasks`),
  которые не должны раздувать лог.
