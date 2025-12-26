<?php

namespace NikunjKothiya\GoPdfConverter;

use NikunjKothiya\GoPdfConverter\Services\GoPdfService;
use NikunjKothiya\GoPdfConverter\Jobs\BatchConvertToPdfJob;
use Illuminate\Support\Facades\Storage;

/**
 * Builder for batch PDF conversion
 */
class BatchBuilder
{
    protected GoPdfService $service;
    protected array $inputPaths;
    protected ?string $outputDir = null;
    protected ?string $disk = null;
    protected array $options = [];
    protected array $tempFiles = [];

    public function __construct(GoPdfService $service, array $inputPaths)
    {
        $this->service = $service;
        $this->inputPaths = $inputPaths;
    }

    /**
     * Set the output directory
     */
    public function outputDir(string $directory): self
    {
        $this->outputDir = $directory;
        return $this;
    }

    /**
     * Set the storage disk to use for input and output paths
     */
    public function disk(string $disk): self
    {
        $this->disk = $disk;
        return $this;
    }

    /**
     * Alias for outputDir()
     */
    public function saveTo(string $directory): self
    {
        return $this->outputDir($directory);
    }

    /**
     * Set page size for all files
     */
    public function pageSize(string $size): self
    {
        $this->options['page_size'] = $size;
        return $this;
    }

    /**
     * Shorthand for A4 page size
     */
    public function a4(): self
    {
        return $this->pageSize('A4');
    }

    /**
     * Shorthand for A3 page size - good for wide tables
     */
    public function a3(): self
    {
        return $this->pageSize('A3');
    }

    /**
     * Shorthand for Tabloid page size - best for very wide tables
     */
    public function tabloid(): self
    {
        return $this->pageSize('Tabloid');
    }

    /**
     * Auto landscape mode - recommended for CSVs with many columns
     * Uses A3 landscape for optimal viewing
     */
    public function wideFormat(): self
    {
        return $this->pageSize('A3')->landscape()->fontSize(8)->margin(10);
    }

    /**
     * Set orientation for all files
     */
    public function orientation(string $orientation): self
    {
        $this->options['orientation'] = strtolower($orientation);
        return $this;
    }

    /**
     * Set landscape orientation
     */
    public function landscape(): self
    {
        return $this->orientation('landscape');
    }

    /**
     * Set portrait orientation
     */
    public function portrait(): self
    {
        return $this->orientation('portrait');
    }

    /**
     * Set page margins
     */
    public function margin(float $margin): self
    {
        $this->options['margin'] = $margin;
        return $this;
    }

    /**
     * Set font size
     */
    public function fontSize(float $size): self
    {
        $this->options['font_size'] = $size;
        return $this;
    }

    /**
     * Enable header row styling
     */
    public function withHeaders(): self
    {
        $this->options['header_row'] = true;
        return $this;
    }

    /**
     * Disable header row styling
     */
    public function withoutHeaders(): self
    {
        $this->options['header_row'] = false;
        return $this;
    }

    /**
     * Set number of parallel workers
     */
    public function workers(int $count): self
    {
        $this->options['workers'] = $count;
        return $this;
    }

    /**
     * Set conversion timeout
     */
    public function timeout(int $seconds): self
    {
        $this->options['timeout'] = $seconds;
        return $this;
    }

    /**
     * Force native Go conversion for all files (bypass LibreOffice)
     */
    public function native(bool $native = true): self
    {
        $this->options['native'] = $native;
        return $this;
    }

    /**
     * Set global header text for all files
     */
    public function headerText(string $text): self
    {
        $this->options['header_text'] = $text;
        return $this;
    }

    /**
     * Set global footer text for all files
     */
    public function footerText(string $text): self
    {
        $this->options['footer_text'] = $text;
        return $this;
    }

    /**
     * Add custom option
     */
    public function option(string $key, mixed $value): self
    {
        $this->options[$key] = $value;
        return $this;
    }

