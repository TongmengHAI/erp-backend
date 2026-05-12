<?php

declare(strict_types=1);

it('responds to /api/v1/health with status ok', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJsonPath('status', 'ok');
});

it('responds to the /up health probe', function () {
    $this->get('/up')->assertOk();
});
