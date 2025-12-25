<?php

namespace NikunjKothiya\GoPdfConverter;

use Illuminate\Support\ServiceProvider;
use NikunjKothiya\GoPdfConverter\Services\GoPdfService;
use NikunjKothiya\GoPdfConverter\Commands\ConvertCommand;

class GoPdfServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/gopdf.php',
            'gopdf'
        );

        // Register the main service
        $this->app->singleton('gopdf.converter', function ($app) {
            return new GoPdfService(
                config('gopdf.binary_path'),
                config('gopdf.libreoffice_path'),
                config('gopdf.temp_dir'),
                config('gopdf.defaults'),
                config('gopdf.timeout')
            );
        });

        // Register alias
        $this->app->alias('gopdf.converter', GoPdfService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Auto-configure binaries on first boot
        $this->ensureBinaryIsExecutable();

        // Publish config
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/gopdf.php' => config_path('gopdf.php'),
            ], 'gopdf-config');

            // Register commands
            $this->commands([
                ConvertCommand::class,
            ]);
        }

        // Ensure temp directory exists
        $tempDir = config('gopdf.temp_dir');
        if ($tempDir && !is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }
    }

    /**
     * Ensure the binary is executable.
     * This runs automatically on package boot - no manual installation required.
     */
    protected function ensureBinaryIsExecutable(): void
    {
        $binaryPath = $this->resolveBinaryPath();
        
        if ($binaryPath && file_exists($binaryPath) && !is_executable($binaryPath)) {
            @chmod($binaryPath, 0755);
        }
    }

    /**
     * Resolve the binary path based on OS/architecture.
     */
    protected function resolveBinaryPath(): ?string
    {
        // Check configured path first
        $configuredPath = config('gopdf.binary_path');
        if ($configuredPath && file_exists($configuredPath)) {
            return $configuredPath;
        }

        // Auto-detect based on OS/arch
        $os = PHP_OS_FAMILY === 'Windows' ? 'windows' : strtolower(PHP_OS_FAMILY);
        $arch = php_uname('m');

        // Normalize architecture
        if (in_array($arch, ['x86_64', 'amd64', 'AMD64'])) {
            $arch = 'amd64';
        } elseif (in_array($arch, ['aarch64', 'arm64', 'ARM64'])) {
            $arch = 'arm64';
        }

        // Normalize OS
        if ($os === 'darwin') {
            $os = 'darwin';
        } elseif ($os === 'linux') {
            $os = 'linux';
        } elseif ($os === 'windows') {
            $os = 'windows';
        }

        // Build binary name
        $binaryName = "gopdfconv-{$os}-{$arch}";
        if ($os === 'windows') {
            $binaryName .= '.exe';
        }

        // Check in package bin directory
        $packageBinPath = __DIR__ . '/../bin/' . $binaryName;
        if (file_exists($packageBinPath)) {
            return $packageBinPath;
        }

        // Check for generic binary
        $genericPath = __DIR__ . '/../bin/gopdfconv';
        if (file_exists($genericPath)) {
            return $genericPath;
        }

        return null;
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return ['gopdf.converter', GoPdfService::class];
    }
}

