<?php

session_start();
require __DIR__ . '/../vendor/autoload.php';

use App\UrlRepository;
use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Flash\Messages;
use Slim\Routing\RouteContext;
use Slim\Views\PhpRenderer;

use Illuminate\Validation\Factory;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use App\Connection;

use App\UrlHelper;
use App\UrlChecker;
$container = new \DI\Container();


$container->set('flash', function () {
    return new Messages();
});

$container->set(PhpRenderer::class, function () {
    return new PhpRenderer(__DIR__ . '/../templates');
});

// Подключение к базе данных
$container->set(PDO::class, function () {
    return Connection::getConnection();
});

$app = AppFactory::createFromContainer($container);
$router = $app->getRouteCollector()->getRouteParser();

//обработка ошибок
// Define Custom Error Handler
$customErrorHandler = function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app) {
    $renderer = $app->getContainer()->get(PhpRenderer::class);

    // 404 - ресурс не найден в БД
    if ($exception instanceof \App\Exception\UrlNotFoundException) {
        $response = $app->getResponseFactory()->createResponse(404);
        return $renderer->render($response, '404.phtml');
    }
    // 404 - роут не найден (Slim выбрасывает HttpNotFoundException)
    if ($exception instanceof \Slim\Exception\HttpNotFoundException) {
        $response = $app->getResponseFactory()->createResponse(404);
        return $renderer->render($response, '404.phtml');
    }

    $response = $app->getResponseFactory()->createResponse(500);
    return $renderer->render($response, '500.phtml');
};

$app->addRoutingMiddleware();
// Add Error Middleware
$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);


$initFilePath = implode('/', [dirname(__DIR__), 'database.sql']);
$initSql = file_get_contents($initFilePath);
$container->get(PDO::class)->exec($initSql);

// Define app routes
$app->get('/', function (Request $request, Response $response) {
    $renderer = $this->get(PhpRenderer::class);
    $flash = $this->get('flash');
    $errors = $flash->getMessage('error'); // массив сообщений
    $viewData = [
        'errors' => $errors
    ];
    return $renderer->render($response, 'index.phtml', $viewData);
})->setName('index');

$app->post('/urls', function (Request $request, Response $response) {
    $flash = $this->get('flash');
    $data = $request->getParsedBody();
    $urlName = $data['url']['name'] ?? null;

    // Создаём Translator (заглушка)
    $translator = new Translator(new ArrayLoader(), 'en');

    $factory = new Factory($translator);
// Данные для проверки
    $data = [
        'url' => $urlName
    ];
    $rules = [
        'url' => 'required|url|max:255'
    ];
    $validator = $factory->make($data, $rules);

    if ($validator->fails() || !filter_var($urlName, FILTER_VALIDATE_URL)) {
        $flash->addMessage('error', 'Неверный URL');
        $redirectUrl = RouteContext::fromRequest($request)
            ->getRouteParser()
            ->urlFor('index');
        return $response
            ->withHeader('Location', $redirectUrl)
            ->withStatus(302);
    }

    //Здесь будет проверка на уникальность url с помощью класса UrlHelper
    if ($urlName != null) {
        $existingUrlId  = $this->get(UrlHelper::class);
        $findIdByUrl = $existingUrlId ->findIdByUrl($urlName);
        if ($findIdByUrl !== null) {
            $flash->addMessage('succes', 'Страница уже существует');
            return $response
                ->withHeader('Location', "/urls/{$findIdByUrl}")
                ->withStatus(302);
        }
    }

        $flash->addMessage('succes', 'Страница успешно добавлена');
        $stmt = $this->get(PDO::class)->prepare("INSERT INTO urls (name) VALUES (:name)");
        $stmt->bindValue(':name', $urlName, PDO::PARAM_STR);
        $stmt->execute();

        $id = $this->get(PDO::class)->lastInsertId();
        return $response
            ->withHeader('Location', "/urls/{$id}")
            ->withStatus(302);
});

$app->get('/urls/{id:[0-9]+}', function (Request $request, Response $response, array $args) {
    $renderer = $this->get(PhpRenderer::class);
    $flash = $this->get('flash');
    $errors = $flash->getMessage('error');
    $succes = $flash->getMessage('succes');
    $id = $args['id'];
    if (!ctype_digit((string)$id) || (int)$id <= 0) {
        throw new \App\Exception\UrlNotFoundException('Invalid ID');
    }

    $id = (int)$id;
    $urlRepository = $this->get(UrlRepository::class);
    $urlData = $urlRepository->getById($id);

    //редирект на 404 в случае не существующей страницы
    if ($urlData === false) {
        return $renderer->render($response, '404.phtml')->withStatus(404);
    }
    $dbh = $this->get(PDO::class);
    $stmt = $dbh->prepare("SELECT * FROM url_checks WHERE url_id = :id");
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);


    $viewData = [
        'id' => $urlData['id'],
        'name' => $urlData['name'],
        'created_at' => $urlData['created_at'],
        'succes' => $succes,
        'errors' => $errors,
        'checks' => $checks,
        ];
    return $renderer->render($response, 'url.phtml', $viewData);
});

$app->get('/urls', function (Request $request, Response $response) {
    $renderer = $this->get(PhpRenderer::class);
    $dbh = $this->get(PDO::class);
    $stmt = $dbh->prepare("
        SELECT
            urls.id,
            urls.name,
            uc.status_code,
            uc.created_at AS last_check_at
        FROM urls
        LEFT JOIN url_checks uc
            ON uc.id = (
                SELECT id
                FROM url_checks
                WHERE url_id = urls.id
                ORDER BY created_at DESC
                LIMIT 1
            )
        ORDER BY urls.id ASC;
    ");
    $stmt->execute();
    $url = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $viewData = [
        'urls' => $url,
    ];
    return $renderer->render($response, 'urls.phtml', $viewData);
});

$app->post('/urls/{url_id:[0-9]+}/checks', function (Request $request, Response $response, array $args) {
    $renderer = $this->get(PhpRenderer::class);
    $id = $args['url_id'];
    $urlCheck = $this->get(UrlChecker::class);
    $urlRepository = $this->get(UrlRepository::class);
    $urlData = $urlRepository->getById($id);

    if ($urlData === null) {
        return $renderer->render($response, '404.phtml')->withStatus(404);
    }
    $data = $urlCheck->getData($urlData['name']);

    $flash = $this->get('flash');
    $statusCode = $data['status_code'];
    $h1 = $data['h1'];
    $title = $data['title'];
    $description = $data['description'];
    //$h1Test = $urlCheck->getH1($url_id);
    if ($statusCode === null) {
        $flash->addMessage('error', 'Произошла ошибка при проверке, не удалось подключиться');
        return $response
            ->withHeader('Location', "/urls/{$id}")
            ->withStatus(302);
    }
    $dbh = $this->get(PDO::class);
    $stmt = $dbh->prepare("INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at)
                                    VALUES (:url_id, :status_code, :h1, :title, :description, NOW()::timestamp(0))");
    $stmt->bindValue(':url_id', $id, PDO::PARAM_INT);
    $stmt->bindValue(':status_code', $statusCode, PDO::PARAM_INT);
    $stmt->bindValue(':h1', $h1, PDO::PARAM_STR);
    $stmt->bindValue(':title', $title, PDO::PARAM_STR);
    $stmt->bindValue(':description', $description, PDO::PARAM_STR);
    $stmt->execute();

    $flash->addMessage('succes', 'Страница успешно проверена');
    return $response
        ->withHeader('Location', "/urls/{$id}")
        ->withStatus(302);
});

$app->run();
