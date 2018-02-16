<?php

namespace App\Http\Repositories;

use App\Http\Models\LinkedConnectionPage;
use Cache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Http\Models\LinkedConnection;


/**
 * Class LinkedConnectionsRepositories
 * A read-only repository for realtime train data in linkedconnections format
 *
 * @package App\Http\Controllers
 */
class LinkedConnectionsRepository implements LinkedConnectionsRepositoryContract
{

    const PAGE_SIZE_SECONDS = 600;
    private $rawLinkedConnectionsSource;

    /**
     * Create a new repository instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->rawLinkedConnectionsSource = app(LinkedConnectionsRawRepositoryContract::class);
    }


    /**
     * Retrieve an array of LinkedConnection objects for a certain departure time
     *
     * @param Carbon $departureTime
     * @return \App\Http\Models\LinkedConnectionPage
     */
    public function getFilteredLinkedConnections(
        Carbon $departureTime,
        $filterKey,
        $filterOperator,
        $filterValue
    ): array
    {

        $raw = $this->rawLinkedConnectionsSource->getRawLinkedConnections($departureTime);

        $filterValue = urldecode($filterValue);
        if ($filterKey == null || $filterOperator == null || $filterValue == null) {
            return $raw;
        }

        foreach ($raw['data'] as $key => &$entry) {

            if (!key_exists('arrivalDelay', $entry)) {
                $entry['arrivalDelay'] = 0;
            }
            if (!key_exists('departureDelay', $entry)) {
                $entry['departureDelay'] = 0;
            }
            $keep = false;

            switch ($filterOperator) {
                case '=':
                    $keep = ($entry[$filterKey] == $filterValue);
                    break;
                case '!=':
                    $keep = ($entry[$filterKey] != $filterValue);
                    break;
                case '<':
                    $keep = ($entry[$filterKey] < $filterValue);
                    break;
                case '<=':
                    $keep = ($entry[$filterKey] <= $filterValue);
                    break;
                case '>':
                    $keep = ($entry[$filterKey] > $filterValue);
                    break;
                case '>=':
                    $keep = ($entry[$filterKey] >= $filterValue);
                    break;
            }
            if (!$keep) {
                // Remove this from the results
                unset($raw['data'][$key]);
            }

        }

        $raw['data'] = array_values($raw['data']);
        return $raw;
    }

    public function getLinkedConnectionsInWindow(
        Carbon $departureTime,
        int $window = self::PAGE_SIZE_SECONDS
    ): LinkedConnectionPage
    {
        $departureTime = $departureTime->copy();
        $departureTime = $this->getRoundedDepartureTime($departureTime);

        $cacheKey = 'lc|getLinkedConnectionsInWindow|' . $departureTime->getTimestamp() . "|" . $window;
        if (Cache::has($cacheKey)) {
            $previousResponse = Cache::get($cacheKey);

            // If data isn't too old, just return for faster responses
            if (Carbon::now()
                ->lessThan($previousResponse->getExpiresAt())) {
                return $previousResponse;
            }
        }

        $departures = [];
        $etag = "";
        $expiresAt = null;

        for ($addedSeconds = 0; $addedSeconds < $window; $addedSeconds += self::PAGE_SIZE_SECONDS) {
            $windowPage = $this->getLinkedConnections($departureTime);
            $departures = array_merge($departures, $windowPage->getLinkedConnections());

            $etag .= $windowPage->getEtag();

            if ($expiresAt == null || $windowPage->getExpiresAt()->lessThan($expiresAt)) {
                $expiresAt = $windowPage->getExpiresAt();
            }

            $departureTime = $departureTime->addSeconds(self::PAGE_SIZE_SECONDS);
        }

        // Calculate a new etag based on the concatenation of all other etags
        $etag = md5($etag);

        if (isset($previousResponse) && $etag == $previousResponse->getEtag()) {
            // If nothing changed, return the previous response. This way we get to keep the created_at date for caching purposes.
            return $previousResponse;
        }

        $combinedPage = new LinkedConnectionPage($departures, new Carbon(), $expiresAt, $etag);

        Cache::put($cacheKey, $combinedPage, 120);

        return $combinedPage;
    }

