<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\Project;
use App\Services\BiFlowSource;
use App\Services\BiFlowWizard;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BiFlowWizardController extends Controller
{
    public function options(Request $request, string $tenant, BiFlowSource $sources): JsonResponse
    {
        return $this->respond(fn (): array => $sources->choices($request->user(), $tenant));
    }

    public function fields(Request $request, string $tenant, BiFlowSource $sources): JsonResponse
    {
        return $this->respond(fn (): array => $sources->fields($request->user(), $tenant, $request->all()));
    }

    public function prepare(Request $request, string $tenant, BiFlowWizard $wizard): JsonResponse
    {
        return $this->respond(fn (): array => $wizard->prepare($request->user(), $tenant, $request->all()));
    }

    public function status(Request $request, string $id, BiFlowWizard $wizard): JsonResponse
    {
        Project::where('user_id', $request->user()->id)->findOrFail($id);

        return $this->respond(fn (): array => $wizard->status($request->user(), $id));
    }

    public function verify(Request $request, string $id, string $approval, BiFlowWizard $wizard): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['fingerprint']) !== []) {
            throw ValidationException::withMessages(['flow' => 'A conferência aceita somente a assinatura da revisão selecionada.']);
        }
        $request->validate(['fingerprint' => ['required', 'string', 'size:64']]);
        $record = Approval::where('user_id', $request->user()->id)->where('project_id', $id)->findOrFail($approval);
        abort_unless(hash_equals($record->fingerprint, $request->string('fingerprint')->toString()), 409, 'A revisão mudou. Atualize a conferência.');

        return $this->respond(fn (): array => $wizard->verify($request->user(), $id, $approval));
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return response()->json($operation())->header('Cache-Control', 'no-store');
        } catch (ModelNotFoundException|HttpExceptionInterface $error) {
            throw $error;
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['flow' => 'Outra operação deste projeto está em andamento. Aguarde e confira o acompanhamento.']);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['flow' => $error->getMessage()]);
        }
    }
}
