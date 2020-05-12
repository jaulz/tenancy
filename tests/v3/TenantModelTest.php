<?php

namespace Stancl\Tenancy\Tests\v3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Tests\TestCase;
use Stancl\Tenancy\UniqueIDGenerators\UUIDGenerator;
use Stancl\Tenancy\Contracts;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

class TenantModelTest extends TestCase
{
    /** @test */
    public function created_event_is_dispatched()
    {
        Event::fake([TenantCreated::class]);

        Event::assertNotDispatched(TenantCreated::class);

        Tenant::create();

        Event::assertDispatched(TenantCreated::class);
    }

    /** @test */
    public function current_tenant_can_be_resolved_from_service_container_using_typehint()
    {
        $tenant = Tenant::create();

        tenancy()->initialize($tenant);

        $this->assertSame($tenant->id, app(Contracts\Tenant::class)->id);

        tenancy()->end();

        $this->assertSame(null, app(Tenant::class));
    }

    /** @test */
    public function keys_which_dont_have_their_own_column_go_into_data_json_column()
    {
        $tenant = Tenant::create([
            'foo' => 'bar',
        ]);

        // Test that model works correctly
        $this->assertSame('bar', $tenant->foo);
        $this->assertSame(null, $tenant->data);

        // Low level test to test database structure
        $this->assertSame(json_encode(['foo' => 'bar']), DB::table('tenants')->where('id', $tenant->id)->first()->data);
        $this->assertSame(null, DB::table('tenants')->where('id', $tenant->id)->first()->foo ?? null);

        // Model has the correct structure when retrieved
        $tenant = Tenant::first();
        $this->assertSame('bar', $tenant->foo);
        $this->assertSame(null, $tenant->data);

        // Model can be updated
        $tenant->update([
            'foo' => 'baz',
            'abc' => 'xyz',
        ]);

        $this->assertSame('baz', $tenant->foo);
        $this->assertSame('xyz', $tenant->abc);
        $this->assertSame(null, $tenant->data);

        // Model can be retrieved after update & is structure correctly
        $tenant = Tenant::first();

        $this->assertSame('baz', $tenant->foo);
        $this->assertSame('xyz', $tenant->abc);
        $this->assertSame(null, $tenant->data);
    }

    /** @test */
    public function id_is_generated_when_no_id_is_supplied()
    {
        config(['tenancy.id_generator' => UUIDGenerator::class]);

        $this->mock(UUIDGenerator::class, function ($mock) {
            return $mock->shouldReceive('generate')->once();
        });

        $tenant = Tenant::create();

        $this->assertNotNull($tenant->id);
    }

    /** @test */
    public function autoincrement_ids_are_supported()
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->bigIncrements('id')->change();
        });

        unset(app()[UniqueIdentifierGenerator::class]);

        $tenant1 = MyTenant::create();
        $tenant2 = MyTenant::create();

        $this->assertSame(1, $tenant1->id);
        $this->assertSame(2, $tenant2->id);
    }

    /** @test */
    public function custom_tenant_model_can_be_used()
    {
        $tenant = MyTenant::create();

        tenancy()->initialize($tenant);

        $this->assertTrue(tenant() instanceof MyTenant);
    }
    
    /** @test */
    public function custom_tenant_model_that_doesnt_extend_vendor_Tenant_model_can_be_used()
    {
        $tenant = AnotherTenant::create([
            'id' => 'acme',
        ]);

        tenancy()->initialize($tenant);

        $this->assertTrue(tenant() instanceof AnotherTenant);
    }

    // todo test that tenant can be created even in another DB context - that the central trait works
}

class MyTenant extends Tenant
{
    protected $table = 'tenants';
    public $increments = true;
}

class AnotherTenant extends Model implements Contracts\Tenant
{
    protected $guarded = [];
    protected $table = 'tenants';

    public function getTenantKeyName(): string
    {
        return 'id';
    }

    public function getTenantKey(): string
    {
        return $this->getAttribute('id');
    }

    public function run(callable $callback)
    {
        $callback();
    }
}