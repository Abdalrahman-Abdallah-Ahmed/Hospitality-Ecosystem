<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The hotel group is the account: the thing usage is metered against and
     * AI cost is attributed to. Until now the column was optional and nothing
     * ever populated it, so every hotel in the system had no account at all.
     *
     * Backfill a single-property group for each of them, then make the column
     * required so the gap cannot reopen. Soft-deleted hotels are included —
     * the constraint applies to every row, not just the visible ones.
     */
    public function up(): void
    {
        $orphans = DB::table('hotels')
            ->whereNull('hotel_group_id')
            ->get(['id', 'name', 'slug', 'country_code', 'currency', 'timezone']);

        foreach ($orphans as $hotel) {
            $groupId = (string) Str::uuid();

            DB::table('hotel_groups')->insert([
                'id' => $groupId,
                'name' => $hotel->name,
                'slug' => $this->availableSlug($hotel->slug ?: Str::slug((string) $hotel->name)),
                'country_code' => $hotel->country_code,
                'default_currency' => $hotel->currency ?: 'USD',
                'default_timezone' => $hotel->timezone ?: 'UTC',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('hotels')->where('id', $hotel->id)->update([
                'hotel_group_id' => $groupId,
            ]);
        }

        Schema::table('hotels', function (Blueprint $table) {
            $table->dropForeign(['hotel_group_id']);
        });

        Schema::table('hotels', function (Blueprint $table) {
            $table->uuid('hotel_group_id')->nullable(false)->change();

            // restrictOnDelete, not nullOnDelete: the column is required now,
            // so setting it null on a hard delete would fail anyway. A group
            // with hotels still attached must not be deletable — losing the
            // account a meter event points at is not a recoverable state.
            $table->foreign('hotel_group_id')
                ->references('id')->on('hotel_groups')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverting restores the optional column and the original foreign key.
     * The backfilled groups are deliberately left in place: there is no way
     * to tell them apart from groups someone created on purpose, and an
     * orphaned group is harmless where a deleted one is not.
     */
    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropForeign(['hotel_group_id']);
        });

        Schema::table('hotels', function (Blueprint $table) {
            $table->uuid('hotel_group_id')->nullable()->change();

            $table->foreign('hotel_group_id')
                ->references('id')->on('hotel_groups')
                ->nullOnDelete();
        });
    }

    private function availableSlug(string $base): string
    {
        $base = $base ?: 'group';
        $slug = $base;

        while (DB::table('hotel_groups')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }
};
