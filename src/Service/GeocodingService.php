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
      return $cache->data;
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
    }
    catch (RequestException $e) {
      // Log without emitting deprecated warnings.
      Error::logException($this->logger, $e, 'Geocoding lookup failed for @location', [
        '@location' => $location,
      ]);
    }

    return NULL;
  }

}
