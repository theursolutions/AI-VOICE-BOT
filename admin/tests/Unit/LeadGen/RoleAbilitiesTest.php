<?php

namespace Tests\Unit\LeadGen;

use App\Models\Role;
use Tests\TestCase;

/** Action-level permissions inside a module (Role::allowsAbility). */
class RoleAbilitiesTest extends TestCase
{
    private function role(array $modules, ?array $abilities = null, bool $owner = false): Role
    {
        return new Role(['modules' => $modules, 'abilities' => $abilities, 'is_owner' => $owner]);
    }

    public function test_a_role_without_stored_abilities_gets_all_of_them(): void
    {
        // Every role that existed before abilities did looks like this.
        $role = $this->role(['leadgen']);

        $this->assertTrue($role->allowsAbility('leadgen', 'send'));
        $this->assertSame(Role::abilityKeys('leadgen'), $role->abilitiesFor('leadgen'));
    }

    public function test_a_narrowed_role_gets_only_its_abilities(): void
    {
        $role = $this->role(['leadgen'], ['leadgen' => ['view', 'search']]);

        $this->assertTrue($role->allowsAbility('leadgen', 'view'));
        $this->assertFalse($role->allowsAbility('leadgen', 'approve'));
        $this->assertFalse($role->allowsAbility('leadgen', 'send'));
        $this->assertSame(['view', 'search'], $role->abilitiesFor('leadgen'));
    }

    public function test_no_module_means_no_ability(): void
    {
        $role = $this->role(['contacts']);

        $this->assertFalse($role->allowsAbility('leadgen', 'view'));
        $this->assertSame([], $role->abilitiesFor('leadgen'));
    }

    public function test_wildcard_and_owner_roles(): void
    {
        $this->assertTrue($this->role(['*'])->allowsAbility('leadgen', 'approve'));
        $this->assertTrue($this->role([], ['leadgen' => []], true)->allowsAbility('leadgen', 'send'));
    }

    public function test_an_empty_list_allows_nothing(): void
    {
        $this->assertFalse($this->role(['leadgen'], ['leadgen' => []])->allowsAbility('leadgen', 'view'));
    }
}
