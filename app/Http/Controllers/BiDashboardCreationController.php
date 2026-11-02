<?php

namespace App\Http\Controllers;

use App\Services\BiDashboardCreation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BiDashboardCreationController extends Controller
{
    public function describe(Request $request, string $tenant, string $id, BiDashboardCreation $settings): JsonResponse
    {
        return $this->respond(fn (): array => $settings->describe($request->user(), $tenant, $id));
    }

    public function prepare(Request $request, string $tenant, string $id, BiDashboardCreation $settings): JsonResponse
    {
        return $this->respond(fn (): array => $settings->prepare($request->user(), $tenant, $id, $request->all()));
    }

    public function verify(Request $request, string $id, BiDashboardCreation $settings): JsonResponse
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
            throw ValidationException::withMessages(['dashboard' => $e->getMessage()]);
        }
    }
}
