<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_menus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('navigation_menus')->cascadeOnDelete();
            $table->string('key', 100)->unique();
            $table->string('label', 120);
            $table->string('route_name', 160)->nullable();
            $table->string('icon', 80)->nullable();
            $table->string('permission_name', 120)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['parent_id', 'is_active', 'sort_order']);
        });

        Schema::create('navigation_menu_role', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('navigation_menu_id')->constrained('navigation_menus')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['navigation_menu_id', 'role_id'], 'navigation_menu_role_unique');
            $table->index(['role_id', 'is_visible', 'sort_order']);
        });

        Schema::create('navigation_access_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 80);
            $table->string('subject_type', 160);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_access_audits');
        Schema::dropIfExists('navigation_menu_role');
        Schema::dropIfExists('navigation_menus');
    }
};
