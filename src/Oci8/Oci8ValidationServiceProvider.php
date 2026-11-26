<?php

namespace Yajra\Oci8;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Validation\ValidationServiceProvider;
use Yajra\Oci8\Validation\Oci8DatabasePresenceVerifier;

class Oci8ValidationServiceProvider extends ValidationServiceProvider
{
    protected function registerPresenceVerifier(): void
    {
        $this->app->singleton('validation.presence', fn (Application $app) => new Oci8DatabasePresenceVerifier($app->make('db')));
    }
}
