<?php

declare(strict_types=1);

namespace Pr0jectX\PxDrupalVM\ProjectX\Plugin\EnvironmentType\Commands;

use JoeStewart\Robo\Task\Vagrant\loadTasks as vagrantTasks;
use Pr0jectX\Px\Contracts\DatabaseCommandInterface;
use Pr0jectX\Px\ExecutableBuilder\Commands\MySql;
use Pr0jectX\Px\ExecutableBuilder\Commands\MySqlDump;
use Pr0jectX\Px\ExecutableBuilder\Commands\Scp;
use Pr0jectX\Px\ProjectX\Plugin\EnvironmentType\EnvironmentDatabase;
use Pr0jectX\Px\ProjectX\Plugin\EnvironmentType\EnvironmentTypeInterface;
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

    /**
     * Define the temporary directory.
     */
    protected const TEMP_DIRECTORY = '/tmp';

    /**
     * Define default database application.
     */
    protected const DEFAULT_DB_APPLICATION = 'sequel_ace';

    /**
     * Connect to the DrupalVM database using an external application.
     *
     * @param string|null $appName
     *   The DB application name e.g (sequel_pro, sequel_ace).
     */
    public function dbLaunch(string $appName = null): void
    {
        try {
            $appOptions = $this->getDatabaseApplicationOptions();

            if (empty($appOptions)) {
                throw new \RuntimeException(
                    'There are no supported database applications found!'
                );
            }

            if (!isset($appName)) {
                $appName = count($appOptions) === 1
                    ? array_key_first($appOptions)
                    : $this->askChoice(
                        'Select the database application to launch',
                        $appOptions,
                        array_key_exists(static::DEFAULT_DB_APPLICATION, $appOptions)
                            ? static::DEFAULT_DB_APPLICATION
                            : array_key_first($appOptions)
                    );
            }

            if (!isset($this->databaseApplicationInfo()[$appName])) {
                throw new \InvalidArgumentException(sprintf(
                    'The database application %s is invalid!',
                    $appName
                ));
            }
            $this->callDatabaseApplicationExecute($appName);
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Import the primary database to the environment.
     *
     * @param string $importFile
     *   The database import file.
     * @param array $opts
     *   The database import options.
     *
     * @option string $database
     *   Set the database table (defaults to primary database table).
     * @option string $username
     *   Set the database username (defaults to primary database username).
     * @option string $password
     *   Set the database password (defaults to primary database password).
     */
    public function dbImport(string $importFile, array $opts = [
        'database' => null,
        'username' => null,
        'password' => null
    ]): void
    {
        if (!file_exists($importFile)) {
            throw new \InvalidArgumentException(
                'The database import file does not exist.'
            );
        }
        $filename = basename($importFile);
        $targetPath = "{$this->getTempDirectory()}/{$filename}";

        if ($remoteImportFilepath = $this->scpFileToGuest($targetPath, $importFile)) {
            $database = $this->getEnvironmentPrimaryDatabase();

            $this->importRemoteDatabase(
                $database->getHost(),
                $opts['database'] ?? $database->getDatabase(),
                $opts['username'] ?? $database->getUsername(),
                $opts['password'] ?? $database->getPassword(),
                $remoteImportFilepath,
                $this->isFileGzipped($importFile)
            );
        }
    }

    /**
     * Export the primary database from the environment.
     *
     * @param string $exportDir
     *   The local export directory.
     * @param array $opts
     *   The database export options.
     *
     * @option string $database
     *   Set the database table (defaults to primary database table).
     * @option string $username
     *   Set the database username (defaults to primary database username).
     * @option string $password
     *   Set the database password (defaults to primary database password).
     * @option string $filename
     *   The database export filename.
     */
    public function dbExport(string $exportDir, array $opts = [
        'database' => null,
        'username' => null,
        'password' => null,
        'filename' => 'db'
    ]): void
    {
        if (!is_dir($exportDir)) {
            throw new \InvalidArgumentException(
                'The database export directory does not exist.'
            );
        }
        $database = $this->getEnvironmentPrimaryDatabase();

        $remotePath = $this->exportRemoteDatabase(
            $database->getHost(),
            $opts['database'] ?? $database->getDatabase(),
            $opts['username'] ?? $database->getUsername(),
            $opts['password'] ?? $database->getPassword(),
            $opts['filename']
        );

        if ($remotePath && $this->remoteFileExist($remotePath)) {
            $exportFilename = basename($remotePath);
            $targetPath = "{$exportDir}/{$exportFilename}";

            $this->scpFileToHost(
                $targetPath,
                $remotePath
            );
        }
    }

    /**
     * Define the database application information.
     *
     * @return array[]
     *   An array of the database application.
     */
    protected function databaseApplicationInfo(): array
    {
        return [
            'sequel_ace' => [
                'os' => 'Darwin',
                'label' => 'Sequel Ace',
                'location' => '/Applications/Sequel Ace.app',
                'execute' => function (string $appLocation) {
                    $this->openSequelDatabaseFile($appLocation);
                }
            ],
            'sequel_pro' => [
                'os' => 'Darwin',
                'label' => 'Sequel Pro',
                'location' => '/Applications/Sequel Pro.app',
                'execute' => function (string $appLocation) {
                    $this->openSequelDatabaseFile($appLocation);
                }
            ],
        ];
    }

    /**
     * Get the single database application definition.
     *
     * @param string $name
     *   The database application machine name.
     *
     * @return array
     *   An array of the database application definition.
     */
    protected function getDatabaseApplicationInfo(string $name): array
    {
        return $this->databaseApplicationInfo()[$name] ?? [];
    }

    /**
     * Get the applicable database applications.
     *
     * @return array
     *   An array of valid database applications.
     */
    protected function getDatabaseApplications(): array
    {
        return array_filter($this->databaseApplicationInfo(), static function ($appInfo) {
            return $appInfo['os'] === PHP_OS && file_exists($appInfo['location']);
        });
    }

    /**
     * Get the database application options.
     *
     * @return array
     *   An array of the database application options.
     */
    protected function getDatabaseApplicationOptions(): array
    {
        $options = [];

        foreach ($this->getDatabaseApplications() as $key => $info) {
            if (!isset($info['label'])) {
                continue;
            }
            $options[$key] = $info['label'];
        }

        return $options;
    }

    /**
     * Call the database application execute function.
     *
     * @param string $name
     *   The database application machine name.
     */
    protected function callDatabaseApplicationExecute(string $name): void
    {
        $dbInfo = $this->getDatabaseApplicationInfo($name);

        if (is_callable($dbInfo['execute'])) {
            call_user_func($dbInfo['execute'], $dbInfo['location']);
        }
    }

    /**
     * Open the sequel (pro/ace) application database file.
     *
     * @param string $appPath
     *   The the database application location path.
     */
    protected function openSequelDatabaseFile(string $appPath): void
    {
        $projectTempDir = PxApp::projectTempDir();

        $vagrantConfigs = DrupalVM::getVagrantConfigs();
        $sequelTempPath = "{$projectTempDir}/sequel.spf";

        $database = $this->getEnvironmentPrimaryDatabase();

        $writeResponse = $this->taskWriteToFile($sequelTempPath)
            ->text($this->sequelXmlFile())
            ->place('label', 'Project-X Database')
            ->place('ssh_port', 22)
            ->place('ssh_user', $vagrantConfigs['vagrant_user'])
            ->place('ssh_host', $vagrantConfigs['vagrant_hostname'])
            ->place('ssh_key', DrupalVM::getVagrantSshPrivateKey())
            ->place('host', $database->getHost())
            ->place('database', $database->getDatabase())
            ->place('username', $database->getUsername())
            ->place('password', $database->getPassword())
            ->place('port', 3306)
            ->run();

        if ($writeResponse->getExitCode() === 0) {
            $this->taskExec("open -a '{$appPath}' {$sequelTempPath}")->run();
        }
    }

    /**
     * Scp the file to the vagrant host machine.
     *
     * @param string $targetPath
     *   The fully qualified target path.
     * @param string $sourcePath
     *   The fully qualified source path.
     *
     * @return string|bool
     *   Return the target file path; otherwise false.
     */
    protected function scpFileToHost(string $targetPath, string $sourcePath)
    {
        $continue = true;

        if (file_exists($sourcePath)) {
            $continue = (bool) $this->confirm(
                sprintf('The %s file already exist, continue?', $targetPath),
                true
            );
        }

        if ($continue) {
            $scpCommand = (new Scp())
                ->identityFile(DrupalVM::getVagrantSshPrivateKey())
                ->source(DrupalVM::getVagrantSshPath($sourcePath))
                ->target($targetPath)
                ->build();

            $response = $this->taskExec($scpCommand)->run();

            if ($response->getExitCode() === 0) {
                $targetFilename = basename($targetPath);
                $this->success(
                    sprintf('The %s file has successfully been downloaded!', $targetFilename)
                );

                return $targetPath;
            }
        }

        return false;
    }

    /**
     * Scp the file to the vagrant guest machine.
     *
     * @param string $targetPath
     *   The fully qualified target path.
     * @param string $sourcePath
     *   The fully qualified source path.
     *
     * @return string|bool
     *   Return the target file path; otherwise false.
     */
    protected function scpFileToGuest(string $targetPath, string $sourcePath)
    {
        if (file_exists($sourcePath)) {
            $scpCommand = (new Scp())
                ->identityFile(DrupalVM::getVagrantSshPrivateKey())
                ->source($sourcePath)
                ->target(DrupalVM::getVagrantSshPath($targetPath))
                ->build();

            $results = $this->taskExec($scpCommand)->run();

            if ($results->getExitCode() === 0) {
                $targetFilename = basename($targetPath);
                $this->success(
                    sprintf('The %s file was successfully uploaded!', $targetFilename)
                );

                return $targetPath;
            }
        }

        return false;
    }

    /**
     * Import the remote database.
     *
     * @param string $host
     *   The database host.
     * @param string|null $database
     *   The database name.
     * @param string|null $username
     *   The database user.
     * @param string|null $password
     *   The database user password.
     * @param string $importFilepath
     *   The database import file path.
     * @param bool $importFileGzip
     *   A flag to state if the the database import file needs to be unzipped prior to import.
     *
     * @return bool
     *   Return true if the import was successful; otherwise false.
     */
    protected function importRemoteDatabase(
        string $host,
        string $database,
        string $username,
        string $password,
        string $importFilepath,
        bool $importFileGzip = false
    ): bool {
        if ($this->remoteFileExist($importFilepath)) {
            $mysqlCommand = (new MySql())
                ->host($host)
                ->user($username)
                ->password($password)
                ->database($database)
                ->build();

            $remoteCommand = !$importFileGzip
                ? "{$mysqlCommand} < {$importFilepath}"
                : "zcat {$importFilepath} | {$mysqlCommand}";

            $response = $this->taskVagrantSsh()
                ->command($remoteCommand)
                ->run();

            if ($response->getExitCode() === 0) {
                $this->success(
                    'The database was successfully imported!'
                );
                return true;
            }
        } else {
            $this->error(
                sprintf("The %s file doesn't exist within the system!", $importFilepath)
            );
        }

        return false;
    }

    /**
     * Export the remote database.
     *
     * @param string $host
     *   The database host.
     * @param string|null $database
     *   The database name.
     * @param string|null $username
     *   The database user.
     * @param string|null $password
     *   The database user password.
     * @param string $exportFilename
     *   The database export filename.
     *
     * @return string|bool
     *   The remote database file path; otherwise false.
     */
    protected function exportRemoteDatabase(
        string $host,
        string $database,
        string $username,
        string $password,
        string $exportFilename
    ) {
        $mysqlDump = (new MySqlDump())
            ->host($host)
            ->user($username)
            ->password($password)
            ->database($database)
            ->build();

        $dbFilename = "{$this->getTempDirectory()}/{$exportFilename}.sql.gz";

        $results = $this->taskVagrantSsh()
            ->command("{$mysqlDump} | gzip -c > {$dbFilename}")
            ->run();

        if ($results->getExitCode() === 0) {
            $this->success(
                'The database was successfully exported!'
            );

            return $dbFilename;
        }

        return false;
    }

    /**
     * Determine if a remote file exist.
     *
     * @param $remotePath
     *   The fully qualified remote file path.
     *
     * @return bool
     */
    protected function remoteFileExist($remotePath): bool
    {
        $results = $this->taskVagrantSsh()
            ->printOutput(false)
            ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
            ->command("if [[ -f \"{$remotePath}\"  ]]; then echo 1; else echo 0; fi")
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
    protected function isFileGzipped(string $filepath): bool
    {
        if (!file_exists($filepath)) {
            throw new \InvalidArgumentException(
                'The file path does not exist.'
            );
        }
        $contentType = mime_content_type($filepath);

        $mimeType = substr(
            $contentType,
            strpos($contentType, '/') + 1
        );

        return $mimeType == 'x-gzip' || $mimeType == 'gzip';
    }

    /**
     * Get the DrupalVM temporary directory.
     *
     * @return string
     *   The temporary directory.
     */
    protected function getTempDirectory(): string
    {
        return static::TEMP_DIRECTORY;
    }

    /**
     * Get the squeal configuration template contents.
     *
     * @return string
     *   The sequel configuration template contents.
     */
    protected function sequelXmlFile(): string
    {
        return DrupalVM::loadTemplateFile('sequel.xml');
    }

    /**
     * Get environment primary database.
     *
     * @return \Pr0jectX\Px\ProjectX\Plugin\EnvironmentType\EnvironmentDatabase
     */
    protected function getEnvironmentPrimaryDatabase(): EnvironmentDatabase
    {
        return PxApp::getEnvironmentInstance()->selectEnvDatabase(
            EnvironmentTypeInterface::ENVIRONMENT_DB_PRIMARY
        );
    }
}
