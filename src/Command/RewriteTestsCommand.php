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
namespace BrowscapHelper\Command;

use BrowscapHelper\Factory\Regex\GeneralBlackberryException;
use BrowscapHelper\Factory\Regex\GeneralDeviceException;
use BrowscapHelper\Factory\Regex\NoMatchException;
use BrowscapHelper\Source\TxtFileSource;
use BrowserDetector\Cache\Cache;
use BrowserDetector\Detector;
use BrowserDetector\Factory\NormalizerFactory;
use BrowserDetector\Helper\GenericRequestFactory;
use BrowserDetector\Loader\DeviceLoader;
use BrowserDetector\Loader\NotFoundException;
use BrowserDetector\Version\VersionInterface;
use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use Psr\SimpleCache\CacheInterface as PsrCacheInterface;
use Seld\JsonLint\JsonParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use UaResult\Device\Device;
use UaResult\Result\Result;
use UaResult\Result\ResultInterface;

/**
 * Class RewriteTestsCommand
 *
 * @category   Browscap Helper
 */
class RewriteTestsCommand extends Command
{
    /**
     * @var \Monolog\Logger
     */
    private $logger;

    /**
     * @var \Psr\SimpleCache\CacheInterface
     */
    private $cache;

    /**
     * @var \BrowserDetector\Detector
     */
    private $detector;

    /**
     * @var \Seld\JsonLint\JsonParser
     */
    private $jsonParser;

    /**
     * @var array
     */
    private $tests = [];

    /**
     * @param \Monolog\Logger                 $logger
     * @param \Psr\SimpleCache\CacheInterface $cache
     * @param \BrowserDetector\Detector       $detector
     */
    public function __construct(Logger $logger, PsrCacheInterface $cache, Detector $detector)
    {
        $this->logger   = $logger;
        $this->cache    = $cache;
        $this->detector = $detector;

        $this->jsonParser = new JsonParser();

        parent::__construct();
    }

    /**
     * Configures the current command.
     */
    protected function configure(): void
    {
        $this
            ->setName('rewrite-tests')
            ->setDescription('Rewrites existing tests');
    }

