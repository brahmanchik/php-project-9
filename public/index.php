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

$dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=hexlet_db_dev;';
$user = 'a1111';
$password = '';
$dbh = new PDO($dsn, $user, $password);

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
    //Здесь будет проверка на уникальность url с помощью класса UrlHelper
    if ($urlName != null) {
        $urlChecker = new UrlHelper($dbh);
        if ($urlChecker->createIfNotExists($urlName) === false) {
            $flash->addMessage('succes', 'Страница уже существует');
            $stmt = $dbh->prepare("INSERT INTO urls (name) VALUES (:name)");
            $stmt->bindValue(':name', $urlName, PDO::PARAM_STR);
            $stmt->execute();

            $id = $dbh->lastInsertId();
            return $response
                ->withHeader('Location', "/urls/{$id}")
                ->withStatus(302);
        }
    }
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
    if ($validator->fails()) {

        $flash->addMessage('error', 'Неверный URL');
        $redirectUrl = RouteContext::fromRequest($request)
            ->getRouteParser()
            ->urlFor('index');
        return $response
            ->withHeader('Location', $redirectUrl)
            ->withStatus(302);
    } else {
        $flash->addMessage('succes', 'Страница успешно добавлена');
        $stmt = $dbh->prepare("INSERT INTO urls (name) VALUES (:name)");
        $stmt->bindValue(':name', $urlName, PDO::PARAM_STR);
        $stmt->execute();

        $id = $dbh->lastInsertId();
        return $response
            ->withHeader('Location', "/urls/{$id}")
            ->withStatus(302);
    }
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
    $viewData = [
        'id' => $url['id'],
        'name' => $url['name'],
        'created_at' => $url['created_at'],
        'succes' => $succes,
        'checks' => []  // <<< временно пусто
    ];
    return $renderer->render($response, 'url.phtml', $viewData);
});

$app->get('/urls', function (Request $request, Response $response) use ($dbh) {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    $stmt = $dbh->prepare("SELECT * FROM urls");
    $stmt->execute();
    $url = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $viewData = [
        'urls' => $url, // передаём весь массив
    ];
    return $renderer->render($response, 'urls.phtml', $viewData);
});

// Run app
$app->run();