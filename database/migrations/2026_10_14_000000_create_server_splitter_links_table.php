<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row for each sub-server: the main server it was split from and the resources it took,
 * which go back to the main server when the sub-server is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_splitter_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('parent_id');
            $table->unsignedInteger('server_id')->unique();
            $table->unsignedInteger('memory');
            $table->unsignedInteger('disk');
            $table->unsignedInteger('cpu')->default(0);
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_splitter_links');
    }
};
