<?php

namespace App\Services\Sso\Adapters;

use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

interface ProductAdapterInterface
{
    public function formatResponse(User $user, array $attributes): Response|JsonResponse;
}