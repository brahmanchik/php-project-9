<?php

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

    public function getData(string $siteName) //переименовать в getData например
    {
        try {
            $client = new Client([
                'timeout' => 5,
                'http_errors' => false
            ]);
            $res = $client->request('GET', $siteName);
            $statusCode = $res->getStatusCode();

            if ($statusCode === 404) {
                throw new \App\Exception\UrlNotFoundException('URL returned 404');
            }
            $data['status_code'] = $statusCode;
        } catch (\App\Exception\UrlNotFoundException $e) {
            // Сайт недоступен (DNS, timeout и т.п.)
            throw $e;
        } catch (\Throwable $e) {
            // Все остальные ошибки (DNS, timeout, сеть, SSL и т.д.) - это 500
            throw new \Exception('Failed to check URL: ' . $e->getMessage(), 500);
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
