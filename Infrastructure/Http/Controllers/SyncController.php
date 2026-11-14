<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\LoanSync\API\Contracts\SyncServiceContract;

/**
 * The four endpoints a handset talks to.
 *
 * The device id comes from the `X-Device-Id` header rather than the body, so
 * one value identifies the handset on every call including the ones with no
 * body at all.
 */
final class SyncController
{
    private const string DEVICE_HEADER = 'X-Device-Id';

    public function __construct(
        private readonly SyncServiceContract $sync,
    ) {
    }

    public function push(Request $request): Response
    {
        $operations = $request->input('operations', []);

        if (!is_array($operations)) {
            return Response::unprocessable(['operations' => 'Expected a list of operations.']);
        }

        return Response::json($this->sync->push($this->deviceId($request), array_values($operations)));
    }

    public function pull(Request $request): Response
    {
        $entities = $request->query('entities');

        if (is_string($entities)) {
            $entities = array_values(array_filter(array_map('trim', explode(',', $entities))));
        }

        $page = $this->sync->pull(
            deviceId: $this->deviceId($request),
            cursor:   $request->integer('cursor'),
            entities: is_array($entities) && $entities !== [] ? $entities : null,
            limit:    $request->integer('limit') ?: 500,
        );

        return Response::json($page->toArray());
    }

    public function shard(Request $request): Response
    {
        return Response::json($this->sync->claimShard($this->deviceId($request))->toArray());
    }

    public function register(Request $request): Response
    {
        return Response::json($this->sync->registerDevice(
            $this->deviceId($request),
            $request->only(['platform', 'app_version', 'model', 'push_token']),
        ));
    }

    private function deviceId(Request $request): string
    {
        return trim((string) ($request->header(self::DEVICE_HEADER) ?? ''));
    }
}
