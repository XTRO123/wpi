<?php declare(strict_types=1);

namespace Xtro123\Wpi\Services;


use Illuminate\Console\OutputStyle;

use EvolutionCMS\Models\SiteContent;
use EvolutionCMS\Models\SiteTmplvarContentvalue;
use Illuminate\Support\Str;
use Xtro123\Wpi\Services\TvService;
use Xtro123\Wpi\Services\ImageService;

class PostService
{
    protected TvService $tvService;
    protected ImageService $imageService;

    public function __construct(TvService $tvService, ImageService $imageService)
    {
        $this->tvService = $tvService;
        $this->imageService = $imageService;
    }

    /**
     * Get errors from ImageService.
     */
    public function getErrors(): array
    {
        return $this->imageService->getErrors();
    }

    /**
     * Import Posts/Pages from XML.
     */
    public function import(\SimpleXMLElement $xml, array $namespaces, array $categoryMap, array $userMap, $output): void
    {
        $output->info("Step 3: Importing Posts and Attachments...");

        $attachments = [];
        $items = [];

        foreach ($xml->channel->item as $item) {
            $wp = $item->children($namespaces["wp"]);
            $post_type = (string) $wp->post_type;

            if ($post_type === "attachment") {
                $id = (string) $wp->post_id;
                $url = (string) $wp->attachment_url;
                if (!empty($url)) {
                    $attachments[$id] = $url;
                }
            } elseif (in_array($post_type, ["post", "page"])) {
                $id = (string) $wp->post_id;
                $items[$id] = $item;
            }
        }

        $output->info("Found " . count($attachments) . " attachments.");

        $bar = $output->createProgressBar(count($items));
        $bar->start();

        $postMap = [];

        foreach ($items as $id => $item) {
            try {
                $evoId = $this->createResource($item, $namespaces, $userMap, $attachments);
                if ($evoId) {
                    $postMap[$id] = $evoId;
                }
            } catch (\Exception $e) {
                $output->error("Error importing post $id: " . $e->getMessage());
            }
            $bar->advance();
        }
        $bar->finish();
        $output->newLine();

        // Parent update for Pages
        foreach ($items as $id => $item) {
            try {
                $wp = $item->children($namespaces["wp"]);
                $post_type = (string) $wp->post_type;
                if ($post_type == "page" && !empty($wp->post_parent) && (string) $wp->post_parent != "0") {
                    $wpParent = (string) $wp->post_parent;
                    if (isset($postMap[$id]) && isset($postMap[$wpParent])) {
                        $res = SiteContent::find($postMap[$id]);
                        $res->parent = $postMap[$wpParent];
                        $res->save();
                    }
                }
            } catch (\Exception $e) {
            }
        }
        $output->info("Posts Imported.");
    }

    protected function createResource(\SimpleXMLElement $item, array $namespaces, array $userMap, array $attachments): int
    {
        $wp = $item->children($namespaces["wp"]);
        $content = $item->children($namespaces["content"]);
        $excerpt = $item->children($namespaces["excerpt"]);

        $title = (string) $item->title;
        $alias = (string) $wp->post_name;
        if (empty($alias))
            $alias = Str::slug($title);

        $body = (string) $content->encoded;
        $intro = (string) $excerpt->encoded;

        // Process Images
        $body = $this->processImages($body);
        $intro = $this->processImages($intro);

        $date = strtotime((string) $wp->post_date);
        $status = ((string) $wp->status == "publish") ? 1 : 0;
        $author_login = (string) $wp->post_author;
        $postType = (string) $wp->post_type;

        $parent = 0;
        if ($postType == "post") {
            foreach ($item->category as $cat) {
                if ((string) $cat["domain"] == "category") {
                    $nicename = (string) $cat["nicename"];
                    $folder = SiteContent::where("alias", $nicename)->first();
                    if ($folder) {
                        $parent = $folder->id;
                        break;
                    }
                }
            }
        }

        $createdBy = $userMap[$author_login] ?? 1;

        $type = ($postType === "page") ? "Page" : "Post";
        $tplId = $this->tvService->getTemplateId($type);

        $resource = SiteContent::create([
            "pagetitle" => $title,
            "alias" => $alias,
            "introtext" => $intro,
            "content" => $body,
            "parent" => $parent,
            "template" => $tplId,
            "published" => $status,
            "createdon" => $date,
            "createdby" => $createdBy
        ]);

        if ($parent > 0) {
            $parentRes = SiteContent::find($parent);
            if ($parentRes && $parentRes->isfolder == 0) {
                $parentRes->isfolder = 1;
                $parentRes->save();
            }
        }

        $this->tvService->processItemTvs($resource->id, $item, $tplId, $namespaces);
        $this->processFeaturedImage($resource->id, $item, $tplId, $namespaces, $attachments);

        return $resource->id;
    }

