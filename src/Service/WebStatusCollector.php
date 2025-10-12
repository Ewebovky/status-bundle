<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpKernel\Kernel;

final class WebStatusCollector
{
    public function __construct(
        private readonly ?ManagerRegistry $doctrine = null,
        private readonly string $appEnv = 'prod',
    ) {}

    /** @return array<string,mixed> */
    public function collect(string $host): array
    {
        $framework              = 'symfony';
        $frameworkVersion       = Kernel::VERSION;                          // např. 7.1.x
        $frameworkMajorVersion  = Kernel::MAJOR_VERSION . '.' . Kernel::MINOR_VERSION;
        //$frameworkMajorVersion  = preg_replace('/^(\d+\.\d+).*/', '$1', $frameworkVersion) ?? null;

        $phpVersion             = PHP_VERSION;                                     // např. 8.4.13
        $phpMajorVersion        = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;         // např. 8.4

        $serverSoftware         = $_SERVER['SERVER_SOFTWARE'] ?? php_sapi_name();
        //$serverOperatingSystem  = PHP_OS_FAMILY;                              // např. Darwin
        $serverName             = php_uname('n');
        $serverIp               = isset($_SERVER["SERVER_ADDR"]) ? $_SERVER["SERVER_ADDR"] : null;

        $dbServer = null;
        $dbVersion = null;
        try {
            $conn = $this->doctrine?->getConnection();
            if ($conn) {
                $dbServer  = $conn->getDatabasePlatform()->getName();       // mysql, mariadb, postgresql, ...
                $dbVersion = $conn->getNativeConnection()->getAttribute($conn->getNativeConnection()::ATTR_SERVER_VERSION);
            }
        } catch (\Throwable) {
            // necháme null - endpoint neselže kvůli DB
        }

        return [
            'framework'                     => $framework,
            'frameworkVersion'              => $frameworkVersion,
            'frameworkMajorVersion'         => $frameworkMajorVersion,
            'frameworkEndOfMaintenance'     => Kernel::END_OF_MAINTENANCE,                           // nechávám na tobě (pokud chceš, můžeme dopočítávat z mapy)
            'frameworkEndOfLife'            => Kernel::END_OF_LIFE,
            'environment'                   => $this->appEnv,
            'phpMajorVersion'               => $phpMajorVersion,
            'phpVersion'                    => $phpVersion,
            'serverSoftware'                => (string) $serverSoftware,
            'host'                          => $host,
            'serverOperatingSystem'         => $this->getOs()['name'],
            'serverOperatingSystemVersion'  => $this->getOs()['version'],
            'serverName'                    => $serverName,
            'serverIp'                      => $serverIp,
            'dbServer'                      => $dbServer,
            'dbVersion'                     => $dbVersion,
            'generatedAt'                   => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    private function getOs(): array
    {
        $os_release = shell_exec('cat /etc/os-release');

        $os = ['name' => '', 'version' => ''];

        if ($os_release) {
            // Rozdělí výstup podle řádků
            $lines = explode("\n", $os_release);
            foreach ($lines as $line) {
                if (strpos($line, 'NAME=') === 0) {
                    $name = trim($line, 'NAME=');
                    $name = trim($name, '"'); // Odstraní případné uvozovky
                    $os['name'] = $name;
                }
                if (strpos($line, 'VERSION=') === 0) {
                    $version = trim($line, 'VERSION=');
                    $version = trim($version, '"'); // Odstraní případné uvozovky
                    $os['version'] = $version;
                }
            }
        } else {
            // Pokud /etc/os-release není k dispozici, zkusí lsb_release
            $lsb_release = shell_exec('lsb_release -ds');
            if ($lsb_release) {
                $os['name'] = trim($lsb_release, '"');
            }
        }

        if ($os['name'] == '') {
            $os['name'] = php_uname('s');
        }
        if ($os['version'] == '') {
            $os['version'] = php_uname('r');
        }

        return $os;
    }
}
