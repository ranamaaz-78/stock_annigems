<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosCustomerPhoneUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
    }

    public function test_phone_is_saved_with_the_ticket_and_a_taken_number_is_refused(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();
        $this->actingAs($admin);

        $customer = Customer::factory()->create(['phone' => '03001112222']);
        $other = Customer::factory()->create(['phone' => '03003334444']);
        $service = app(CustomerService::class);

        $service->syncFromTicket($customer, ['walkin_name' => $customer->name, 'walkin_phone' => '03005556666'], $admin);
        $this->assertSame('03005556666', $customer->fresh()->phone);

        $this->expectException(ValidationException::class);
        $service->syncFromTicket($customer->fresh(), ['walkin_name' => $customer->name, 'walkin_phone' => $other->phone], $admin);
    }
}
