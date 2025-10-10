<?php
declare(strict_types=1);

namespace Ewebovky\StatusBundle\Service;

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
        $framework             = 'symfony';
        $frameworkVersion      = Kernel::VERSION;
        $frameworkMajorVersion = Kernel::MAJOR_VERSION . '.' . Kernel::MINOR_VERSION;

        $phpVersion      = PHP_VERSION;
        $phpMajorVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        $serverSoftware        = $_SERVER['SERVER_SOFTWARE'] ?? \php_sapi_name();
        $serverOperatingSystem = PHP_OS_FAMILY;
        $serverName            = \php_uname('n');
        $serverIp              = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $dbServer  = null;
        $dbVersion = null;
        try {
            $conn = $this->doctrine?->getConnection();
            if ($conn) {
                $dbServer  = $conn->getDatabasePlatform()->getName();
                $native    = $conn->getNativeConnection();
                $dbVersion = \is_object($native) && \defined($native::class.'::ATTR_SERVER_VERSION')
                    ? $native->getAttribute($native::ATTR_SERVER_VERSION)
                    : null;
            }
        } catch (\Throwable) {
            // necháme null – endpoint kvůli DB neselže
        }

        return [
            'framework'                     => $framework,
            'frameworkVersion'              => $frameworkVersion,
            'frameworkMajorVersion'         => $frameworkMajorVersion,
            'frameworkEndOfMaintenance'     => Kernel::END_OF_MAINTENANCE,
            'frameworkEndOfLife'            => Kernel::END_OF_LIFE,
            'environment'                   => $this->appEnv,
            'phpMajorVersion'               => $phpMajorVersion,
            'phpVersion'                    => $phpVersion,
            'serverSoftware'                => (string) $serverSoftware,
            'host'                          => $host,
            'serverOperatingSystem'         => $serverOperatingSystem,
            'serverOperatingSystemVersion'  => $this->getOs(),
            'serverName'                    => $serverName,
            'serverIp'                      => $serverIp,
            'dbServer'                      => $dbServer,
            'dbVersion'                     => $dbVersion,
            'generatedAt'                   => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    private function getOs(): string
    {
        $os_release = @\shell_exec('cat /etc/os-release');
        if ($os_release) {
            foreach (\explode("\n", (string)$os_release) as $line) {
                if (\str_starts_with($line, 'VERSION_ID=')) {
                    $version = \trim(\substr($line, \strlen('VERSION_ID=')), "\" \t\r\n");
                    return $version;
                }
            }
        }

        $lsb_release = @\shell_exec('lsb_release -ds');
        return $lsb_release ? \trim((string)$lsb_release) : '';
    }
}
