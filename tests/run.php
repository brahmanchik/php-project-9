<?php

use App\Exception\UrlNotFoundException;
use App\UrlChecker;
use App\UrlCheckRepository;
use App\UrlRepository;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Slim\Flash\Messages;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\MemoryUrlCheckRepository;
use Tests\Support\MemoryUrlRepository;

use function Tests\Support\checkerFor;
use function Tests\Support\expect;
use function Tests\Support\test;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/MemoryUrlRepository.php';
require __DIR__ . '/Support/MemoryUrlCheckRepository.php';
require __DIR__ . '/Support/functions.php';

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';

// Загружаем настоящее приложение. Главная страница не обращается к базе.
ob_start();
require __DIR__ . '/../public/index.php';
$home = ob_get_clean();

// Ожидаемые ошибки пишутся во временный лог, а не в вывод тестов.
$errorLog = tempnam(sys_get_temp_dir(), 'page-analyzer-tests-');
ini_set('error_log', $errorLog);

$passed = 0;
$failed = 0;

$requestFactory = new ServerRequestFactory();
$urls = new MemoryUrlRepository();
$checks = new MemoryUrlCheckRepository();
$container->set(UrlRepository::class, $urls);
$container->set(UrlCheckRepository::class, $checks);
$flashStorage = [];
$container->set('flash', new Messages($flashStorage));

test('Главная страница, layout и именованная форма', function () use ($home) {
    expect(str_contains($home, 'Анализатор страниц'), 'Главная не отрендерилась');
    expect(substr_count($home, '<html') === 1, 'Layout продублирован');
    expect(str_contains($home, 'action="/urls"'), 'Неверный адрес формы');
});

test('Единое экранирование кавычек, HTML, null и чисел', function () {
    expect(\App\e('<script>"\'&') === '&lt;script&gt;&quot;&#039;&amp;', 'Нет защиты HTML');
    expect(\App\e(null) === '', 'null не превратился в пустую строку');
    expect(\App\e(404) === '404', 'Число не преобразовано в строку');
    expect(\App\e("\xFF") === "\u{FFFD}", 'Некорректный UTF-8 не заменён');
});

test('Отсутствующая запись имеет код исключения 404', function () {
    expect((new UrlNotFoundException())->getCode() === 404, 'Код исключения не 404');
});

$html = '<html><head><title>Тест</title><meta name="description" content="Описание"></head>'
    . '<body><h1>Заголовок</h1></body></html>';

foreach ([200, 403, 404, 500] as $status) {
    test("UrlChecker возвращает данные для HTTP $status", function () use ($status, $html) {
        $data = checkerFor(new Response($status, [], $html))->getData('https://example.test');
        expect($data['status_code'] === $status, 'Потерян код ответа');
        expect($data['h1'] === ($status >= 400 ? null : 'Заголовок'), 'Неверный h1');
        expect($data['title'] === ($status >= 400 ? null : 'Тест'), 'Неверный title');
        expect($data['description'] === ($status >= 400 ? null : 'Описание'), 'Неверный description');
    });
}

test('Пустая страница сохраняет код ответа и null для SEO-полей', function () {
    $data = checkerFor(new Response(404))->getData('https://example.test');
    expect($data === ['status_code' => 404, 'h1' => null, 'title' => null, 'description' => null], 'Неверные поля');
});

test('Обрезка русских символов не повреждает UTF-8', function () {
    $data = checkerFor(new Response(200, [], '<h1>' . str_repeat('Я', 201) . '</h1>'))
        ->getData('https://example.test');
    expect($data['h1'] === str_repeat('Я', 200) . '...', 'Неверная обрезка');
});

foreach ([ConnectException::class, RequestException::class, LogicException::class] as $class) {
    test("UrlChecker не подменяет $class другим исключением", function () use ($class) {
        $exception = $class === LogicException::class
            ? new LogicException('Unexpected bug')
            : new $class('Network failure', new Request('GET', 'https://example.test'));
        try {
            checkerFor($exception)->getData('https://example.test');
        } catch (Throwable $caught) {
            expect($caught === $exception, 'Исключение подменено');
            return;
        }
        throw new RuntimeException('Исключение не передано вызывающему коду');
    });
}

