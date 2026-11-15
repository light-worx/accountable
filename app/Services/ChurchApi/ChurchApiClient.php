<?php

namespace App\Services\ChurchApi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Read-only client for the church.net.za API. Follows pagination and turns failures into plain messages. */
class ChurchApiClient
{
    private function http(): PendingRequest
    {
        $token = config('church_api.token');

        if (blank($token)) {
            throw new SyncException('CHURCH_API_TOKEN is not set in .env.');
        }

        return Http::withToken($token)
            ->acceptJson()
            ->baseUrl(rtrim((string) config('church_api.url'), '/'))
            ->timeout((int) config('church_api.timeout', 15))
            ->retry(2, 500, throw: false);
    }

    /** @return list<array> every record from every page */
    public function all(string $endpoint, array $query = []): array
    {
        $rows = [];
        $page = 1;

        do {
            try {
                $response = $this->http()->get($endpoint, $query + ['per_page' => 100, 'page' => $page]);
            } catch (ConnectionException $e) {
                throw new SyncException('Could not reach church.net.za (' . $e->getMessage() . ').', 0, $e);
            }

            if ($response->failed()) {
                throw new SyncException(match ($response->status()) {
                    401 => 'church.net.za refused the API token (401). Check CHURCH_API_TOKEN.',
                    403 => 'church.net.za says this token is not allowed (403). Check its abilities and IP allow-list.',
                    429 => 'church.net.za is limiting requests (429). Try again in a minute.',
                    default => "church.net.za returned an error ({$response->status()}) for /{$endpoint}.",
                });
            }

            $json = $response->json();

            if (! is_array($json) || ! isset($json['data']) || ! is_array($json['data'])) {
                throw new SyncException("Unexpected reply from /{$endpoint}: no 'data' list found.");
            }

            $rows = array_merge($rows, $json['data']);

            $last = data_get($json, 'meta.last_page');
            $hasMore = $last !== null ? $page < (int) $last : filled(data_get($json, 'links.next'));
            $page++;
        } while ($hasMore && $page <= 500);

        return $rows;
    }
}