<?php

declare(strict_types=1);

namespace Macrolab\Booking;

use DateTimeImmutable;
use Macrolab\Clock;

/**
 * One reservation. Times are UTC instants; the interval is half-open,
 * [startsAt, endsAt), so a booking that ends at 10:00 and one that starts at
 * 10:00 do not collide.
 */
final class Booking
{
    public function __construct(
        public readonly int $id,
        public readonly int $equipmentId,
        public readonly int $userId,
        public readonly DateTimeImmutable $startsAt,
        public readonly DateTimeImmutable $endsAt,
        public readonly ?string $purpose,
        public readonly string $status,
        public readonly bool $createdByAdmin = false,
        public readonly ?string $ownerNetid = null,
        public readonly ?string $ownerName = null,
        public readonly ?string $equipmentName = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            equipmentId: (int) $row['equipment_id'],
            userId: (int) $row['user_id'],
            startsAt: Clock::fromSql((string) $row['starts_at']),
            endsAt: Clock::fromSql((string) $row['ends_at']),
            purpose: $row['purpose'] !== null ? (string) $row['purpose'] : null,
            status: (string) $row['status'],
            createdByAdmin: (bool) ($row['created_by_admin'] ?? false),
            ownerNetid: isset($row['owner_netid']) && $row['owner_netid'] !== null
                ? (string) $row['owner_netid'] : null,
            ownerName: isset($row['owner_name']) && $row['owner_name'] !== null
                ? (string) $row['owner_name'] : null,
            equipmentName: isset($row['equipment_name']) && $row['equipment_name'] !== null
                ? (string) $row['equipment_name'] : null,
        );
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function durationMinutes(): int
    {
        return (int) round(($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp()) / 60);
    }

    /** Name to display for the owner, falling back to the netID. */
    public function ownerLabel(): string
    {
        if ($this->ownerName !== null && $this->ownerName !== '') {
            return $this->ownerName;
        }

        return $this->ownerNetid ?? ('user #' . $this->userId);
    }
}
