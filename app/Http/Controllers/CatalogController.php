<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Project;
use App\Services\Audit;
use App\Services\BiCatalog;
use App\Services\BiInspector;
use App\Services\BiManualActions;
use App\Services\BiPublications;
use App\Services\BiViewer;
use App\Services\BiWorkspaceSync;
use App\Services\RefreshCoverage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CatalogController extends Controller
{
    public function prepareAction(Request $request, string $tenant, string $id, BiManualActions $actions): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:clone_app,delete_draft,publish_app'], 'name' => ['required_if:action,clone_app', 'nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'], 'desk' => ['required_if:action,publish_app', 'nullable', 'integer', 'min:1'], 'publish_mode' => ['required_if:action,publish_app', 'nullable', 'in:new,replace'], 'request_id' => ['required', 'uuid']]);
        try {
            $result = $actions->prepare($request->user(), $tenant, $id, $data);

            return response()->json($result, $result['reused'] ? 200 : 201);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }
    }

    public function prepareFlowAction(Request $request, string $tenant, string $id, BiManualActions $actions): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:clone_flow,publish_flow'], 'name' => ['required_if:action,clone_flow', 'nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'], 'desk' => ['required_if:action,publish_flow', 'nullable', 'integer', 'min:1'], 'publish_mode' => ['required_if:action,publish_flow', 'nullable', 'in:new,replace'], 'copy_data' => ['sometimes', 'boolean'], 'request_id' => ['required', 'uuid']]);
        try {
            $result = $actions->prepare($request->user(), $tenant, $id, $data, 'flow');

            return response()->json($result, $result['reused'] ? 200 : 201);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }
    }

    public function actionStatus(Request $request, string $id, BiManualActions $actions): JsonResponse
    {
        return response()->json($actions->status($request->user(), $id));
    }

    public function reconcilePublication(Request $request, string $id, BiPublications $publications): JsonResponse
    {
        try {
            return response()->json($publications->reconcile($request->user(), $id));
        } catch (ModelNotFoundException|HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['publication' => $e->getMessage()]);
        }
    }

    public function syncProject(Request $request, string $id, BiWorkspaceSync $sync): JsonResponse
    {
        try {
            return response()->json($sync->refreshProject($request->user(), $id));
        } catch (ModelNotFoundException|HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['sync' => $e->getMessage()]);
        }
    }

    public function snapshot(Request $request, string $tenant, BiCatalog $catalog): JsonResponse
    {
        try {
            return response()->json($catalog->snapshot($request->user(), $tenant, $request->boolean('refresh')));
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['catalog' => $e->getMessage()]);
        }
    }

    public function detail(Request $request, string $tenant, string $kind, string $id, BiCatalog $catalog): JsonResponse
    {
        try {
            return response()->json($catalog->detail($request->user(), $tenant, $kind, $id));
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['catalog' => $e->getMessage()]);
        }
    }

    public function inspect(Request $request, string $tenant, string $kind, string $id, string $action, BiInspector $inspector): JsonResponse
    {
        try {
            return response()->json($inspector->inspect($request->user(), $tenant, $kind, $id, $action));
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['inspection' => $e->getMessage()]);
        }
    }

    public function variable(Request $request, string $tenant, string $id, BiCatalog $catalog): JsonResponse
    {
        return $this->detail($request, $tenant, 'variables', $id, $catalog);
    }

    public function preview(Request $request, string $tenant, string $id, BiCatalog $catalog): JsonResponse
    {
        $data = $request->validate(['dashboard' => ['nullable', 'integer', 'min:1']]);
        try {
            return response()->json($catalog->preview($request->user(), $tenant, $id, isset($data['dashboard']) ? (int) $data['dashboard'] : null));
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['preview' => $e->getMessage()]);
        }
    }

    public function image(Request $request, string $tenant, string $id, BiCatalog $catalog): BinaryFileResponse
    {
        $data = $request->validate(['dashboard' => ['nullable', 'integer', 'min:1']]);
        try {
            $path = $catalog->image($request->user(), $tenant, $id, isset($data['dashboard']) ? (int) $data['dashboard'] : null);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['image' => $e->getMessage()]);
        }

        return response()->file($path, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function viewer(Request $request, string $tenant, string $id, BiViewer $viewer): JsonResponse
    {
        $data = $request->validate(['dashboard' => ['nullable', 'integer', 'min:1'], 'isolated_context' => ['required', 'accepted']]);
        try {
            return response()->json($viewer->create($request->user(), $tenant, $id, isset($data['dashboard']) ? (int) $data['dashboard'] : null));
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['viewer' => $e->getMessage()]);
        }
    }

    public function coverage(Request $request, string $tenant, string $id): JsonResponse
    {
        try {
            return response()->json(app(RefreshCoverage::class)->inspect($request->user(), $tenant, $id));
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['coverage' => $e->getMessage()]);
        }
    }

    public function project(Request $request, string $tenant, BiCatalog $catalog): JsonResponse
    {
        abort_if($request->user()->role === 'viewer', 403, 'Seu perfil permite somente leitura.');
        $data = $request->validate(['resource' => ['required', 'regex:/^(app|flow|table|schedule):[1-9][0-9]*$/D'], 'name' => ['nullable', 'string', 'max:100']]);
        [$kind,$id] = explode(':', $data['resource'], 2);
        try {
            $detail = $catalog->detail($request->user(), $tenant, $kind, $id, includeRelations: false);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['resource' => $e->getMessage()]);
        }
        $name = $data['name'] ?? $detail['resource']['name'];
        $project = Project::create(['user_id' => $request->user()->id, 'name' => mb_substr($name, 0, 100), 'description' => 'Projeto vinculado a '.$data['resource'].'.', 'tenant_id' => $tenant, 'tenant_name' => $detail['tenant']['name'], 'source_ref' => $data['resource'], 'instructions' => 'Recurso inicial do projeto: '.$data['resource'].'. Inspecione este recurso e suas dependências antes de propor alterações.']);
        $conversation = Conversation::create(['project_id' => $project->id, 'user_id' => $request->user()->id]);
        Audit::record($request->user()->id, 'project.from_catalog', 'Projeto vinculado a um recurso do catálogo.', $project->id, ['resource' => $data['resource'], 'tenant_id' => $tenant]);

        return response()->json(['project' => $project, 'conversation' => $conversation], 201);
    }
}
