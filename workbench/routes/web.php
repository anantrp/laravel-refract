<?php

use Illuminate\Support\Facades\Route;
use Workbench\App\Scenarios;

Route::get('/scenario/{scenario}', fn (string $scenario, Scenarios $scenarios) => $scenarios->run(strtoupper($scenario)));
