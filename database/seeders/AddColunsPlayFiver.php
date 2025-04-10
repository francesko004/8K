<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class AddColunsPlayFiver extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Schema::table('games_keys', function (Blueprint $table) {
            $table->string('playfiver_url')->nullable();
            $table->string('playfiver_secret')->nullable();
            $table->string('playfiver_code')->nullable();
            $table->string('playfiver_token')->nullable();

        });
    }
}
