<?php

declare(strict_types = 1);

namespace Pr0jectX\PxDrupalVM\ProjectX\Plugin\EnvironmentType;

use JoeStewart\Robo\Task\Vagrant\loadTasks as vagrantTasks;
use Pr0jectX\Px\ConfigTreeBuilder\ConfigTreeBuilder;
use Pr0jectX\Px\ProjectX\Plugin\EnvironmentType\EnvironmentDatabase;
use Pr0jectX\Px\ProjectX\Plugin\EnvironmentType\EnvironmentTypeBase;
use Pr0jectX\Px\ProjectX\Plugin\EnvironmentType\EnvironmentTypeInterface;
use Pr0jectX\Px\PxApp;
use Pr0jectX\PxDrupalVM\DrupalVM;
use Pr0jectX\PxDrupalVM\ProjectX\Plugin\EnvironmentType\Commands\DatabaseCommands;
use Pr0jectX\PxDrupalVM\ProjectX\Plugin\EnvironmentType\Commands\DrupalVMCommands;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Yaml\Yaml;

/**
 * Define the DrupalVM environment type.
 */
class DrupalVMEnvironmentType extends EnvironmentTypeBase
{
    use vagrantTasks;

    const DEFAULT_PHP_VERSION = '7.2';

    const DEFAULT_DRUPAL_ROOT = 'web';

    const DRUPALVM_PORT = 3306;

    const DRUPALVM_ROOT = '/var/www/drupal';

    const DRUPALVM_CONFIG_FILENAME = 'config.yml';

    const DEFAULT_INSTALLABLE_PACKAGES = 'drush, xdebug, adminer, mailhog, pimpmylog';

    /**
     * {@inheritDoc}
     */
    public static function pluginId() : string
    {
        return 'drupalvm';
    }

    /**
     * {@inheritDoc}
     */
    public static function pluginLabel() : string
    {
        return 'DrupalVM';
    }

    /**
     * {@inheritDoc}
     */
    public function registeredCommands() : array
    {
        return array_merge([
            DatabaseCommands::class,
            DrupalVMCommands::class,
        ], parent::registeredCommands());
    }

