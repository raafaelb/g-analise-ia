<?php

namespace App\Http\Controllers;

use App\Services\BiCredentials;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BiCredentialController extends Controller
{
    public function options(Request $request, string $tenant, BiCredentials $credentials): JsonResponse
    {
        return $this->respond(fn (): array => $credentials->options($request->user(), $tenant));
    }

    public function describe(Request $request, string $tenant, string $id, BiCredentials $credentials): JsonResponse
    {
        return $this->respond(fn (): array => $credentials->describe($request->user(), $tenant, $id));
    }

    public function prepare(Request $request, string $tenant, BiCredentials $credentials): JsonResponse
    {
        return $this->respond(fn (): array => $credentials->prepare($request->user(), $tenant, $request->all()));
    }

    public function inspect(Request $request, string $tenant, string $id, string $action, BiCredentials $credentials): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['schema', 'search', 'offset']) !== []) {
            throw ValidationException::withMessages(['schema' => 'Esta consulta aceita somente schema, termo de busca e posição da estrutura.']);
        }
        $data = $request->validate(['schema' => ['nullable', 'string', 'max:128'], 'search' => ['nullable', 'string', 'max:100'], 'offset' => ['sometimes', 'integer', 'min:0', 'max:1000000']]);

        return $this->respond(fn (): array => $credentials->inspect($request->user(), $tenant, $id, $action, $data['schema'] ?? null, $data['search'] ?? null, (int) ($data['offset'] ?? 0)));
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return response()->json($operation())->header('Cache-Control', 'no-store');
        } catch (ModelNotFoundException|HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['connection' => $e->getMessage()]);
        }
    }
}
