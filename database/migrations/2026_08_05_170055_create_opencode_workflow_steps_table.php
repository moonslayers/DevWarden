<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('opencode_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opencode_workflow_id')->constrained()->cascadeOnDelete();
            $table->string('step_name');
            $table->string('command');
            $table->string('status');
            $table->text('summary')->nullable();
            $table->text('raw_output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opencode_workflow_steps');
    }
};
