<?php

namespace App\Http\Controllers;

use App\Services\BiAppCreation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BiAppCreationController extends Controller
{
    public function prepare(Request $request, string $tenant, BiAppCreation $creation): JsonResponse
    {
        return $this->respond(fn (): array => $creation->prepare($request->user(), $tenant, $request->all()));
    }

    public function verify(Request $request, string $id, BiAppCreation $creation): JsonResponse
    {
        return $this->respond(fn (): array => $creation->reconcile($request->user(), $id));
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return response()->json($operation())->header('Cache-Control', 'no-store');
        } catch (ModelNotFoundException|HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['application' => $e->getMessage()]);
        }
    }
}
