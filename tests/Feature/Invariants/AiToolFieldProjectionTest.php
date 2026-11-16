<?php

use App\Ai\Tools\Admin\AdminToolset;
use App\Ai\Tools\Admin\GuardedTool;
use App\Ai\Tools\Contracts\ReadsData;
use App\Models\Guest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
| Spec 017 (SPEC-103 FR-014, research R5): an AI read tool sends the model
| provider only the fields its task needs. A guest is seeded with canary
| contact details, every read tool runs, and none of the canaries may appear
| in what the tool returns — except a field tests/Inventory/ai_tools.php
| lists under pii_fields for that tool.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
    Queue::fake();
    knFakeEmbeddings();
});

it('keeps guest contact details and identity data out of every read tool unless listed', function () {
    $s = aatSeed('Canary');
    $s['guest']->update(['email' => 'canary-guest@example.test', 'phone_number' => '+201009998877']);
    $guest = Guest::withoutGlobalScope('hotel')->findOrFail($s['guest']->id);

    $canaries = array_filter([
        'email' => 'canary-guest@example.test',
        'phone_number' => '1009998877',
        'identity_hash' => $guest->identity_hash,
    ]);
    $allowed = (require base_path('tests/Inventory/ai_tools.php'))['pii_fields'];
    $checked = 0;

    foreach (AdminToolset::for($s['admin']) as $tool) {
        if ($tool->kind() !== GuardedTool::READ || ! $tool->inner() instanceof ReadsData) {
            continue;
        }

        $output = json_encode(aatCall($tool, aatArgs($tool->name(), $s)));
        $mayShow = $allowed[$tool->inner()::class] ?? [];
        $checked++;

        foreach ($canaries as $field => $value) {
            if (! in_array($field, $mayShow, true)) {
                expect(str_contains($output, $value))->toBeFalse("{$tool->name()} sends the guest's {$field} to the model");
            }
        }
    }

    expect($checked)->toBeGreaterThan(10);
});
