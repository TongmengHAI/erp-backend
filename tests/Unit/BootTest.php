<?php

declare(strict_types=1);

it('loads PHP 8.3 or newer', function () {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80300);
});

it('has bcmath extension loaded', function () {
    expect(extension_loaded('bcmath'))->toBeTrue();
});
