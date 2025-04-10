<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Validator;

class ValidationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        Validator::extend('cpf', function ($attribute, $value, $parameters, $validator) {
            $cpf = preg_replace('/\D/', '', $value);
            if (strlen($cpf) != 11) {
                return false;
            }
            if (preg_match('/(\d)\1{10}/', $cpf)) {
                return false;
            }
            for ($t = 9; $t < 11; $t++) {
                for ($d = 0, $c = 0; $c < $t; $c++) {
                    $d += $cpf[$c] * (($t + 1) - $c);
                }
                $d = ((10 * $d) % 11) % 10;
                if ($cpf[$c] != $d) {
            return false;
        }
            }
            return true;
        }, 'O CPF informado é inválido.');
    }

    /**
     * Register any application services.
     */
    public function register()
    {
        //
    }
}
