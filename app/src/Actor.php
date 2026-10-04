<?php

declare(strict_types=1);

namespace Macrolab;

/**
 * Whoever is making the current request: a lab member, or the administrator.
 * Passed to BookingPolicy, which is the only thing that decides what they may
 * change.
 */
final class Actor
{
    private function __construct(
        public readonly ?User $user,
        public readonly bool $isAdmin,
    ) {
    }

    public static function forUser(User $user): self
    {
        return new self($user, false);
    }

    public static function forAdmin(): self
    {
        return new self(null, true);
    }

    public function userId(): ?int
    {
        return $this->user?->id;
    }

    public function label(): string
    {
        return $this->isAdmin ? 'Administrator' : ($this->user?->label() ?? 'unknown');
    }

    /** Their own timesheet: lab technicians and lab managers. The admin keeps none. */
    public function canRegisterTime(): bool
    {
        return $this->user !== null && $this->user->role->canRegisterTime();
    }

    /** Everyone's time, read-only: the administrator and lab managers. */
    public function canViewAllTime(): bool
    {
        return $this->isAdmin || ($this->user !== null && $this->user->role->canViewAllTime());
    }
}
