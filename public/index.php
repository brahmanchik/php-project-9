<?php

use App\Connection;
use App\UrlCheckRepository;
use App\UrlChecker;
use App\UrlHelper;
use App\UrlRepository;
use DI\Container;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Flash\Messages;
use Slim\Http\Response;
use Slim\Views\PhpRenderer;
use Valitron\Validator;

session_start();
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/helpers.php';

$container = new Container();
$container->set('flash', function () {
    return new Messages();
});

$container->set(PhpRenderer::class, function () {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    $renderer->setLayout('layout.phtml');

    return $renderer;
});

// Подключение к базе данных
$container->set(PDO::class, function () {
    return Connection::getConnection();
});

$container->set(ClientInterface::class, function () {
    return new Client([
        'timeout' => 10,
        'connect_timeout' => 5,
    ]);
});

$container->set(Validator::class, function () {
    $validator = new Validator();

    $validator
        ->rule('required', 'url')
        ->message('URL не должен быть пустым');

    $validator
        ->rule('url', 'url')
        ->message('Некорректный URL');

    $validator
        ->rule('lengthMax', 'url', 255)
        ->message('URL не должен быть длиннее 255 символов');

    return $validator;
});

$app = AppFactory::createFromContainer($container);
$routeParser = $app->getRouteCollector()->getRouteParser();
$container->get(PhpRenderer::class)->addAttribute('routeParser', $routeParser);

// Обработка ошибок
$customErrorHandler = function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app) {
    $renderer = $app->getContainer()->get(PhpRenderer::class);

    if ($exception->getCode() === 404) {
        $response = $app->getResponseFactory()->createResponse(404);
        return $renderer->render($response, 'errors/404.phtml');
    }

    if ($logErrors) {
        error_log($logErrorDetails ? (string) $exception : $exception->getMessage());
    }

    $response = $app->getResponseFactory()->createResponse(500);
    return $renderer->render($response, 'errors/500.phtml');
};

$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

// Маршруты
$app->get('/', function (Request $request, Response $response) {
    $renderer = $this->get(PhpRenderer::class);
    $flash = $this->get('flash');
    $errors = $flash->getMessage('error'); // массив сообщений
    $viewData = [
        'errors' => $errors,
        'urlName' => '',
    ];
    return $renderer->render($response, 'index.phtml', $viewData);
})->setName('home');

$app->post('/urls', function (Request $request, Response $response) use ($routeParser) {
    $flash = $this->get('flash');

    $body = $request->getParsedBody();
    $urlName = (string) ($body['url']['name'] ?? '');

    $validator = $this
        ->get(Validator::class)
        ->withData(['url' => $urlName]);

    if (!$validator->validate()) {
        $renderer = $this->get(PhpRenderer::class);

        $errors = $validator->errors();
        $urlErrors = $errors['url'] ?? ['Некорректный URL'];

        return $renderer->render(
            $response->withStatus(422),
            'index.phtml',
            [
                'errors' => $urlErrors,
                'urlName' => $urlName,
            ]
        );
    }

    $urlName = UrlHelper::normalize($urlName);

    $urlRepository = $this->get(UrlRepository::class);
    $existingUrlId = $urlRepository->findIdByName($urlName);

    if ($existingUrlId !== null) {
        $flash->addMessage('success', 'Страница уже существует');

        $url = $routeParser->urlFor(
            'urls.show',
            ['id' => (string) $existingUrlId]
        );

        return $response->withRedirect($url);
    }

    $id = $urlRepository->create($urlName);
    $flash->addMessage('success', 'Страница успешно добавлена');
    $url = $routeParser->urlFor('urls.show', ['id' => (string) $id]);

    return $response->withRedirect($url);
})->setName('urls.store');

$app->get('/urls/{id:[0-9]+}', function (Request $request, Response $response, array $args) {
    $renderer = $this->get(PhpRenderer::class);
    $flash = $this->get('flash');
    $errors = $flash->getMessage('error');
    $success = $flash->getMessage('success');
    $id = (int) $args['id'];
    $urlRepository = $this->get(UrlRepository::class);
    $urlData = $urlRepository->getById($id);
    $checks = $this->get(UrlCheckRepository::class)->getByUrlId($id);

    $viewData = [
        'id' => $urlData['id'],
        'name' => $urlData['name'],
        'created_at' => $urlData['created_at'],
        'success' => $success,
        'errors' => $errors,
        'checks' => $checks,
    ];
    return $renderer->render($response, 'urls/show.phtml', $viewData);
})->setName('urls.show');

$app->get('/urls', function (Request $request, Response $response) {
    $renderer = $this->get(PhpRenderer::class);
    $urls = $this->get(UrlRepository::class)->getAll();
    $latestChecks = $this->get(UrlCheckRepository::class)->getLatestByUrlId();

    // Создаём массив, где ключ — ID сайта
    $checksByUrlId = [];

    foreach ($latestChecks as $check) {
        $urlId = (int) $check['url_id'];
        $checksByUrlId[$urlId] = $check;
    }

    // Соединяем URL с последними проверками
    $urlsWithChecks = [];

    foreach ($urls as $url) {
        $urlId = (int) $url['id'];
        $latestCheck = $checksByUrlId[$urlId] ?? null;

        $url['status_code'] = $latestCheck['status_code'] ?? null;
        $url['last_check_at'] = $latestCheck['last_check_at'] ?? null;

        $urlsWithChecks[] = $url;
    }

    return $renderer->render(
        $response,
        'urls/index.phtml',
        ['urls' => $urlsWithChecks]
    );
})->setName('urls.index');

$app->post('/urls/{url_id:[0-9]+}/checks', function (
    Request $request,
    Response $response,
    array $args
) use ($routeParser) {
    $id = (int) $args['url_id'];
    $urlCheck = $this->get(UrlChecker::class);
    $urlRepository = $this->get(UrlRepository::class);
    $urlData = $urlRepository->getById($id);

    $flash = $this->get('flash');

    try {
        $data = $urlCheck->getData($urlData['name']);
    } catch (ConnectException | RequestException $exception) {
        error_log((string) $exception);
        $flash->addMessage(
            'error',
            'Произошла ошибка при проверке, не удалось подключиться'
        );

        $url = $routeParser->urlFor('urls.show', ['id' => (string) $id]);

        return $response->withRedirect($url);
    }

    $this->get(UrlCheckRepository::class)->create($id, $data);

    $flash->addMessage('success', 'Страница успешно проверена');
    $url = $routeParser->urlFor('urls.show', ['id' => (string) $id]);

    return $response->withRedirect($url);
})->setName('urls.checks.store');

$app->run();
