<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\BitesMiddleware\Shared\UseMiddlewareDBTrait;

return new class extends Migration {

    use UseMiddlewareDBTrait;

    public function up()
    {
        if (Schema::hasColumn('workspace_users','user_id')) {
            Schema::table('workspace_users', function (Blueprint $table) {
                $table->dropIndex(['workspace_id','user_id']);
                $table->renameColumn('user_id', 'b_user_id');
                $table->index(['workspace_id', 'b_user_id']);
            });
        }
    }

    public function getConnection(): string
    {
        return $this->getConnectionName();
    }
};
