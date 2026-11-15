<?php

namespace App\Services\ChurchApi;

use Illuminate\Support\Facades\Cache;

/**
 * Live (briefly cached) lists of circuits and societies from church.net.za, for the set-up dropdowns.
 * To refresh immediately:  php artisan cache:forget church_api.circuits
 */
class Directory
{
    public function __construct(private readonly ChurchApiClient $api)
    {
    }

    /** @return array<int|string,string> circuit id => label */
    public function circuitOptions(): array
    {
        return collect($this->circuits())
            ->mapWithKeys(fn (array $c) => [$c['id'] => $c['district'] ? "{$c['name']} — {$c['district']}" : $c['name']])
            ->all();
    }

    /** @return array<int|string,string> society id => name */
    public function societyOptions(?string $circuitId): array
    {
        if (blank($circuitId)) {
            return [];
        }

        return collect($this->societies($circuitId))
            ->mapWithKeys(fn (array $s) => [$s['id'] => $s['name']])
            ->all();
    }

    public function circuit(string $id): array
    {
        return $this->circuits()[$id] ?? throw new SyncException('That circuit was not found on church.net.za.');
    }

    public function society(string $circuitId, string $id): array
    {
        return $this->societies($circuitId)[$id] ?? throw new SyncException('That society was not found in that circuit on church.net.za.');
    }

    private function circuits(): array
    {
        return Cache::remember('church_api.circuits', now()->addHours(6), function () {
            $circuits = [];

            foreach ($this->api->all('circuits', ['include' => 'district']) as $c) {
                $id = $this->text($c['id'] ?? null);
                $name = $this->text($c['name'] ?? null);

                if ($id === null || $name === null) {
                    continue;
                }

                $circuits[$id] = [
                    'id' => $id,
                    'slug' => $this->text($c['slug'] ?? null) ?? $id,
                    'name' => $name,
                    'reference' => $this->text($c['reference'] ?? null),
                    'district' => $this->text(data_get($c, 'district.name')),
                ];
            }

            if ($circuits === []) {
                throw new SyncException('church.net.za returned no circuits.');
            }

            uasort($circuits, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            return $circuits;
        });
    }

    private function societies(string $circuitId): array
    {
        return Cache::remember("church_api.societies.{$circuitId}", now()->addHour(), function () use ($circuitId) {
            $circuit = $this->circuit($circuitId);
            $societies = [];

            foreach ($this->api->all('societies', ['circuit' => $circuit['slug'], 'include' => 'circuit']) as $s) {
                $id = $this->text($s['id'] ?? null);
                $name = $this->text($s['name'] ?? null);

                // Never trust the server-side filter alone: keep only societies that really belong to this circuit.
                if ($id === null || $name === null || $this->text(data_get($s, 'circuit.id')) !== $circuitId) {
                    continue;
                }

                $societies[$id] = ['id' => $id, 'slug' => $this->text($s['slug'] ?? null), 'name' => $name];
            }

            uasort($societies, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            return $societies;
        });
    }

    private function text(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}