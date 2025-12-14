<?php
session_start();
require __DIR__ . '/../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Flash\Messages;
use Slim\Routing\RouteContext;
use Slim\Views\PhpRenderer;

use Illuminate\Validation\Factory;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;

use App\UrlHelper;

// Создаём контейнер
$container = new \DI\Container();
AppFactory::setContainer($container);

$app = AppFactory::create();

$container->set('flash', function () {
    return new Messages();
});

$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(true, true, true);

if (!empty($_ENV['DATABASE_URL'])) {
    $url = parse_url($_ENV['DATABASE_URL']);

    $host = $url['host'];
    $port = $url['port'];
    $dbName = ltrim($url['path'], '/');
    $user = $url['user'];
    $password = $url['pass'];

    $dsn = "pgsql:host=$host;port=$port;dbname=$dbName";
} else {
    // ЛОКАЛЬНАЯ БАЗА
    $dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=hexlet_db_dev;';
    $user = 'a1111';
    $password = '';
}

$dbh = new PDO($dsn, $user, $password);

$initFilePath = implode('/', [dirname(__DIR__), 'database.sql']);
$initSql = file_get_contents($initFilePath);
$dbh->exec($initSql);

// Define app routes
$app->get('/', function (Request $request, Response $response) {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    $flash = $this->get('flash');
    $errors = $flash->getMessage('error'); // массив сообщений
    $viewData = [
        'errors' => $errors
    ];
    return $renderer->render($response, 'index.phtml', $viewData);
})->setName('index');

$app->post('/urls', function (Request $request, Response $response) use ($dbh) {
    $flash = $this->get('flash');
    $data = $request->getParsedBody();
    $urlName = $data['url']['name'] ?? null;

    //ниже будет валидация
    // Создаём Translator (заглушка)
    $translator = new Translator(new ArrayLoader(), 'en');

// Создаём Factory
    $factory = new Factory($translator);
// Данные для проверки
    $data = [
        'url' => $urlName
    ];
// Правила валидации
    $rules = [
        'url' => 'required|url|max:255'
    ];

// Создаём Validator
    $validator = $factory->make($data, $rules);

// Проверяем
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
        $urlChecker = new UrlHelper($dbh);
        $findIdByUrl = $urlChecker->findIdByUrl($urlName);
        if ($findIdByUrl !== null) {
            $flash->addMessage('succes', 'Страница уже существует');
            //вобщем в этом месте думаю createIfNotExists должна возвращать id той строки которая уже добавлена и туда надо возвращаться
            //$stmt = $dbh->prepare("INSERT INTO urls (name) VALUES (:name)");
            //$stmt->bindValue(':name', $urlName, PDO::PARAM_STR);
            //$stmt->execute();

            //$id = $dbh->lastInsertId();
            return $response
                ->withHeader('Location', "/urls/{$findIdByUrl}")
                ->withStatus(302);
        }
    }

        $flash->addMessage('succes', 'Страница успешно добавлена');
        $stmt = $dbh->prepare("INSERT INTO urls (name) VALUES (:name)");
        $stmt->bindValue(':name', $urlName, PDO::PARAM_STR);
        $stmt->execute();

        $id = $dbh->lastInsertId();
        return $response
            ->withHeader('Location', "/urls/{$id}")
            ->withStatus(302);

    //выше валидация
});

//Реализуйте вывод конкретного введенного URL на отдельной странице urls/{id}
//запихнуть в DI контейнер подключение к БД
$app->get('/urls/{id}', function (Request $request, Response $response, array $args) use ($dbh) {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    $flash = $this->get('flash');
    $succes = $flash->getMessage('succes'); // массив сообщений
    $id = $args['id'];
    $stmt = $dbh->prepare("SELECT * FROM urls WHERE id = :id");
    $stmt->bindValue(':id', $id, PDO::PARAM_STR);
    $stmt->execute();
    $url = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $dbh->prepare("SELECT * FROM url_checks WHERE url_id = :id");
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);


    $viewData = [
        'id' => $url['id'],
        'name' => $url['name'],
        'created_at' => $url['created_at'],
        'succes' => $succes,
        'checks' => $checks,
        ];
    return $renderer->render($response, 'url.phtml', $viewData);
});

$app->get('/urls', function (Request $request, Response $response) use ($dbh) {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    $stmt = $dbh->prepare("
        SELECT
            urls.id,
            urls.name,
            MAX(url_checks.created_at) AS last_check_at
        FROM urls
        LEFT JOIN url_checks
            ON urls.id = url_checks.url_id
        GROUP BY urls.id, urls.name
        ORDER BY urls.id DESC
    ");
    $stmt->execute();
    $url = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $viewData = [
        'urls' => $url, // передаём весь массив
    ];
    return $renderer->render($response, 'urls.phtml', $viewData);
});


$app->post('/urls/{url_id}/checks', function (Request $request, Response $response, array $args) use ($dbh) {

    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    $flash = $this->get('flash');
    $succes = $flash->getMessage('succes'); // массив сообщений
    $url_id = $args['url_id'];
    $stmt = $dbh->prepare("INSERT INTO url_checks (url_id, created_at) VALUES (:url_id, NOW()::timestamp(0))");
    $stmt->bindValue(':url_id', $url_id, PDO::PARAM_STR);
    $stmt->execute();


    return $response
        ->withHeader('Location', "/urls/{$url_id}")
        ->withStatus(302);
    //return $renderer->render($response, 'url.phtml', $viewData);
});

// Run app
$app->run();