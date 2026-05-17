<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FonnteService
{
    public function __construct(
        private readonly ?string $token = null,
        private readonly ?string $endpoint = null,
    ) {}

    /**
     * Send a WhatsApp message via the Fonnte API.
     * Returns true on HTTP 200 with Fonnte's `status: true`, false otherwise.
     */
    public function send(string $target, string $message): bool
    {
        $token    = $this->token    ?? config('services.fonnte.token');
        $endpoint = $this->endpoint ?? config('services.fonnte.endpoint');

        if (empty($token)) {
            Log::warning('FonnteService: token is not configured');
            return false;
        }

        try {
            $response = Http::asForm()
                ->withHeaders(['Authorization' => $token])
                ->timeout(15)
                ->post($endpoint, [
                    'target'     => $target,
                    'message'    => $message,
                    'countryCode' => '62',
                ]);

            $ok = $response->successful() && ($response->json('status') ?? false);

            if (! $ok) {
                Log::warning('FonnteService: send failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                    'target' => $target,
                ]);
            }

            return $ok;
        } catch (\Throwable $e) {
            Log::error('FonnteService: exception', [
                'message' => $e->getMessage(),
                'target'  => $target,
            ]);
            return false;
        }
    }
}
