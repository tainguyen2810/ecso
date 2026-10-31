<?php

namespace App\Modules\Tenancy\Exceptions;

use RuntimeException;

class NoTenantContext extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No tenant context is set. Tenant-scoped code must run inside a resolved request or TenantContext::run().');
    }
}
