<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\StoneImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stones image folder: unique display names, private files, branch scope.
 */
class StoneImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedModuleTwo();
        Storage::fake('stone-images');
    }

    /**
     * A real JPEG the folder will accept.
     */
    private function jpeg(string $name): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40);
    }

    /**
     * Upload keeps the original filename and writes a private path.
     */
    public function test_upload_stores_the_original_name_and_a_separate_path(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)
            ->post(route('branch.stone-images.store'), [
                'files' => [$this->jpeg('Round Diamond.jpg')],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.images.0.name', 'Round Diamond');

        $image = StoneImage::query()->first();
        $this->assertNotNull($image);
        $this->assertSame('Round Diamond', $image->name);
        $this->assertSame('round diamond', $image->name_key);
        $this->assertNotSame($image->name, basename($image->path));
        Storage::disk('stone-images')->assertExists($image->path);

        $this->actingAs($admin)
            ->get(route('branch.stone-images.show', $image))
            ->assertOk();
    }

    /**
     * The same name, ignoring case, cannot be stored twice in one branch.
     */
    public function test_duplicate_name_is_rejected(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)->post(route('branch.stone-images.store'), [
            'files' => [$this->jpeg('opal.png')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->actingAs($admin)->post(route('branch.stone-images.store'), [
            'files' => [$this->jpeg('OPAL.PNG')],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(1, StoneImage::query()->count());
    }

    /**
     * Rename changes the label only. The file path stays.
     */
    public function test_rename_updates_the_display_name_without_moving_the_file(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();

        $this->actingAs($admin)->post(route('branch.stone-images.store'), [
            'files' => [$this->jpeg('old-name.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $image = StoneImage::query()->first();
        $path = $image->path;

        $this->actingAs($admin)
            ->patchJson(route('branch.stone-images.update', $image), ['name' => 'new-name'])
            ->assertOk()
            ->assertJsonPath('data.image.name', 'new-name');

        $image->refresh();
        $this->assertSame('new-name', $image->name);
        $this->assertSame($path, $image->path);
        Storage::disk('stone-images')->assertExists($path);
    }

    /**
     * Another branch cannot open or rename this folder's picture.
     */
    public function test_another_branch_cannot_open_the_image(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $admin = User::factory()->admin($branch->id)->create();
        $outsider = User::factory()->admin($other->id)->create();

        $this->actingAs($admin)->post(route('branch.stone-images.store'), [
            'files' => [$this->jpeg('private.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $image = StoneImage::query()->withoutGlobalScopes()->first();

        $this->actingAs($outsider)
            ->get(route('branch.stone-images.show', $image))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->patchJson(route('branch.stone-images.update', $image), ['name' => 'stolen.jpg'])
            ->assertForbidden();
    }

    /**
     * Cashiers do not get the folder.
     */
    public function test_cashier_cannot_open_the_folder(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier($branch->id)->create();

        $this->actingAs($cashier)
            ->get(route('branch.stone-images.index'))
            ->assertForbidden();
    }

    /**
     * The upload asks first: names already in the folder are listed, ignoring
     * case and extension.
     */
    public function test_check_lists_names_already_in_the_folder(): void
    {
        $admin = User::factory()->admin(Branch::factory()->create()->id)->create();

        $this->actingAs($admin)->post(route('branch.stone-images.store'), [
            'files' => [$this->jpeg('opal.png')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->actingAs($admin)
            ->postJson(route('branch.stone-images.check'), ['names' => ['OPAL.JPG', 'ruby.jpg']])
            ->assertOk()
            ->assertJsonPath('data.existing', ['opal']);
    }

    /**
     * Replace keeps the same entry and name but swaps the file, and the old
     * file is removed.
     */
    public function test_replace_swaps_the_file_and_keeps_the_entry(): void
    {
        $admin = User::factory()->admin(Branch::factory()->create()->id)->create();

        $this->actingAs($admin)->post(route('branch.stone-images.store'), [
            'files' => [$this->jpeg('opal.png')],
        ], ['Accept' => 'application/json'])->assertCreated();
        $before = StoneImage::query()->firstOrFail();
        $oldPath = $before->path;

        $this->actingAs($admin)->post(route('branch.stone-images.store'), [
            'files' => [$this->jpeg('Opal.jpg')],
            'on_duplicate' => 'replace',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.replaced.0', $before->uuid)
            ->assertJsonPath('data.images.0.uuid', $before->uuid);

        $this->assertSame(1, StoneImage::query()->count());
        $after = $before->fresh();
        $this->assertSame('opal', $after->name);
        $this->assertNotSame($oldPath, $after->path);
        $this->assertSame('image/jpeg', $after->mime);
        Storage::disk('stone-images')->assertMissing($oldPath);
        Storage::disk('stone-images')->assertExists($after->path);
    }

    /**
     * Skip leaves the existing picture alone and still uploads the rest.
     */
    public function test_skip_keeps_the_existing_picture_and_uploads_the_rest(): void
    {
        $admin = User::factory()->admin(Branch::factory()->create()->id)->create();

        $this->actingAs($admin)->post(route('branch.stone-images.store'), [
            'files' => [$this->jpeg('opal.png')],
        ], ['Accept' => 'application/json'])->assertCreated();
        $original = StoneImage::query()->firstOrFail();

        $this->actingAs($admin)->post(route('branch.stone-images.store'), [
            'files' => [$this->jpeg('opal.jpg'), $this->jpeg('ruby.jpg')],
            'on_duplicate' => 'skip',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.skipped.0', 'opal')
            ->assertJsonCount(1, 'data.images');

        $this->assertSame(2, StoneImage::query()->count());
        $this->assertSame($original->path, $original->fresh()->path);

        // Everything skipped is still a success, with nothing new to show.
        $this->actingAs($admin)->post(route('branch.stone-images.store'), [
            'files' => [$this->jpeg('ruby.png')],
            'on_duplicate' => 'skip',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(0, 'data.images');
    }

    /**
     * A replaced picture is addressed with a new version, so a browser does
     * not keep showing the old file.
     */
    public function test_replaced_picture_gets_a_new_version_in_its_url(): void
    {
        $admin = User::factory()->admin(Branch::factory()->create()->id)->create();

        $this->actingAs($admin)->post(route('branch.stone-images.store'), [
            'files' => [$this->jpeg('opal.png')],
        ], ['Accept' => 'application/json'])->assertCreated();
        $image = StoneImage::query()->firstOrFail();
        $image->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
        $oldUrl = $image->fresh()->url();

        $this->actingAs($admin)->post(route('branch.stone-images.store'), [
            'files' => [$this->jpeg('opal.jpg')],
            'on_duplicate' => 'replace',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertNotSame($oldUrl, $image->fresh()->url());
    }

    /**
     * Another branch's folder is never touched by a replace.
     */
    public function test_replace_never_touches_another_branchs_picture(): void
    {
        $mine = User::factory()->admin(Branch::factory()->create()->id)->create();
        $other = User::factory()->admin(Branch::factory()->create()->id)->create();

        $this->actingAs($other)->post(route('branch.stone-images.store'), [
            'files' => [$this->jpeg('opal.png')],
        ], ['Accept' => 'application/json'])->assertCreated();
        $theirs = StoneImage::query()->withoutGlobalScopes()->firstOrFail();

        $this->actingAs($mine)->post(route('branch.stone-images.store'), [
            'files' => [$this->jpeg('opal.jpg')],
            'on_duplicate' => 'replace',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(2, StoneImage::query()->withoutGlobalScopes()->count());
        $this->assertSame($theirs->path, $theirs->fresh()->path);
    }
}
