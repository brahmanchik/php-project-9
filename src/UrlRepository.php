<?php

namespace App;

use PDO;
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
            "SELECT id, name, created_at FROM urls WHERE id = :id"
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new UrlNotFoundException('URL not found');
        }
        return $row;
    }

    public function findIdByName(string $name): ?int
    {
        $stmt = $this->dbh->prepare("SELECT id FROM urls WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => $name]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function create(string $name): int
    {
        $stmt = $this->dbh->prepare("INSERT INTO urls (name) VALUES (:name) RETURNING id");
        $stmt->execute(['name' => $name]);

        return (int) $stmt->fetchColumn();
    }

    public function getAll(): array
    {
        $stmt = $this->dbh->query("SELECT id, name, created_at FROM urls ORDER BY id DESC");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
