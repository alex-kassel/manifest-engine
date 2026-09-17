# 📋 План бескомпромиссного рефакторинга: alex-kassel/manifest-engine

> [!IMPORTANT]
> **Установка архитектурного курса:** Обратная совместимость (BC) **не требуется**. Любые неоптимальные контракты, лишние классы и промежуточные слои устраняются бесповоротно. `ManifestEngine` проектируется как высокопроизводительный, надёжный фундамент для дочерних манифестов (`workspace-manifest`) и приложений (`workspace-development-toolkit`).

---

## 1. Контекст экосистемы и распределение ответственности

Пакет является фундаментом трёхуровневой архитектуры:

```
┌─────────────────────────────────────────────────────────────┐
│ workspace-development-toolkit (Главное приложение / CLI)   │
│ Оркестрация воркспейсов, симлинки, composer, git, проверки  │
└──────────────────────────────┬──────────────────────────────┘
                               │ использует
┌──────────────────────────────▼──────────────────────────────┐
│ workspace-manifest (Доменный слой манифеста)               │
│ Доменная валидация (WorkspaceValidator), правила F-03,      │
│ WorkspaceSchema (JSON Schema Draft-07), DTO воркспейса     │
└──────────────────────────────┬──────────────────────────────┘
                               │ использует фундамент
┌──────────────────────────────▼──────────────────────────────┐
│ manifest-engine (Низкоуровневый движок плоских манифестов)  │
│ Атомарный I/O (replace), Cache::lock, dot-access,           │
│ DTO Bridge, реестр манифестов приложения, CLI-инспекция     │
└─────────────────────────────────────────────────────────────┘
```

### Принципы рефакторинга:
1. **Никакого фантомного функционала:** Удаляем неиспользуемые зависимости `illuminate/validation` и `illuminate/translation`. Доменная валидация специфична для каждого типа манифеста и живёт в дочерних пакетах (как `WorkspaceValidator`), а структурная валидация обеспечивается Draft-07 JSON Schema.
2. **Схлопывание избыточных слоёв:** Устраняем искусственное разделение на `ManifestManager` и `ManifestRegistry`. Менеджер является единым реестром и фабрикой.
3. **Устранение микросервисов-однодневок:** `ManifestInspectionService`, `ManifestStatusReport`, `ManifestValidationReport` полностью удаляются. Таблицы рендерятся консольными командами напрямую.
4. **Гарантированная целостность данных:** Устраняем race condition в `save()` (lost-update) и согласуем события жизненного цикла (`Saving`/`Saved`) с `mutate()`.
5. **Безупречный Git-diff и кроссплатформенность:** Добавляем `JSON_UNESCAPED_UNICODE` и устраняем баги склеивания путей на Windows.

---

## 2. Целевая архитектура пакета (Спроектировано с нуля)

```
src/
├── Manifest.php             // Документ: load, save, mutate, get, set, append, forget, toDto, saveDto
├── ManifestManager.php      // Единый реестр и фабрика манифестов: register, get, has, open, all
├── Contracts/
│   ├── ManifestSchema.php   // defaults(): array, jsonSchema(): array
│   └── ManifestDto.php      // fromArray(array): static, toArray(): array
├── DTOs/
│   └── ManifestDefinition.php // Определение в реестре: name, filename, schema, description, metadata
├── Events/
│   ├── ManifestOpened.php   // Диспатчится при реальной загрузке с диска
│   ├── ManifestSaving.php   // Диспатчится перед записью на диск
│   ├── ManifestSaved.php    // Диспатчится после успешной атомарной замены
│   └── ManifestMutated.php  // Диспатчится после транзакционной мутации (содержит before и after)
├── Exceptions/
│   ├── ManifestException.php
│   ├── ManifestLockTimeoutException.php
│   └── ManifestNotFoundException.php
├── Facades/
│   └── Manifest.php         // Фасад к ManifestManager
├── Schemas/
│   └── BaseSchema.php       // Базовая реализация контракта ManifestSchema
└── Console/Commands/
    ├── ManifestMakeCommand.php
    ├── ManifestSchemaCommand.php
    ├── ManifestStatusCommand.php
    └── ManifestValidateCommand.php
```

---

## 3. Таблица радикальных изменений и замен

