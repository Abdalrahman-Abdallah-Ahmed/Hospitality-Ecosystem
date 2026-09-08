<?php

use App\Enums\HousekeepingStatusesEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->enum('housekeeping_status', HousekeepingStatusesEnum::cases())
                ->default(HousekeepingStatusesEnum::CLEAN->value)
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('housekeeping_status');
        });
    }
};
