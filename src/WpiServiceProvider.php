<?php declare(strict_types=1); namespace Xtro123\Wpi;

use EvolutionCMS\ServiceProvider;
use Xtro123\Wpi\Console\ImportCommand;

class WpiServiceProvider extends ServiceProvider
{
    protected $namespace = 'wpi';

    public function register()
    {
        $this->commands([
            ImportCommand::class
        ]);
    }
}

