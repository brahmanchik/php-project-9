<?php

session_start();
require __DIR__ . '/../vendor/autoload.php';

use App\Connection;
use App\Exception\UrlNotFoundException;
use App\UrlChecker;
use App\UrlHelper;
use App\UrlRepository;
use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Flash\Messages;
use Slim\Views\PhpRenderer;
use Valitron\Validator;

$container = new Container();
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
    if ($exception instanceof UrlNotFoundException)  {
        $response = $app->getResponseFactory()->createResponse(404);
        return $renderer->render($response, '404.phtml');
    }
    // 404 - роут не найден (Slim выбрасывает HttpNotFoundException)
    if ($exception instanceof HttpNotFoundException) {
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

    $body = $request->getParsedBody();
    $urlName = $body['url']['name'] ?? '';

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
            ['errors' => $urlErrors]
        );
    }

    $urlName = UrlHelper::normalize($urlName);

    $urlHelper = $this->get(UrlHelper::class);
    $existingUrlId = $urlHelper->findIdByUrl($urlName);

    if ($existingUrlId !== null) {
        $flash->addMessage('success', 'Страница уже существует');

        return $response
            ->withHeader('Location', "/urls/{$existingUrlId}")
            ->withStatus(302);
    }

        $flash->addMessage('success', 'Страница успешно добавлена');
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
    $success = $flash->getMessage('success');
    $id = (int) $args['id'];
    $urlRepository = $this->get(UrlRepository::class);
    $urlData = $urlRepository->getById($id);

    $dbh = $this->get(PDO::class);
    $stmt = $dbh->prepare("SELECT * FROM url_checks WHERE url_id = :id");
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);


    $viewData = [
        'id' => $urlData['id'],
        'name' => $urlData['name'],
        'created_at' => $urlData['created_at'],
        'success' => $success,
        'errors' => $errors,
        'checks' => $checks,
        ];
    return $renderer->render($response, 'url.phtml', $viewData);
});

$app->get('/urls', function (Request $request, Response $response) {
    $renderer = $this->get(PhpRenderer::class);
    $dbh = $this->get(PDO::class);

    // Первый простой запрос: получаем все URL
    $urlsStatement = $dbh->query(
        "SELECT id, name
        FROM urls
        ORDER BY id DESC"
    );

    $urls = $urlsStatement->fetchAll(PDO::FETCH_ASSOC);

    // Второй запрос: получаем только последнюю проверку каждого URL
    $checksStatement = $dbh->query(
        "SELECT DISTINCT ON (url_id)
            url_id,
            status_code,
            created_at AS last_check_at
        FROM url_checks
        ORDER BY url_id, created_at DESC, id DESC"
    );

    $latestChecks = $checksStatement->fetchAll(PDO::FETCH_ASSOC);

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
        'urls.phtml',
        ['urls' => $urlsWithChecks]
    );
});

$app->post('/urls/{url_id:[0-9]+}/checks', function (Request $request, Response $response, array $args) {
    $id = (int) $args['url_id'];
    $urlCheck = $this->get(UrlChecker::class);
    $urlRepository = $this->get(UrlRepository::class);
    $urlData = $urlRepository->getById($id);

    $flash = $this->get('flash');

    try {
        $data = $urlCheck->getData($urlData['name']);
    } catch (Throwable $exception) {
        $flash->addMessage(
            'error',
            'Произошла ошибка при проверке, не удалось подключиться'
        );

        return $response
            ->withHeader('Location', "/urls/{$id}")
            ->withStatus(302);
    }

    $statusCode = $data['status_code'];
    $h1 = $data['h1'];
    $title = $data['title'];
    $description = $data['description'];
    $dbh = $this->get(PDO::class);
    $stmt = $dbh->prepare("INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at)
                                    VALUES (:url_id, :status_code, :h1, :title, :description, NOW()::timestamp(0))");
    $stmt->bindValue(':url_id', $id, PDO::PARAM_INT);
    $stmt->bindValue(':status_code', $statusCode, PDO::PARAM_INT);
    $stmt->bindValue(':h1', $h1, PDO::PARAM_STR);
    $stmt->bindValue(':title', $title, PDO::PARAM_STR);
    $stmt->bindValue(':description', $description, PDO::PARAM_STR);
    $stmt->execute();

    $flash->addMessage('success', 'Страница успешно проверена');
    return $response
        ->withHeader('Location', "/urls/{$id}")
        ->withStatus(302);
});

$app->run();