test('Неверный URL возвращает 422 и сохраняет безопасное старое значение', function () use ($app, $requestFactory) {
    $old = 'bad"><script>alert(1)</script>';
    $response = $app->handle($requestFactory->createServerRequest('POST', '/urls')
        ->withParsedBody(['url' => ['name' => $old]]));
    $body = (string) $response->getBody();
    expect($response->getStatusCode() === 422, 'Неверный статус валидации');
    expect(str_contains($body, 'value="' . \App\e($old) . '"'), 'Не сохранено старое значение');
    expect(!str_contains($body, '<script>alert(1)</script>'), 'Ввод попал в HTML без защиты');
});

test('Добавление URL использует репозиторий и именованный редирект', function () use ($app, $requestFactory, $urls) {
    $response = $app->handle($requestFactory->createServerRequest('POST', '/urls')
        ->withParsedBody(['url' => ['name' => 'https://example.test/path?query=1']]));
    expect($response->getStatusCode() === 302, 'Нет редиректа');
    expect($response->getHeaderLine('Location') === '/urls/1', 'Неверный редирект');
    expect($urls->urls[1]['name'] === 'https://example.test', 'URL не нормализован');
});

test('Повторный URL не создаёт дубликат', function () use ($app, $requestFactory, $urls) {
    $response = $app->handle($requestFactory->createServerRequest('POST', '/urls')
        ->withParsedBody(['url' => ['name' => 'https://example.test/another']]));
    expect(count($urls->urls) === 1, 'Создан дубликат');
    expect($response->getHeaderLine('Location') === '/urls/1', 'Нет редиректа к существующей записи');
});

foreach ([200, 403, 404, 500] as $status) {
    test("POST проверки сохраняет HTTP $status и возвращает к карточке", function () use (
        $status,
        $html,
        $app,
        $container,
        $requestFactory,
        $checks
    ) {
        $before = count($checks->checks);
        $container->set(UrlChecker::class, checkerFor(new Response($status, [], $html)));
        $response = $app->handle($requestFactory->createServerRequest('POST', '/urls/1/checks'));
        expect($response->getStatusCode() === 302, 'Нет редиректа после проверки');
        expect($response->getHeaderLine('Location') === '/urls/1', 'Неверный адрес карточки');
        expect(count($checks->checks) === $before + 1, 'Результат не сохранён');
        expect($checks->checks[$before]['status_code'] === $status, 'Сохранён неверный код');
    });
}

foreach ([ConnectException::class, RequestException::class] as $class) {
    test("POST проверки: $class без записи в базу", function () use (
        $class,
        $app,
        $container,
        $requestFactory,
        $checks,
        &$flashStorage
    ) {
        $before = count($checks->checks);
        $flashStorage = [];
        $container->set('flash', new Messages($flashStorage));
        $container->set(UrlChecker::class, checkerFor(
            new $class('Network failure', new Request('GET', 'https://example.test'))
        ));
        $response = $app->handle($requestFactory->createServerRequest('POST', '/urls/1/checks'));
        expect($response->getHeaderLine('Location') === '/urls/1', 'Нет возврата к карточке');
        expect(count($checks->checks) === $before, 'Сохранена проверка без ответа сайта');
        expect(isset($flashStorage['slimFlash']['error']), 'Нет сообщения об ошибке');
    });
}

test('Ошибка программирования не маскируется под сетевую ошибку', function () use (
    $app,
    $container,
    $requestFactory,
    $checks
) {
    $before = count($checks->checks);
    $container->set(UrlChecker::class, checkerFor(new LogicException('SECRET_INTERNAL_DETAILS')));
    $response = $app->handle($requestFactory->createServerRequest('POST', '/urls/1/checks'));
    expect($response->getStatusCode() === 500, 'Ошибка не дошла до общего обработчика');
    expect(!str_contains((string) $response->getBody(), 'SECRET_INTERNAL_DETAILS'), 'Утечка деталей ошибки');
    expect(count($checks->checks) === $before, 'Создана проверка после ошибки');
});

