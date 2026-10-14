<?php

namespace App;

use GuzzleHttp\ClientInterface;
use Symfony\Component\DomCrawler\Crawler;

class UrlChecker
{
    public function __construct(private ClientInterface $client)
    {
    }

    public function getData(string $siteName): array
    {
        $response = $this->client->request('GET', $siteName, ['http_errors' => false]);
        $data = ['status_code' => $response->getStatusCode()];
        $content = $response->getBody()->getContents();

        $crawler = new Crawler($content);

        $data['h1'] = $this->truncate(
            $crawler->filter('h1')->getNode(0)?->textContent
        );

        $data['title'] = $this->truncate(
            $crawler->filter('title')->getNode(0)?->textContent
        );

        $data['description'] = $this->truncate(
            $crawler->filter('meta[name="description"]')->getNode(0)?->getAttribute('content')
        );

        return $data;
    }

    private function truncate(?string $value): ?string
    {
        if ($value === null || mb_strlen($value) <= 200) {
            return $value;
        }

        return mb_substr($value, 0, 200) . '...';
    }
}