    /**
     * Executes the current command.
     *
     * This method is not abstract because you can use this class
     * as a concrete class. In this case, instead of defining the
     * execute() method, you set the code to execute by passing
     * a Closure to the setCode() method.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     *
     * @throws \FileLoader\Exception
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *
     * @return int|null null or 0 if everything went fine, or an error code
     *
     * @see    setCode()
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $consoleLogger = new ConsoleLogger($output);
        $this->logger->pushHandler(new PsrHandler($consoleLogger));

        $basePath                = 'vendor/mimmi20/browser-detector-tests/';
        $detectorTargetDirectory = $basePath . 'tests/issues/';
        $testSource              = 'tests/';

        $output->writeln('remove old test files ...');

        $finder = new Finder();
        $finder->files();
        $finder->ignoreDotFiles(true);
        $finder->ignoreVCS(true);
        $finder->ignoreUnreadableDirs();
        $finder->in($detectorTargetDirectory);

        foreach ($finder as $file) {
            unlink($file->getPathname());
        }

        $finder = new Finder();
        $finder->files();
        $finder->ignoreDotFiles(true);
        $finder->ignoreVCS(true);
        $finder->ignoreUnreadableDirs();
        $finder->in($basePath . 'tests/UserAgentsTest/');

        foreach ($finder as $file) {
            unlink($file->getPathname());
        }

        $output->writeln('rewrite tests and circleci ...');
        $testResults = [];

        foreach ($this->getHelper('useragent')->getUserAgents(new TxtFileSource($this->logger, $testSource)) as $useragent) {
            $useragent = trim($useragent);
            $result    = $this->handleTest($useragent);

            if (null === $result) {
                $this->logger->info('UA "' . $useragent . '" was skipped because a similar UA was already added');

                continue;
            }

            $testResults[] = $useragent;
        }

        $folderChunks    = array_chunk($testResults, 1000);
        $circleFile      = $basePath . '.circleci/config.yml';
        $circleciContent = '';

        foreach ($folderChunks as $folderId => $folderChunk) {
            $targetDirectory = $detectorTargetDirectory . sprintf('%1$07d', $folderId) . '/';
            $fileChunks      = array_chunk($folderChunk, 100);

            foreach ($fileChunks as $fileId => $fileChunk) {
                foreach ($fileChunk as $useragent) {
                    $result = $this->handleTest($useragent);

                    if (null === $result) {
                        $this->logger->error('UA "' . $useragent . '" was skipped because a similar UA was already added');

                        continue;
                    }

                    $this->getHelper('detector-test-writer')->write($result, $targetDirectory, $folderId);
                }
            }

            $count = count($folderChunk);
            $group = sprintf('%1$07d', $folderId);

            $tests = str_pad((string) $count, 4, ' ', STR_PAD_LEFT) . ' test' . (1 !== $count ? 's' : '');

            $testContent = [
                '        \'tests/issues/' . $group . '/\',',
            ];

            $testFile = $basePath . 'tests/UserAgentsTest/T' . $group . 'Test.php';
            file_put_contents(
                $testFile,
                str_replace(
                    ['//### tests ###', '### group ###', '### count ###'],
                    [implode(PHP_EOL, $testContent), $group, $count],
                    file_get_contents('templates/test.php.txt')
                )
            );

            $columns = 111 + 2 * mb_strlen((string) $count);

            $circleciContent .= PHP_EOL;
            $circleciContent .= '    #' . $tests;
            $circleciContent .= PHP_EOL;
            $circleciContent .= '    #  - run: php -n -d memory_limit=768M vendor/bin/phpunit --printer \'ScriptFUSION\PHPUnitImmediateExceptionPrinter\ImmediateExceptionPrinter\' --colors --no-coverage --group ' . $group . ' -- ' . $tests;
            $circleciContent .= PHP_EOL;
            $circleciContent .= '      - run: php -n -d memory_limit=768M vendor/bin/phpunit --colors --no-coverage --columns ' . $columns . '  tests/UserAgentsTest/T' . $group . 'Test.php -- ' . $tests;
            $circleciContent .= PHP_EOL;
        }

        $output->writeln('writing ' . $circleFile . ' ...');
        file_put_contents(
            $circleFile,
            str_replace('### tests ###', $circleciContent, file_get_contents('templates/config.yml.txt'))
        );

        $output->writeln('done');

        return 0;
    }

    /**
     * @param string $useragent
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *
     * @return \UaResult\Result\ResultInterface|null
     */
    private function handleTest(string $useragent): ?ResultInterface
    {
        $this->logger->info('        detect for new result');

        $newResult = $this->detector->parseString($useragent);

        if (!$newResult->getDevice()->getType()->isMobile()
            && !$newResult->getDevice()->getType()->isTablet()
            && !$newResult->getDevice()->getType()->isTv()
        ) {
            $keys = [
                (string) $newResult->getBrowser()->getName(),
                $newResult->getBrowser()->getVersion()->getVersion(VersionInterface::IGNORE_MICRO),
                (string) $newResult->getEngine()->getName(),
                $newResult->getEngine()->getVersion()->getVersion(VersionInterface::IGNORE_MICRO),
                (string) $newResult->getOs()->getName(),
                $newResult->getOs()->getVersion()->getVersion(VersionInterface::IGNORE_MICRO),
                (string) $newResult->getDevice()->getDeviceName(),
                (string) $newResult->getDevice()->getMarketingName(),
                (string) $newResult->getDevice()->getManufacturer()->getName(),
            ];

            $key = implode('-', $keys);

            if (array_key_exists($key, $this->tests)) {
                return null;
            }

            $this->tests[$key] = 1;
        } elseif (($newResult->getDevice()->getType()->isMobile() || $newResult->getDevice()->getType()->isTablet())
            && false === mb_strpos((string) $newResult->getBrowser()->getName(), 'general')
            && !in_array($newResult->getBrowser()->getName(), [null, 'unknown'])
            && false === mb_strpos((string) $newResult->getDevice()->getDeviceName(), 'general')
            && !in_array($newResult->getDevice()->getDeviceName(), [null, 'unknown'])
        ) {
            $keys = [
                (string) $newResult->getBrowser()->getName(),
                $newResult->getBrowser()->getVersion()->getVersion(VersionInterface::IGNORE_MICRO),
                (string) $newResult->getEngine()->getName(),
                $newResult->getEngine()->getVersion()->getVersion(VersionInterface::IGNORE_MICRO),
                (string) $newResult->getOs()->getName(),
                $newResult->getOs()->getVersion()->getVersion(VersionInterface::IGNORE_MICRO),
                (string) $newResult->getDevice()->getDeviceName(),
                (string) $newResult->getDevice()->getMarketingName(),
                (string) $newResult->getDevice()->getManufacturer()->getName(),
            ];

            $key = implode('-', $keys);

            if (array_key_exists($key, $this->tests)) {
                return null;
            }

            $this->tests[$key] = 1;
        }

        $this->logger->info('        rewriting');

        // rewrite browsers

        $this->logger->info('        rewriting browser');

        /** @var \UaResult\Browser\BrowserInterface $browser */
        $browser = clone $newResult->getBrowser();

        // rewrite platforms

        $this->logger->info('        rewriting platform');

        $platform = clone $newResult->getOs();

        // @var $platform \UaResult\Os\OsInterface|null

        $this->logger->info('        rewriting device');

        $normalizedUa = (new NormalizerFactory())->build()->normalize($useragent);

        // rewrite devices

        /** @var \UaResult\Device\DeviceInterface $device */
        $device   = clone $newResult->getDevice();
        $replaced = false;

        if (in_array($device->getDeviceName(), [null, 'unknown'])) {
            $device   = new Device(null, null);
            $replaced = true;
        }

        if (!$replaced
            && $device->getType()->isMobile()
            && !in_array($device->getDeviceName(), ['general Apple Device'])
            && false !== mb_stripos($device->getDeviceName(), 'general')
        ) {
            try {
                $regexFactory = $this->getHelper('regex-factory');
                $regexFactory->detect($normalizedUa);
                [$device] = $regexFactory->getDevice();
                $replaced = false;

                if (null === $device || in_array($device->getDeviceName(), [null, 'unknown'])) {
                    $device   = new Device(null, null);
                    $replaced = true;
                }

                if (!$replaced
                    && !in_array($device->getDeviceName(), ['general Desktop', 'general Apple Device', 'general Philips TV'])
                    && false !== mb_stripos($device->getDeviceName(), 'general')
                ) {
                    $device = new Device('not found via regexes', null);
                }
            } catch (\InvalidArgumentException $e) {
                $this->logger->error($e);

                $device = new Device(null, null);
            } catch (NotFoundException $e) {
                $this->logger->debug($e);

                $device = new Device(null, null);
            } catch (GeneralBlackberryException $e) {
                $deviceLoader = DeviceLoader::getInstance(new Cache($this->cache), $this->logger);

                try {
                    [$device] = $deviceLoader->load('general blackberry device', $normalizedUa);
                } catch (\Exception $e) {
                    $this->logger->crit($e);

                    $device = new Device(null, null);
                }
            } catch (GeneralDeviceException $e) {
                $deviceLoader = DeviceLoader::getInstance(new Cache($this->cache), $this->logger);

                try {
                    [$device] = $deviceLoader->load('general mobile device', $normalizedUa);
                } catch (\Exception $e) {
                    $this->logger->crit($e);

                    $device = new Device(null, null);
                }
            } catch (NoMatchException $e) {
                $this->logger->debug($e);

                $device = new Device(null, null);
            } catch (\Exception $e) {
                $this->logger->error($e);

                $device = new Device(null, null);
            }
        }

        // rewrite engines

        $this->logger->info('        rewriting engine');

        /** @var \UaResult\Engine\EngineInterface $engine */
        $engine = clone $newResult->getEngine();

        $this->logger->info('        generating result');

        $request = (new GenericRequestFactory())->createRequestFromString($useragent);

        return new Result($request->getHeaders(), $device, $platform, $browser, $engine);
    }
}
