<?php

declare(strict_types=1);

namespace youvision\think\databackup\service;

use think\Service;
use youvision\think\databackup\Backup;
use youvision\think\databackup\command\Backup as BackupCommand;
use youvision\think\databackup\command\Clean;
use youvision\think\databackup\command\Files;
use youvision\think\databackup\command\Restore;

class Databackup extends Service
{
    public function register(): void
    {
        $this->merge_config();

        $factory = function () {
            return new Backup((array) $this->app->config->get('databackup', []));
        };

        $this->app->bind(Backup::class, $factory);
        $this->app->bind('databackup', $factory);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                BackupCommand::class,
                Restore::class,
                Files::class,
                Clean::class,
            ]);
        }
    }

    private function merge_config(): void
    {
        $default = include __DIR__ . '/../config/databackup.php';
        $current = (array) $this->app->config->get('databackup', []);
        $this->app->config->set(array_replace_recursive($default, $current), 'databackup');
    }
}