    protected function processFeaturedImage(int $resourceId, \SimpleXMLElement $item, int $tplId, array $namespaces, array $attachments): void
    {
        $thumbId = null;
        foreach ($item->children($namespaces["wp"])->postmeta as $meta) {
            if ((string) $meta->meta_key === "_thumbnail_id") {
                $thumbId = (string) $meta->meta_value;
                break;
            }
        }

        if ($thumbId && isset($attachments[$thumbId])) {
            $url = $attachments[$thumbId];
            $localPath = $this->imageService->download($url);

            if ($localPath) {
                $tvName = "image";
                $tv = \EvolutionCMS\Models\SiteTmplvar::firstOrCreate(
                    ["name" => $tvName],
                    ["caption" => "Post Image", "type" => "image", "category" => 0]
                );

                \EvolutionCMS\Models\SiteTmplvarTemplate::firstOrCreate([
                    "tmplvarid" => $tv->id,
                    "templateid" => $tplId
                ]);

                SiteTmplvarContentvalue::updateOrCreate(
                    ["contentid" => $resourceId, "tmplvarid" => $tv->id],
                    ["value" => $localPath]
                );
            }
        }
    }

    protected function processImages(string $content): string
    {
        if (empty($content))
            return $content;

        preg_match_all("/<img\s+[^>]*src=[\"']([^\"']+)[\"'][^>]*>/i", $content, $matches);

        if (!empty($matches[1])) {
            $urls = array_unique($matches[1]);
            foreach ($urls as $url) {
                if (!str_starts_with($url, "http"))
                    continue;

                $newUrl = $this->imageService->download($url);
                if ($newUrl !== $url) {
                    $content = str_replace($url, $newUrl, $content);
                }
            }
        }

        return $content;
    }

    public function rollback(\SimpleXMLElement $xml, array $namespaces, $output): void
    {
        $output->info("Deleting Posts...");

        $items = [];
        foreach ($xml->channel->item as $item) {
            $wp = $item->children($namespaces["wp"]);
            $post_type = (string) $wp->post_type;
            if (in_array($post_type, ["post", "page"])) {
                $items[] = $item;
            }
        }

        $bar = $output->createProgressBar(count($items));
        $bar->start();

        $deletedTvKeys = [];

        foreach ($items as $item) {
            $wp = $item->children($namespaces["wp"]);
            $alias = (string) $wp->post_name;
            if (empty($alias))
                $alias = Str::slug((string) $item->title);

            $res = SiteContent::withTrashed()->where("alias", $alias)->first();
            if ($res) {
                SiteTmplvarContentvalue::where("contentid", $res->id)->delete();
                $res->forceDelete();
            }

            foreach ($wp->postmeta as $meta) {
                $key = (string) $meta->meta_key;
                if (substr($key, 0, 1) == "_")
                    continue;

                // Optimization: Don't check/delete if already handled in this run
                if (!in_array($key, $deletedTvKeys)) {
                    $this->tvService->deleteTv($key);
                    $deletedTvKeys[] = $key;
                }
            }

            $hasTags = false;
            foreach ($item->category as $cat) {
                if ((string) $cat["domain"] == "post_tag") {
                    $hasTags = true;
                    break;
                }
            }
            if ($hasTags && !in_array("tags", $deletedTvKeys)) {
                $this->tvService->deleteTv("tags");
                $deletedTvKeys[] = "tags";
            }
            $bar->advance();
        }
        $bar->finish();

        $this->tvService->deleteTv("image");
        $output->newLine();
    }
}


