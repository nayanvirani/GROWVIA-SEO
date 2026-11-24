<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/** Consistent response envelope: {data, meta?} on success, {error: {message, fields?}} on failure. */
abstract class ApiController extends Controller
{
    protected function ok(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        if ($data instanceof LengthAwarePaginator) {
            $meta += ['page' => $data->currentPage(), 'per_page' => $data->perPage(), 'total' => $data->total(), 'last_page' => $data->lastPage()];
            $data = $data->items();
        }

        return response()->json(array_filter(['data' => $data, 'meta' => $meta ?: null], fn ($v) => $v !== null), $status);
    }
}
