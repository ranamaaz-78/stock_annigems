<?php

namespace Tests\Feature;

use App\Enums\StonePricingMode;
use App\Enums\StoneStatus;
use App\Models\Branch;
use App\Models\Stone;
use App\Models\StoneImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Image code: a stone lot picks up the folder picture with the same name.
 */
class StoneImageCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        Storage::fake('stone-images');
    }

    /**
     * Minimal valid registration body with an image code.
     *
     * @return array<string, mixed>
     */
    private function payload(string $imageCode): array
    {
        return [
            'name' => 'CITIRINO',
            'pricing_mode' => StonePricingMode::PER_PIECE->value,
            'status' => StoneStatus::IN_STOCK->value,
            'pieces' => 5,
            'price_per_piece' => '9',
            'image_code' => $imageCode,
        ];
    }

    /**
     * The code is normalised and the matching picture attaches, whatever the
     * case or extension typed.
     */
    public function test_code_attaches_the_picture_with_that_name(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)->post(route('branch.stone-images.store'), [
            'files' => [UploadedFile::fake()->image('Stone1.jpg', 40, 40)],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->actingAs($admin)
            ->post(route('branch.stones.store'), $this->payload(' STONE1.JPG '))
            ->assertRedirect();

        $stone = Stone::query()->firstOrFail();
        $this->assertSame('stone1', $stone->image_code);
        $this->assertSame(StoneImage::query()->first()->id, $stone->image?->id);

        $this->actingAs($admin)->get(route('branch.stones.show', $stone))->assertOk()
            ->assertSee(route('branch.stone-images.show', $stone->image), false);
    }

    /**
     * A code with no picture yet is kept and attaches once the file exists.
     */
    public function test_code_without_a_picture_attaches_after_upload(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)->post(route('branch.stones.store'), $this->payload('opal'))->assertRedirect();
        $stone = Stone::query()->firstOrFail();
        $this->assertNull($stone->image);

        $this->actingAs($admin)->post(route('branch.stone-images.store'), [
            'files' => [UploadedFile::fake()->image('opal.png', 40, 40)],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertNotNull($stone->fresh()->image);
    }

    /**
     * Another branch's picture of the same name is never attached.
     */
    public function test_code_never_matches_another_branchs_picture(): void
    {
        $mine = Branch::factory()->create();
        $other = Branch::factory()->create();
        $admin = User::factory()->admin($mine->id)->create();
        $stranger = User::factory()->admin($other->id)->create();

        $this->actingAs($stranger)->post(route('branch.stone-images.store'), [
            'files' => [UploadedFile::fake()->image('ruby.jpg', 40, 40)],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->actingAs($admin)->post(route('branch.stones.store'), $this->payload('ruby'))->assertRedirect();

        $this->assertNull(Stone::query()->firstOrFail()->image);
    }
}
