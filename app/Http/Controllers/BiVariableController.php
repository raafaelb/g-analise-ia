<?php

namespace App\Http\Controllers;

use App\Services\BiVariables;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BiVariableController extends Controller
{
    public function options(Request $request, string $tenant, BiVariables $settings): JsonResponse
    {
        return $this->respond(fn (): array => $settings->options($request->user(), $tenant));
    }

    public function describe(Request $request, string $tenant, string $id, BiVariables $settings): JsonResponse
    {
        return $this->respond(fn (): array => $settings->options($request->user(), $tenant, $id));
    }

    public function prepare(Request $request, string $tenant, BiVariables $settings): JsonResponse
    {
        return $this->respond(fn (): array => $settings->prepare($request->user(), $tenant, $request->all()));
    }

    public function verify(Request $request, string $id, BiVariables $settings): JsonResponse
    {
        return $this->respond(fn (): array => $settings->reconcile($request->user(), $id));
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return response()->json($operation())->header('Cache-Control', 'no-store');
        } catch (ModelNotFoundException|HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['variable' => $e->getMessage()]);
        }
    }
}
