<?php declare(strict_types=1);

namespace Xtro123\Wpi\Console;

use Illuminate\Console\Command;
use EvolutionCMS\Models\SiteContent;
use EvolutionCMS\Models\SiteTmplvar;
use EvolutionCMS\Models\SiteTmplvarContentvalue;
use EvolutionCMS\Models\SiteTemplate;
use Illuminate\Support\Facades\DB;
use Xtro123\Wpi\Services\CategoryService;
use Xtro123\Wpi\Services\UserService;
use Xtro123\Wpi\Services\PostService;
use Xtro123\Wpi\Services\TvService;
use Xtro123\Wpi\Services\ImageService;

class ImportCommand extends Command
{
    // Keeping file optional in signature to allow interactive prompt if missing
    protected $signature = 'wpi:import {file? : Path to the XML file} {--rollback : Delete all imported content} {--no-pdf : Disable downloading PDF files}';
    protected $description = 'Import WordPress XML export file';

    protected string $basePath;

    protected CategoryService $categoryService;
    protected UserService $userService;
    protected PostService $postService;
    protected TvService $tvService;
    protected ImageService $imageService;

    public function __construct()
    {
        parent::__construct();

        $this->basePath = dirname(__DIR__, 6);

        // Manual DI
        $this->tvService = new TvService();
        $this->categoryService = new CategoryService($this->tvService);
        $this->userService = new UserService();

        $this->imageService = new ImageService();
        $this->postService = new PostService($this->tvService, $this->imageService);
    }

    public function handle()
    {
        set_time_limit(0);

        // Determine file path logic FIRST
        $inputPath = $this->argument('file');

        // If meant for rollback, we still might need file for parsing nodes... 
        // But usually rollback just cleans up. However, existing logic uses file to find IDs.
        // So we need file for rollback too.

        if (!$inputPath) {
            $inputPath = $this->ask("Please enter the name of the XML file (e.g. export.xml)");
        }

        if (empty($inputPath)) {
            $this->error("No file provided. Aborting.");
            return;
        }

        // Logic to resolve relative path
        if (file_exists($inputPath)) {
            $file = $inputPath;
        } else {
            $testPath = $this->basePath . '/' . $inputPath;
            if (file_exists($testPath)) {
                $file = $testPath;
            } else {
                $this->error("File not found: $inputPath (checked relative to project root too)");
                return;
            }
        }

        if ($this->option('rollback')) {
            if (!$this->confirm('WARNING: This will PERMANENTLY DELETE all imported data! Are you sure you want to proceed?')) {
                $this->info('Rollback aborted.');
                return;
            }
            $this->rollback($file);
            return;
        }

        $this->info('Starting Import from: ' . $file);

        // Configure Image Service Flags (PDF)
        if ($this->option('no-pdf')) {
            $this->imageService->setAllowPdf(false);
            $this->info("PDF downloads disabled.");
        }

        $this->info("Reading file...");

        $xml = simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($xml === false) {
            $this->error("Invalid XML file format.");
            return;
        }

        $namespaces = $xml->getNamespaces(true);

        // Validation: Check Generator and Version
        $generator = (string) $xml->channel->generator;
        if (empty($generator)) {
            $this->error("Invalid WXR file: <generator> tag missing.");
            return;
        }

        // Try to parse version. Typical formats: "https://wordpress.org/?v=6.0" or "WordPress/6.0"
        $versionInfo = "0";
        if (preg_match('/v=([0-9]+\.?[0-9]*)/', $generator, $matches)) {
            $versionInfo = $matches[1];
        } elseif (preg_match('/WordPress\/?\s*([0-9]+\.?[0-9]*)/i', $generator, $matches)) {
            $versionInfo = $matches[1];
        }

        $majorVersion = (int) $versionInfo;

        if ($majorVersion < 6) {
            $this->error("Unsupported WordPress version ($versionInfo). This tool requires WordPress 6.0 or higher.");
            return;
        }

        $this->info("Verified WordPress version: $versionInfo");

        // Step 1: Categories
        $categoryMap = $this->categoryService->import($xml, $namespaces, $this->output);

        // Step 2: Users
        $userMap = $this->userService->import($xml, $namespaces, $this->output);

        // Step 3: Posts Setup (Count Pre-check)
        $this->output->newLine();
        $this->info("Analyzing Media Files...");

        // Count attachments
        $attachmentNodes = $xml->channel->xpath("item/wp:post_type[text()='attachment']");
        $count = count($attachmentNodes);

        $this->info("Found $count potential media files to download.");

        if ($count > 0) {
            if (!$this->confirm("Do you want to download these $count files? (Existing files will be skipped)", true)) {
                $this->imageService->setDownloadEnabled(false);
                $this->info("Media downloads disabled.");
            } else {
                // Skip existing by default for speed
                $this->imageService->setOverwriteExisting(false);
                $this->info("Downloads enabled. Existing files will be skipped.");
            }
        }

        // Step 3: Posts
        $this->postService->import($xml, $namespaces, $categoryMap, $userMap, $this->output);

        $this->info('Import Completed Successfully!');

        // Show Image Warnings
        $errors = $this->postService->getErrors();
        if (!empty($errors)) {
            $this->warn("There were " . count($errors) . " image download failures:");
            foreach ($errors as $error) {
                $this->line(" - " . $error);
            }

            if ($this->confirm('Do you want to save the error log to a file?', true)) {
                $filename = 'import_errors_' . date('Y-m-d_H-i-s') . '.log';
                $logPath = $this->basePath . '/' . $filename;
                $content = implode(PHP_EOL, $errors);
                file_put_contents($logPath, $content);
                $this->info("Log saved to $filename");
            }
        }
    }

    protected function rollback(string $file)
    {
        $this->info('Rolling back import based on file: ' . $file);

        if (file_exists($file)) {
            $xml = simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NOCDATA);
            $namespaces = $xml->getNamespaces(true);

            $this->postService->rollback($xml, $namespaces, $this->output);
            $this->categoryService->rollback($xml, $namespaces, $this->output);
            $this->userService->rollback($xml, $namespaces, $this->output);

            $this->tvService->rollback();
        } else {
            $this->error("Cannot rollback: XML file not found or invalid.");
        }

        $this->info('Rollback Complete.');
    }
}
