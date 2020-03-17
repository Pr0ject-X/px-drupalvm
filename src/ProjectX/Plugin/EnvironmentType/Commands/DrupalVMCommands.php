<?php

declare(strict_types=1);

namespace Pr0jectX\PxDrupalVM\ProjectX\Plugin\EnvironmentType\Commands;

use JoeStewart\Robo\Task\Vagrant\loadTasks as vagrantTasks;
use Pr0jectX\Px\ProjectX\Plugin\PluginCommandTaskBase;

/**
 * Define the DrupalVM extra commands that are non-standard.
 */
class DrupalVMCommands extends PluginCommandTaskBase
{
    use vagrantTasks;

    /**
     * Provision the project environment.
     */
    public function envProvision()
    {
        $this->taskVagrantProvision()->run();
    }

    /**
     * @hook replace-command env:start
     *
     * @param array $opts
     * @option $provision
     *   Provision the environment.
     */
    public function drupalVMEnvStart(array $opts = ['provision' => false])
    {
        $this->plugin->start($opts);
    }

    /**
     * @hook replace-command env:restart
     *
     * @param array $opts
     * @option $provision
     *   Provision the environment.
     */
    public function drupalVMEnvRestart(array $opts = ['provision' => false])
    {
        $this->plugin->restart($opts);
    }
}
