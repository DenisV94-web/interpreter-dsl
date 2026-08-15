# Установка и требования

Интерпретатор работает в двух окружениях:

| Окружение               | Зачем                      | Что нужно                                            |
| ----------------------- | -------------------------- | ---------------------------------------------------- |
| **Standalone** (ПК, CI) | тесты, примеры, разработка | Только PHP 8.1+                                      |
| **Bitrix** (сервер)     | продакшен                  | Битрикс24 + корпоративные `Logger`/`ResponseHandler` |

> **Python не нужен.** Документация собирается GitHub Actions
> при пуше — локально ничего устанавливать не требуется.

---

## Установка на ПК (Windows)

### 1. Git

Скачай и установи [Git for Windows](https://git-scm.com/download/win).
После установки настрой переводы строк (важно для работы с сервером на Linux):

```bash
git --version
git config --global core.autocrlf true
git config --global user.name "Твоё Имя"
git config --global user.email "you@example.com"
```

### 2. PHP 8.1+ (CLI)

1. Скачай **NTS x64** zip с [windows.php.net/download](https://windows.php.net/download/)
   (например, PHP 8.3 NTS VS16 x64).
2. Распакуй в `C:\php`.
3. Добавь `C:\php` в переменную окружения **Path**:
   _Параметры системы → Дополнительно → Переменные среды → Path → Изменить → Создать_.
4. Открой **новую** консоль и проверь:

```bash
php -v
```

Для запуска тестов правка `php.ini` не нужна — всё работает из коробки.

### 3. Клонируй и проверь

```bash
git clone https://github.com/DenisV94-web/interpreter-dsl.git
cd interpreter-dsl
php tests/run_interpreter_tests.php
```

Ожидаемый вывод:

```text
Mode:            Standalone
...
TOTAL                         131       0
```

Логи прогона — в `tests/logs/`.

Живой пример работы движка:

```bash
php examples/runner.php
```

---

## Установка на сервер (Битрикс24)

### 1. Размещение файлов

| Откуда (репозиторий)              | Куда (сервер)                            |
| --------------------------------- | ---------------------------------------- |
| `src/Api/Services/Actions/`       | `/local/src/Api/Services/Actions/`       |
| `src/Api/CurlLogic.php`           | `/local/src/Api/CurlLogic.php`           |
| `tests/run_interpreter_tests.php` | `/local/tests/run_interpreter_tests.php` |

> Standalone-версии `Logger.php` и `ResponseHandler.php` на сервер
> **не копируются**: там работают корпоративные классы с теми же именами.

### 2. Автозагрузчик

В `/local/php_interface/init.php` (или в своём автозагрузчике)
должна быть регистрация PSR-4 для пространства `Api\`:

```php
spl_autoload_register(function (string $class): void {
    $prefix  = 'Api\\';
    $baseDir = $_SERVER['DOCUMENT_ROOT'] . '/local/src/Api/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
```

### 3. Запуск тестов на сервере

```bash
cd /home/bitrix/www
php local/tests/run_interpreter_tests.php
```

Ожидаемый вывод:

```text
Mode:            Bitrix
Bitrix Version:  26.150.0
...
TOTAL                         131       0
```

Логи прогона — в `/local/local_logs/interpreter_tests/`.

Раннер сам определяет окружение: если рядом есть ядро Битрикс —
работает в режиме Bitrix, если нет — в Standalone.

---

## Если что-то пошло не так

| Симптом                                              | Причина и решение                                                                        |
| ---------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| `'php' is not recognized...`                         | `C:\php` не в Path или консоль не перезапущена                                           |
| `Constant ... already defined`                       | Старая версия `bootstrap.php` — обнови из репозитория                                    |
| `Typed property ... $logDir must not be accessed...` | Старый конструктор `TestLogger` — обнови из репозитория                                  |
| На сервере `Interface "Api\ICurlLogic" not found`    | Интерфейс и класс в одном файле: подключи `CurlLogic.php` в раннере через `require_once` |
| Тесты пишут логи не туда                             | Проверь, что `bootstrap.php` определяет `INTERPRETER_TESTS_LOG_DIR`                      |

---

## Что дальше

- [Первый запуск: собираем первый конфиг](quickstart.md)
