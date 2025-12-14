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

    public static function normalize(string $url): string {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'http';
        $host   = $parts['host'] ?? '';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path   = $parts['path'] ?? '';
        return $scheme . '://' . $host . $port . $path;
    }
    public function findIdByUrl(string $url): ?int
    {
        $url = self::normalize($url);

        $stmt = $this->dbh->prepare(
            "SELECT id FROM urls WHERE name = :url LIMIT 1"
        );
        $stmt->bindValue(':url', $url, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null; // URL не найден
        }

        return (int) $row['id']; // URL уже есть → возвращаем id
    }
}

