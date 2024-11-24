<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    use \Modules\BitesMiddleware\Shared\UseMiddlewareDBTrait;

    public function up()
    {
        Schema::create('workspace_models', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->morphs('model');
        });
    }

    public function getConnection(): string
    {
        return $this->getConnectionName();
    }
};
