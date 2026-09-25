<?php

namespace App\Services\Shopify;

/** Snapshot of a Shopify BulkOperation (https://shopify.dev/docs/api/admin-graphql/latest/objects/BulkOperation). */
final readonly class BulkOperation
{
    public const DONE_OK = ['COMPLETED'];

    public const DONE_ERROR = ['FAILED', 'CANCELED', 'CANCELING', 'EXPIRED'];

    public function __construct(
        public string $id,
        public string $status,
        public int $objectCount = 0,
        public ?string $url = null,
        public ?string $errorCode = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            status: (string) $data['status'],
            objectCount: (int) ($data['objectCount'] ?? 0),
            url: $data['url'] ?? null,
            errorCode: $data['errorCode'] ?? null,
        );
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, self::DONE_OK, true);
    }

    public function isFailed(): bool
    {
        return in_array($this->status, self::DONE_ERROR, true);
    }

    public function isFinished(): bool
    {
        return $this->isCompleted() || $this->isFailed();
    }
}
