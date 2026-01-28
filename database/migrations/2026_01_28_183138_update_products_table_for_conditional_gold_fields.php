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
        Schema::table('products', function (Blueprint $table) {
            // Change material default to 'Outros' - still required
            $table->enum('material', ['Ouro 18K', 'Ouro 10K', 'Outros'])
                ->default('Outros')
                ->change();

            // Make gold_karat nullable - only required for gold products
            $table->enum('gold_karat', ['24k', '22k', '21k', '20k', '18k', '16k', '14k', '10k'])
                ->nullable()
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('material', ['Ouro 18K', 'Ouro 10K', 'Outros'])
                ->default('Ouro 18K')
                ->change();

            $table->enum('gold_karat', ['24k', '22k', '21k', '20k', '18k', '16k', '14k', '10k'])
                ->default('18k')
                ->change();
        });
    }
};