    /**
     * {@inheritDoc}
     */
    public function envPackages() : array
    {
        return [
            'drush',
            'composer',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function envAppRoot() : string
    {
        return DrupalVM::getDrupalVMConfigs()['drupal_core_path']
            ?? static::DRUPALVM_ROOT;
    }

    /**
     * {@inheritDoc}
     */
    public function envDatabases(): array
    {
        $dbConfigs = DrupalVM::getDatabaseConfigs();

        return [
            EnvironmentTypeInterface::ENVIRONMENT_DB_PRIMARY => (new EnvironmentDatabase())
                ->setPort(static::DRUPALVM_PORT)
                ->setType($dbConfigs['drupal_db_backend'])
                ->setHost($dbConfigs['drupal_db_host'])
                ->setUsername($dbConfigs['drupal_db_user'])
                ->setPassword($dbConfigs['drupal_db_password'])
                ->setDatabase($dbConfigs['drupal_db_name'])
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function init(array $opts = [])
    {
        $this
            ->printBanner()
            ->installDrupalVM()
            ->writeDrupalVMConfig()
            ->writeDrupalVMVagrantFile();

        if ($this->confirm('Provision the environment now?', true)) {
            /** @var \Symfony\Component\Console\Application $application */
            $application = PxApp::service('application');

            /** @var \Consolidation\AnnotatedCommand\AnnotatedCommand $command */
            if ($command = $application->find('env:start')) {
                $command->optionsHook();
                $this->taskSymfonyCommand($command)
                    ->opt('provision')
                    ->run();
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function start(array $opts = []) : DrupalVMEnvironmentType
    {
        $task = $this->taskVagrantUp();

        if (isset($opts['provision']) && $opts['provision']) {
            $task->provision();
        }
        $task->run();

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function stop(array $opts = []) : DrupalVMEnvironmentType
    {
        $this->taskVagrantHalt()->run();

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function restart(array $opts = []) : DrupalVMEnvironmentType
    {
        $task = $this->taskVagrantReload();

        if (isset($opts['provision']) && $opts['provision']) {
            $task->provision();
        }
        $task->run();

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function destroy(array $opts = []) : DrupalVMEnvironmentType
    {
        $this->taskVagrantDestroy()->run();

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function info(array $opts = []) : DrupalVMEnvironmentType
    {
        $this->taskVagrantStatus()->run();

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function ssh(array $opts = []) : DrupalVMEnvironmentType
    {
        $this->taskVagrantSsh()->run();

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function launch(array $opts = []) : DrupalVMEnvironmentType
    {
        $schema = $opts['schema'] ?? 'http';

        if ($hostname = DrupalVM::getDrupalVMConfigs()['vagrant_hostname']) {
            $this->taskOpenBrowser("{$schema}://{$hostname}")->run();
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function exec(string $cmd) : DrupalVMEnvironmentType
    {
        $this->taskVagrantSsh()->command($cmd)->run();

        return $this;
    }

    /**
     * Install DrupalVM with composer.
     *
     * @return \Pr0jectX\PxDrupalVM\ProjectX\Plugin\EnvironmentType\DrupalVMEnvironmentType
     */
    protected function installDrupalVM() : DrupalVMEnvironmentType
    {
        if (!PxApp::composerHasPackage('geerlingguy/drupal-vm')) {
            $this->taskComposerRequire()
                ->dependency('geerlingguy/drupal-vm', null)
                ->run();

            $this->taskComposerInstall()->run();
        }

        return $this;
    }

    /**
     * Write the DrupalVM vagrant file.
     *
     * @return \Pr0jectX\PxDrupalVM\ProjectX\Plugin\EnvironmentType\DrupalVMEnvironmentType
     */
    protected function writeDrupalVMVagrantFile() : DrupalVMEnvironmentType
    {
        $rootPath = PxApp::projectRootPath();
        $vagrantFile = "{$rootPath}/VagrantFile";

        $writeVagrantFile = true;

        if (file_exists($vagrantFile)) {
            $writeVagrantFile = $this->confirm(
                'The VagrantFile already exist, continue?',
                false
            );
        }

        if ($writeVagrantFile) {
            $result = $this->taskWriteToFile($vagrantFile)
                ->textFromFile(DrupalVM::getTemplateFilePath('VagrantFile'))
                ->run();

            if ($result->getExitCode() === 0) {
                $this->success(
                    'The VagrantFile was successfully written.'
                );
            }
        }

        return $this;
    }

    /**
     * Print the DrupalVM banner.
     *
     * @return \Pr0jectX\PxDrupalVM\ProjectX\Plugin\EnvironmentType\DrupalVMEnvironmentType
     */
    protected function printBanner() : DrupalVMEnvironmentType
    {
        print file_get_contents(
            DrupalVM::rootPath() . '/banner.txt'
        );

        return $this;
    }

    /**
     * Write DrupalVM configuration to the filesystem.
     *
     * @return \Pr0jectX\PxDrupalVM\ProjectX\Plugin\EnvironmentType\DrupalVMEnvironmentType
     *
     * @throws \Exception
     */
    protected function writeDrupalVMConfig() : DrupalVMEnvironmentType
    {
        if ($drupalVmConfig = $this->drupalVMConfiguration()->build()) {
            $writeConfig = true;
            $configPath = PxApp::projectRootPath() . '/' . static::DRUPALVM_CONFIG_FILENAME;

            if (file_exists($configPath)) {
                $writeConfig = $this->confirm(
                    'The DrupalVM configurations already exist, continue?',
                    true
                );
            }

            if (true === $writeConfig) {
                $response = $this->taskWriteToFile($configPath)
                    ->text($this->arrayToYaml($drupalVmConfig))
                    ->run();

                if ($response->getExitCode() === 0) {
                    $this->success(
                        'The DrupalVM configurations were successfully written.'
                    );
                }
            }
        } else {
            $this->error(
                'Unable to write the DrupalVM configurations.'
            );
        }

        return $this;
    }

    /**
     * DrupalVM installable package options.
     *
     * @return array
     *   An array of installable packages.
     */
    protected function drupalVMInstallablePackages() : array
    {
        return [
            'adminer',
            'blackfire',
            'drupalconsole',
            'drush',
            'elasticsearch',
            'java',
            'mailhog',
            'memcached',
            'newrelic',
            'nodejs',
            'pimpmylog',
            'redis',
            'ruby',
            'selenium',
            'solr',
            'tideways',
            'upload-progress',
            'varnish',
            'xdebug',
            'xhprof',
        ];
    }

    /**
     * DrupalVM configuration tree builder.
     *
     * @return \Pr0jectX\Px\ConfigTreeBuilder\ConfigTreeBuilder
     *
     * @throws \Exception
     */
    protected function drupalVMConfiguration() : ConfigTreeBuilder
    {
        $config = DrupalVM::getDrupalVMConfigs();

        $configTreeBuilder = (new ConfigTreeBuilder())
            ->setQuestionInput($this->input)
            ->setQuestionOutput($this->output);

        $configTreeBuilder
            ->createNode('php_version')
            ->setValue(
                (new ChoiceQuestion($this->formatQuestionDefault(
                    'Select PHP Version', $config['php_version'] ?? static::DEFAULT_PHP_VERSION),
                    ['7.2', '7.3', '7.4'],
                    $config['php_version'] ?? static::DEFAULT_PHP_VERSION
                )))
            ->end();

        $installedExtraDefault = isset($config['installed_extras'])
            ? implode(', ', $config['installed_extras'])
            : static::DEFAULT_INSTALLABLE_PACKAGES;

        $configTreeBuilder
            ->createNode('vagrant_hostname')
                ->setValue(
                    (new Question($this->formatQuestionDefault(
                        'Input VM hostname', $config['vagrant_hostname'] ?? null),
                        $config['vagrant_hostname'] ?? null
                    ))
                        ->setValidator(function ($value) {
                            if (!isset($value)) {
                                throw new \RuntimeException(
                                    'The VM hostname is required!'
                                );
                            }
                            return $value;
                        })
                    ->setNormalizer(function ($value) {
                        if (isset($value) && strpos($value, '.') === false) {
                            $value .= '.test';
                        }
                        return $value;
                    })
                )
            ->end()
            ->createNode('vagrant_machine_name')
                ->setValue((new Question($this->formatQuestionDefault(
                    'Input VM machine name', $config['vagrant_machine_name'] ?? null),
                    $config['vagrant_machine_name'] ?? null
                ))
                ->setValidator(function($value) {
                    if (!isset($value)) {
                        throw new \RuntimeException(
                            'The VM machine name is required!'
                        );
                    }
                    return $value;
                }))
            ->end()
            ->createNode('vagrant_synced_folders')
                ->setArray()
                    ->setKeyValue('type', 'nfs')
                    ->setKeyValue('create', true)
                    ->setKeyValue('local_path', './')
                    ->setKeyValue('destination', static::DRUPALVM_ROOT)
                ->end()
            ->end()
            ->createNode('installed_extras')
            ->setValue(
                (new ChoiceQuestion(
                    $this->formatQuestionDefault('Select installed extras', $installedExtraDefault),
                    $this->drupalVMInstallablePackages(),
                    $installedExtraDefault
                ))->setMultiselect(true))
            ->end();

        $drupalCorePathDefault = $config['drupal_core_path']
            ? substr($config['drupal_core_path'], strrpos($config['drupal_core_path'], '/') + 1)
            : static::DEFAULT_DRUPAL_ROOT;

        $configTreeBuilder
            ->createNode('drupal_core_path')
                ->setValue((new Question($this->formatQuestionDefault(
                    'Input the Drupal root directory', $drupalCorePathDefault
                ), $drupalCorePathDefault
                ))->setNormalizer(function ($drupalRoot) {
                    $drupalVMRoot = static::DRUPALVM_ROOT;
                    return "{$drupalVMRoot}/{$drupalRoot}";
                }))
            ->end()
            ->createNode('drupal_install_site')
                ->setValue(false)
            ->end()
            ->createNode('drupal_build_makefile')
                ->setValue(false)
            ->end()
            ->createNode('drupal_build_composer')
                ->setValue(false)
            ->end()
            ->createNode('drupal_build_composer_project')
                ->setValue(false)
            ->end();

        $configTreeBuilder
            ->createNode('php_xdebug_default_enable')
                ->setCondition(function (array $build) {
                    if (isset($build['installed_extras'])
                        && in_array('xdebug', $build['installed_extras'])) {
                        return true;
                    }
                    return false;
                })
                ->setValue(true)
            ->end();

        return $configTreeBuilder;
    }

    /**
     * Convert an array to a YAML structure.
     *
     * @param array $contents
     *   An array of contents input.
     *
     * @return string
     *   The YAML structured contents.
     */
    protected function arrayToYaml(array $contents) : string
    {
        return Yaml::dump($contents, 10, 4);
    }
}
