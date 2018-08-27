<?php
/**
 * This file is part of the browscap-helper package.
 *
 * Copyright (c) 2015-2018, Thomas Mueller <mimmi20@live.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);
namespace BrowscapHelper\Command\Helper;

use BrowscapHelper\Source\SourceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Helper\Helper;

/**
 * reading existing tests
 *
 * @category  BrowserDetector
 */
class ExistingTestsLoader extends Helper
{
    /**
     * an logger instance
     *
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getName()
    {
        return 'existing-tests-reader';
    }

    /**
     * @param SourceInterface[] $sources
     *
     * @return iterable|string[]
     */
    public function getHeaders(array $sources): iterable
    {
        foreach ($sources as $source) {
            $this->logger->info(sprintf('    reading from source %s ...', $source->getName()));

            yield from $source->getHeaders();
        }
    }
}