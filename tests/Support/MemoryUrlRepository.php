<?php

namespace Tests\Support;

use App\Exception\UrlNotFoundException;
use App\UrlRepository;

class MemoryUrlRepository extends UrlRepository
{
    public array $urls = [];

    public function __construct()
    {
    }

    public function getById(int $id): array
    {
        return $this->urls[$id] ?? throw new UrlNotFoundException();
    }

    public function findIdByName(string $name): ?int
    {
        foreach ($this->urls as $url) {
            if ($url['name'] === $name) {
                return $url['id'];
            }
        }

        return null;
    }

    public function create(string $name): int
    {
        $id = count($this->urls) + 1;
        $this->urls[$id] = ['id' => $id, 'name' => $name, 'created_at' => '2026-01-01 12:00:00'];

        return $id;
    }

    public function getAll(): array
    {
        return array_reverse(array_values($this->urls));
    }
}
