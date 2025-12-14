<?php declare(strict_types=1);

namespace Xtro123\Wpi\Services;



use EvolutionCMS\Models\SiteContent;
use Xtro123\Wpi\Services\TvService;
use Illuminate\Console\OutputStyle;

class CategoryService
{
    protected TvService $tvService;

    public function __construct(TvService $tvService)
    {
        $this->tvService = $tvService;
    }

    /**
     * Import Categories from XML.
     *
     * @param \SimpleXMLElement $xml
     * @param array $namespaces
     * @param OutputStyle $output
     * @return array Map of WP_ID => EVO_ID
     */
    public function import(\SimpleXMLElement $xml, array $namespaces, OutputStyle $output): array
    {
        $output->info("Step 1: Importing Categories...");

        $nodes = $xml->channel->xpath("wp:category");
        $bar = $output->createProgressBar(count($nodes));
        $bar->start();

        $categories = [];

        // Pre-process nodes into array
        foreach ($nodes as $node) {
            try {
                $wp_id = (string) $node->children($namespaces["wp"])->term_id;
                $parent = (string) $node->children($namespaces["wp"])->category_parent;
                $name = (string) $node->children($namespaces["wp"])->cat_name;
                $slug = (string) $node->children($namespaces["wp"])->category_nicename;

                // Extract description from Yoast/SEO meta if available
                $introtext = "";
                foreach ($node->children($namespaces["wp"])->termmeta as $meta) {
                    if ((string) $meta->meta_key == "autodescription-term-settings") {
                        $rawValue = (string) $meta->meta_value;
                        $data = json_decode($rawValue, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $data = @unserialize($rawValue, ['allowed_classes' => false]);
                        }
                        if (is_array($data) && isset($data["description"])) {
                            $introtext = $data["description"];
                        }
                    }
                }

                $categories[$wp_id] = [
                    "id" => $wp_id,
                    "parent_slug" => $parent,
                    "name" => $name,
                    "slug" => $slug,
                    "introtext" => $introtext,
                    "evo_id" => 0
                ];
            } catch (\Exception $e) {
                $output->error("Error processing category node: " . $e->getMessage());
            }
            $bar->advance();
        }
        $bar->finish();
        $output->newLine();

        $output->info("Processing Categories...");
        $bar = $output->createProgressBar(count($categories));
        $bar->start();

        // Get Template for Categories
        $tplId = $this->tvService->getTemplateId("Category");

        // Pass 1: Create Resources (without hierarchy)
        foreach ($categories as $id => $cat) {
            try {
                $existing = SiteContent::where("alias", $cat["slug"])->first();
                if ($existing) {
                    $categories[$id]["evo_id"] = $existing->id;
                    if ($existing->template === 0) {
                        $existing->template = $tplId;
                        $existing->save();
                    }
                } else {
                    $resource = SiteContent::create([
                        "pagetitle" => $cat["name"],
                        "alias" => $cat["slug"],
                        "parent" => 0,
                        "template" => $tplId,
                        "published" => 1,
                        "isfolder" => 0, // Default to 0, will update if has children
                        "introtext" => $cat["introtext"],
                        "content" => "",
                        "createdon" => time()
                    ]);
                    $categories[$id]["evo_id"] = $resource->id;
                }
            } catch (\Exception $e) {
                $output->error("Error saving category {$cat["name"]}: " . $e->getMessage());
            }
            $bar->advance();
        }
        $bar->finish();
        $output->newLine();

        // Pass 2: Update hierarchy (Parent/Child relationships)
        $slugToWpId = [];
        foreach ($categories as $id => $cat) {
            $slugToWpId[$cat["slug"]] = $id; // Mapping by slug!
        }

        foreach ($categories as $id => $cat) {
            try {
                if (!empty($cat["parent_slug"]) && isset($slugToWpId[$cat["parent_slug"]])) {
                    $parentIdWp = $slugToWpId[$cat["parent_slug"]];
                    // Correctly map slug -> WP_ID -> EVO_ID
                    $parentIdEvo = $categories[$parentIdWp]["evo_id"];

                    $res = SiteContent::find($cat["evo_id"]);
                    if ($res && $res->parent != $parentIdEvo) {
                        $res->parent = $parentIdEvo;
                        $res->save();

                        // Ensure Parent is marked as folder
                        $parentRes = SiteContent::find($parentIdEvo);
                        if ($parentRes && $parentRes->isfolder == 0) {
                            $parentRes->isfolder = 1;
                            $parentRes->save();
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore parent update errors or log slightly?
            }
        }

        $output->info("Categories Imported.");
        return array_column($categories, "evo_id", "id");
    }

    /**
     * Rollback categories import (Delete created resources).
     */
    public function rollback(\SimpleXMLElement $xml, array $namespaces, OutputStyle $output): void
    {
        $output->info("Deleting Categories...");
        $cats = $xml->channel->xpath("wp:category");
        $bar = $output->createProgressBar(count($cats));
        $bar->start();

        foreach ($cats as $node) {
            $slug = (string) $node->children($namespaces["wp"])->category_nicename;
            $res = SiteContent::withTrashed()->where("alias", $slug)->first();
            if ($res) {
                $res->forceDelete();
            }
            $bar->advance();
        }
        $bar->finish();
        $output->newLine();
    }
}


