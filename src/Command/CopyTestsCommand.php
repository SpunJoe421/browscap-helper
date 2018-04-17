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

use BrowscapHelper\Source\BrowscapSource;
use BrowscapHelper\Source\CollectionSource;
use BrowscapHelper\Source\CrawlerDetectSource;
use BrowscapHelper\Source\MobileDetectSource;
use BrowscapHelper\Source\PiwikSource;
use BrowscapHelper\Source\TxtFileSource;
use BrowscapHelper\Source\UapCoreSource;
use BrowscapHelper\Source\WhichBrowserSource;
use BrowscapHelper\Source\WootheeSource;
use BrowscapHelper\Source\YzalisSource;
use Monolog\Handler\PsrHandler;
use Monolog\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

/**
 * Class CopyTestsCommand
 *
 * @category   Browscap Helper
 */
class CopyTestsCommand extends Command
{
    /**
     * @var \Monolog\Logger
     */
    private $logger;

    /**
     * @var string
     */
    private $targetDirectory = '';

    /**
     * @var string
     */
    private $sourcesDirectory = '';

    /**
     * @param \Monolog\Logger $logger
     * @param string          $sourcesDirectory
     * @param string          $targetDirectory
     */
    public function __construct(Logger $logger, string $sourcesDirectory, string $targetDirectory)
    {
        $this->logger           = $logger;
        $this->targetDirectory  = $targetDirectory;
        $this->sourcesDirectory = $sourcesDirectory;

        parent::__construct();
    }

    /**
     * Configures the current command.
     */
    protected function configure(): void
    {
        $this
            ->setName('copy-tests')
            ->setDescription('Copies tests from browscap and other libraries')
            ->addOption(
                'resources',
                null,
                InputOption::VALUE_REQUIRED,
                'Where the resource files are located',
                $this->sourcesDirectory
            );
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
     * @throws \LogicException When this abstract method is not implemented
     *
     * @return int|null null or 0 if everything went fine, or an error code
     *
     * @see    setCode()
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $consoleLogger = new ConsoleLogger($output);
        $this->logger->pushHandler(new PsrHandler($consoleLogger));

        $output->writeln('reading already existing tests ...');
        $txtChecks  = [];
        $testSource = 'tests';

        foreach ($this->getHelper('useragent')->getUserAgents(new TxtFileSource($this->logger, $testSource), false) as $useragent) {
            if (array_key_exists($useragent, $txtChecks)) {
                $this->logger->alert('    UA "' . $useragent . '" added more than once --> skipped');

                continue;
            }

            $txtChecks[$useragent] = 1;
        }

        $output->writeln('remove tests ...');

        $finder   = new Finder();
        $finder->files();
        $finder->name('*.txt');
        $finder->ignoreDotFiles(true);
        $finder->ignoreVCS(true);
        $finder->sortByName();
        $finder->ignoreUnreadableDirs();
        $finder->in($testSource);

        foreach ($finder as $file) {
            unlink($file->getPathname());
        }

        $output->writeln('init sources ...');

        $sourcesDirectory = $input->getOption('resources');

        $source = new CollectionSource(
            [
                new BrowscapSource($this->logger),
                new PiwikSource($this->logger),
                new UapCoreSource($this->logger),
                new WhichBrowserSource($this->logger),
                new WootheeSource($this->logger),
                new MobileDetectSource($this->logger),
                new YzalisSource($this->logger),
                new CrawlerDetectSource($this->logger),
                new TxtFileSource($this->logger, $sourcesDirectory),
            ]
        );

        $output->writeln('copy tests from sources ...');

        $newTestsCounter = 0;

        foreach ($this->getHelper('useragent')->getUserAgents($source) as $useragent) {
            if (array_key_exists($useragent, $txtChecks)) {
                continue;
            }

            $txtChecks[$useragent] = 1;
            ++$newTestsCounter;
        }

        $output->writeln('rewrite tests ...');

        $folderChunks = array_chunk(array_unique(array_keys($txtChecks)), 1000);

        foreach ($folderChunks as $folderId => $folderChunk) {
            $this->getHelper('txt-test-writer')->write(
                $folderChunk,
                $testSource,
                $folderId
            );
        }

        $output->writeln('');
        $output->writeln('tests copied for Browscap helper:    ' . $newTestsCounter);
        $output->writeln('tests available for Browscap helper: ' . count($txtChecks));

        return 0;
    }
}
