<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\BitesMiddleware\Shared\UseMiddlewareDBTrait;

return new class extends Migration {

    public function up()
    {
        if (!(Schema::hasTable('workspace_users'))) {
            Schema::create('workspace_users', function (Blueprint $table) {
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('b_user_id')->index();
                $table->index(['workspace_id', 'b_user_id']);
                $table->timestamps();
            });
        }
    }

    public function getConnection(): string
    {
        return $this->getConnectionName();
    }
};
