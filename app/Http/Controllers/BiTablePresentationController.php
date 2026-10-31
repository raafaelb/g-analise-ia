<?php

namespace App\Http\Controllers;

use App\Services\BiTablePresentation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BiTablePresentationController extends Controller
{
    public function describe(Request $request, string $tenant, string $id, BiTablePresentation $tables): JsonResponse
    {
        return $this->respond(fn (): array => $tables->describe($request->user(), $tenant, $id));
    }

    public function prepare(Request $request, string $tenant, string $id, BiTablePresentation $tables): JsonResponse
    {
        return $this->respond(fn (): array => $tables->prepare($request->user(), $tenant, $id, $request->all()));
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return response()->json($operation())->header('Cache-Control', 'no-store, private');
        } catch (ModelNotFoundException|HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['table' => $e->getMessage()]);
        }
    }
}
