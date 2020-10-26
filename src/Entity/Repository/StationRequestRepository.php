<?php

namespace App\Entity\Repository;

use App\Doctrine\Repository;
use App\Entity;
use App\Exception;
use App\Radio\AutoDJ;
use App\Utilities;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class StationRequestRepository extends Repository
{
    public function submit(
        Entity\Station $station,
        string $trackId,
        bool $isAuthenticated,
        string $ip
    ): int {
        // Forbid web crawlers from using this feature.
        if (Utilities::isCrawler()) {
            throw new Exception(__('Search engine crawlers are not permitted to use this feature.'));
        }

        // Verify that the station supports requests.
        if (!$station->getEnableRequests()) {
            throw new Exception(__('This station does not accept requests currently.'));
        }

        // Verify that Track ID exists with station.
        $media_repo = $this->em->getRepository(Entity\Media::class);
        $media_item = $media_repo->findOneBy(['unique_id' => $trackId, 'station_id' => $station->getId()]);

        if (!($media_item instanceof Entity\Media)) {
            throw new Exception(__('The song ID you specified could not be found in the station.'));
        }

        if (!$media_item->isRequestable()) {
            throw new Exception(__('The song ID you specified cannot be requested for this station.'));
        }

        // Check if the song is already enqueued as a request.
        $this->checkPendingRequest($media_item, $station);

        // Check the most recent song history.
        $this->checkRecentPlay($media_item, $station);

        if (!$isAuthenticated) {
            // Check for any request (on any station) within the last $threshold_seconds.
            $thresholdMins = $station->getRequestThreshold() ?? 5;
            $thresholdSeconds = $thresholdMins * 60;

            // Always have a minimum threshold to avoid flooding.
            if ($thresholdSeconds < 60) {
                $thresholdSeconds = 15;
            }

            $recent_requests = $this->em->createQuery(/** @lang DQL */ 'SELECT sr
                FROM App\Entity\StationRequest sr
                WHERE sr.ip = :user_ip
                AND sr.timestamp >= :threshold')
                ->setParameter('user_ip', $ip)
                ->setParameter('threshold', time() - $thresholdSeconds)
                ->getArrayResult();

            if (count($recent_requests) > 0) {
                throw new Exception(__(
                    'You have submitted a request too recently! Please wait before submitting another one.'
                ));
            }
        }

        // Save request locally.
        $record = new Entity\StationRequest($station, $media_item, $ip);
        $this->em->persist($record);
        $this->em->flush();

        return $record->getId();
    }

    /**
     * Check if the song is already enqueued as a request.
     *
     * @param Entity\Media $media
     * @param Entity\Station $station
     *
     * @throws Exception
     */
    public function checkPendingRequest(Entity\Media $media, Entity\Station $station): bool
    {
        $pending_request_threshold = time() - (60 * 10);

        try {
            $pending_request = $this->em->createQuery(/** @lang DQL */ 'SELECT sr.timestamp
                FROM App\Entity\StationRequest sr
                WHERE sr.track_id = :track_id
                AND sr.station_id = :station_id
                AND (sr.timestamp >= :threshold OR sr.played_at = 0)
                ORDER BY sr.timestamp DESC')
                ->setParameter('track_id', $media->getId())
                ->setParameter('station_id', $station->getId())
                ->setParameter('threshold', $pending_request_threshold)
                ->setMaxResults(1)
                ->getSingleScalarResult();
        } catch (\Exception $e) {
            return true;
        }

        if ($pending_request > 0) {
            throw new Exception(__('Duplicate request: this song was already requested and will play soon.'));
        }

        return true;
    }

    public function getNextPlayableRequest(
        Entity\Station $station,
        ?CarbonInterface $now = null
    ): ?Entity\StationRequest {
        $now ??= CarbonImmutable::now($station->getTimezoneObject());

        // Look up all requests that have at least waited as long as the threshold.
        $requests = $this->em->createQuery(/** @lang DQL */ 'SELECT sr, sm
            FROM App\Entity\StationRequest sr JOIN sr.track sm
            WHERE sr.played_at = 0
            AND sr.station = :station
            ORDER BY sr.skip_delay DESC, sr.id ASC')
            ->setParameter('station', $station)
            ->execute();

        foreach ($requests as $request) {
            /** @var Entity\StationRequest $request */
            if ($request->shouldPlayNow($now)) {
                try {
                    $this->checkRecentPlay($request->getTrack(), $station);
                } catch (\Exception $e) {
                    continue;
                }

                return $request;
            }
        }

        return null;
    }

    /**
     * Check the most recent song history.
     *
     * @param Entity\Media $media
     * @param Entity\Station $station
     *
     * @throws Exception
     */
    public function checkRecentPlay(Entity\Media $media, Entity\Station $station): bool
    {
        $lastPlayThresholdMins = ($station->getRequestThreshold() ?? 15);

        if (0 === $lastPlayThresholdMins) {
            return true;
        }

        $lastPlayThreshold = time() - ($lastPlayThresholdMins * 60);

        $recentTracks = $this->em->createQuery(/** @lang DQL */ 'SELECT sh.id, sh.title, sh.artist
                FROM App\Entity\SongHistory sh
                WHERE sh.station = :station
                AND sh.timestamp_start >= :threshold
                ORDER BY sh.timestamp_start DESC')
            ->setParameter('station', $station)
            ->setParameter('threshold', $lastPlayThreshold)
            ->getArrayResult();


        $eligibleTracks = [
            [
                'title' => $media->getTitle(),
                'artist' => $media->getArtist(),
            ],
        ];

        $isDuplicate = (null === AutoDJ\Queue::getDistinctTrack($eligibleTracks, $recentTracks));

        if ($isDuplicate) {
            throw new Exception(__(
                'This song or artist has been played too recently. Wait a while before requesting it again.'
            ));
        }

        return true;
    }
}
