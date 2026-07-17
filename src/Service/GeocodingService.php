<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Error;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for geocoding location strings using the Nominatim API.
 */
class GeocodingService {

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cache;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a new GeocodingService object.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   Logger factory service.
   */
  public function __construct(CacheBackendInterface $cache, ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->cache = $cache;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('makerspace_dashboard');
  }

  /**
   * TTL (seconds) for caching an unresolvable location ("no match").
   *
   * Locations that Nominatim genuinely cannot resolve rarely become
   * resolvable, so we remember them for a full day to avoid re-querying
   * on every dashboard render.
   */
  protected const NEGATIVE_TTL = 86400;

  /**
   * TTL (seconds) for caching a transient lookup failure (network/HTTP).
   *
   * Kept short so a temporary Nominatim outage or rate-limit self-heals,
   * while still preventing the same location from being retried on every
   * render within the same request storm.
   */
  protected const ERROR_TTL = 900;

  /**
   * Geocodes a location string (e.g., "City, State").
   *
   * @param string $location
   *   The location string to geocode.
   *
   * @return array|null
   *   An array with 'lat' and 'lon' keys, or NULL on failure.
   */
  public function geocode(string $location): ?array {
    $cid = 'makerspace_dashboard_geocode:' . md5($location);
    if ($cache = $this->cache->get($cid)) {
      // Negative results are cached as FALSE so repeated failures do not
      // re-hit Nominatim (which rate-limits aggressively) on every render.
      return $cache->data ?: NULL;
    }

    $url = 'https://nominatim.openstreetmap.org/search';
    try {
      $response = $this->httpClient->request('GET', $url, [
        'query' => [
          'q' => $location,
          'format' => 'json',
          'limit' => 1,
        ],
        'headers' => [
          'User-Agent' => 'Makerspace Dashboard/1.0',
        ],
      ]);

      $data = json_decode($response->getBody(), TRUE);
      if (!empty($data) && isset($data[0]['lat']) && isset($data[0]['lon'])) {
        $coordinates = [
          'lat' => (float) $data[0]['lat'],
          'lon' => (float) $data[0]['lon'],
        ];
        $this->cache->set($cid, $coordinates, CacheBackendInterface::CACHE_PERMANENT);
        return $coordinates;
      }

      // Valid response, but the location could not be resolved. Cache the
      // miss so we stop asking about a location that will not resolve.
      $this->cache->set($cid, FALSE, time() + self::NEGATIVE_TTL);
    }
    catch (RequestException $e) {
      // Cache the transient failure for a short window to back off from a
      // rate-limited or unreachable Nominatim instead of retrying every
      // render, and log once (without emitting deprecated warnings).
      $this->cache->set($cid, FALSE, time() + self::ERROR_TTL);
      Error::logException($this->logger, $e, 'Geocoding lookup failed for @location', [
        '@location' => $location,
      ]);
    }

    return NULL;
  }

}
