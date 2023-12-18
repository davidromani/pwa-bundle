<?php

declare(strict_types=1);

namespace SpomkyLabs\PwaBundle\Command;

use JsonException;
use RuntimeException;
use SpomkyLabs\PwaBundle\ImageProcessor\ImageProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Mime\MimeTypes;
use function count;
use function is_int;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

#[AsCommand(name: 'pwa:build', description: 'Generate the Progressive Web App Manifest',)]
final class GenerateManifestCommand extends Command
{
    private readonly MimeTypes $mime;

    public function __construct(
        private readonly null|ImageProcessor $imageProcessor,
        #[Autowire('%spomky_labs_pwa.config%')]
        private readonly array $config,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $rootDir,
        private readonly Filesystem $filesystem,
    ) {
        $this->mime = MimeTypes::getDefault();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('url_prefix', 'u', InputOption::VALUE_OPTIONAL, 'Public URL prefix', '');
        $this->addOption(
            'public_folder',
            'p',
            InputOption::VALUE_OPTIONAL,
            'Public folder',
            $this->rootDir . '/public'
        );
        $this->addOption('asset_folder', 'a', InputOption::VALUE_OPTIONAL, 'Asset folder', '/pwa');
        $this->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output file', 'pwa.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('PWA Manifest Generator');
        $manifest = $this->config;
        $manifest = array_filter($manifest, static fn ($value) => ($value !== null && $value !== []));

        $publicUrl = $input->getOption('url_prefix');
        $publicFolder = Path::canonicalize($input->getOption('public_folder'));
        $assetFolder = '/' . trim((string) $input->getOption('asset_folder'), '/');
        $outputFile = '/' . trim((string) $input->getOption('output'), '/');

        if (! $this->filesystem->exists($publicFolder)) {
            $this->filesystem->mkdir($publicFolder);
        }

        $manifest = $this->processIcons($io, $manifest, $publicUrl, $publicFolder, $assetFolder);
        if ($manifest === self::FAILURE) {
            return self::FAILURE;
        }
        $manifest = $this->processScreenshots($io, $manifest, $publicUrl, $publicFolder, $assetFolder);
        if ($manifest === self::FAILURE) {
            return self::FAILURE;
        }
        $manifest = $this->processShortcutIcons($io, $manifest, $publicUrl, $publicFolder, $assetFolder);
        if ($manifest === self::FAILURE) {
            return self::FAILURE;
        }

        try {
            file_put_contents(
                sprintf('%s%s', $publicFolder, $outputFile),
                json_encode(
                    $manifest,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                )
            );
        } catch (JsonException $exception) {
            $io->error(sprintf('Unable to generate the manifest file: %s', $exception->getMessage()));
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string|null> $components
     * @return array{src: string, type: string}
     */
    private function storeFile(
        string $data,
        string $publicUrl,
        string $publicFolder,
        string $assetFolder,
        string $type,
        array  $components
    ): array {
        $tempFilename = $this->filesystem->tempnam($publicFolder, $type . '-');
        $hash = mb_substr(hash('sha256', $data), 0, 8);
        file_put_contents($tempFilename, $data);
        $mime = $this->mime->guessMimeType($tempFilename);
        $extension = $this->mime->getExtensions($mime);

        if (empty($extension)) {
            throw new RuntimeException(sprintf('Unable to guess the extension for the mime type "%s"', $mime));
        }

        $components[] = $hash;
        $filename = sprintf('%s/%s.%s', $assetFolder, implode('-', $components), $extension[0]);
        $localFilename = sprintf('%s%s', $publicFolder, $filename);

        file_put_contents($localFilename, $data);
        $this->filesystem->remove($tempFilename);

        return [
            'src' => sprintf('%s%s', $publicUrl, $filename),
            'type' => $mime,
        ];
    }

    /**
     * @return array{src: string, type: string, sizes: string, form_factor: ?string}
     */
    private function storeScreenshot(
        string  $data,
        string  $publicUrl,
        string  $publicFolder,
        string  $assetFolder,
        ?string $format,
        ?string $formFactor
    ): array {
        if ($format !== null) {
            $data = $this->imageProcessor->process($data, null, null, $format);
        }

        ['width' => $width, 'height' => $height] = $this->imageProcessor->getSizes($data);
        $size = sprintf('%sx%s', $width, $height);
        $formFactor ??= $width > $height ? 'wide' : 'narrow';

        $fileData = $this->storeFile(
            $data,
            $publicUrl,
            $publicFolder,
            $assetFolder,
            'screenshot',
            ['screenshot', $formFactor, $size]
        );

        return $fileData + [
            'sizes' => $size,
            'form_factor' => $formFactor,
        ];
    }

    private function handleSizeAndPurpose(?string $purpose, int $size, array $fileData): array
    {
        $sizes = $size === 0 ? 'any' : $size . 'x' . $size;
        $fileData += [
            'sizes' => $sizes,
        ];

        if ($purpose !== null) {
            $fileData += [
                'purpose' => $purpose,
            ];
        }

        return $fileData;
    }

    /**
     * @return array{src: string, sizes: string, type: string, purpose: ?string}
     */
    private function storeShortcutIcon(
        string  $data,
        string  $publicUrl,
        string  $publicFolder,
        string  $assetFolder,
        int  $size,
        ?string $purpose
    ): array {
        $fileData = $this->storeFile(
            $data,
            $publicUrl,
            $publicFolder,
            $assetFolder,
            'shortcut-icon',
            ['shortcut-icon', $purpose, $size === 0 ? 'any' : $size . 'x' . $size]
        );

        return $this->handleSizeAndPurpose($purpose, $size, $fileData);
    }

    /**
     * @return array{src: string, sizes: string, type: string, purpose: ?string}
     */
    private function storeIcon(
        string  $data,
        string  $publicUrl,
        string  $publicFolder,
        string  $assetFolder,
        int  $size,
        ?string $purpose
    ): array {
        $fileData = $this->storeFile(
            $data,
            $publicUrl,
            $publicFolder,
            $assetFolder,
            'icon',
            ['icon', $purpose, $size === 0 ? 'any' : $size . 'x' . $size]
        );

        return $this->handleSizeAndPurpose($purpose, $size, $fileData);
    }

    private function processIcons(
        SymfonyStyle $io,
        array $manifest,
        mixed $publicUrl,
        string $publicFolder,
        string $assetFolder
    ): array|int {
        if ($this->config['icons'] === []) {
            return $manifest;
        }
        if (! $this->createDirectoryIfNotExists($publicFolder, $assetFolder) || ! $this->checkImageProcessor($io)) {
            return self::FAILURE;
        }
        $manifest['icons'] = [];
        $progressBar = $io->createProgressBar(count($this->config['icons']));
        $progressBar->start();
        $io->info('Processing icons');
        $progressBar->start();
        foreach ($this->config['icons'] as $icon) {
            $this->processProgressBar($progressBar, 'icon', $icon['src']);
            foreach ($icon['sizes'] as $size) {
                if (! is_int($size) || $size < 0) {
                    $io->error('The icon size must be a positive integer');
                    return self::FAILURE;
                }
                $data = $this->loadFile($icon['src'], $size, $icon['format'] ?? null);
                if ($data === null) {
                    $io->error(sprintf('Unable to read the icon "%s"', $icon['src']));
                    return self::FAILURE;
                }

                $iconManifest = $this->storeIcon(
                    $data,
                    $publicUrl,
                    $publicFolder,
                    $assetFolder,
                    $size,
                    $icon['purpose'] ?? null
                );
                $manifest['icons'][] = $iconManifest;
            }
        }
        $progressBar->finish();
        $io->info('Icons are built');

        return $manifest;
    }

    private function processScreenshots(
        SymfonyStyle $io,
        array $manifest,
        mixed $publicUrl,
        string $publicFolder,
        string $assetFolder
    ): array|int {
        if ($this->config['screenshots'] === []) {
            return $manifest;
        }
        if (! $this->createDirectoryIfNotExists($publicFolder, $assetFolder) || ! $this->checkImageProcessor($io)) {
            return self::FAILURE;
        }
        $manifest['screenshots'] = [];
        $progressBar = $io->createProgressBar(count($this->config['screenshots']));
        $progressBar->start();
        $io->info('Processing screenshots');
        foreach ($this->config['screenshots'] as $screenshot) {
            $this->processProgressBar($progressBar, 'screenshot', $screenshot['src']);
            $data = $this->loadFile($screenshot['src'], null, $screenshot['format'] ?? null);
            if ($data === null) {
                $io->error(sprintf('Unable to read the icon "%s"', $screenshot['src']));
                return self::FAILURE;
            }
            $screenshotManifest = $this->storeScreenshot(
                $data,
                $publicUrl,
                $publicFolder,
                $assetFolder,
                $screenshot['format'] ?? null,
                $screenshot['form_factor'] ?? null
            );
            if (isset($screenshot['label'])) {
                $screenshotManifest['label'] = $screenshot['label'];
            }
            if (isset($screenshot['platform'])) {
                $screenshotManifest['platform'] = $screenshot['platform'];
            }
            $manifest['screenshots'][] = $screenshotManifest;
        }
        $progressBar->finish();

        return $manifest;
    }

    private function processShortcutIcons(
        SymfonyStyle $io,
        array|int $manifest,
        mixed $publicUrl,
        string $publicFolder,
        string $assetFolder
    ): array|int {
        if ($this->config['shortcuts'] === []) {
            return $manifest;
        }
        if (! $this->createDirectoryIfNotExists($publicFolder, $assetFolder) || ! $this->checkImageProcessor($io)) {
            return self::FAILURE;
        }
        $manifest['shortcuts'] = [];
        $progressBar = $io->createProgressBar(count($this->config['shortcuts']));
        $io->info('Processing shortcuts');
        $progressBar->start();
        foreach ($this->config['shortcuts'] as $shortcutConfig) {
            $this->processProgressBar($progressBar, 'shortcuts', $shortcutConfig['name']);
            $shortcut = $shortcutConfig;
            if (isset($shortcut['icons'])) {
                unset($shortcut['icons']);
            }
            if (isset($shortcutConfig['icons'])) {
                if (! $this->checkImageProcessor($io)) {
                    return self::FAILURE;
                }
                foreach ($shortcutConfig['icons'] as $icon) {
                    foreach ($icon['sizes'] as $size) {
                        if (! is_int($size) || $size < 0) {
                            $io->error('The icon size must be a positive integer');
                            return self::FAILURE;
                        }

                        $data = $this->loadFile($icon['src'], $size, $icon['format'] ?? null);
                        if ($data === null) {
                            $io->error(sprintf('Unable to read the icon "%s"', $icon['src']));
                            return self::FAILURE;
                        }

                        $iconManifest = $this->storeShortcutIcon(
                            $data,
                            $publicUrl,
                            $publicFolder,
                            $assetFolder,
                            $size,
                            $icon['purpose'] ?? null
                        );
                        $shortcut['icons'][] = $iconManifest;
                    }
                }
            }
            $manifest['shortcuts'][] = $shortcut;
        }
        $progressBar->finish();
        $manifest['shortcuts'] = array_values($manifest['shortcuts']);

        return $manifest;
    }

    private function loadFile(string $src, ?int $size, ?string $format): ?string
    {
        $data = file_get_contents($src);
        if ($data === false) {
            return null;
        }
        if ($size !== 0 && $size !== null) {
            $data = $this->imageProcessor->process($data, $size, $size, $format);
        }

        return $data;
    }

    private function checkImageProcessor(SymfonyStyle $io): bool
    {
        if ($this->imageProcessor === null) {
            $io->error('Image processor not found');
            return false;
        }

        return true;
    }

    private function createDirectoryIfNotExists(string $publicFolder, string $assetFolder): bool
    {
        try {
            $this->filesystem->mkdir(sprintf('%s%s', $publicFolder, $assetFolder));
        } catch (IOExceptionInterface) {
            return false;
        }

        return true;
    }

    private function processProgressBar(ProgressBar $progressBar, string $type, string $src): void
    {
        $progressBar->advance();
        $progressBar->setMessage(sprintf('Processing %s %s', $type, $src));
    }
}
