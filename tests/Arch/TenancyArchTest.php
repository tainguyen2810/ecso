<?php

use App\Modules\Access\Models\Role;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Models with a tenant_id column that are deliberately not global-scoped (documented in each class). */
const TENANT_SCOPE_EXCEPTIONS = [
    Role::class,     // NULL tenant_id = system roles shared by all tenants
    AuditLog::class,  // NULL tenant_id = platform actions; readers filter explicitly
];

it('scopes every model that has a tenant_id column', function () {
    $files = glob(app_path('Modules/*/Models/*.php'));
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($file, app_path().'/'));
        $model = new $class;
        expect($model)->toBeInstanceOf(Model::class);

        if (! Schema::hasColumn($model->getTable(), 'tenant_id') || in_array($class, TENANT_SCOPE_EXCEPTIONS, true)) {
            continue;
        }

        expect(class_uses_recursive($class))->toHaveKey(BelongsToTenant::class, message: "{$class} has tenant_id but is not tenant-scoped");
    }
});

arch('no debugging helpers in application code')
    ->expect('App')
    ->not->toUse(['dd', 'dump', 'ray', 'var_dump']);

arch('env() is only read in config files')
    ->expect('App')
    ->not->toUse('env');
