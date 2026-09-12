<?php

namespace App\Http\Middleware;

use App\Services\AutoDownloadLifecycleService;
use App\Services\TorrentClientService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** @psalm-api Laravel route middleware entrypoint. */
class RecordTorrentClientConnectivity
{
    public function __construct(
        protected TorrentClientService $clientService,
        protected AutoDownloadLifecycleService $lifecycle,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $client = $this->clientService->getActiveClient();
        $response = $next($request);

        if ($client === null || ! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);
        if (! is_array($payload) || ! array_key_exists('connected', $payload)) {
            return $response;
        }

        $this->lifecycle->recordClientConnectivity(
            $client->getId(),
            (bool) $payload['connected']
        );

        return $response;
    }
}
