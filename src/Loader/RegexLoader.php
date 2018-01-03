<?php
/**
 * This file is part of the browscap-helper package.
 *
 * Copyright (c) 2015-2017, Thomas Mueller <mimmi20@live.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);
namespace BrowscapHelper\Loader;

use BrowserDetector\Loader\NotFoundException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * detection class using regexes
 *
 * @category  BrowserDetector
 *
 * @author    Thomas Mueller <mimmi20@live.de>
 * @copyright 2012-2017 Thomas Mueller
 * @license   http://www.opensource.org/licenses/MIT MIT License
 */
class RegexLoader
{
    /**
     * @var \Psr\Cache\CacheItemPoolInterface
     */
    private $cache;

    /**
     * an logger instance
     *
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param \Psr\Cache\CacheItemPoolInterface $cache
     * @param \Psr\Log\LoggerInterface          $logger
     */
    public function __construct(CacheItemPoolInterface $cache, LoggerInterface $logger)
    {
        $this->cache  = $cache;
        $this->logger = $logger;
    }

    /**
     * @return array|null
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getRegexes(): ?array
    {
        $cacheInitializedId = hash('sha512', 'regex-cache is initialized');
        $cacheInitialized   = $this->cache->getItem($cacheInitializedId);

        if (!$cacheInitialized->isHit() || !$cacheInitialized->get()) {
            $this->initCache($cacheInitialized);
        }

        $cacheItem = $this->cache->getItem(hash('sha512', 'regex-cache'));

        if (!$cacheItem->isHit()) {
            throw new NotFoundException('no regexes are found');
        }

        return $cacheItem->get();
    }

    /**
     * @param \Psr\Cache\CacheItemInterface $cacheInitialized
     *
     * @throws \BrowserDetector\Loader\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function initCache(CacheItemInterface $cacheInitialized): void
    {
        static $regexes = null;

        if (null === $regexes) {
            $regexes = Yaml::parse(file_get_contents(__DIR__ . '/../../data/regexes.yaml'));
        }

        if (!isset($regexes['regexes']) || !is_array($regexes['regexes'])) {
            throw new NotFoundException('no regexes are defined in the regexes.yaml file');
        }

        $cacheItem = $this->cache->getItem(hash('sha512', 'regex-cache'));
        $cacheItem->set($regexes['regexes']);

        $this->cache->save($cacheItem);

        $cacheInitialized->set(true);
        $this->cache->save($cacheInitialized);
    }
}
