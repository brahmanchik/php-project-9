<?php

namespace App;

use PDO;
use Slim\Exception\HttpNotFoundException;
use App\Exception\UrlNotFoundException;

class UrlRepository
{
    private PDO $dbh;

    public function __construct(PDO $dbh)
    {
        $this->dbh = $dbh;
    }

    public function getById(int $id): array
    {
        $stmt = $this->dbh->prepare(
            "SELECT * FROM urls WHERE id = :id"
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC); // это можно избмежать если сделать так $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION); https://ru.hexlet.io/blog/posts/php-modul-pdo
        //if($row === false) {
        //    return null;
        //}

        if ($row === false) {
            throw new UrlNotFoundException('URL not found'); // Вместо \Exception
        }
        return $row;
    }
}