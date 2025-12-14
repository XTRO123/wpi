<?php declare(strict_types=1); namespace Xtro123\Wpi\Services;

use EvolutionCMS\Models\SiteTemplate;
use EvolutionCMS\Models\SiteTmplvar;
use EvolutionCMS\Models\SiteTmplvarContentvalue;
use EvolutionCMS\Models\SiteTmplvarTemplate;
use Illuminate\Support\Str;

/**
 * Service for managing Template Variables (TVs) and Templates.
 */
class TvService
{
    protected array $templateCache = [];

    // Map logical names to TV Types
    protected array $tvTypeMap = [
        // Numbers
        "nights" => "number",
        "price" => "number",
        "cost" => "number",
        "count" => "number",
        "order" => "number",
        "cena" => "number",
        "stoimost" => "number",
        "kol-vo" => "number",
        "kol-vo-celovek" => "number",
        "skidka" => "number",
        "sale" => "number",
        
        // Dates
        "date" => "date",
        "start_date" => "date",
        "end_date" => "date",
        "data" => "date",
        "daty-tura" => "textarea", 

        // Images
        "image" => "image",
        "img" => "image",
        "photo" => "image",
        "thumb" => "image",
        "picture" => "image",
        "foto" => "image",
        "izobrazenie" => "image",
    ];

    /**
     * Get or Create a Template for a specific entity type (Post, Page, Category).
     *
     * @param string $type The entity type name (e.g. "Category", "Post")
     * @return int Template ID
     */
    public function getTemplateId(string $type): int
    {
        $tplName = "WordPress Import - $type";

        if (isset($this->templateCache[$tplName])) {
            return $this->templateCache[$tplName];
        }

        $tpl = SiteTemplate::where("templatename", $tplName)->first();
        if (!$tpl) {
            $tpl = SiteTemplate::create([
                "templatename" => $tplName,
                "content" => "[*content*]",
                "description" => "Template for imported WordPress {$type}s"
            ]);
        }

        $this->templateCache[$tplName] = $tpl->id;
        return $tpl->id;
    }

    /**
     * Process meta for an item: extract, transliterate, link, and save.
     * 
     * @param int $resourceId The EvoCMS Resource ID
     * @param \SimpleXMLElement $item The XML item node
     * @param int $tplId The Template ID to link TVs to
     * @param array $namespaces XML namespaces
     */
    public function processItemTvs(int $resourceId, \SimpleXMLElement $item, int $tplId, array $namespaces): void
    {
        $metaData = $this->extractMeta($item, $namespaces);
        
        // 1. Single Values
        foreach ($metaData["single"] as $key => $val) {
            // $key is already transliterated slug
            $caption = $metaData["captions"][$key] ?? ucfirst($key);
            $this->ensureTvLinked($key, $tplId, $caption);
            $this->saveTvValue($resourceId, $key, $val);
        }

        // 2. Grouped Values (ACF Repeaters/Groups)
        foreach ($metaData["grouped"] as $base => $data) {
            ksort($data);
            $data = array_values($data); // Reset indices
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            
            $caption = $metaData["captions"][$base] ?? ucfirst($base);
            $this->ensureTvLinked($base, $tplId, $caption);
            $this->saveTvValue($resourceId, $base, $json);
        }

        // 3. Tags (Special case)
        if (!empty($metaData["tags"])) {
            $this->ensureTvLinked("tags", $tplId, "Tags");
            $this->saveTvValue($resourceId, "tags", $metaData["tags"]);
        }
    }

