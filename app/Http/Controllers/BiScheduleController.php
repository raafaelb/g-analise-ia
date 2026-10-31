<?php

namespace App\Http\Controllers;

use App\Services\BiSchedules;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BiScheduleController extends Controller
{
    public function options(Request $request, string $tenant, BiSchedules $schedules): JsonResponse
    {
        return $this->respond(fn (): array => $schedules->options($request->user(), $tenant));
    }

    public function describe(Request $request, string $tenant, string $id, BiSchedules $schedules): JsonResponse
    {
        return $this->respond(fn (): array => $schedules->options($request->user(), $tenant, $id));
    }

    public function prepare(Request $request, string $tenant, BiSchedules $schedules): JsonResponse
    {
        return $this->respond(fn (): array => $schedules->prepare($request->user(), $tenant, $request->all()));
    }

    public function verify(Request $request, string $id, BiSchedules $schedules): JsonResponse
    {
        return $this->respond(fn (): array => $schedules->verifyConfiguration($request->user(), $id));
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return response()->json($operation())->header('Cache-Control', 'no-store');
        } catch (ModelNotFoundException|HttpExceptionInterface $failure) {
            throw $failure;
        } catch (RuntimeException $failure) {
            throw ValidationException::withMessages(['schedule' => $failure->getMessage()]);
        }
    }
}