| Было (текущий код) | Стало (целевое решение) | Обоснование |
| :--- | :--- | :--- |
| `ManifestManager` + `ManifestRegistry` (2 сервиса, `register()` возвращает `Registry`) | Единый класс `ManifestManager` (`register()` возвращает `$this`) | Устраняет поломку fluent-цепочек, убирает 80 строк тривиальной обертки. |
| `illuminate/validation` + `illuminate/translation` в `composer.json` | Удалены полностью из зависимостей | Пакет не использует их; дочерние пакеты валидируют данные сами. |
| `ManifestInspectionService` + 2 DTO отчетов | Удалены; код вынесен в команды | Лишний singleton-сервис и 2 DTO ради вывода строк в CLI. |
| `BaseManifestCommand` с мертвым `resolveBasePath()` | Команды получают зависимости через `handle(ManifestManager $manager)` | Чистый идиоматичный Laravel-код без фиктивного базового класса. |
| `Manifest::exportJsonSchema()` в классе документа | Метод вынесен в `ManifestManager::exportSchema()` | Экспорт схемы не требует создания экземпляра файла на диске. |
| `save()` пишет локальный снапшот без перечитывания | `save()` и `mutate()` используют единый безопасный транзакционный pipeline | Исключает затирание параллельных изменений (lost updates). |
| `mutate()` не стреляет `ManifestSaving`/`Saved` | `mutate()` диспатчит полный жизненный цикл событий | Слушатели кэша и аудита гарантированно срабатывают на все записи. |
| `JSON_ENCODE_FLAGS` без `JSON_UNESCAPED_UNICODE` | Включен `JSON_UNESCAPED_UNICODE` | Кириллица и спецсимволы не экранируются в `\uXXXX` в Git-diff. |
| `str_starts_with(..., DIRECTORY_SEPARATOR)` на Windows | Использование `Manifest::isAbsolutePath()` и `resolvePath()` | Пути вида `C:\...` не превращаются в `C:\project\C:\...`. |
| Распаковка `...$this->all()` в `toDto()` | Безопасная гидратация с валидацией ключей | Исключает фатальные ошибки PHP при ключах вроде `"$schema"`. |
| `ManifestOpened` в `__construct()` | Диспатч в `load()` по факту чтения | Корректная семантика события открытия файла. |

---

## 4. Пошаговый план реализации (Чек-листы)

### Фаза 1: Надежность, атомарность и целостность данных (P1)

- [ ] **Шаг 1.1: Устранение потери данных (Lost Update) и унификация событий записи**
  * **Проблема:** Метод `save()` перезаписывает файл устаревшим локальным снапшотом без перечитывания под локом. Метод `mutate()` перечитывает диск, но не вызывает события `ManifestSaving` и `ManifestSaved`.
  * **Решение:**
    - Объединить низкоуровневую запись в единый закрытый метод транзакции под `Cache::lock`.
    - В `mutate()` перед записью диспатчить `ManifestSaving`, после записи — `ManifestSaved` и `ManifestMutated`.
    - В `save()` гарантировать консистентность данных либо выполнять слияние при наличии внешних изменений.

- [ ] **Шаг 1.2: Исправление кроссплатформенных путей на Windows**
  * **Проблема:** `ManifestMakeCommand` и `canonicalPath()` проверяют `str_starts_with($path, DIRECTORY_SEPARATOR)`. На Windows абсолютные пути с буквой диска (`C:\...`) ошибочно принимаются за относительные и склеиваются с `base_path` в невалидные строки `C:\project\C:\...`.
  * **Решение:** Во всех местах использовать единый метод `Manifest::resolvePath($path)` и существующий метод `Manifest::isAbsolutePath($path)`.

- [ ] **Шаг 1.3: Удаление фантомных зависимостей валидации**
  * **Проблема:** В `composer.json` подключены `illuminate/validation` и `illuminate/translation`, которые не используются ни в коде, ни в дочерних пакетах.
  * **Решение:** Удалить обе зависимости из `composer.json`. Скорректировать документацию и описание команды `manifest:validate` (проверка синтаксиса JSON и базовой структуры).

