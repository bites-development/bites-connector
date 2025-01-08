<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\BitesMiddleware\Shared\UseMiddlewareDBTrait;

return new class extends Migration {
    use UseMiddlewareDBTrait;

    public function up()
    {
        if(!Schema::hasTable('workspace_models')) {
            Schema::create('workspace_models', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id');
                $table->morphs('model');
            });
        }
    }

    public function getConnection(): string
    {
        return $this->getConnectionName();
    }
};
