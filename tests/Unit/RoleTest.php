<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use Macrolab\Actor;
use Macrolab\Role;
use Macrolab\User;
use PHPUnit\Framework\TestCase;

/**
 * Who may use what. Every member books machines; the role adds the time pages.
 */
final class RoleTest extends TestCase
{
    public function testWhatEachRoleMayUse(): void
    {
        self::assertFalse(Role::LabUser->canRegisterTime());
        self::assertFalse(Role::LabUser->canViewAllTime());

        self::assertTrue(Role::LabTechnician->canRegisterTime());
        self::assertFalse(Role::LabTechnician->canViewAllTime());

        self::assertTrue(Role::LabManager->canRegisterTime());
        self::assertTrue(Role::LabManager->canViewAllTime());
    }

    public function testTheAdministratorSeesEveryonesTimeButKeepsNoTimesheet(): void
    {
        $admin = Actor::forAdmin();

        self::assertTrue($admin->canViewAllTime());
        self::assertFalse($admin->canRegisterTime());
    }

    public function testAnActorFollowsTheirRole(): void
    {
        self::assertFalse(Actor::forUser($this->user(Role::LabUser))->canRegisterTime());
        self::assertTrue(Actor::forUser($this->user(Role::LabTechnician))->canRegisterTime());
        self::assertTrue(Actor::forUser($this->user(Role::LabManager))->canViewAllTime());
    }

    public function testAnUnknownStoredRoleMeansTheLeastAccess(): void
    {
        $user = User::fromRow([
            'id' => 1, 'netid' => 'x', 'display_name' => null, 'email' => null,
            'status' => 'approved', 'role' => 'superuser',
        ]);

        self::assertSame(Role::LabUser, $user->role);
    }

    private function user(Role $role): User
    {
        return new User(id: 1, netid: 'x', displayName: null, email: null, status: 'approved', role: $role);
    }
}
