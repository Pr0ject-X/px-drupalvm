<?php

declare(strict_types=1);

namespace Pr0jectX\PxDrupalVM\ProjectX\Plugin\EnvironmentType\Commands;

use JoeStewart\Robo\Task\Vagrant\loadTasks as vagrantTasks;
use Pr0jectX\Px\Contracts\DatabaseCommandInterface;
use Pr0jectX\Px\ExecutableBuilder\Commands\MySql;
use Pr0jectX\Px\ExecutableBuilder\Commands\MySqlDump;
use Pr0jectX\Px\ExecutableBuilder\Commands\Scp;
use Pr0jectX\Px\ProjectX\Plugin\PluginCommandTaskBase;
use Pr0jectX\Px\PxApp;
use Pr0jectX\PxDrupalVM\DrupalVM;
use Robo\Contract\VerbosityThresholdInterface;

/**
 * Define the DrupalVM database commands.
 */
class DatabaseCommands extends PluginCommandTaskBase implements DatabaseCommandInterface
{
    use vagrantTasks;

    const TEMP_DIRECTORY = '/tmp';

    const SEQUEL_PRO_APP = '/Applications/Sequel Pro.app';

    /**
     * Connect to the DrupalVM database using Sequel Pro.
     */
    public function dbLaunch()
    {
        if (strtolower(php_uname('s')) !== 'darwin') {
          throw new \RuntimeException(
              'The Sequel Pro application is only available on Mac.'
          );
        }

        if (file_exists(static::SEQUEL_PRO_APP)) {
            $projectTempDir = PxApp::projectTempDir();

            $dbConfigs = DrupalVM::getDatabaseConfigs();
            $vagrantConfigs = DrupalVM::getVagrantConfigs();
            $sequelProTempPath = "{$projectTempDir}/sequelpro.spf";

            $writeResponse = $this->taskWriteToFile($sequelProTempPath)
                ->text($this->sequelproXmlFile())
                ->place('label', 'DrupalVM')
                ->place('ssh_port', 22)
                ->place('ssh_user', $vagrantConfigs['vagrant_user'])
                ->place('ssh_host', $vagrantConfigs['vagrant_hostname'])
                ->place('host', $dbConfigs['drupal_db_host'])
                ->place('database', $dbConfigs['drupal_db_name'])
                ->place('username', $dbConfigs['drupal_db_user'])
                ->place('password', $dbConfigs['drupal_db_password'])
                ->place('port', 3306)
                ->run();

            if ($writeResponse->getExitCode() === 0) {
                $this->taskExec("open {$sequelProTempPath}")->run();
            }
        } else {
            throw new \RuntimeException(
                sprintf('Unable to locate the Sequel Pro application at %s.', static::SEQUEL_PRO_APP)
            );
        }
    }

    /**
     * Import the database to the DrupalVM environment.
     *
     * @param string $source_file
     *   The database source file.
     */
    public function dbImport(string $source_file)
    {
        if (!file_exists($source_file)) {
            throw new \InvalidArgumentException(
                'The source database file does not exist.'
            );
        }
        $filename = basename($source_file);
        $targetPath = "{$this->getTempDirectory()}/{$filename}";

        if ($remotePath = $this->scpFileToGuest($targetPath, $source_file)) {
            $this->importRemoteDatabase(
                $remotePath, $this->isFileGzipped($source_file)
            );
        }
    }

    /**
     * Export the database from the DrupalVM environment.
     *
     * @param string $export_dir
     *   The local export directory.
     * @param array $opts
     * @option $filename
     *   The filename of the database export.
     */
    public function dbExport(string $export_dir, array $opts = ['filename' => 'db'])
    {
        if (!is_dir($export_dir)) {
            throw new \InvalidArgumentException(
                'The export directory does not exist.'
            );
        }

        if ($remotePath = $this->exportRemoteDatabase($opts['filename'])) {
            if ($this->remoteFileExist($remotePath)) {
                $exportFilename = basename($remotePath);
                $targetPath = "{$export_dir}/{$exportFilename}";

                $this->scpFileToHost(
                    $targetPath, $remotePath
                );
            }
        }
    }

    /**
     * Scp the file to the vagrant host machine.
     *
     * @param string $target_path
     *   The fully qualified target path.
     * @param string $source_path
     *   The fully qualified source path.
     *
     * @return string|bool
     *   Return the target file path; otherwise false.
     */
    protected function scpFileToHost(string $target_path, string $source_path)
    {
        $continue = true;

        if (file_exists($source_path)) {
            $continue = (bool) $this->confirm(
                sprintf('The %s file already exist, continue?', $target_path), true
            );
        }

        if ($continue) {
            $scpCommand = (new Scp())
                ->identityFile("{$_SERVER['HOME']}/.vagrant.d/insecure_private_key")
                ->source(DrupalVM::getVagrantSshPath($source_path))
                ->target($target_path)
                ->build();
            
            $response = $this->taskExec($scpCommand)->run();

            if ($response->getExitCode() === 0) {
                $targetFilename = basename($target_path);
                $this->success(
                    sprintf('The %s file has successfully been downloaded!', $targetFilename)
                );
                return $target_path;
            }
        }

        return false;
    }

