<?php

declare(strict_types=1);

namespace Breadlesscode\Backups\Service;

use Breadlesscode\Backups\Compressor\BackupCompressorInterface;
use Breadlesscode\Backups\Factory\FilesystemFactory;
use Breadlesscode\Backups\Generators\BackupNameGeneratorInterface;
use Breadlesscode\Backups\Step\StepInterface;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Exception;
use Neos\Flow\Log\PsrLoggerFactoryInterface;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Utility\Files;
use Psr\Log\LoggerInterface;

/**
 * @Flow\Scope(value="singleton")
 */
class BackupService
{
    /**
     * @Flow\InjectConfiguration()
     * @var array
     */
    protected $config = [];

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var BackupIndexService
     */
    protected $indexService;

    /**
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    public function initializeObject()
    {
        $this->indexService = $this->objectManager->get(BackupIndexService::class);
        $this->persistenceManager = $this->objectManager->get(PersistenceManager::class);

        $filesystemFactory = $this->objectManager->get(FilesystemFactory::class);
        $this->filesystem = $filesystemFactory->get($this->config['filesystem']['type']);

        $loggerFactory = $this->objectManager->get(PsrLoggerFactoryInterface::class);
        $this->logger = $loggerFactory->get('backupLogger');
    }

    public function getBackups(int $start = 0, int $limit = 0): array
    {
        return $this->indexService->getBackups($start, $limit);
    }

    /**
     * @throws FileNotFoundException
     * @throws \League\Csv\CannotInsertRecord
     * @throws \League\Csv\Exception
     */
    public function deleteBackup(string $name): bool
    {
        $this->indexService->deleteBackup($name);
        $backupFilename = $this->getCompressor()->generateFilename($name);

        if ($this->filesystem->has($backupFilename)) {
            $this->filesystem->delete($backupFilename);
        }

        $this->logger->info('deleted backup '.$name);

        return true;
    }

    public function restoreBackup(string $name)
    {
        $backup = $this->indexService->getBackup($name);
        $backupPath = $this->getTemporaryBackupPath($backup['name']);
        // extract archive
        $compressor = $this->getCompressor($backup['meta']['compressor']);
        $backupFilename = $compressor->generateFilename($backup['name']);
        $archivePath = Files::concatenatePaths([$this->config['tempPath'], $backupFilename]);
        // download archive form filesystem to temp folder
        file_put_contents(
            $this->getTemporaryBackupPath($backupFilename, false),
            $this->filesystem->readStream($backupFilename)
        );
        // decompress backup archive
        $compressor->decompress($archivePath, $backupPath);
        $steps = $this->getStepsInstances($backupPath);

        foreach ($steps as $step) {
            /** @var StepInterface $step  */
            $step->restore();
        }
        // persist all changes from the steps
        $this->persistenceManager->persistAll();
        $this->logger->info('restored backup '.$name);
        // delete temp stuff
        Files::removeDirectoryRecursively($backupPath);
        Files::unlink($archivePath);
    }


    /**
     * @throws Exception
     * @throws \League\Flysystem\FileExistsException
     * @throws \Neos\Utility\Exception\FilesException
     */
    public function createBackup(): bool
    {
        $backupName = $this->generateBackupName();
        $backupPath = $this->getTemporaryBackupPath($backupName);
        $steps = $this->getStepsInstances($backupPath);
        $meta = [
            'steps' => $this->config['steps'],
            'compressor' => $this->config['compressor']
        ];

        foreach ($steps as $step) {
            /** @var StepInterface $step  */
            $step->backup();
        }

        // create archive
        $compressor = $this->getCompressor();
        $archivePath = $compressor->compress($backupPath, $this->config['tempPath']);

        if (!is_file($archivePath)) {
            $this->logger->error('couldn\`t create backup archive file: ' . $archivePath);

            return false;
        }

        // upload to file system and delete local one
        $this->filesystem->writeStream(basename($archivePath), fopen($archivePath, 'r'));
        unlink($archivePath);
        Files::removeDirectoryRecursively($backupPath);

        // update index
        $this->indexService->addBackup($backupName, new \DateTime(), $meta);
        $this->logger->info('added backup '.$backupName);

        return true;
    }

    /**
     * generates a name for a new backup
     */
    public function generateBackupName(): string
    {
        $backupNameGenerator = $this->objectManager->get(BackupNameGeneratorInterface::class);

        return $backupNameGenerator->generate();
    }

    public function getTemporaryBackupPath(string $name = '', bool $create = true): string
    {
        $path = Files::concatenatePaths([$this->config['tempPath'], $name]);

        if ($create) {
            Files::createDirectoryRecursively($path);
        }

        return $path;
    }

    protected function getCompressor(string $compressorClass = null): BackupCompressorInterface
    {
        $compressorClass = $compressorClass ?? $this->config['compressor'];
        $compressor = $this->objectManager->get($compressorClass);

        if(!$compressor instanceof BackupCompressorInterface) {
            throw new Exception('The configured compressor '.$this->config['compressor'].' does not implement '.BackupCompressorInterface::class.'.', 1577627851);
        }

        return $compressor;
    }

    public function getStepsInstances(string $backupPath, array $whiteList = null): array
    {
        $steps = [];

        foreach ($this->config['steps'] as $stepClass => $stepConfig) {
            if ($whiteList !== null && !array_key_exists($stepClass, $whiteList)) {
                continue;
            }

            if ($whiteList !== null && array_key_exists($stepClass, $whiteList)) {
                $steps[$stepClass] = new $stepClass($backupPath, $whiteList[$stepClass]);
                continue;
            }

            $steps[$stepClass] = new $stepClass($backupPath, $stepConfig);
        }

        return $steps;
    }

    public function getCount(): int
    {
        return $this->indexService->getCount();
    }

    public function noStepsConfigured(): bool
    {
        return count($this->config['steps']) === 0;
    }

    public function getBackup(string $name)
    {
        return $this->indexService->getBackup($name);
    }
}
