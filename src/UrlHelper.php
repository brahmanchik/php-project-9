<?php
namespace App;

use PDO;
class UrlHelper
{
    private $dbh;

    public function __construct(PDO $dbh)
    {
        $this->dbh = $dbh;
    }

    public function createIfNotExists(string $url): bool
    {
        $stmt = $this->dbh->prepare("SELECT COUNT(1) as cnt FROM urls WHERE name = :url");
        $stmt->bindValue(':url', $url, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['cnt'] == 0) {
            return true; // такого URL в базе ещё нет
        }

        return false; // URL уже есть
    }
}