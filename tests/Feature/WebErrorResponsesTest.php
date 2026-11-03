<?php

namespace Tests\Feature;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WebErrorResponsesTest extends TestCase
{
    #[TestWith([403])]
    #[TestWith([404])]
    #[TestWith([419])]
    #[TestWith([429])]
    #[TestWith([500])]
    #[TestWith([503])]
    public function test_browser_errors_show_the_recovery_page_and_keep_the_http_status(int $status): void
    {
        config(['app.debug' => false]);
        Route::get('/__web-recovery', fn () => abort($status, 'Internal diagnostic marker'))->middleware('web');

        $response = $this->get('/__web-recovery');

        $response->assertStatus($status)->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (AssertableInertia $page) => $page->component('Error', false)->where('status', $status));
        $response->assertDontSee('Internal diagnostic marker');
    }

    public function test_a_missing_route_outside_the_web_group_also_renders_the_recovery_page(): void
    {
        config(['app.debug' => false]);

        $this->get('/missing-page-for-recovery-test')->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page->component('Error', false)->where('status', 404));
    }

    public function test_an_inertia_session_error_returns_a_page_without_redirecting_or_replaying_the_post(): void
    {
        Route::post('/__expired-form', fn () => abort(419))->middleware('web');

        $this->post('/__expired-form', ['payload' => 'Synthetic secret from form'], ['X-Inertia' => 'true', 'Accept' => 'text/html'])->assertStatus(419)->assertHeader('X-Inertia', 'true')->assertHeaderMissing('Location')->assertJsonPath('component', 'Error')->assertJsonPath('props.status', 419)->assertDontSee('Synthetic secret from form');
    }

    public function test_json_requests_keep_their_existing_error_contract(): void
    {
        Route::get('/__json-recovery', fn () => abort(403, 'Acesso de teste recusado.'))->middleware('web');

        $this->getJson('/__json-recovery')->assertForbidden()->assertJsonPath('message', 'Acesso de teste recusado.')->assertJsonMissingPath('component');
    }

    public function test_api_paths_keep_json_even_when_the_browser_accepts_html(): void
    {
        Route::get('/api/__recovery-test', fn () => abort(403, 'Acesso de teste recusado.'))->middleware('web');

        $this->get('/api/__recovery-test', ['Accept' => 'text/html'])->assertForbidden()->assertHeader('Content-Type', 'application/json')->assertJsonPath('message', 'Acesso de teste recusado.')->assertJsonMissingPath('component');
    }

    public function test_retry_after_survives_the_recovery_page(): void
    {
        Route::get('/__limited-recovery', fn () => abort(429, '', ['Retry-After' => '60']))->middleware('web');

        $this->get('/__limited-recovery')->assertTooManyRequests()->assertHeader('Retry-After', '60')->assertInertia(fn (AssertableInertia $page) => $page->component('Error', false)->where('status', 429));
    }

    public function test_debug_mode_preserves_the_original_error_response(): void
    {
        config(['app.debug' => true]);
        Route::get('/__debug-recovery', fn () => throw new HttpResponseException(response('Synthetic debug response', 500)))->middleware('web');

        $response = $this->get('/__debug-recovery');

        $response->assertStatus(500)->assertSee('Synthetic debug response');
    }
}
