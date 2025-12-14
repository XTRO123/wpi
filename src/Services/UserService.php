<?php declare(strict_types=1);

namespace Xtro123\Wpi\Services;



use EvolutionCMS\Models\User;
use EvolutionCMS\Models\UserAttribute;
use Illuminate\Support\Str;
use Illuminate\Console\OutputStyle;

/**
 * Service for importing WordPress Users as Manager Users.
 */
class UserService
{
    /**
     * Import Users from XML.
     *
     * @param \SimpleXMLElement $xml
     * @param array $namespaces
     * @param OutputStyle $output
     * @return array Map of WP_LOGIN => EVO_USER_ID
     */
    public function import(\SimpleXMLElement $xml, array $namespaces, OutputStyle $output): array
    {
        $output->info("Step 2: Importing Users...");

        $nodes = $xml->channel->xpath("wp:author");
        $bar = $output->createProgressBar(count($nodes));
        $bar->start();

        $userMap = [];

        foreach ($nodes as $node) {
            try {
                $login = (string) $node->children($namespaces["wp"])->author_login;
                $email = (string) $node->children($namespaces["wp"])->author_email;
                $name = (string) $node->children($namespaces["wp"])->author_display_name;

                $existing = User::where("username", $login)->first();

                if ($existing) {
                    $userMap[$login] = $existing->id;
                } else {
                    // Create User without password (requires reset)
                    $user = User::create([
                        "username" => $login,
                        // Password purposefully omitted or random to force reset
                    ]);

                    UserAttribute::create([
                        "internalKey" => $user->id,
                        "fullname" => $name,
                        "email" => $email,
                        "role" => 0, // Default Role ID (0 usually public, 1 admin? Adjust as needed)
                        "blocked" => 0
                    ]);

                    $userMap[$login] = $user->id;
                }
            } catch (\Exception $e) {
                $output->error("Error importing user $login: " . $e->getMessage());
            }
            $bar->advance();
        }
        $bar->finish();
        $output->newLine();
        $output->info("Users Imported.");

        return $userMap;
    }

    /**
     * Rollback users (Delete imported users except admin).
     */
    public function rollback(\SimpleXMLElement $xml, array $namespaces, OutputStyle $output): void
    {
        $output->info("Deleting Users...");
        foreach ($xml->channel->xpath("wp:author") as $node) {
            $login = (string) $node->children($namespaces["wp"])->author_login;
            $user = User::where("username", $login)->first();
            if ($user && $login !== "admin") {
                $user->delete();
            }
        }
    }
}


