<?php

namespace Tests\Support;

use App\UrlCheckRepository;

class MemoryUrlCheckRepository extends UrlCheckRepository
{
    public array $checks = [];

    public function __construct()
    {
    }

    public function getByUrlId(int $urlId): array
    {
        return array_values(array_reverse(array_filter(
            $this->checks,
            fn (array $check) => $check['url_id'] === $urlId
        )));
    }

    public function getLatestByUrlId(): array
    {
        $latest = [];
        foreach ($this->checks as $check) {
            $latest[$check['url_id']] = [
                'url_id' => $check['url_id'],
                'status_code' => $check['status_code'],
                'last_check_at' => $check['created_at'],
            ];
        }

        return array_values($latest);
    }

    public function create(int $urlId, array $data): void
    {
        $this->checks[] = $data + [
            'id' => count($this->checks) + 1,
            'url_id' => $urlId,
            'created_at' => '2026-01-01 12:00:00',
        ];
    }
}
