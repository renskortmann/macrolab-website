<?php

declare(strict_types=1);

namespace Macrolab;

/**
 * What a member may use. Every member can book equipment; the role adds time
 * registration and the overview of everyone's time.
 *
 * The administrator is not a member and has no role: their rights come from
 * being the administrator (Actor::$isAdmin).
 */
enum Role: string
{
    case LabUser = 'lab_user';
    case LabTechnician = 'lab_technician';
    case LabManager = 'lab_manager';

    public function label(): string
    {
        return match ($this) {
            self::LabUser       => 'Lab user',
            self::LabTechnician => 'Lab technician',
            self::LabManager    => 'Lab manager',
        };
    }

    /** Their own timesheet at /time. */
    public function canRegisterTime(): bool
    {
        return $this !== self::LabUser;
    }

    /** Everyone's time, read-only, with the CSV export - as the administrator sees it. */
    public function canViewAllTime(): bool
    {
        return $this === self::LabManager;
    }
}