    /**
     * Get the first n linked connections, starting at a certain time
     * @param \Carbon\Carbon $departureTime The departure time from where the search should start
     * @param int $results The number of linked connections to retrieve
     * @return \App\Http\Models\LinkedConnectionPage A linkedConnections page containing all results
     */
    public function getConnectionsByLimit(
        Carbon $departureTime,
        int $results
    ): LinkedConnectionPage
    {
        $departureTime = $this->getRoundedDepartureTime($departureTime);

        $cacheKey = 'lc|getConnectionsByLimit|' . $departureTime->getTimestamp() . "|" . $results;
        if (Cache::has($cacheKey)) {
            $previousResponse = Cache::get($cacheKey);

            // If data isn't too old, just return for faster responses
            if (Carbon::now()
                ->lessThan($previousResponse->getExpiresAt())) {
                return $previousResponse;
            }
        }

        $departures = [];
        $etag = "";
        $expiresAt = null;

        for ($addedSeconds = 0; $results < count($departures); $addedSeconds += self::PAGE_SIZE_SECONDS) {
            $windowPage = $this->getLinkedConnections($departureTime);
            $departures = array_merge($departures, $windowPage->getLinkedConnections());

            $etag .= $windowPage->getEtag();

            if ($expiresAt == null || $windowPage->getExpiresAt()->lessThan($expiresAt)) {
                $expiresAt = $windowPage->getExpiresAt();
            }

            $departureTime->addSeconds(self::PAGE_SIZE_SECONDS);
        }

        // Calculate a new etag based on the concatenation of all other etags
        $etag = md5($etag);
        if (isset($previousResponse) && $etag == $previousResponse->getEtag()) {
            // return the response with the old creation date, we can use this later on for HTTP headers
            return $previousResponse;
        }

        $combinedPage = new LinkedConnectionPage($departures, new Carbon(), $expiresAt, $etag);

        Cache::put($cacheKey, $combinedPage, 120);

        return $combinedPage;
    }


    /**
     * Retrieve an array of LinkedConnection objects for a certain departure time
     *
     * @param Carbon $departureTime
     * @return \App\Http\Models\LinkedConnectionPage
     */
    public function getLinkedConnections(Carbon $departureTime): LinkedConnectionPage
    {
        $cacheKey = 'lc|getLinkedConnections|' . $departureTime->getTimestamp();
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $raw = $this->rawLinkedConnectionsSource->getRawLinkedConnections($departureTime);
        $expiresAt = $raw['expiresAt'];
        $etag = $raw['etag'];
        $createdAt = $raw['createdAt'];

        $linkedConnections = [];

        foreach ($raw['data'] as $entry) {
            $arrivalDelay = key_exists('arrivalDelay', $entry) ? $entry['arrivalDelay'] : 0;
            $departureDelay = key_exists('departureDelay', $entry) ? $entry['departureDelay'] : 0;

            if (ends_with($departureDelay, "S")) {
                $departureDelay = substr($departureDelay, 0, strlen($departureDelay) - 1);
            }

            if (ends_with($arrivalDelay, "S")) {
                $arrivalDelay = substr($arrivalDelay, 0, strlen($arrivalDelay) - 1);
            }

            $linkedConnections[] = new LinkedConnection($entry['@id'],
                $entry['departureStop'],
                strtotime($entry['departureTime']),
                $departureDelay,
                $entry['arrivalStop'],
                strtotime($entry['arrivalTime']),
                $arrivalDelay,
                $entry['direction'],
                $entry['gtfs:trip'],
                $entry['gtfs:route']
            );
        }
        $linkedConnectionsPage = new LinkedConnectionPage($linkedConnections, $createdAt, $expiresAt, $etag, $raw['previous'], $raw['next']);

        Cache::put($cacheKey, $linkedConnectionsPage, $expiresAt);

        return $linkedConnectionsPage;
    }


    private function getRoundedDepartureTime(Carbon $departureTime): Carbon
    {
        return $departureTime->subMinute($departureTime->minute % 10)->second(0);
    }
}
