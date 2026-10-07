<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In these three tables `hotel_id IS NULL` means global: knowledge every
 * hotel's assistant reads. With `nullOnDelete`, force-deleting a hotel would
 * have turned that hotel's private knowledge into everyone's. Cascading
 * removes it with the hotel instead. (hotel_policies already cascades.)
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['knowledge_chunks', 'knowledge_documents', 'knowledge_base_articles'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['hotel_id']);
                $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['hotel_id']);
                $table->foreign('hotel_id')->references('id')->on('hotels')->nullOnDelete();
            });
        }
    }
};