    /**
     * Add multiple options
     */
    public function options(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /**
     * Execute batch conversion synchronously
     */
    public function convert(): array
    {
        $isCloud = $this->disk && $this->isCloudDisk($this->disk);
        
        if ($isCloud) {
            return $this->convertFromCloud();
        }
        
        $inputPaths = array_map([$this, 'resolvePath'], $this->inputPaths);
        $outputDir = $this->resolvePath($this->outputDir ?? $this->defaultOutputDir());
        
        return $this->service->convertBatch($inputPaths, $outputDir, $this->options);
    }

    /**
     * Handle batch conversion for cloud storage (S3, GCS, etc.)
     * Downloads files from cloud, converts locally, uploads results back
     */
    protected function convertFromCloud(): array
    {
        try {
            // Download all input files from cloud to temp location
            $localInputPaths = [];
            foreach ($this->inputPaths as $cloudPath) {
                $localInputPaths[] = $this->downloadFromCloud($cloudPath);
            }
            
            // Create local temp output directory
            $localOutputDir = $this->getTempDir();
            
            // Perform batch conversion locally
            $result = $this->service->convertBatch($localInputPaths, $localOutputDir, $this->options);
            
            // Upload converted PDFs back to cloud
            $cloudOutputDir = $this->outputDir ?? $this->defaultOutputDir();
            
            if (isset($result['results']) && is_array($result['results'])) {
                foreach ($result['results'] as &$fileResult) {
                    if ($fileResult['success'] && isset($fileResult['job']['OutputPath'])) {
                        $localOutputPath = $fileResult['job']['OutputPath'];
                        if (file_exists($localOutputPath)) {
                            $cloudOutputPath = $cloudOutputDir . '/' . basename($localOutputPath);
                            $this->uploadToCloud($localOutputPath, $cloudOutputPath);
                            $fileResult['cloud_output_path'] = $cloudOutputPath;
                        }
                    }
                }
            }
            
            $result['storage_disk'] = $this->disk;
            $result['cloud_storage'] = true;
            $result['cloud_output_dir'] = $cloudOutputDir;
            
            return $result;
        } finally {
            // Cleanup temp files and directory
            $this->cleanupTempFiles();
        }
    }

    /**
     * Check if a disk is cloud-based (non-local)
     */
    protected function isCloudDisk(string $diskName): bool
    {
        try {
            $disk = Storage::disk($diskName);
            $adapter = $disk->getAdapter();
            
            // Check for common cloud adapter types
            $adapterClass = get_class($adapter);
            
            $cloudAdapters = [
                'League\Flysystem\AwsS3V3\AwsS3V3Adapter',
                'League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter',
                'League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter',
                'League\Flysystem\Ftp\FtpAdapter',
                'League\Flysystem\Sftp\SftpAdapter',
                'Spatie\FlysystemDropbox\DropboxAdapter',
            ];
            
            foreach ($cloudAdapters as $cloudAdapter) {
                if ($adapter instanceof $cloudAdapter || $adapterClass === $cloudAdapter) {
                    return true;
                }
            }
            
            // Also check if the disk config has a driver that's cloud-based
            $config = config("filesystems.disks.{$diskName}");
            if ($config) {
                $cloudDrivers = ['s3', 'gcs', 'azure', 'ftp', 'sftp', 'dropbox', 'rackspace'];
                if (isset($config['driver']) && in_array($config['driver'], $cloudDrivers)) {
                    return true;
                }
            }
            
            return false;
        } catch (\Exception $e) {
            // If we can't determine, assume local
            return false;
        }
    }

    /**
     * Download file from cloud storage to local temp directory
     */
    protected function downloadFromCloud(string $cloudPath): string
    {
        $disk = Storage::disk($this->disk);
        
        if (!$disk->exists($cloudPath)) {
            throw new \NikunjKothiya\GoPdfConverter\Exceptions\FileNotFoundException(
                "File not found on cloud storage: {$cloudPath}",
                $cloudPath
            );
        }
        
        $localPath = $this->getTempPath(basename($cloudPath));
        $contents = $disk->get($cloudPath);
        
        file_put_contents($localPath, $contents);
        $this->tempFiles[] = $localPath;
        
        return $localPath;
    }

    /**
     * Upload file to cloud storage
     */
    protected function uploadToCloud(string $localPath, string $cloudPath): bool
    {
        $disk = Storage::disk($this->disk);
        $contents = file_get_contents($localPath);
        
        return $disk->put($cloudPath, $contents);
    }

    /**
     * Get temp directory for cloud operations
     */
    protected function getTempDir(): string
    {
        $tempDir = config('gopdf.temp_dir', sys_get_temp_dir() . '/gopdf') . '/' . uniqid('batch_');
        
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        $this->tempFiles[] = $tempDir;
        
        return $tempDir;
    }

    /**
     * Get temp file path for cloud operations
     */
    protected function getTempPath(string $filename): string
    {
        $tempDir = config('gopdf.temp_dir', sys_get_temp_dir() . '/gopdf');
        
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        return $tempDir . '/' . uniqid('gopdf_') . '_' . $filename;
    }

    /**
     * Cleanup temporary files created during cloud conversion
     */
    protected function cleanupTempFiles(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_dir($file)) {
                // Remove directory and its contents
                $files = glob($file . '/*');
                foreach ($files as $f) {
                    @unlink($f);
                }
                @rmdir($file);
            } elseif (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    /**
     * Dispatch batch conversion to queue
     */
    public function queue(?string $connection = null, ?string $queue = null)
    {
        $isCloud = $this->disk && $this->isCloudDisk($this->disk);
        
        if ($isCloud) {
            // For cloud storage, pass disk info to job
            $inputPaths = $this->inputPaths;
            $outputDir = $this->outputDir ?? $this->defaultOutputDir();
        } else {
            $inputPaths = array_map([$this, 'resolvePath'], $this->inputPaths);
            $outputDir = $this->resolvePath($this->outputDir ?? $this->defaultOutputDir());
        }
        
        $options = $this->options;
        if ($isCloud) {
            $options['_cloud_disk'] = $this->disk;
            $options['_cloud_inputs'] = $this->inputPaths;
            $options['_cloud_output_dir'] = $this->outputDir ?? $this->defaultOutputDir();
        }
        
        $job = new BatchConvertToPdfJob(
            $inputPaths,
            $outputDir,
            $options
        );

        if ($connection) {
            $job->onConnection($connection);
        }

        if ($queue) {
            $job->onQueue($queue);
        }

        return dispatch($job);
    }

    /**
     * Execute batch conversion synchronously through queue
     */
    public function dispatchSync(): array
    {
        $outputDir = $this->outputDir ?? $this->defaultOutputDir();
        
        $job = new BatchConvertToPdfJob(
            $this->inputPaths,
            $outputDir,
            $this->options
        );

        dispatch_sync($job);

        return [
            'success' => true,
            'total_files' => count($this->inputPaths),
            'output_dir' => $outputDir,
        ];
    }

    /**
     * Get default output directory
     */
    protected function defaultOutputDir(): string
    {
        // Use the directory of the first file
        if (!empty($this->inputPaths)) {
            $info = pathinfo($this->inputPaths[0]);
            return $info['dirname'] ?? '.';
        }
        return 'storage/app/pdf-output';
    }

    /**
     * Resolve path using Laravel storage if disk is set
     */
    protected function resolvePath(string $path): string
    {
        if ($this->disk) {
            // For cloud disks, don't try to get local path
            if ($this->isCloudDisk($this->disk)) {
                return $path;
            }
            return Storage::disk($this->disk)->path($path);
        }

        // If path is absolute, return it
        if (str_starts_with($path, '/') || (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && strpos($path, ':') === 1)) {
            return $path;
        }

        // Otherwise, assume it's relative to base_path()
        return base_path($path);
    }

    /**
     * Get the input paths
     */
    public function getInputPaths(): array
    {
        return $this->inputPaths;
    }

    /**
     * Get the output directory
     */
    public function getOutputDir(): ?string
    {
        return $this->outputDir;
    }

    /**
     * Get the options
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Get the file count
     */
    public function count(): int
    {
        return count($this->inputPaths);
    }
}
