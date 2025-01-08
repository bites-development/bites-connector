<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\BitesMiddleware\Shared\UseMiddlewareDBTrait;

return new class extends Migration {

    public function up()
    {
        if(!Schema::hasTable('user_models')) {
            Schema::create('user_models', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('b_user_id');
                $table->morphs('model');
            });
        }
    }
};
