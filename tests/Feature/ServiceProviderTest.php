<?php

use Anantrp\Refract\RefractServiceProvider;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

it('boots the service provider', function () {
    expect(app()->getProvider(RefractServiceProvider::class))->toBeInstanceOf(RefractServiceProvider::class);
});

it('blocks stray HTTP requests in every test, also after the app is made again', function () {
    expect(fn () => Http::get('https://refract.invalid/'))->toThrow(StrayRequestException::class);

    $this->refreshApplication();

    expect(fn () => Http::get('https://refract.invalid/'))->toThrow(StrayRequestException::class);
});
