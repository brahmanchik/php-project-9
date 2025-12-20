<?php
namespace App;

namespace App;

use PDO;
use GuzzleHttp\Client;

class UrlChecker
{
    private $dbh;

    public function __construct(PDO $dbh)
    {
        $this->dbh = $dbh;
    }

    public function findStatusCode($url_id)
    {
        $stmt = $this->dbh->prepare(
            "SELECT name FROM urls WHERE id = :url_id"
        );
        $stmt->bindValue(':url_id', $url_id, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        try {
            $client = new Client([
                'timeout' => 5,
                'http_errors' => false
            ]);
            $res = $client->request('GET', $row['name']);
            $statusCode = $res->getStatusCode();
            return $statusCode;
        } catch (\Throwable $e) {
            // Сайт недоступен (DNS, timeout и т.п.)
            return null;
        }

    }
}