<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_attachment_uploads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('personal_access_token_id')
                ->constrained('personal_access_tokens')
                ->cascadeOnDelete();
            $table->foreignId('vault_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('vault_nodes')->cascadeOnDelete();
            $table->foreignId('attachment_id')->nullable()->constrained('vault_nodes')->nullOnDelete();
            $table->string('file_name');
            $table->unsignedBigInteger('expected_bytes');
            $table->string('expected_sha256', 64)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('status', 24)->index();
            $table->string('temp_path')->nullable();
            $table->unsignedBigInteger('actual_bytes')->nullable();
            $table->string('actual_sha256', 64)->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_attachment_uploads');
    }
};
