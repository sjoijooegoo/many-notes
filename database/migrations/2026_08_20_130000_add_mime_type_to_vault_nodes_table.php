<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vault_nodes', function (Blueprint $table): void {
            $table->string('mime_type')->nullable()->after('extension');
        });
    }

    public function down(): void
    {
        Schema::table('vault_nodes', function (Blueprint $table): void {
            $table->dropColumn('mime_type');
        });
    }
};
