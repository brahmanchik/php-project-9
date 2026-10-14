# URL Analyzer

[![hexlet-check](https://github.com/brahmanchik/php-project-9/actions/workflows/hexlet-check.yml/badge.svg)](https://github.com/brahmanchik/php-project-9/actions/workflows/hexlet-check.yml)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=brahmanchik_php-project-9&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=brahmanchik_php-project-9)

Веб-приложение для анализа URL-адресов. Оно добавляет сайты, проверяет их доступность и получает
SEO-данные страницы: код ответа, заголовок `h1`, содержимое `title` и `description`.

## Демонстрация

[Посмотреть задеплоенное приложение](https://php-project-9-n5ou.onrender.com/)

## Минимальные системные требования

- **PHP**: версия 8.3 или выше
- **PostgreSQL**: версия 12 или выше
- **Composer**: для управления зависимостями
- **Docker** и **Docker Compose** (опционально, для запуска через Docker)

## Установка

### Вариант 1: Установка через Docker (рекомендуется)

1. Клонируйте репозиторий:

   ```bash
   git clone https://github.com/brahmanchik/php-project-9.git
   cd php-project-9
   ```

2. Соберите и запустите контейнеры:

   ```bash
   docker compose up --build
   ```

Docker Compose запустит PostgreSQL, передаст приложению `DATABASE_URL`, подготовит таблицы командой
`make db-prepare` и запустит веб-сервер. Приложение будет доступно по адресу
[http://localhost:8080](http://localhost:8080).

### Вариант 2: Локальный запуск без Docker

1. Установите зависимости:

   ```bash
   composer install
   ```

2. Скопируйте `.env.example` в `.env` и укажите в `DATABASE_URL` параметры своей базы PostgreSQL.

3. Подготовьте базу и запустите приложение:

   ```bash
   make db-prepare
   make start
   ```

Приложение будет доступно по адресу [http://localhost:8000](http://localhost:8000).

## Проверки

```bash
make lint
make test
```

Через Docker: `docker compose exec web make lint` и `docker compose exec web make test`.
Регрессионные тесты подменяют HTTP-ответы и репозитории: они не обращаются к внешним сайтам
и не меняют рабочую базу данных. Ответы проверяемого сайта, включая 403, 404 и 500,
сохраняются в истории; ошибки соединения показываются отдельным сообщением.
Технические подробности внутренних ошибок записываются в лог и не выводятся посетителям.

## Технологии

- **PHP 8.3+**
- **Slim Framework 4** - веб-фреймворк
- **PostgreSQL** - база данных
- **Guzzle HTTP** - для HTTP запросов
- **Symfony DomCrawler** - для парсинга HTML
