<?php
/**
 * PHP version 7.
 *
 * LICENSE: This source file is subject to copyright
 *
 * @author    Thomas Barriac <thomas@tag-walk.com>
 * @copyright 2019 TAGWALK
 * @license   proprietary
 */

namespace Tagwalk\ApiClientBundle\Manager;

use GuzzleHttp\RequestOptions;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;
use Tagwalk\ApiClientBundle\Model\Media;
use Tagwalk\ApiClientBundle\Provider\ApiProvider;
use Tagwalk\ApiClientBundle\Serializer\Normalizer\MediaNormalizer;
use Tagwalk\ApiClientBundle\Utils\Constants\Status;

class MediaManager
{
    /** @var int default list size */
    public const DEFAULT_SIZE = 24;

    /** @var string default list medias sort for a model */
    public const DEFAULT_MEDIAS_MODEL_SORT = 'created_at:desc';

    /**
     * @var int last query result count
     */
    public $lastCount;

    /**
     * @var ApiProvider
     */
    private $apiProvider;

    /**
     * @var MediaNormalizer
     */
    private $mediaNormalizer;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param ApiProvider     $apiProvider
     * @param MediaNormalizer $mediaNormalizer
     */
    public function __construct(ApiProvider $apiProvider, MediaNormalizer $mediaNormalizer)
    {
        $this->apiProvider = $apiProvider;
        $this->mediaNormalizer = $mediaNormalizer;
        $this->logger = new NullLogger();
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param string $slug
     *
     * @return null|Media
     */
    public function get(string $slug): ?Media
    {
        $data = null;
        $apiResponse = $this->apiProvider->request('GET', '/api/medias/'.$slug, [RequestOptions::HTTP_ERRORS => false]);
        if ($apiResponse->getStatusCode() === Response::HTTP_OK) {
            $data = json_decode($apiResponse->getBody(), true);
            $data = $this->mediaNormalizer->denormalize($data, Media::class);
        } elseif ($apiResponse->getStatusCode() !== Response::HTTP_NOT_FOUND) {
            $this->logger->error('MediaManager::get unexpected status code', [
                'code'    => $apiResponse->getStatusCode(),
                'message' => $apiResponse->getBody()->getContents(),
            ]);
        }

        return $data;
    }

    /**
     * @param string $type
     * @param string $season
     * @param string $designer
     * @param string $look
     *
     * @return null|Media
     */
    public function findByTypeSeasonDesignerLook(string $type, string $season, string $designer, string $look): ?Media
    {
        $media = null;
        if ($type && $season && $designer && $look) {
            $apiResponse = $this->apiProvider->request(
                'GET',
                sprintf('/api/medias/%s/%s/%s/%s', $type, $season, $designer, $look),
                [RequestOptions::HTTP_ERRORS => false]
            );
            if ($apiResponse->getStatusCode() === Response::HTTP_OK) {
                $data = json_decode($apiResponse->getBody(), true);
                $media = $this->mediaNormalizer->denormalize($data, Media::class);
            } elseif ($apiResponse->getStatusCode() !== Response::HTTP_NOT_FOUND) {
                $this->logger->error('MediaManager::findByTypeSeasonDesignerLook unexpected status code', [
                    'code'    => $apiResponse->getStatusCode(),
                    'message' => $apiResponse->getBody()->getContents(),
                ]);
            }
        }

        return $media;
    }

    /**
     * @param string      $type
     * @param string      $season
     * @param string      $designer
     * @param string|null $city
     *
     * @return array|mixed
     */
    public function listRelated(string $type, string $season, string $designer, ?string $city = null): array
    {
        $results = [];
        $query = array_merge([
            'analytics' => 0,
            'from'      => 0,
            'size'      => 6,
        ], compact('type', 'season', 'designer', 'city'));
        $apiResponse = $this->apiProvider->request('GET', '/api/medias', [
            RequestOptions::QUERY       => $query,
            RequestOptions::HTTP_ERRORS => false,
        ]);
        if ($apiResponse->getStatusCode() === Response::HTTP_OK) {
            $results = json_decode($apiResponse->getBody(), true);
        } else {
            $this->logger->error('MediaManager::listRelated unexpected status code', [
                'code'    => $apiResponse->getStatusCode(),
                'message' => $apiResponse->getBody()->getContents(),
            ]);
        }

        return $results;
    }

    /**
     * @param array  $query
     * @param int    $from
     * @param int    $size
     * @param string $status
     *
     * @return Media[]
     */
    public function list($query = [], $from = 0, $size = self::DEFAULT_SIZE, $status = Status::ENABLED): array
    {
        $data = [];
        $this->lastCount = 0;
        $query = array_merge($query, compact('from', 'size', 'status'));
        $apiResponse = $this->apiProvider->request('GET', '/api/medias', [
            RequestOptions::QUERY       => $query,
            RequestOptions::HTTP_ERRORS => false,
        ]);
        if ($apiResponse->getStatusCode() === Response::HTTP_OK) {
            $data = json_decode($apiResponse->getBody(), true);
            foreach ($data as $i => $datum) {
                $data[$i] = $this->mediaNormalizer->denormalize($datum, Media::class);
            }
            $this->lastCount = (int) $apiResponse->getHeaderLine('X-Total-Count');
        } elseif ($apiResponse->getStatusCode() === Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE) {
            throw new OutOfBoundsException('Api response: Range not satisfiable');
        } else {
            $this->logger->error('MediaManager::list unexpected status code', [
                'code'    => $apiResponse->getStatusCode(),
                'message' => $apiResponse->getBody()->getContents(),
            ]);
        }

        return $data;
    }

    /**
     * Find medias looks by model slug.
     *
     * @param string $slug
     * @param array  $query
     *
     * @return Media[]
     */
    public function listByModel(string $slug, array $query = []): array
    {
        $query = array_merge($query, ['sort' => self::DEFAULT_MEDIAS_MODEL_SORT]);
        $data = [];
        $apiResponse = $this->apiProvider->request('GET', '/api/individuals/'.$slug.'/medias', [
            RequestOptions::QUERY       => $query,
            RequestOptions::HTTP_ERRORS => false,
        ]);
        if ($apiResponse->getStatusCode() === Response::HTTP_OK) {
            $medias = json_decode($apiResponse->getBody(), true);
            $data['medias'] = [];
            if (!empty($medias)) {
                foreach ($medias as $media) {
                    $data['medias'][] = $this->mediaNormalizer->denormalize($media, Media::class);
                }
            }
            $this->lastCount = (int) $apiResponse->getHeaderLine('X-Total-Count');
            $data['streetstyles_count'] = (int) $apiResponse->getHeaderLine('X-Streetstyles-Count');
            $data['news_count'] = (int) $apiResponse->getHeaderLine('X-News-Count');
            $data['talks_count'] = (int) $apiResponse->getHeaderLine('X-Talks-Count');
        } else {
            $this->logger->error('MediaManager::listByModel unexpected status code', [
                'code'    => $apiResponse->getStatusCode(),
                'message' => $apiResponse->getBody()->getContents(),
            ]);
            $this->lastCount = 0;
        }

        return $data;
    }
}