    /**
     * Extract and normalize meta data from XML item.
     * Handles standard fields and detects grouped fields (e.g. key-0-subfield).
     *
     * @param \SimpleXMLElement $item
     * @param array $namespaces
     * @return array ["single" => [], "grouped" => [], "tags" => string, "captions" => []]
     */
    protected function extractMeta(\SimpleXMLElement $item, array $namespaces): array
    {
        $allMeta = [];
        $captions = [];
        
        // Tags
        $tags = [];
        foreach ($item->category as $cat) {
             if ((string)$cat["domain"] == "post_tag") {
                 $tags[] = (string)$cat;
             }
        }
        $tagsString = !empty($tags) ? implode(",", $tags) : null;

        // Postmeta processing
        foreach ($item->children($namespaces["wp"])->postmeta as $meta) {
            $key = (string)$meta->meta_key;
            $val = (string)$meta->meta_value;
            
            // Skip hidden internal WP meta (starts with _)
            if (substr($key, 0, 1) == "_") continue;

            $slug = Str::slug($key);
            if (empty($slug)) $slug = $key;

            $allMeta[$slug] = $val;
            $captions[$slug] = $key;
        }

        // Grouping logic (detecting array-like keys)
        $finalMeta = ["single" => [], "grouped" => [], "tags" => $tagsString, "captions" => $captions];

        foreach ($allMeta as $key => $val) {
            // Pattern: field-0-subfield
            if (preg_match("/^(.+)-(\d+)-(.+)$/", $key, $m)) {
                $base = $m[1];
                $index = (int)$m[2];
                $sub = $m[3];
                $finalMeta["grouped"][$base][$index][$sub] = $val;
                
                // Caption fix logic
                if (!isset($finalMeta["captions"][$base]) && isset($captions[$key])) {
                     $finalMeta["captions"][$base] = ucfirst(str_replace("-", " ", $base));
                }

            // Pattern: field-0 (Simple repeater)
            } elseif (preg_match("/^(.+)-(\d+)$/", $key, $m)) {
                $base = $m[1];
                $index = (int)$m[2];
                $finalMeta["grouped"][$base][$index] = $val;
                
                if (!isset($finalMeta["captions"][$base])) {
                     $finalMeta["captions"][$base] = ucfirst(str_replace("-", " ", $base));
                }
            } else {
                $finalMeta["single"][$key] = $val;
            }
        }

        return $finalMeta;
    }

    /**
     * Ensure a TV exists and is linked to the Template.
     */
    protected function ensureTvLinked(string $name, int $tplId, string $caption): void
    {
        $tv = SiteTmplvar::where("name", $name)->first();
        if (!$tv) {
            $type = $this->tvTypeMap[$name] ?? "textarea";
            
            // Logic for specific keywords to force textarea
            if (strpos($name, "bronirovaniya") !== false || strpos($name, "plan") !== false) {
                 $type = "textarea"; 
            }

            $tv = SiteTmplvar::create([
                "name" => $name,
                "caption" => $caption,
                "type" => $type, 
                "category" => 0
            ]);
        }

        SiteTmplvarTemplate::firstOrCreate([
            "tmplvarid" => $tv->id,
            "templateid" => $tplId
        ]);
    }

    /**
     * Save a value for a TV to a specific resource.
     */
    protected function saveTvValue(int $resourceId, string $name, string $value): void
    {
        if (empty($value)) return;
        $tv = SiteTmplvar::where("name", $name)->first();
        if ($tv) {
            SiteTmplvarContentvalue::updateOrCreate(
                ["contentid" => $resourceId, "tmplvarid" => $tv->id],
                ["value" => $value]
            );
        }
    }

    /**
     * Rollback Templates and their TV links.
     */
    public function rollback(): void
    {
        // Delete Templates created by this import
        $tpls = SiteTemplate::where("templatename", "like", "WordPress Import - %")->get();
        foreach ($tpls as $tpl) {
            SiteTmplvarTemplate::where("templateid", $tpl->id)->delete();
            $tpl->delete();
        }
    }

    /**
     * Delete a TV by key (and its group parent if applicable).
     */
    public function deleteTv(string $key): void
    {
         // Clean transliteration
         $slug = Str::slug($key);
         if (empty($slug)) $slug = $key;

         $tv = SiteTmplvar::where("name", $slug)->first();
         if ($tv) {
             $tv->delete();
         }

         // Grouped check
         if (preg_match("/^(.+)-(\d+)(-.+)?$/", $slug, $matches)) {
             $base = $matches[1];
             $tvGroup = SiteTmplvar::where("name", $base)->first();
             if ($tvGroup) {
                 $tvGroup->delete();
             }
         }
    }
}

