<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\BitesMiddleware\Shared\UseMiddlewareDBTrait;

return new class extends Migration {
    use UseMiddlewareDBTrait;

    public function up()
    {
        if ((Schema::hasColumn('workspaces','user_id'))) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->renameColumn('user_id','b_user_id');
            });
        }
    }

    public function getConnection(): string
    {
        return $this->getConnectionName();
    }
};
