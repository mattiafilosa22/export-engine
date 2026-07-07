<?php

namespace Tests\Feature\Console;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoClientCommandTest extends TestCase
{
    public function test_it_drives_the_full_client_server_flow(): void
    {
        Storage::fake('local');
        Http::fake(function (Request $request) {
            return $this->respond($request->method(), $request->url());
        });

        $this->artisan('gamindo:demo-client', ['--players' => 2, '--events' => 3])
            ->assertExitCode(0);

        // Orchestration hit each stage in the pipeline.
        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST' && Str::endsWith($r->url(), '/versions');
        });
        Http::assertSent(function (Request $r) {
            return $r->method() === 'GET' && Str::contains($r->url(), '/players');
        });
        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST' && Str::contains($r->url(), '/events');
        });
        Http::assertSent(function (Request $r) {
            return Str::contains($r->url(), '/download');
        });

        // The downloaded XLSX was saved.
        $this->assertNotEmpty(Storage::disk('local')->files('exports'));
    }

    /**
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    private function respond(string $method, string $url)
    {
        if ($method === 'POST' && Str::endsWith($url, '/versions')) {
            return Http::response(['data' => ['uuid' => 'v1']], 201);
        }
        if (Str::contains($url, '/download')) {
            return Http::response('BINARY-XLSX', 200);
        }
        if ($method === 'GET' && Str::contains($url, '/imports/')) {
            return Http::response(['data' => ['status' => 'completed']], 200);
        }
        if ($method === 'GET' && Str::contains($url, '/exports/')) {
            return Http::response(['data' => ['status' => 'completed', 'progress' => 100]], 200);
        }
        if ($method === 'GET' && Str::contains($url, '/players')) {
            return Http::response(['data' => [['id' => 1], ['id' => 2]]], 200);
        }
        if ($method === 'POST' && Str::contains($url, '/players')) {
            return Http::response(['data' => ['id' => 'imp-players']], 202);
        }
        if ($method === 'POST' && Str::contains($url, '/events')) {
            return Http::response(['data' => ['id' => 'imp-events']], 202);
        }
        if ($method === 'POST' && Str::contains($url, '/exports')) {
            return Http::response(['data' => ['id' => 'exp1']], 202);
        }

        return Http::response([], 404);
    }
}