    /**
     * Scp the file to the vagrant guest machine.
     *
     * @param string $target_path
     *   The fully qualified target path.
     * @param string $source_path
     *   The fully qualified source path.
     *
     * @return string|bool
     *   Return the target file path; otherwise false.
     */
    protected function scpFileToGuest(string $target_path, string $source_path)
    {
        if (file_exists($source_path)) {
            $scpCommand = (new Scp())
                ->identityFile("{$_SERVER['HOME']}/.vagrant.d/insecure_private_key")
                ->source($source_path)
                ->target(DrupalVM::getVagrantSshPath($target_path))
                ->build();

            $results = $this->taskExec($scpCommand)->run();

            if ($results->getExitCode() === 0) {
                $targetFilename = basename($target_path);
                $this->success(
                    sprintf('The %s file was successfully uploaded!', $targetFilename)
                );
                return $target_path;
            }
        }

        return false;
    }

    /**
     * Import the remote database.
     *
     * @param string $target_filepath
     *   The target file path to the database file.
     * @param bool $gzip
     *   A flag to state if the the file needs to be gzipped prior to import.
     *
     * @return bool
     *   Return true if the import was successful; otherwise false.
     */
    protected function importRemoteDatabase(string $target_filepath, bool $gzip = false) : bool
    {
        if ($this->remoteFileExist($target_filepath)) {
            $dbConfigs = DrupalVM::getDatabaseConfigs();

            $mysqlCommand = (new MySql())
                ->host($dbConfigs['drupal_db_host'])
                ->user($dbConfigs['drupal_db_user'])
                ->password($dbConfigs['drupal_db_password'])
                ->database($dbConfigs['drupal_db_name'])
                ->build();

            $remoteCommand = !$gzip
                ? "{$mysqlCommand} < {$target_filepath}"
                : "zcat {$target_filepath} | {$mysqlCommand}";

            $response = $this->taskVagrantSsh()
                ->command($remoteCommand)
                ->run();

            if ($response->getExitCode() === 0) {
                $this->success(
                    'The database was successfully imported into the DrupalVM!'
                );
                return true;
            }
        } else {
            $this->error(
                sprintf("The %s file doesn't exist within the DrupalVM environment!", $target_filepath)
            );
        }

        return false;
    }

    /**
     * Export the remote database.
     *
     * @param string $filename
     *   The exported database filename.
     *
     * @return string|bool
     *   The remote database file path; otherwise false.
     */
    protected function exportRemoteDatabase(string $filename)
    {
        $dbConfigs = DrupalVM::getDatabaseConfigs();

        $mysqlDump = (new MySqlDump())
            ->host($dbConfigs['drupal_db_host'])
            ->user($dbConfigs['drupal_db_user'])
            ->password($dbConfigs['drupal_db_password'])
            ->database($dbConfigs['drupal_db_name'])
            ->build();

        $dbFilename = "{$this->getTempDirectory()}/{$filename}.sql.gz";

        $results = $this->taskVagrantSsh()
            ->command("{$mysqlDump} | gzip -c > {$dbFilename}")
            ->run();

        if ($results->getExitCode() === 0) {
            $this->success(
                'The DrupalVM database was successfully exported!'
            );
            return $dbFilename;
        }

        return false;
    }

    /**
     * Determine if a remote file exist.
     *
     * @param $remote_path
     *   The fully qualified remote file path.
     *
     * @return bool
     */
    protected function remoteFileExist($remote_path)
    {
        $results = $this->taskVagrantSsh()
            ->printOutput(false)
            ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
            ->command("if [[ -f \"{$remote_path}\"  ]]; then echo 1; else echo 0; fi")
            ->run();

        if ($results->getExitCode() === 0) {
            return (bool) $results->getMessage();
        }

        return false;
    }

    /**
     * Determine if the file is gzipped.
     *
     * @param string $filepath
     *   The fully qualified path to the file.
     *
     * @return bool
     */
    protected function isFileGzipped(string $filepath) : bool
    {
        if (!file_exists($filepath)) {
            throw new \InvalidArgumentException(
                'The file path does not exist.'
            );
        }
        $content_type = mime_content_type($filepath);

        $mime_type = substr(
            $content_type, strpos($content_type, '/') + 1
        );

        return $mime_type == 'x-gzip' || $mime_type == 'gzip';
    }

    /**
     * Get the DrupalVM temporary directory.
     *
     * @return string
     *   The temporary directory.
     */
    protected function getTempDirectory() : string
    {
        return static::TEMP_DIRECTORY;
    }

    /**
     * Get the squeal pro configuration template contents.
     *
     * @return string
     *   The sequel pro configuration template contents.
     */
    protected function sequelproXmlFile() : string
    {
        return DrupalVM::loadTemplateFile('sequelpro.xml');
    }
}
