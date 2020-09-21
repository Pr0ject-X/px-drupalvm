<?php

declare(strict_types=1);

namespace Pr0jectX\PxDrupalVM;

use Pr0jectX\Px\PxApp;
use Symfony\Component\Yaml\Yaml;

/**
 * Define the DrupalVM environment type.
 */
class DrupalVM
{
    /**
     * The DrupalVM root path.
     *
     * @return string
     *   The DrupalVM root path.
     */
    public static function rootPath(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Define the DrupalVM template directories.
     *
     * @return array
     *   An array of DrupalVM templates.
     */
    public static function templateDirectories(): array
    {
        return [
            static::rootPath() . '/templates'
        ];
    }

    /**
     * Retrieve the DrupalVM vagrant configurations.
     *
     * @return array
     *   An array of DrupalVM vagrant configurations.
     */
    public static function getVagrantConfigs(): array
    {
        $drupalVmConfigs = DrupalVM::getDrupalVMConfigs();

        return array_intersect_key($drupalVmConfigs, array_flip([
            'vagrant_ip',
            'vagrant_user',
            'vagrant_hostname'
        ]));
    }

    /**
     * Retrieve the DrupalVM database configurations.
     *
     * @return array
     *   An array of DrupalVM database configurations.
     */
    public static function getDatabaseConfigs(): array
    {
        $drupalVmConfigs = DrupalVM::getDrupalVMConfigs();

        $databaseConfigs = array_intersect_key(
            $drupalVmConfigs,
            array_flip([
                'drupal_db_host',
                'drupal_db_name',
                'drupal_db_user',
                'drupal_db_backend',
                'drupal_db_password',
            ])
        );

        if (
            isset($databaseConfigs['drupal_db_host'])
            && $databaseConfigs['drupal_db_host'] === 'localhost'
        ) {
            $databaseConfigs['drupal_db_host'] = '127.0.0.1';
        }

        return $databaseConfigs;
    }

    /**
     * The DrupalVM web server hosts.
     *
     * @return array
     *   An array of web server hosts.
     */
    public static function webServerHosts(): array
    {
        return [
            [
                'name' => '{{ drupal_domain }}',
                'root' => '{{ drupal_core_path }}',
                'ssl' => true,
            ],
            [
                'name' => 'adminer.{{ vagrant_hostname }}',
                'root' => '{{ adminer_install_dir }}',
            ],
            [
                'name' => 'xhprof.{{ vagrant_hostname }}',
                'root' => '{{ php_xhprof_html_dir }}',
            ],
            [
                'name' => 'pimpmylog.{{ vagrant_hostname }}',
                'root' => '{{ pimpmylog_install_dir }}',
            ],
            [
                'name' => '{{ vagrant_ip }}',
                'root' => 'dashboard.{{ vagrant_hostname }}'
            ]
        ];
    }

    /**
     * Get the remote vagrant SSH path.
     *
     * @param string $path
     *   The path to the remote file.
     *
     * @return string
     *   The remote vagrant SSH path.
     */
    public static function getVagrantSshPath(string $path): string
    {
        $vagrantConfig = static::getVagrantConfigs();

        return "{$vagrantConfig['vagrant_user']}@{$vagrantConfig['vagrant_hostname']}:{$path}";
    }

    /**
     * Load the DrupalVM template files.
     *
     * @param string $filename
     *   The template file name.
     *
     * @return bool|string
     *   Return the contents of the template file; otherwise false.
     */
    public static function loadTemplateFile(string $filename)
    {
        if ($filepath = static::getTemplateFilePath($filename)) {
            return file_get_contents($filepath);
        }

        return false;
    }

    /**
     * Retrieve the template file path.
     *
     * @param string $filename
     *   The template file name.
     *
     * @return bool|string
     *   Return the fully qualified path to the template file; otherwise false.
     */
    public static function getTemplateFilePath(string $filename)
    {
        foreach (static::templateDirectories() as $directory) {
            $filepath =  "{$directory}/{$filename}";

            if (!file_exists($filepath)) {
                continue;
            }

            return $filepath;
        }

        return false;
    }

    /**
     * Get the DrupalVM configurations.
     *
     * @return array
     *   An array of the DrupalVM configurations.
     */
    public static function getDrupalVMConfigs(): array
    {
        $drupalVMConfigs = [];
        $projectRoot = PxApp::projectRootPath();

        foreach (static::drupalVMConfigs() as $configFilename) {
            $projectConfigPath = "{$projectRoot}/{$configFilename}";

            if (!file_exists($projectConfigPath)) {
                continue;
            }
            $parsedConfigs = Yaml::parseFile($projectConfigPath);

            if (!is_array($parsedConfigs)) {
                continue;
            }

            $drupalVMConfigs = array_replace_recursive(
                $drupalVMConfigs,
                $parsedConfigs
            );
        }

        return $drupalVMConfigs;
    }

    /**
     * Define the Drupal VM configuration locations.
     *
     * @return array
     *   An array of all Drupal VM configurations.
     */
    protected static function drupalVMConfigs(): array
    {
        return  [
            'vendor/geerlingguy/drupal-vm/default.config.yml',
            'config.yml',
            'local.config.yml',
        ];
    }
}
