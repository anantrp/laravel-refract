<?php

use Illuminate\Support\Facades\Route;
use Workbench\App\Scenarios;

Route::get('/scenario/{row}', fn (string $row, Scenarios $scenarios) => $scenarios->run(strtoupper($row)));
