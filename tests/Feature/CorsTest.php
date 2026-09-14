<?php

beforeEach(function () {
    config(['cors.allowed_origins' => ['https://app.example']]);
});

it('allows a preflight from a configured frontend origin', function () {
    $this->withHeaders([
        'Origin' => 'https://app.example',
        'Access-Control-Request-Method' => 'GET',
        'Access-Control-Request-Headers' => 'authorization,x-api-key',
    ])->options('/api/user')
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.example');
});

it('does not allow a preflight from any other origin', function () {
    $response = $this->withHeaders([
        'Origin' => 'https://evil.example',
        'Access-Control-Request-Method' => 'GET',
        'Access-Control-Request-Headers' => 'authorization,x-api-key',
    ])->options('/api/user');

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBeIn(['*', 'https://evil.example']);
});
