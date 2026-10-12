<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Base controller for all HTTP controllers in the application.
 *
 * Provides authorization helpers ($this->authorize()) used by SuperAdmin
 * and future module controllers alongside Policies.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
