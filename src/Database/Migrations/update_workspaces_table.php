<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        if(!(Schema::hasTable('workspaces') || Schema::hasTable('work_spaces'))) {
            if(!Schema::hasColumn('workspaces','b_user_id')) {
                Schema::table('workspaces', function (Blueprint $table) {
                    $table->unsignedBigInteger('b_user_id')->nullable()->index();
                });
            }
        }
    }
};
