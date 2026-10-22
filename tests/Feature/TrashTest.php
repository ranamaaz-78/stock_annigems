<?php

namespace Tests\Feature;

use App\Enums\DeliveryNoteStatus;
use App\Enums\LineType;
use App\Enums\StoneMovementType;
use App\Models\Branch;
use App\Models\Creditor;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Stone;
use App\Models\User;
use App\Services\StoneStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Soft delete and the Trash page: delete returns stock, restore never takes it
 * out again, and nothing can be removed permanently.
 */
class TrashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
    }

    public function test_deleting_an_open_delivery_note_returns_stock_and_restore_keeps_it_cancelled(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();
        $stone = Stone::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        app(StoneStockService::class)->apply($stone, StoneMovementType::INTAKE, 5, $admin);

        $this->actingAs($admin)->postJson(route('branch.delivery-notes.store'), [
            'walkin_name' => 'Maria Garcia',
            'walkin_phone' => '03001234567',
            'quotation_date' => now()->toDateString(),
            'discount_type' => 'none',
            'discount_value' => 0,
            'tax_type' => 'none',
            'tax_rate' => 0,
            'lines' => [[
                'line_type' => LineType::LOOSE_STONE->value,
                'stone_id' => $stone->id,
                'quantity' => 3,
                'price_per_piece' => 20,
            ]],
        ])->assertOk();

        $note = DeliveryNote::query()->firstOrFail();
        $this->assertSame(2, (int) $stone->fresh()->quantity);

        $this->actingAs($admin)
            ->delete(route('branch.records.destroy', ['type' => 'delivery-notes', 'uuid' => $note->uuid]))
            ->assertRedirect(route('branch.delivery-notes.index'));

        $this->assertSoftDeleted('delivery_notes', ['id' => $note->id]);
        $this->assertSame(5, (int) $stone->fresh()->quantity);

        $this->actingAs($admin)->get(route('branch.trash.index', ['tab' => 'delivery-notes']))
            ->assertOk()
            ->assertSee($note->delivery_note_number);

        $this->actingAs($admin)
            ->post(route('branch.trash.restore', ['type' => 'delivery-notes', 'uuid' => $note->uuid]))
            ->assertRedirect();

        $this->assertNotSoftDeleted('delivery_notes', ['id' => $note->id]);
        $this->assertSame(DeliveryNoteStatus::CANCELLED, $note->fresh()->status);
        $this->assertSame(5, (int) $stone->fresh()->quantity);
    }

    public function test_customers_and_creditors_can_be_deleted_and_restored(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();
        $customer = Customer::factory()->create();
        $creditor = Creditor::factory()->create(['branch_id' => $branch->id]);

        foreach ([['customers', $customer, 'customers'], ['creditors', $creditor, 'creditors']] as [$type, $record, $table]) {
            $this->actingAs($admin)
                ->delete(route('branch.records.destroy', ['type' => $type, 'uuid' => $record->uuid]))
                ->assertRedirect();
            $this->assertSoftDeleted($table, ['id' => $record->id]);

            $this->actingAs($admin)
                ->post(route('branch.trash.restore', ['type' => $type, 'uuid' => $record->uuid]))
                ->assertRedirect();
            $this->assertNotSoftDeleted($table, ['id' => $record->id]);
        }
    }

    public function test_cashier_cannot_delete_or_open_the_trash(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier($branch->id)->create();
        $customer = Customer::factory()->create();

        $this->actingAs($cashier)
            ->delete(route('branch.records.destroy', ['type' => 'customers', 'uuid' => $customer->uuid]))
            ->assertForbidden();
        $this->actingAs($cashier)->get(route('branch.trash.index'))->assertForbidden();
        $this->assertNotSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_no_route_can_delete_permanently(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'trash') || str_contains($route->uri(), 'records')) {
                $this->assertNotContains('forceDelete', [$route->getActionMethod()]);
            }
        }

        $this->assertFalse(method_exists(\App\Http\Controllers\Branch\TrashController::class, 'forceDelete'));
    }
}
