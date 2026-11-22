<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\ClientApplications\ClientApplicationResource;
use App\Filament\Resources\ClientApplications\Pages\CreateClientApplication;
use App\Filament\Resources\ClientApplications\Pages\EditClientApplication;
use App\Filament\Resources\ClientApplications\Pages\ListClientApplications;
use App\Models\ClientApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientApplicationProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ClientApplication::LOGO_DISK);
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_active' => true]));
    }

    public function test_url_and_logo_are_optional(): void
    {
        Livewire::test(CreateClientApplication::class)
            ->fillForm(['name' => 'Web Shelf', 'rate_limit_per_minute' => 60, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $application = ClientApplication::query()->where('slug', 'web-shelf')->sole();
        $this->assertNull($application->website_url);
        $this->assertNull($application->logo_path);
        $this->assertNull($application->logoUrl());
    }

    public function test_admin_can_save_a_url_and_a_logo(): void
    {
        Livewire::test(CreateClientApplication::class)
            ->fillForm([
                'name' => 'Web Shelf',
                'website_url' => 'https://shelf.example.com',
                'logo_path' => UploadedFile::fake()->image('logo.png', 128, 128),
                'rate_limit_per_minute' => 60,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $application = ClientApplication::query()->where('slug', 'web-shelf')->sole();
        $this->assertSame('https://shelf.example.com', $application->website_url);
        $this->assertStringStartsWith(ClientApplication::LOGO_DIRECTORY.'/', $application->logo_path);
        Storage::disk(ClientApplication::LOGO_DISK)->assertExists($application->logo_path);
        $this->assertNotNull($application->logoUrl());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'ftp scheme' => ['ftp://files.example.com'];
        yield 'not a url' => ['shelf'];
    }

    #[DataProvider('invalidUrls')]
    public function test_only_http_urls_are_accepted(string $url): void
    {
        Livewire::test(CreateClientApplication::class)
            ->fillForm(['name' => 'Web Shelf', 'website_url' => $url, 'rate_limit_per_minute' => 60, 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['website_url']);

        $this->assertSame(0, ClientApplication::query()->count());
    }

    public function test_svg_and_non_image_logos_are_rejected(): void
    {
        foreach ([UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'), UploadedFile::fake()->create('logo.pdf', 4, 'application/pdf')] as $file) {
            Livewire::test(CreateClientApplication::class)
                ->fillForm(['name' => 'Web Shelf', 'logo_path' => $file, 'rate_limit_per_minute' => 60, 'is_active' => true])
                ->call('create')
                ->assertHasFormErrors(['logo_path']);
        }

        $this->assertSame(0, ClientApplication::query()->count());
    }

    public function test_replacing_or_clearing_a_logo_removes_the_old_file(): void
    {
        $disk = Storage::disk(ClientApplication::LOGO_DISK);
        $first = UploadedFile::fake()->image('first.png')->store(ClientApplication::LOGO_DIRECTORY, ClientApplication::LOGO_DISK);
        $second = UploadedFile::fake()->image('second.png')->store(ClientApplication::LOGO_DIRECTORY, ClientApplication::LOGO_DISK);
        $application = ClientApplication::query()->create(['name' => 'Web Shelf', 'slug' => 'web-shelf', 'logo_path' => $first]);

        $application->update(['logo_path' => $second]);
        $disk->assertMissing($first);
        $disk->assertExists($second);

        $application->update(['logo_path' => null]);
        $disk->assertMissing($second);
    }

    public function test_soft_delete_keeps_the_logo_and_force_delete_removes_it(): void
    {
        $disk = Storage::disk(ClientApplication::LOGO_DISK);
        $path = UploadedFile::fake()->image('logo.png')->store(ClientApplication::LOGO_DIRECTORY, ClientApplication::LOGO_DISK);
        $application = ClientApplication::query()->create(['name' => 'Web Shelf', 'slug' => 'web-shelf', 'logo_path' => $path]);

        $application->delete();
        $disk->assertExists($path);

        $application->forceDelete();
        $disk->assertMissing($path);
    }

    public function test_edit_page_and_list_show_the_url_and_logo(): void
    {
        $path = UploadedFile::fake()->image('logo.png')->store(ClientApplication::LOGO_DIRECTORY, ClientApplication::LOGO_DISK);
        $withLogo = ClientApplication::query()->create([
            'name' => 'Web Shelf', 'slug' => 'web-shelf',
            'website_url' => 'https://shelf.example.com', 'logo_path' => $path,
        ]);
        $withoutLogo = ClientApplication::query()->create(['name' => 'Helpdesk', 'slug' => 'web-helpdesk']);

        Livewire::test(EditClientApplication::class, ['record' => $withLogo->getRouteKey()])
            ->assertFormSet(['website_url' => 'https://shelf.example.com']);

        Livewire::test(ListClientApplications::class)
            ->assertCanSeeTableRecords([$withLogo, $withoutLogo])
            ->assertSee('https://shelf.example.com')
            ->assertSee(basename($path))
            ->assertSee(ClientApplicationResource::initialsAvatar('Helpdesk'), false);
    }

    public function test_initials_avatar_is_a_local_escaped_svg(): void
    {
        $avatar = ClientApplicationResource::initialsAvatar('<script>');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $avatar);
        $svg = base64_decode(substr($avatar, strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('&lt;', $svg);
        $this->assertStringNotContainsString('<script', $svg);
    }
}
