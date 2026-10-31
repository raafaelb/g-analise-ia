<?php

namespace App\Http\Controllers;

use App\Services\BiScheduleRuns;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BiLoadController extends Controller
{
    public function describe(Request $request, string $tenant, string $id, BiScheduleRuns $runs): JsonResponse
    {
        return $this->respond(fn (): array => $runs->describe($request->user(), $tenant, $id));
    }

    public function recent(Request $request, string $tenant, string $id, BiScheduleRuns $runs): JsonResponse
    {
        return $this->respond(fn (): array => ['executions' => $runs->recent($request->user(), $tenant, $id)]);
    }

    public function prepare(Request $request, string $tenant, string $id, BiScheduleRuns $runs): JsonResponse
    {
        return $this->respond(fn (): array => $runs->prepare($request->user(), $tenant, $id, $request->all()));
    }

    public function show(Request $request, string $id, BiScheduleRuns $runs): JsonResponse
    {
        return $this->respond(fn (): array => $runs->show($request->user(), $id));
    }

    public function observe(Request $request, string $id, BiScheduleRuns $runs): JsonResponse
    {
        return $this->respond(fn (): array => $runs->observe($request->user(), $id));
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return response()->json($operation())->header('Cache-Control', 'no-store');
        } catch (ModelNotFoundException|HttpExceptionInterface $failure) {
            throw $failure;
        } catch (RuntimeException $failure) {
            throw ValidationException::withMessages(['load' => $failure->getMessage()]);
        }
    }
}
