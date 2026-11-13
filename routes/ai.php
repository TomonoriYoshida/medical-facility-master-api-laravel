<?php

use App\Mcp\Servers\MedicalFacilityServer;
use Laravel\Mcp\Facades\Mcp;

// Public and read-only like the API, with its own per-address rate limit
// (one question to an AI client may make several tool calls).
Mcp::web('/mcp', MedicalFacilityServer::class)
    ->middleware('throttle:mcp')
    ->name('mcp');
