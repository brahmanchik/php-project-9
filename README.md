# URL Analyzer

Веб-приложение для анализа URL-адресов. Позволяет добавлять URL, проверять их доступность и получать информацию о содержимом страниц (статус код, заголовок, описание).

# URL Analyzer

[![hexlet-check](https://github.com/brahmanchik/php-project-9/actions/workflows/hexlet-check.yml/badge.svg)](https://github.com/brahmanchik/php-project-9/actions/workflows/hexlet-check.yml)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=brahmanchik_php-project-9&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=brahmanchik_php-project-9)

## Демонстрация

[Посмотреть задеплоенное приложение](https://php-project-9-n5ou.onrender.com/)

## Минимальные системные требования

- **PHP**: версия 8.2 или выше
- **PostgreSQL**: версия 12 или выше
- **Composer**: для управления зависимостями
- **Docker** и **Docker Compose** (опционально, для запуска через Docker)

## Установка

### Вариант 1: Установка через Docker (рекомендуется)

1. Клонируйте репозиторий:
   git clone <url-репозитория>
   cd php-project-9.
2. Создайте файл `.env` в корне проекта:
   DATABASE_URL=postgresql://username:password@localhost:5432/database_name3. Запустите проект через Docker Compose:
   
3. docker-compose up --build Приложение будет доступно по адресу: `http://localhost:8080`


## Запуск проекта Через Docker

docker-compose up
make start
Приложение будет доступно по адресу: http://localhost:8000

## Технологии

- **PHP 8.2+**
- **Slim Framework 4** - веб-фреймворк
- **PostgreSQL** - база данных
- **Guzzle HTTP** - для HTTP запросов
- **Symfony DomCrawler** - для парсинга HTML