- [ ] **Шаг 1.4: Инъекция провайдера блокировок вместо жесткой связности с фасадом `Cache`**
  * **Проблема:** `withLock()` вызывает статический фасад `Cache::lock()`. Если сервис `cache` не сконфигурирован, блокировка молча отключается. В standalone-режиме фасад выбрасывает фатал.
  * **Решение:** Внедрять `LockProvider` / `CacheManager` через конструктор `ManifestManager` и передавать в `Manifest`. При невозможности взять лок бросать прозрачное исключение, не допуская тихого повреждения данных.

---

### Фаза 2: Радикальное архитектурное упрощение кодовой базы (P2)

- [ ] **Шаг 2.1: Слияние `ManifestRegistry` в `ManifestManager`**
  * **Проблема:** `ManifestRegistry` — избыточный класс из 80 строк. `ManifestManager::register()` возвращает `ManifestRegistry`, ломая fluent chaining.
  * **Решение:**
    - Перенести хранение `array<string, ManifestDefinition> $manifests` непосредственно в `ManifestManager`.
    - Сделать так, чтобы `ManifestManager::register()` возвращал `$this` (`ManifestManager`).
    - Удалить класс `ManifestRegistry`. В сервис-провайдере `ManifestEngineServiceProvider` зарегистрировать alias `ManifestRegistry::class => ManifestManager::class` для плавной миграции дочернего пакета при необходимости.

- [ ] **Шаг 2.2: Полное удаление `ManifestInspectionService`, `ManifestStatusReport`, `ManifestValidationReport`**
  * **Проблема:** Выделенный сервис-синглтон и 2 DTO созданы исключительно ради вывода двух консольных таблиц.
  * **Решение:**
    - Удалить `ManifestInspectionService.php`, `ManifestStatusReport.php`, `ManifestValidationReport.php`.
    - Убрать регистрацию сервиса из `ManifestEngineServiceProvider`.
    - В `ManifestStatusCommand` и `ManifestValidateCommand` выполнять сбор статуса напрямую из `ManifestManager::all()` с форматированием размера через стандартный `Illuminate\Support\Number::fileSize()`.

- [ ] **Шаг 2.3: Упразднение `BaseManifestCommand` и очистка консольного слоя**
  * **Проблема:** Базовый класс содержит неиспользуемый метод `resolveBasePath()`, а команды дублируют парсинг опций.
  * **Решение:** Удалить `BaseManifestCommand`. Внедрять `ManifestManager` и `Filesystem` через метод `handle()` каждой команды.

- [ ] **Шаг 2.4: Перенос генерации JSON Schema из объекта документа `Manifest`**
  * **Проблема:** Метод `exportJsonSchema()` в классе `Manifest` заставляет создавать экземпляр файла на диске и триггерит событие открытия.
  * **Решение:** Вынести экспорт схемы в метод `ManifestManager::exportSchema(string|ManifestSchema $schema, ?string $outputPath = null)` или хелпер схемы.

---

### Фаза 3: Чистота сериализации, надежность DTO и код-стайл (P3)

- [ ] **Шаг 3.1: Включение `JSON_UNESCAPED_UNICODE`**
  * **Проблема:** Отсутствие флага приводит к экранированию кириллицы и национальных символов в `\uXXXX` в Git-diff.
  * **Решение:** Добавить `JSON_UNESCAPED_UNICODE` в константу `Manifest::JSON_ENCODE_FLAGS`.

- [ ] **Шаг 3.2: Защита распаковки параметров в `toDto()`**
  * **Проблема:** Распаковка `...$this->all()` выбрасывает фатал при наличии ключей вроде `"$schema"`, дефисов или точек.
  * **Решение:** Добавить фильтрацию/проверку валидности имен аргументов конструктора либо отдавать приоритет `fromArray()` / замыканию.

- [ ] **Шаг 3.3: Корректный диспатч события `ManifestOpened`**
  * **Проблема:** Событие диспатчится в конструкторе `Manifest` до фактического чтения файла с диска.
  * **Решение:** Перенести диспатч `ManifestOpened` в метод `load()`, вызывая его строго по факту чтения.

- [ ] **Шаг 3.4: Удаление мертвого кода и констант**
  * **Проблема:** Константы `ManifestRegistry::EMPTY_REGISTRY`, `ManifestManager::DEFAULT_BASE_DIR` засоряют код.
  * **Решение:** Удалить неиспользуемые константы.
