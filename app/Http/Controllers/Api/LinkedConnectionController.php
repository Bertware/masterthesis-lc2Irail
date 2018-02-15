<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;


use App\Http\Repositories\LinkedConnectionsRawRepositoryContract;
use App\Http\Repositories\LinkedConnectionsRepositoryContract;
use App\Http\Repositories\LinkedConnectionsWebRepository;
use App\Http\Repositories\LiveboardsRepositoryContract;
use App\Http\Requests\HyperrailRequest;


use Cache;
use Carbon\Carbon;

class LinkedConnectionController extends Controller
{

    public function getConnections(HyperrailRequest $request)
    {
        $repository = app(LinkedConnectionsRawRepositoryContract::class);
        $filtered = $repository->getRawLinkedConnections($request->getDateTime());

        return response()->json($filtered['data'], 200)->withHeaders([
            'Expires' => $filtered['expiresAt']->format('D, d M Y H:i:s e'),
            'Cache-Control' => 'Public, max-age=' . $filtered['expiresAt']->diffInSeconds(new Carbon()),
            'Last-Modified' => $filtered['createdAt']->format('D, d M Y H:i:s e'),
            'ETag' => $filtered['etag'],
        ]);
    }

    public function getFilteredConnections(HyperrailRequest $request, String $key, String $operator, String $value)
    {
        /**
         * @var $repository LiveboardsRepositoryContract
         */
        $repository = app(LinkedConnectionsRepositoryContract::class);
        $filtered = $repository->getFilteredLinkedConnections($request->getDateTime(), urldecode($key),
            urldecode($operator), urldecode($value));

        return response()->json($filtered['data'], 200)->withHeaders([
            'Expires' => $filtered['expiresAt']->format('D, d M Y H:i:s e'),
            'Cache-Control' => 'Public, max-age=' . $filtered['expiresAt']->diffInSeconds(new Carbon()),
            'Last-Modified' => $filtered['createdAt']->format('D, d M Y H:i:s e'),
            'ETag' => $filtered['etag'],
        ]);
    }

}