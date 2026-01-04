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
            "SELECT * FROM urls WHERE id = :id"
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new UrlNotFoundException('URL not found');
        }
        return $row;
    }
}
