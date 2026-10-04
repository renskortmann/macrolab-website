<?php

declare(strict_types=1);

namespace Macrolab;

/**
 * A lab member. Identified by netID in both authentication stages, so that
 * accounts created with a local password keep working unchanged once TU Delft
 * SSO is switched on.
 */
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $netid,
        public readonly ?string $displayName,
        public readonly ?string $email,
        public readonly string $status,
        public readonly Role $role = Role::LabUser,
        public readonly ?string $passwordHash = null,
        public readonly ?string $samlNameId = null,
        public readonly ?string $note = null,
        public readonly ?string $firstLoginAt = null,
        public readonly ?string $lastLoginAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            netid: (string) $row['netid'],
            displayName: $row['display_name'] !== null ? (string) $row['display_name'] : null,
            email: $row['email'] !== null ? (string) $row['email'] : null,
            status: (string) $row['status'],
            // An unknown or missing value means the least access.
            role: Role::tryFrom((string) ($row['role'] ?? '')) ?? Role::LabUser,
            passwordHash: isset($row['password_hash']) && $row['password_hash'] !== null
                ? (string) $row['password_hash'] : null,
            samlNameId: isset($row['saml_name_id']) && $row['saml_name_id'] !== null
                ? (string) $row['saml_name_id'] : null,
            note: isset($row['note']) && $row['note'] !== null ? (string) $row['note'] : null,
            firstLoginAt: isset($row['first_login_at']) && $row['first_login_at'] !== null
                ? (string) $row['first_login_at'] : null,
            lastLoginAt: isset($row['last_login_at']) && $row['last_login_at'] !== null
                ? (string) $row['last_login_at'] : null,
        );
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function hasPassword(): bool
    {
        return $this->passwordHash !== null && $this->passwordHash !== '';
    }

    /** Name to show next to a booking. */
    public function label(): string
    {
        return $this->displayName !== null && $this->displayName !== ''
            ? $this->displayName
            : $this->netid;
    }
}
