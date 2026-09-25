<?php

use App\Connection;

require __DIR__ . '/../vendor/autoload.php';

$sql = file_get_contents(__DIR__ . '/../database.sql');

if ($sql === false) {
    throw new RuntimeException('Не удалось прочитать database.sql');
}

Connection::getConnection()->exec($sql);

echo "База данных подготовлена\n";