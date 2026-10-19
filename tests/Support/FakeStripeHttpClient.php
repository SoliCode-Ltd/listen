<?php

namespace Tests\Support;

use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for Stripe's API (Stripe\ApiRequestor::setHttpClient): each test
 * says what a request is answered with, every request is kept, and nothing
 * ever leaves the machine. A request no test set up gets Stripe's 404.
 */
class FakeStripeHttpClient implements ClientInterface
{
    /** @var list<array{method:string, path:string, params:array}> */
    public array $requests = [];

    /** @var array<string, array{0:int, 1:array<string, mixed>}> */
    private array $answers = [];

    /** Stripe cannot be reached at all. */
    public bool $unreachable = false;

    /** @param  array<string, mixed>  $body */
    public function answer(string $method, string $path, array $body, int $status = 200): self
    {
        $this->answers[strtolower($method).' '.$path] = [$status, $body];

        return $this;
    }

    public function subscription(string $id, string $status, string $customer = 'cus_test', string $price = 'price_test'): self
    {
        return $this->answer('get', '/v1/subscriptions/'.$id, [
            'id' => $id, 'object' => 'subscription', 'customer' => $customer, 'status' => $status,
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_'.$id, 'object' => 'subscription_item', 'quantity' => 1,
                'price' => ['id' => $price, 'object' => 'price', 'product' => 'prod_test'],
            ]]],
        ]);
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtolower($method);
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => (array) $params];

        if ($this->unreachable) {
            throw ApiConnectionException::factory('Could not connect to Stripe (test).');
        }

        [$status, $body] = $this->answers[$method.' '.$path] ?? [404, ['error' => [
            'type' => 'invalid_request_error',
            'code' => 'resource_missing',
            'message' => 'Nothing set up in the test for '.$method.' '.$path,
        ]]];

        return [json_encode($body), $status, []];
    }

    public function sent(string $method, string $path): bool
    {
        foreach ($this->requests as $request) {
            if ($request['method'] === strtolower($method) && $request['path'] === $path) {
                return true;
            }
        }

        return false;
    }
}
