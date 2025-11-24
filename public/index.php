<?php
require __DIR__ . '/../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;

use Illuminate\Validation\Factory;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Support\MessageBag;

$app = AppFactory::create();

$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=hexlet_db_dev;';
$user = 'a1111';
$password = '';
$dbh = new PDO($dsn, $user, $password);

// Define app routes
$app->get('/', function (Request $request, Response $response) {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    $readmeContent = 'Для того чтобы увидеть роутинг в http запросe в конце url адреса добавтье это - /hello/user';
    $viewData = [
        'key1' => $readmeContent
    ];
    return $renderer->render($response, 'index.phtml', $viewData);
});

$app->post('/urls', function (Request $request, Response $response) use ($dbh) {
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
        'url' => 'required|url'
    ];

// Создаём Validator
    $validator = $factory->make($data, $rules);

// Проверяем
    if ($validator->fails()) {
        dd("Неверный URL!\n");
        print_r($validator->errors()->all());
    } else {
        dd("URL верный!\n");
    }
    //выше валидация
    $stmt = $dbh->prepare("INSERT INTO urls (name) VALUES (:name)");
    $stmt->bindValue(':name', $urlName, PDO::PARAM_STR);
    $stmt->execute();

    return $response
        ->withHeader('Location', '/')
        ->withStatus(302);
});

// Run app
$app->run();