<?php

use Anantrp\Refract\RefractServiceProvider;

it('boots the service provider', function () {
    expect(app()->getProvider(RefractServiceProvider::class))->toBeInstanceOf(RefractServiceProvider::class);
});
