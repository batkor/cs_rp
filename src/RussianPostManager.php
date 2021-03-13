<?php

namespace Drupal\cs_rp;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use LapayGroup\RussianPost\CategoryList;

/**
 * The manager integrate Russian Post for Drupal.
 */
class RussianPostManager {

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * RussianPostManager constructor.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(CacheBackendInterface $cache, ConfigFactoryInterface $configFactory) {
    $this->cache = $cache;
    $this->configFactory = $configFactory;
  }

  /**
   * Returns category list.
   *
   * @param array $exclude
   *   The list categories for exclude.
   *
   * @return array
   *   The category list.
   */
  public function getCategoryList(array $exclude = [400, 700, 800]): array {
    $cid = 'russian_post_category:' . Json::encode($exclude);
    $result = $this->cache->get($cid);
    if ($result) {
      return $result->data;
    }
    $categoryList = new CategoryList();
    $categoryList->setCategoryDelete($exclude);
    $result = $categoryList->parseToArray();
    $this->cache->set($cid, $result);
    return $result;
  }
  
}
