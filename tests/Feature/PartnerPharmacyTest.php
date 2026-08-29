<?php

namespace Tests\Feature;

use App\Models\PartnerPharmacy;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The partner-pharmacy logo wall.
 *
 * Curated rows rather than a view over `stores`: a pharmacy can belong on the
 * wall before it holds a vendor account, and holding one is not by itself a
 * reason to be displayed. See the migration for the full reasoning.
 */
class PartnerPharmacyTest extends TestCase
{
    private function admin(): User
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        return $this->makeUser([
            'role' => 'admin',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
    }

    private function logo(): UploadedFile
    {
        return UploadedFile::fake()->image('logo.png', 400, 200);
    }

    public function test_an_admin_can_add_a_partner(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->postJson('/api/v1/admin/partner-pharmacies', [
            'name' => 'Mercy Pharmacy',
            'logo' => $this->logo(),
            'link_url' => '/stores/mercy',
            'sort_order' => 1,
            'is_active' => 1,
        ], $this->tokenFor($admin))->assertCreated();

        $partner = PartnerPharmacy::firstWhere('name', 'Mercy Pharmacy');

        $this->assertNotNull($partner);
        Storage::disk('public')->assertExists($partner->logo_path);

        // Disk-relative, never an absolute URL. The host has moved once
        // already and rows with a baked-in origin do not survive that.
        $this->assertStringStartsWith('partners/', $partner->logo_path);
        $this->assertStringNotContainsString('http', $partner->logo_path);
    }

    public function test_the_storefront_sees_only_active_partners_in_order(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        foreach ([['B second', 2, true], ['A first', 1, true], ['Hidden', 0, false]] as [$name, $order, $active]) {
            $this->postJson('/api/v1/admin/partner-pharmacies', [
                'name' => $name,
                'logo' => $this->logo(),
                'sort_order' => $order,
                'is_active' => $active ? 1 : 0,
            ], $this->tokenFor($admin))->assertCreated();
        }

        $names = array_column(
            $this->getJson('/api/v1/partner-pharmacies')->assertOk()->json('data'),
            'name'
        );

        $this->assertSame(['A first', 'B second'], $names);
    }

    public function test_the_public_endpoint_needs_no_account(): void
    {
        // It feeds the homepage, which most visitors reach signed out.
        $this->getJson('/api/v1/partner-pharmacies')->assertOk();
    }

    public function test_the_public_endpoint_is_not_cacheable(): void
    {
        // Same reasoning as the banners endpoint: this sits behind an edge
        // cache that will hold a plain 200 GET, and a partner added or
        // withdrawn is meant to appear or disappear now.
        // Asserted by directive rather than by exact string: Symfony
        // normalises and reorders Cache-Control, so pinning the whole header
        // tests the framework's formatting rather than our intent.
        $header = $this->getJson('/api/v1/partner-pharmacies')->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $header);
        $this->assertStringContainsString('no-cache', $header);
    }

    public function test_the_logo_url_is_absolute_and_served_from_media(): void
    {
        /*
         * Deliberately without Storage::fake().
         *
         * Faking a disk rebuilds it from Laravel's defaults and drops the
         * custom `url` this application sets — config/filesystems.php points
         * the public disk at APP_URL./media, because Hostinger blocks the
         * /storage prefix at the web server, ahead of PHP. Under a fake the
         * accessor returns /storage/... and the assertion would be testing the
         * default config rather than ours.
         *
         * No file is written either way: the accessor only builds a URL from
         * the stored path.
         */
        $partner = PartnerPharmacy::create([
            'name' => 'Mercy Pharmacy',
            'logo_path' => 'partners/mercy.png',
        ]);

        $url = $this->getJson('/api/v1/partner-pharmacies')->json('data.0.logo_url');

        $this->assertStringStartsWith('http', $url);
        $this->assertStringContainsString('/media/partners/', $url);
        $this->assertStringNotContainsString('/storage/', $url);
        $this->assertSame($partner->logo_url, $url);
    }

    public function test_a_store_account_cannot_manage_the_wall(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $owner = $this->makeUser([
            'role' => 'store_owner',
            'role_id' => Role::where('name', 'store_owner')->value('id'),
        ]);

        Store::create([
            'owner_id' => $owner->id,
            'name' => 'A Shop',
            'slug' => 'shop-'.uniqid(),
            'email' => 'shop'.uniqid().'@pharmacy.test',
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
        ]);

        // This is the platform saying who it works with, not a shop managing
        // its own listing.
        $this->getJson('/api/v1/admin/partner-pharmacies', $this->tokenFor($owner))
            ->assertStatus(403);
    }

    public function test_an_svg_logo_is_refused(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        // SVG can carry script, and served from api.taga.ng it would run
        // same-origin to the API. PublicStorageController would refuse to
        // serve one anyway, so accepting the upload only creates dead files.
        $this->postJson('/api/v1/admin/partner-pharmacies', [
            'name' => 'Scripted',
            'logo' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
        ], $this->tokenFor($admin))->assertStatus(422);
    }

    public function test_removing_a_partner_deletes_its_logo(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->postJson('/api/v1/admin/partner-pharmacies', [
            'name' => 'Mercy Pharmacy',
            'logo' => $this->logo(),
        ], $this->tokenFor($admin))->assertCreated();

        $partner = PartnerPharmacy::firstWhere('name', 'Mercy Pharmacy');
        $path = $partner->logo_path;

        $this->deleteJson("/api/v1/admin/partner-pharmacies/{$partner->id}", [], $this->tokenFor($admin))
            ->assertOk();

        // Otherwise the public disk accumulates orphans nothing references and
        // nothing will ever clean up.
        Storage::disk('public')->assertMissing($path);
        $this->assertNull(PartnerPharmacy::find($partner->id));
    }

    public function test_hiding_a_partner_keeps_the_row(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->postJson('/api/v1/admin/partner-pharmacies', [
            'name' => 'Mercy Pharmacy',
            'logo' => $this->logo(),
        ], $this->tokenFor($admin))->assertCreated();

        $partner = PartnerPharmacy::firstWhere('name', 'Mercy Pharmacy');

        $this->putJson("/api/v1/admin/partner-pharmacies/{$partner->id}/toggle", [], $this->tokenFor($admin))
            ->assertOk();

        // Hidden, not deleted — taking a logo down should not cost the record
        // or the file.
        $this->assertFalse($partner->fresh()->is_active);
        Storage::disk('public')->assertExists($partner->logo_path);
        $this->assertSame([], $this->getJson('/api/v1/partner-pharmacies')->json('data'));
    }
}
