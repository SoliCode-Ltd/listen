<?php

namespace Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for Stripe's API in plan sync tests (ApiRequestor::setHttpClient):
 * it answers like Stripe and remembers every request, so nothing leaves the
 * machine. Prices keep their amounts, so a sync can tell a changed price from
 * one that is already right.
 */
class FakeStripePlanSyncClient implements ClientInterface
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<string, array{unit_amount: int, active: bool}> Stripe's prices by id */
    public array $prices = [];

    /** When set, every request fails with this Stripe error. */
    public ?string $failWith = null;

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtolower((string) $method);
        $path = (string) parse_url((string) $absUrl, PHP_URL_PATH);
        $params = (array) $params;
        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params];

        if ($this->failWith !== null) {
            return $this->json(['error' => ['type' => 'api_error', 'message' => $this->failWith]], 500);
        }

        if (str_ends_with($path, '/search')) {
            return $this->json(['object' => 'search_result', 'data' => [], 'has_more' => false, 'url' => $path]);
        }

        if ($path === '/v1/products') {
            return $this->json(['id' => 'prod_synced', 'object' => 'product']);
        }

        if (preg_match('#^/v1/products/(\w+)$#', $path, $match)) {
            return $this->json(['id' => $match[1], 'object' => 'product']);
        }

        if ($path === '/v1/prices') {
            $id = 'price_'.($params['recurring']['interval'] ?? 'month').'_'.(int) ($params['unit_amount'] ?? 0);
            $this->prices[$id] = ['unit_amount' => (int) ($params['unit_amount'] ?? 0), 'active' => true];

            return $this->json(['id' => $id, 'object' => 'price'] + $this->prices[$id]);
        }

        if (preg_match('#^/v1/prices/(\w+)$#', $path, $match)) {
            if (! isset($this->prices[$match[1]])) {
                return $this->json(['error' => ['type' => 'invalid_request_error', 'message' => 'No such price']], 404);
            }
            if ($method === 'post' && array_key_exists('active', $params)) {
                $this->prices[$match[1]]['active'] = $params['active'] === 'true' || $params['active'] === true;
            }

            return $this->json(['id' => $match[1], 'object' => 'price'] + $this->prices[$match[1]]);
        }

        if ($path === '/v1/coupons') {
            return $this->json(['object' => 'coupon', 'valid' => true] + $params);
        }

        return $this->json(['error' => ['type' => 'invalid_request_error', 'message' => 'No such object']], 404);
    }

    /**
     * The requests that would have changed something in Stripe.
     *
     * @return list<array{method: string, path: string, params: array<string, mixed>}>
     */
    public function writes(): array
    {
        return array_values(array_filter($this->requests, fn (array $request): bool => $request['method'] !== 'get'));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: string, 1: int, 2: array<string, string>}
     */
    private function json(array $body, int $status = 200): array
    {
        return [json_encode($body), $status, []];
    }
}
