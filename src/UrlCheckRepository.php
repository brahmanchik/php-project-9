<?php

namespace App;

use PDO;

class UrlCheckRepository
{
    public function __construct(private PDO $dbh)
    {
    }

    public function getByUrlId(int $urlId): array
    {
        $stmt = $this->dbh->prepare(
            "SELECT id, url_id, status_code, h1, title, description, created_at
            FROM url_checks
            WHERE url_id = :url_id
            ORDER BY id DESC"
        );
        $stmt->execute(['url_id' => $urlId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLatestByUrlId(): array
    {
        $stmt = $this->dbh->query(
            "SELECT DISTINCT ON (url_id)
                url_id,
                status_code,
                created_at AS last_check_at
            FROM url_checks
            ORDER BY url_id, created_at DESC, id DESC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(int $urlId, array $data): void
    {
        $stmt = $this->dbh->prepare(
            "INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at)
            VALUES (:url_id, :status_code, :h1, :title, :description, NOW()::timestamp(0))"
        );
        $stmt->execute([
            'url_id' => $urlId,
            'status_code' => $data['status_code'],
            'h1' => $data['h1'],
            'title' => $data['title'],
            'description' => $data['description'],
        ]);
    }
}
