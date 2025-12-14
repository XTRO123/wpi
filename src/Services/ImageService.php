<?php declare(strict_types=1);

namespace Xtro123\Wpi\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ImageService
{
    protected string $basePath;
    protected string $baseUrl;
    protected array $errors = [];
    protected bool $allowPdf = true;
    protected bool $downloadEnabled = true;
    protected bool $overwriteExisting = false;

    protected array $mimeMap = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public function __construct()
    {
        $this->basePath = dirname(__DIR__, 6) . "/assets/images/wpi";
        $this->baseUrl = "assets/images/wpi";

        if (!file_exists($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    public function setAllowPdf(bool $allow): void
    {
        $this->allowPdf = $allow;
    }

    public function setDownloadEnabled(bool $enabled): void
    {
        $this->downloadEnabled = $enabled;
    }

    public function setOverwriteExisting(bool $overwrite): void
    {
        $this->overwriteExisting = $overwrite;
    }

    public function download(string $url): string
    {
        if (!$this->downloadEnabled) {
            return $url;
        }

        // Rate Limiting
        usleep(200000);

        /*
        if (!str_starts_with($url, "https")) {
            if (str_starts_with($url, "http")) {
                $url = str_replace("http://", "https://", $url);
            } else {
                return $url; 
            }
        }
        */

        try {
            // Check for existence BEFORE request to speed up
            $pathInfo = pathinfo(parse_url($url, PHP_URL_PATH));
            $name = $pathInfo["filename"] ?? "image";
            $ext = $pathInfo["extension"] ?? null;

            if (preg_match("#/(\d{4})/(\d{2})/#", $url, $matches)) {
                $datePath = $matches[1] . "/" . $matches[2];
            } else {
                $datePath = date("Y/m");
            }

            $fullDir = $this->basePath . "/" . $datePath;
            $cleanName = Str::slug($name);

            // If we have an extension, we can check if file exists immediately
            if ($ext && !$this->overwriteExisting) {
                $localPath = $fullDir . "/" . $cleanName . "." . $ext;
                if (file_exists($localPath)) {
                    return $this->baseUrl . "/" . $datePath . "/" . $cleanName . "." . $ext;
                }
            }

            $opts = [
                "http" => [
                    "method" => "GET",
                    "timeout" => 5,
                    "ignore_errors" => true,
                    "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n"
                ],
                "ssl" => [
                    "verify_peer" => true,
                    "verify_peer_name" => true,
                ]
            ];
            $context = stream_context_create($opts);

            $http_response_header = null;
            $content = @file_get_contents($url, false, $context);

            if ($content === false) {
                $error = error_get_last();
                $msg = "Failed to open URL $url: " . ($error["message"] ?? "Unknown error");
                $this->errors[] = $msg;
                Log::error("[WPI] " . $msg);
                return $url;
            }

            $currentContentType = "";

            // Validate Headers
            if (isset($http_response_header)) {
                // Find the LAST HTTP status code (to handle redirects like 301 -> 200)
                $finalStatus = "";
                foreach ($http_response_header as $header) {
                    if (str_starts_with($header, "HTTP/")) {
                        $finalStatus = $header;
                    }
                }

                if (!empty($finalStatus) && !preg_match("#HTTP/\d\.\d\s+200\s+OK#i", $finalStatus)) {
                    $msg = "HTTP Error for $url: " . $finalStatus;
                    $this->errors[] = $msg;
                    Log::warning("[WPI] " . $msg);
                    return $url;
                }

                $validType = false;
                $currentContentType = "unknown";
                $isPdf = false;

                foreach ($http_response_header as $header) {
                    if (stripos($header, "Content-Type:") === 0) {
                        $currentContentType = trim(substr($header, 13));

                        // Check for Image
                        if (str_starts_with(strtolower($currentContentType), "image/")) {
                            $validType = true;
                        }
                        // Check for PDF
                        elseif (str_starts_with(strtolower($currentContentType), "application/pdf")) {
                            if ($this->allowPdf) {
                                $validType = true;
                            } else {
                                $isPdf = true;
                            }
                        }

                        break;
                    }
                }

                if (!$validType) {
                    if ($isPdf) {
                        $msg = "Skipped PDF $url (use --pdf to enable)";
                    } else {
                        $msg = "Invalid Content-Type for $url: $currentContentType";
                    }
                    $this->errors[] = $msg;
                    Log::warning("[WPI] " . $msg);
                    return $url;
                }
            } else {
                $msg = "No response headers for $url";
                $this->errors[] = $msg;
                Log::warning("[WPI] " . $msg);
                return $url;
            }

            // Deep Validation
            $isPdfContent = (substr($content, 0, 4) === "%PDF");

            if ($this->allowPdf && $isPdfContent) {
                // Valid PDF
            } else {
                if (@getimagesizefromstring($content) === false) {
                    $msg = "Invalid image data (getimagesizefromstring failed) for $url";
                    $this->errors[] = $msg;
                    Log::error("[WPI] " . $msg);
                    return $url;
                }
            }

            // Smart Extension Detection if missing
            if (empty($ext)) {
                $cleanMime = strtolower(explode(";", $currentContentType)[0]);
                if (isset($this->mimeMap[$cleanMime])) {
                    $ext = $this->mimeMap[$cleanMime];
                } else {
                    $ext = "jpg"; // Fallback
                }
            }

            if (!file_exists($fullDir)) {
                mkdir($fullDir, 0755, true);
            }

            $finalName = $cleanName . "." . $ext;
            $localPath = $fullDir . "/" . $finalName;

            // Re-check existence if we didn't have extension before
            if (file_exists($localPath) && !$this->overwriteExisting) {
                return $this->baseUrl . "/" . $datePath . "/" . $finalName;
            }

            file_put_contents($localPath, $content);

            return $this->baseUrl . "/" . $datePath . "/" . $finalName;

        } catch (\Exception $e) {
            $msg = "Exception downloading $url: " . $e->getMessage();
            $this->errors[] = $msg;
            Log::error("[WPI] " . $msg);
            return $url;
        }
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
