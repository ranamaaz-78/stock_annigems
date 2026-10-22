<?php

namespace Tests\Feature;

use App\Enums\StoneMovementType;
use App\Models\Branch;
use App\Models\Stone;
use App\Models\User;
use App\Services\StoneStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Inventory export: the sheet's column order with the barcode last, remaining
 * stock, and narrowing to ticked folders or lots.
 */
class StoneExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
    }

    /**
     * @return list<list<mixed>>
     */
    private function rowsOf(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($path, $content);

        return IOFactory::load($path)->getActiveSheet()->toArray();
    }

    public function test_export_has_the_sheet_columns_with_barcode_last_and_remaining_stock(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();
        $stone = Stone::factory()->create(['branch_id' => $branch->id, 'barcode' => '654321', 'is_active' => true]);
        app(StoneStockService::class)->apply($stone, StoneMovementType::INTAKE, 7, $admin);

        $response = $this->actingAs($admin)->post(route('branch.stones.export'));
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));

        $rows = $this->rowsOf($response->getContent());
        $this->assertSame('MATERIAL / NAME', $rows[0][0]);
        $this->assertSame('BARCODE', $rows[0][16]);
        $this->assertSame('654321', (string) $rows[1][16]);
        $this->assertSame($stone->name, $rows[1][0]);
    }

    public function test_export_can_be_narrowed_to_ticked_lots_and_is_branch_scoped(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();
        $a = Stone::factory()->create(['branch_id' => $branch->id, 'name' => 'AAA']);
        Stone::factory()->create(['branch_id' => $branch->id, 'name' => 'BBB']);
        Stone::factory()->create(['branch_id' => $other->id, 'name' => 'ZZZ']);

        $all = $this->rowsOf($this->actingAs($admin)->post(route('branch.stones.export'))->getContent());
        $this->assertCount(3, $all);

        $one = $this->rowsOf($this->actingAs($admin)->post(route('branch.stones.export'), ['uuids' => [$a->uuid]])->getContent());
        $this->assertCount(2, $one);
        $this->assertSame('AAA', $one[1][0]);
    }

    public function test_user_without_stone_view_cannot_export(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($user)->post(route('branch.stones.export'))->assertForbidden();
    }
}
