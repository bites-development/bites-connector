<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('sns_logs', function (Blueprint $table) {
            $table->uuid('id');
            $table->text('message');
            $table->string('message_identifier')->unique();
            $table->string('message_type')->index();
            $table->timestamps();
        });
    }
};
