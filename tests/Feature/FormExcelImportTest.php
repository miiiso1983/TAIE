<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Advisor\Services\TabularFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FormExcelImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_preview_is_read_only_and_returns_rows_and_columns(): void
    {
        $this->setupUser(['sales.customer.create']);
        $file = UploadedFile::fake()->createWithContent('customers.csv', "customer_number,name\nC1,Company One\nC2,Company Two\n");
        $this->post('/api/forms/import-preview', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.header.0', 'customer_number')->assertJsonPath('data.rows.1.cells.1', 'Company Two');
        $this->assertSame(0, DB::table('sales_customers')->count());
    }

    public function test_preview_rejects_invalid_headers_oversized_batches_and_old_excel_format(): void
    {
        $this->setupUser(['hr.employee.create']);
        foreach ([['bad.csv', "code,code\nA,B\n"], ['big.csv', "code\n".implode("\n", range(1, 101))."\n"], ['old.xls', 'not-an-excel-file']] as [$name,$content]) {
            $this->post('/api/forms/import-preview', ['file' => UploadedFile::fake()->createWithContent($name, $content)], ['Accept' => 'application/json'])->assertUnprocessable();
        }
    }

    public function test_template_is_real_xlsx_and_authentication_is_required(): void
    {
        $this->postJson('/api/forms/import-template', ['columns' => ['customer_number', 'name']])->assertUnauthorized();
        $this->setupUser(['sales.customer.create']);
        $response = $this->postJson('/api/forms/import-template', ['columns' => ['customer_number', 'name']])->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $table = app(TabularFileService::class)->read($path, 'xlsx', 0, true);
        $this->assertSame(['customer_number', 'name'], $table['header']);
        if (is_file($path)) {
            unlink($path);
        }
        $this->postJson('/api/forms/import-template', ['columns' => ['=HYPERLINK(...)']])->assertUnprocessable();
    }

    private function setupUser(array $permissions): array
    {
        config(['teami.audit_hmac_key' => str_repeat('p', 48), 'teami.billing_enforce' => false]);
        [$tenant,$user] = $this->tenantUserWithPermissions($permissions);
        $this->actingAs($user)->withHeader('X-Tenant', $tenant->uuid)->withHeader('Idempotency-Key', (string) Str::uuid());

        return [$tenant, $user];
    }

    private function tenantUserWithPermissions(array $names): array
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant', 'slug' => 'tenant']);
        $user = User::factory()->create();
        $tenant->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Business Operator', 'guard_name' => 'api']);
        $permissions = collect($names)->map(fn ($name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'api'], ['module' => str_starts_with($name, 'hr.') ? 'hr' : 'sales']));
        $role->permissions()->sync($permissions->pluck('id')->all());
        DB::table('role_user')->insert(['tenant_id' => $tenant->id, 'role_id' => $role->id, 'user_id' => $user->id]);

        return [$tenant, $user];
    }
}
