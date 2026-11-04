<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('translator_locales')->nullable();
            $table->boolean('can_edit')->default(true);
        });

        Schema::create('wines', function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
            $table->timestamps();
        });

        Schema::create('wine_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wine_id')->constrained()->cascadeOnDelete();
            $table->string('locale_code', 16);
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->text('tasting')->nullable();
            $table->json('notes')->nullable();
            $table->json('content')->nullable();
            $table->string('internal_code')->nullable();
            $table->timestamps();
            $table->unique(['wine_id', 'locale_code']);
        });

        Schema::create('grapes', function (Blueprint $table): void {
            $table->id();
            $table->json('name')->nullable();
            $table->json('description')->nullable();
            $table->json('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('regions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
        Schema::dropIfExists('grapes');
        Schema::dropIfExists('wine_translations');
        Schema::dropIfExists('wines');
    }
};
