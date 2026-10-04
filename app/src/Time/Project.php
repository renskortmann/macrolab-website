<?php

declare(strict_types=1);

namespace Macrolab\Time;

/**
 * Something time is booked against. Deliberately unrelated to equipment: the
 * time registration system does not know the booking system exists.
 */
final class Project
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $code,
        public readonly ?string $description,
        public readonly bool $isActive,
        public readonly int $entryCount = 0,
        public readonly int $totalMinutes = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            name: (string) $row['name'],
            code: isset($row['code']) && $row['code'] !== null && $row['code'] !== ''
                ? (string) $row['code'] : null,
            description: isset($row['description']) && $row['description'] !== null
                ? (string) $row['description'] : null,
            isActive: (bool) $row['is_active'],
            entryCount: (int) ($row['entry_count'] ?? 0),
            totalMinutes: (int) ($row['total_minutes'] ?? 0),
        );
    }

    /** "Beam alignment (BA-12)" when there is a code, otherwise just the name. */
    public function label(): string
    {
        return $this->code === null ? $this->name : $this->name . ' (' . $this->code . ')';
    }
}
