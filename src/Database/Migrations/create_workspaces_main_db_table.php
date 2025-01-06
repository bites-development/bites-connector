<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\BitesMiddleware\Shared\UseMiddlewareDBTrait;

return new class extends Migration {
    use UseMiddlewareDBTrait;

    public function up()
    {
        if (!(Schema::hasTable('workspaces') || Schema::hasTable('work_spaces'))) {
            Schema::create('workspaces', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->nullable();
                $table->boolean('status')->default(1);
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->timestamps();
            });
        }
    }

    public function getConnection(): string
    {
        return $this->getConnectionName();
    }
};
