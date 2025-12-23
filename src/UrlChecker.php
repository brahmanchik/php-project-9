<?php

namespace App;

namespace App;

use PDO;
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

class UrlChecker
{
    private $dbh;

    public function __construct(PDO $dbh)
    {
        $this->dbh = $dbh;
    }

    public function findStatusCode($url_id) //переименовать в getData например
    {
        $stmt = $this->dbh->prepare(
            "SELECT name FROM urls WHERE id = :url_id"
        );
        $stmt->bindValue(':url_id', $url_id, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC); // это можно избмежать если сделать так $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION); https://ru.hexlet.io/blog/posts/php-modul-pdo
        try {
            $client = new Client([
                'timeout' => 5,
                'http_errors' => false
            ]);
            $res = $client->request('GET', $row['name']);
            $statusCode = $res->getStatusCode();
            $data['status_code'] = $statusCode;

            //return $statusCode;
        } catch (\Throwable $e) {
            // Сайт недоступен (DNS, timeout и т.п.)
            return null;
        }
        $content = $res->getBody()->getContents();

        $crawler = new Crawler($content);

        $data['h1'] = optional($crawler->filter('h1')->getNode(0))?->textContent;
        $data['title'] = optional($crawler->filter('title')->getNode(0))?->textContent;
        $data['description'] = optional(
            $crawler->filter('meta[name="description"]')->getNode(0)
        )?->getAttribute('content');

        return $data;
    }
}