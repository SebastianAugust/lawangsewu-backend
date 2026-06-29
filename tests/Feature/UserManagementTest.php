<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::create([
            'name' => 'Owner',
            'email' => 'owner@test.com',
            'password' => Hash::make('secret123'),
            'role' => 'owner',
            'branch_id' => null,
        ]);
    }

    private function branch(): Branch
    {
        return Branch::create([
            'name' => 'Pusat',
            'address' => 'Semarang',
            'is_active' => true,
        ]);
    }

    public function test_owner_can_create_cabang_and_kasir_together(): void
    {
        Sanctum::actingAs($this->owner());

        $res = $this->postJson('/api/users', [
            'branch_name' => 'Cabang Pandanaran',
            'name' => 'Budi',
            'email' => 'budi@test.com',
            'password' => 'rahasia123',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('branches', ['name' => 'Cabang Pandanaran']);

        $branch = Branch::where('name', 'Cabang Pandanaran')->first();
        $this->assertDatabaseHas('users', [
            'email' => 'budi@test.com',
            'role' => 'kasir',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }

    public function test_creating_cabang_is_one_to_one(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/users', [
            'branch_name' => 'Cabang A',
            'name' => 'Budi',
            'email' => 'budi@test.com',
            'password' => 'rahasia123',
        ])->assertStatus(201);

        $this->postJson('/api/users', [
            'branch_name' => 'Cabang B',
            'name' => 'Siti',
            'email' => 'siti@test.com',
            'password' => 'rahasia123',
        ])->assertStatus(201);

        // Each kasir owns exactly one distinct branch — never shared.
        $branchIds = User::where('role', 'kasir')->pluck('branch_id');
        $this->assertCount(2, $branchIds);
        $this->assertCount(2, $branchIds->unique());
    }

    public function test_kasir_cannot_manage_users(): void
    {
        $branch = $this->branch();
        $kasir = User::create([
            'name' => 'Kasir',
            'email' => 'kasir@test.com',
            'password' => Hash::make('rahasia123'),
            'role' => 'kasir',
            'branch_id' => $branch->id,
        ]);
        Sanctum::actingAs($kasir);

        $this->getJson('/api/users')->assertStatus(403);
        $this->postJson('/api/users', [
            'name' => 'X',
            'email' => 'x@test.com',
            'password' => 'rahasia123',
            'branch_id' => $branch->id,
        ])->assertStatus(403);
    }

    public function test_inactive_kasir_cannot_login_active_can(): void
    {
        $branch = $this->branch();
        $kasir = User::create([
            'name' => 'Kasir',
            'email' => 'kasir@test.com',
            'password' => Hash::make('rahasia123'),
            'role' => 'kasir',
            'branch_id' => $branch->id,
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => 'kasir@test.com',
            'password' => 'rahasia123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $kasir->update(['is_active' => true]);

        $this->postJson('/api/login', [
            'email' => 'kasir@test.com',
            'password' => 'rahasia123',
        ])->assertStatus(200)->assertJsonStructure(['token', 'user']);
    }

    public function test_editing_kasir_without_branch_creates_and_assigns_one(): void
    {
        Sanctum::actingAs($this->owner());

        // Mirrors production data: a kasir created before the cabang system,
        // so branch_id is null.
        $kasir = User::create([
            'name' => 'Kasir Lama',
            'email' => 'lama@test.com',
            'password' => Hash::make('rahasia123'),
            'role' => 'kasir',
            'branch_id' => null,
        ]);

        $this->putJson("/api/users/{$kasir->id}", [
            'branch_name' => 'Cabang Baru',
        ])->assertStatus(200)
            ->assertJsonPath('branch.name', 'Cabang Baru');

        $branch = Branch::where('name', 'Cabang Baru')->first();
        $this->assertNotNull($branch);
        $this->assertDatabaseHas('users', [
            'id' => $kasir->id,
            'branch_id' => $branch->id,
        ]);
    }

    public function test_owner_can_delete_cabang_and_kasir(): void
    {
        Sanctum::actingAs($this->owner());
        $branch = $this->branch();
        $kasir = User::create([
            'name' => 'Kasir',
            'email' => 'kasir@test.com',
            'password' => Hash::make('rahasia123'),
            'role' => 'kasir',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);

        $this->deleteJson("/api/users/{$kasir->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('users', ['id' => $kasir->id]);
        $this->assertDatabaseMissing('branches', ['id' => $branch->id]);
    }

    public function test_deleting_kasir_keeps_orders_but_unlinks_them(): void
    {
        Sanctum::actingAs($this->owner());
        $branch = $this->branch();
        $kasir = User::create([
            'name' => 'Kasir',
            'email' => 'kasir@test.com',
            'password' => Hash::make('rahasia123'),
            'role' => 'kasir',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);

        $order = \App\Models\Order::create([
            'user_id' => $kasir->id,
            'branch_id' => $branch->id,
            'total_price' => 50000,
        ]);

        $this->deleteJson("/api/users/{$kasir->id}")
            ->assertStatus(200);

        // Transaksi tetap ada, hanya tidak lagi terikat ke kasir/cabang.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'user_id' => null,
            'branch_id' => null,
        ]);
    }

    public function test_owner_cannot_delete_owner_account(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/users/{$owner->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public function test_owner_can_deactivate_kasir(): void
    {
        Sanctum::actingAs($this->owner());
        $branch = $this->branch();
        $kasir = User::create([
            'name' => 'Kasir',
            'email' => 'kasir@test.com',
            'password' => Hash::make('rahasia123'),
            'role' => 'kasir',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);

        $this->putJson("/api/users/{$kasir->id}", ['is_active' => false])
            ->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $kasir->id,
            'is_active' => false,
        ]);
    }
}
