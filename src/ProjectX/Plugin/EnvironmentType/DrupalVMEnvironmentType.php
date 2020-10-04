<?php

declare(strict_types=1);

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

    protected const DEFAULT_DRUPAL_ROOT = 'web';

    protected const DRUPALVM_PORT = 3306;

    protected const DRUPALVM_ROOT = '/var/www/drupal';

    protected const DRUPALVM_CONFIG_FILENAME = 'config.yml';

    protected const DRUPALVM_LOCAL_VAGRANTFILE = 'vagrantfile.local';

    protected const DEFAULT_WEBSERVER = 'apache';

    protected const DEFAULT_SSL_CERT = '/etc/ssl/certs/ssl-cert-snakeoil.pem';

    protected const DEFAULT_SSL_CERT_KEY = '/etc/ssl/private/ssl-cert-snakeoil.key';

    protected const DEFAULT_VAGRANT_PLUGINS = ['vagrant-bindfs', 'vagrant-vbguest', 'vagrant-hostsupdater'];

    protected const DEFAULT_INSTALLABLE_PACKAGES = 'drush, xdebug, adminer, mailhog, pimpmylog';

    /**
     * {@inheritDoc}
     */
    public static function pluginId(): string
    {
        return 'drupalvm';
    }

    /**
     * {@inheritDoc}
     */
    public static function pluginLabel(): string
    {
        return 'DrupalVM';
    }

    /**
     * {@inheritDoc}
     */
    public function registeredCommands(): array
    {
        return array_merge([
            DatabaseCommands::class,
            DrupalVMCommands::class,
        ], parent::registeredCommands());
    }

    /**
     * {@inheritDoc}
     */
    public function execBuilderOptions(): array
    {
        return [
            'quote' => "'",
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function envPackages(): array
    {
        return [
            'drush',
            'composer',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function envAppRoot(): string
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
    public function init(array $opts = []): void
    {
        $this
            ->printBanner()
            ->installDrupalVM()
            ->writeDrupalVMConfig()
            ->writeDrupalVMVagrantFile()
            ->writeDrupalVMVagrantFileLocal();

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
    public function start(array $opts = []): DrupalVMEnvironmentType
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
    public function stop(array $opts = []): DrupalVMEnvironmentType
    {
        $this->taskVagrantHalt()->run();

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function restart(array $opts = []): DrupalVMEnvironmentType
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
    public function destroy(array $opts = []): DrupalVMEnvironmentType
    {
        $this->taskVagrantDestroy()->run();

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function info(array $opts = []): DrupalVMEnvironmentType
    {
        $this->taskVagrantStatus()->run();

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function ssh(array $opts = []): DrupalVMEnvironmentType
    {
        $this->taskVagrantSsh()->run();

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function launch(array $opts = []): DrupalVMEnvironmentType
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
    public function exec(string $cmd): DrupalVMEnvironmentType
    {
        $this->taskVagrantSsh()->command($cmd)->run();

        return $this;
    }

    /**
     * Install DrupalVM with composer.
     *
     * @return \Pr0jectX\PxDrupalVM\ProjectX\Plugin\EnvironmentType\DrupalVMEnvironmentType
     */
    protected function installDrupalVM(): DrupalVMEnvironmentType
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
    protected function writeDrupalVMVagrantFile(): DrupalVMEnvironmentType
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
    protected function printBanner(): DrupalVMEnvironmentType
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
     */
    protected function writeDrupalVMConfig(): DrupalVMEnvironmentType
    {
        try {
            if ($drupalVmConfig = $this->drupalVMConfiguration()->build()) {
                $configPath = PxApp::projectRootPath() . '/' . static::DRUPALVM_CONFIG_FILENAME;

                $this->confirmWriteFile(
                    $configPath,
                    $this->arrayToYaml($drupalVmConfig),
                    'The DrupalVM configurations already exist, continue?',
                    'The DrupalVM configurations were successfully written.'
                );
            }
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }

        return $this;
    }

    /**
     * Write DrupalVM vagrant file local configurations.
     */
    protected function writeDrupalVMVagrantFileLocal(): void
    {
        $configContents = [];
        $drupalVmConfig = DrupalVM::getDrupalVMConfigs();

        if ($this->hasVagrantPlugin('vagrant-bindfs', $drupalVmConfig['vagrant_plugins'])) {
            $configContents[] = DrupalVM::loadTemplateFile('vagrantfile.bindfs.txt');
        }

        if (!empty($configContents)) {
            $vagrantFileLocalPath =  PxApp::projectRootPath() . '/' . static::DRUPALVM_LOCAL_VAGRANTFILE;

            try {
                $this->confirmWriteFile(
                    $vagrantFileLocalPath,
                    implode("\r\n", $configContents),
                    'The DrupalVM vagrant.local file already exist, continue?',
                    'The DrupalVM vagrant.local file was successfully written.'
                );
            } catch (\Exception $exception) {
                $this->error($exception->getMessage());
            }
        }
    }

    /**
     * DrupalVM installable package options.
     *
     * @return array
     *   An array of installable packages.
     */
    protected function drupalVMInstallablePackages(): array
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
     * Define the DrupalVM vagrant plugin options.
     *
     * @return string[]
     *   An array of supported vagrant plugins.
     */
    protected function drupalVMVagrantPluginOptions(): array
    {
        return [
            'vagrant-bindfs',
            'vagrant-vbguest',
            'vagrant-hostsupdater'
        ];
    }

    /**
     * DrupalVM configuration tree builder.
     *
     * @return \Pr0jectX\Px\ConfigTreeBuilder\ConfigTreeBuilder
     *
     * @throws \Exception
     */
    protected function drupalVMConfiguration(): ConfigTreeBuilder
    {
        $config = DrupalVM::getDrupalVMConfigs();

        $phpVersions = PxApp::activePhpVersions();
        $phpDefaultVersion = $phpVersions[1];

        $configTreeBuilder = (new ConfigTreeBuilder())
            ->setQuestionInput($this->input)
            ->setQuestionOutput($this->output);

        $configTreeBuilder
            ->createNode('php_version')
            ->setValue(
                (new ChoiceQuestion(
                    $this->formatQuestionDefault(
                        'Select PHP Version',
                        $config['php_version'] ?? $phpDefaultVersion
                    ),
                    $phpVersions,
                    $config['php_version'] ?? $phpDefaultVersion
                ))
            )
            ->end();

        $webServerDefault = $config['drupalvm_webserver']
            ?? static::DEFAULT_WEBSERVER;

        $configTreeBuilder->createNode('drupalvm_webserver')
            ->setValue(
                (new ChoiceQuestion(
                    $this->formatQuestionDefault('Select the Web Server', $webServerDefault),
                    ['apache', 'nginx'],
                    $webServerDefault
                ))
            )
            ->end();

        $installedExtraDefault = static::DEFAULT_INSTALLABLE_PACKAGES;

        $configTreeBuilder
            ->createNode('vagrant_hostname')
                ->setValue(
                    (new Question(
                        $this->formatQuestionDefault(
                            'Input VM hostname',
                            $config['vagrant_hostname'] ?? null
                        ),
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
                ->setValue((new Question(
                    $this->formatQuestionDefault(
                        'Input VM machine name',
                        $config['vagrant_machine_name'] ?? null
                    ),
                    $config['vagrant_machine_name'] ?? null
                ))
                ->setValidator(function ($value) {
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
            ->end();

        $vagrantPlugins = $this->mergeVagrantPlugins(
            $config['vagrant_plugins'],
            static::DEFAULT_VAGRANT_PLUGINS
        );

        $configTreeBuilder
            ->createNode('vagrant_plugins')
            ->setValue(function () use ($vagrantPlugins) {
                $plugins = [];

                $pluginList = implode(', ', $vagrantPlugins);
                $pluginOptions = $this->drupalVMVagrantPluginOptions();

                $question = (new ChoiceQuestion(
                    $this->formatQuestionDefault(
                        'Select the vagrant plugins to install',
                        $pluginList
                    ),
                    $pluginOptions,
                    $pluginList
                ))->setMultiselect(true);

                foreach ($this->doAsk($question) as $name) {
                    $plugins[] = ['name' => $name];
                }

                return $plugins;
            })
        ->end();

        $configTreeBuilder->createNode('installed_extras')
            ->setValue(
                (new ChoiceQuestion(
                    $this->formatQuestionDefault('Select installed extras', $installedExtraDefault),
                    $this->drupalVMInstallablePackages(),
                    $installedExtraDefault
                ))->setMultiselect(true)
            )
            ->end();

        $sslCert = static::DEFAULT_SSL_CERT;
        $sslCertKey = static::DEFAULT_SSL_CERT_KEY;

        $configTreeBuilder->createNode('apache_vhosts_ssl')
            ->setArray()
                ->setKeyValue('servername', '{{ drupal_domain }}')
                ->setKeyValue('documentroot', '{{ drupal_core_path }}')
                ->setKeyValue('certificate_file', $sslCert)
                ->setKeyValue('certificate_key_file', $sslCertKey)
                ->setKeyValue('extra_parameters', '{{ apache_vhost_php_fpm_parameters }}')
            ->end()
            ->setCondition(static function ($build) {
                return isset($build['drupalvm_webserver']) && $build['drupalvm_webserver'] === 'apache';
            })
            ->end();

        $nginxHosts = $configTreeBuilder->createNode('nginx_hosts');

        foreach (DrupalVM::webServerHosts() as $webServerHost) {
            $array = $nginxHosts->setArray();

            $array->setKeyValue('server_name', $webServerHost['name']);
            $array->setKeyValue('root', $webServerHost['root']);
            $array->setKeyValue('is_php', true);

            if (isset($webServerHost['ssl']) && $webServerHost['ssl']) {
                $array->setKeyValue(
                    'extra_parameters',
                    implode(' ', [
                        'listen 443 ssl;',
                        "ssl_certificate {$sslCert};",
                        "ssl_certificate_key {$sslCertKey};",
                        'ssl_protocols TLSv1.1 TLSv1.2;',
                        'ssl_ciphers HIGH:!aNULL:!MD5;'
                    ])
                );
            }
            $array->end();
        }

        $nginxHosts->setCondition(static function ($build) {
            return isset($build['drupalvm_webserver']) && $build['drupalvm_webserver'] === 'nginx';
        })->end();

        $drupalCorePathDefault = isset($config['drupal_core_path'])
            ? substr($config['drupal_core_path'], strrpos($config['drupal_core_path'], '/') + 1)
            : static::DEFAULT_DRUPAL_ROOT;

        $configTreeBuilder
            ->createNode('drupal_core_path')
                ->setValue((new Question($this->formatQuestionDefault(
                    'Input the Drupal root directory',
                    $drupalCorePathDefault
                ), $drupalCorePathDefault))->setNormalizer(function ($drupalRoot) {
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
                    if (
                        isset($build['installed_extras'])
                        && in_array('xdebug', $build['installed_extras'], true)
                    ) {
                        return true;
                    }
                    return false;
                })
                ->setValue(true)
            ->end();

        return $configTreeBuilder;
    }

    /**
     * Merge the vagrant plugins.
     *
     * @param array $defaultPlugins
     *   An array of the default plugins.
     * @param array $installPlugins
     *   An array of the install plugins.
     *
     * @return array
     *   An array of the merged plugins.
     */
    protected function mergeVagrantPlugins(
        array $defaultPlugins,
        array $installPlugins
    ): array {
        return array_unique(array_merge(
            $this->flattenVagrantPlugins($defaultPlugins),
            $installPlugins
        ));
    }

    /**
     * Determine if the vagrant plugin exist.
     *
     * @param string $plugin
     *   The plugin name.
     * @param array $activePlugins
     *   An array of active plugins.
     *
     * @return bool
     *   Return true if the plugin exist; otherwise false.
     */
    protected function hasVagrantPlugin(string $plugin, array $activePlugins): bool
    {
        return in_array($plugin, $this->flattenVagrantPlugins($activePlugins), true);
    }

    /**
     * Flatten the vagrant plugins.
     *
     * @param array $plugins
     *   An array of vagrant plugins.
     *
     * @return array
     *   An array of flatten plugins.
     */
    protected function flattenVagrantPlugins(array $plugins): array
    {
        $flatten = [];

        foreach ($plugins as $plugin) {
            if (!isset($plugin['name'])) {
                continue;
            }
            $flatten[] = $plugin['name'];
        }

        return $flatten;
    }

    /**
     * Confirm before writing contents to path.
     *
     * @param string $path
     *   The file path.
     * @param string $contents
     *   The file contents.
     * @param string $confirmMessage
     *   The file overwrite confirm message.
     * @param string $successMessage
     *   The file success message to display.
     */
    protected function confirmWriteFile(
        string $path,
        string $contents,
        string $confirmMessage,
        string $successMessage
    ): void {
        $write = file_exists($path)
            ? $this->confirm($confirmMessage, true)
            : true;

        if (true === $write) {
            $response = $this->taskWriteToFile($path)
                ->text($contents)
                ->run();

            if ($response->getExitCode() !== 0) {
                throw new \RuntimeException(sprintf(
                    'Failed to write contents to %s.',
                    $path
                ));
            }

            $this->success($successMessage);
        }
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
    protected function arrayToYaml(array $contents): string
    {
        return Yaml::dump($contents, 10, 4);
    }
}
