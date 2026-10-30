<?php

namespace App\Http\Controllers;

use App\Services\BiDesks;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BiDeskController extends Controller
{
    public function describe(Request $request, string $tenant, string $kind, string $id, BiDesks $desks): JsonResponse
    {
        return $this->respond(fn (): array => $desks->describe($request->user(), $tenant, $kind, $id));
    }

    public function prepare(Request $request, string $tenant, string $kind, BiDesks $desks): JsonResponse
    {
        return $this->respond(fn (): array => $desks->prepare($request->user(), $tenant, $kind, $request->all()));
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return response()->json($operation())->header('Cache-Control', 'no-store');
        } catch (ModelNotFoundException|HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['desk' => $e->getMessage()]);
        }
    }
}