foreach (['/no-such-route', '/urls/999', '/urls/999/checks'] as $path) {
    test("Несуществующий адрес $path возвращает 404", function () use ($path, $app, $requestFactory) {
        $method = str_ends_with($path, '/checks') ? 'POST' : 'GET';
        $response = $app->handle($requestFactory->createServerRequest($method, $path));
        expect($response->getStatusCode() === 404, 'Неверный статус');
        expect(str_contains((string) $response->getBody(), 'Ошибка 404'), 'Не показан шаблон 404');
    });
}

test('Обработчик принимает любые исключения с кодом 404', function () use ($customErrorHandler, $requestFactory) {
    $response = $customErrorHandler(
        $requestFactory->createServerRequest('GET', '/'),
        new RuntimeException('Another not-found cause', 404),
        false,
        false,
        false
    );
    expect($response->getStatusCode() === 404, 'Обработчик привязан к конкретному классу');
});

test('Карточка и список показывают сохранённые проверки', function () use ($app, $requestFactory, $checks) {
    $show = $app->handle($requestFactory->createServerRequest('GET', '/urls/1'));
    $index = $app->handle($requestFactory->createServerRequest('GET', '/urls'));
    expect($show->getStatusCode() === 200, 'Карточка не открывается');
    expect($index->getStatusCode() === 200, 'Список не открывается');
    expect(str_contains((string) $show->getBody(), 'data-test="checks"'), 'Потерян маркер тестов');
    expect(str_contains((string) $index->getBody(), '500'), 'Не показана последняя проверка');
    expect(array_column($checks->getByUrlId(1), 'id') === [4, 3, 2, 1], 'Неверный порядок истории');
});

test('В маршрутах и UrlHelper больше нет SQL', function () {
    foreach (['public/index.php', 'src/UrlHelper.php'] as $file) {
        $code = file_get_contents(__DIR__ . '/../' . $file);
        expect(!preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b|->(?:query|prepare)\(/', $code), "SQL остался в $file");
    }
});

test('Шаблоны используют единую обёртку экранирования', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../templates'));
    foreach ($files as $file) {
        if ($file->getExtension() === 'phtml') {
            expect(!str_contains(file_get_contents($file->getPathname()), 'htmlspecialchars'), 'Остался длинный вызов');
        }
    }
});

foreach ([403, 404, 500] as $status) {
    test("Hexlet: ответ HTTP $status показывает ошибку без SEO страницы ошибки", function () use (
        $status,
        $app,
        $container,
        $requestFactory,
        $checks,
        &$flashStorage
    ) {
        $flashStorage = [];
        $container->set('flash', new Messages($flashStorage));
        $errorHtml = '<h1>Im wrong site, you shouldnt have my header</h1><title>Error page</title>';
        $container->set(UrlChecker::class, checkerFor(new Response($status, [], $errorHtml)));
        $before = count($checks->checks);
        $response = $app->handle($requestFactory->createServerRequest('POST', '/urls/1/checks'));

        expect($response->getHeaderLine('Location') === '/urls/1', 'Нет возврата к карточке');
        expect(count($checks->checks) === $before + 1, 'Код HTTP не сохранён, нарушено замечание наставника');
        expect($checks->checks[$before]['status_code'] === $status, 'Неверный код HTTP');
        expect(isset($flashStorage['slimFlash']['error']), 'Hexlet ожидает сообщение об ошибке');
        expect(!isset($flashStorage['slimFlash']['success']), 'Ошибка сайта показана как успех');

        $container->set('flash', new Messages($flashStorage));
        $page = $app->handle($requestFactory->createServerRequest('GET', '/urls/1'));
        $body = (string) $page->getBody();
        expect(
            str_contains($body, 'Произошла ошибка при проверке, не удалось подключиться'),
            'Не показано сообщение, ожидаемое Hexlet'
        );
        expect(!str_contains($body, 'Im wrong site, you shouldnt have my header'), 'Сохранён h1 страницы ошибки');
        expect($checks->checks[$before]['h1'] === null, 'h1 страницы ошибки попал в результат');
        expect($checks->checks[$before]['title'] === null, 'title страницы ошибки попал в результат');
        expect($checks->checks[$before]['description'] === null, 'description страницы ошибки попал в результат');
    });
}

unlink($errorLog);
echo "\nРезультат: $passed успешно, $failed с ошибкой.\n";
exit($failed === 0 ? 0 : 1);
