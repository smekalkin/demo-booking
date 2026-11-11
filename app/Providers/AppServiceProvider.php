<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->constrainRouteIds();
    }

    /**
     * id в адресе — только цифры, не больше 18: столько гарантированно помещается в bigint.
     *
     * Иначе `/api/bookings/abc` доходит до PostgreSQL и падает с 500 вместо 404.
     */
    private function constrainRouteIds(): void
    {
        Route::pattern('booking', '[0-9]{1,18}');
        Route::pattern('room', '[0-9]{1,18}');
    }
}
