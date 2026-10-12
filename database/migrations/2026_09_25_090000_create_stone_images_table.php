<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch image folder for the Stones module.
 *
 * Each row is one picture in that branch's folder. `path` is the private-disk
 * location (a generated file name). `name` is the filename the operator
 * uploaded or later renamed — the label they see, stored separately from the
 * path so a rename does not move the file. `name_key` is the lowercased name
 * so uniqueness is case-insensitive on every database, the same way Windows
 * treats "Photo.jpg" and "photo.jpg" as the same file.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stone_images', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('name', 180)->comment('Display filename, exactly as uploaded or renamed');
            $table->string('name_key', 180)->comment('Lowercased name; unique with branch_id');
            $table->string('path', 255)->comment('Private disk path; not the display name');
            $table->string('mime', 40);
            $table->unsignedInteger('size_bytes');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('created_by_role', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('updated_by_role', 30)->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'name_key'], 'stone_images_branch_name_unique');
            $table->index(['branch_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stone_images');
    }
};
