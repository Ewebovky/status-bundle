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
        [$dbDriver, $dbVersion] = $this->detectDatabase();

        return [
            'framework'                     => 'symfony',
            'frameworkVersion'              => Kernel::VERSION,
            'frameworkMajorVersion'         => Kernel::MAJOR_VERSION . '.' . Kernel::MINOR_VERSION,
            'frameworkEndOfMaintenance'     => Kernel::END_OF_MAINTENANCE,                           // nechávám na tobě (pokud chceš, můžeme dopočítávat z mapy)
            'frameworkEndOfLife'            => Kernel::END_OF_LIFE,
            'environment'                   => $this->appEnv,
            'phpMajorVersion'               => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'phpVersion'                    => PHP_VERSION,
            'serverSoftware'                => (string) $_SERVER['SERVER_SOFTWARE'] ?? php_sapi_name(),
            'host'                          => $host,
            'serverOperatingSystem'         => $this->detectOs()['name'],
            'serverOperatingSystemVersion'  => $this->detectOs()['version'],
            'serverName'                    => php_uname('n'),
            'serverIp'                      => $this->detectServerIp(),
            'dbServer'                      => $dbDriver,
            'dbVersion'                     => $dbVersion,
            'generatedAt'                   => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    private function detectOs(): array
    {
        $result = ['name' => '', 'version' => ''];

        // 1) Linux: /etc/os-release
        if (is_readable('/etc/os-release')) {
            $data = file('/etc/os-release', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            $kv = [];
            foreach ($data as $line) {
                if ($line[0] === '#') continue;
                $pos = strpos($line, '=');
                if ($pos === false) continue;

                $k = substr($line, 0, $pos);
                $v = substr($line, $pos + 1);
                $v = trim($v);

                // odstraň uvozovky, pokud jsou
                if ((str_starts_with($v, '"') && str_ends_with($v, '"')) ||
                    (str_starts_with($v, "'") && str_ends_with($v, "'"))
                ) {
                    $v = substr($v, 1, -1);
                }
                $kv[$k] = $v;
            }

            if (!empty($kv['NAME']))    $result['name']    = $kv['NAME'];
            if (!empty($kv['VERSION'])) $result['version'] = $kv['VERSION'];

            // někteří výrobci VERSION neuvádí → vezmi VERSION_ID jako rozumný fallback
            if ($result['version'] === '' && !empty($kv['VERSION_ID'])) {
                $result['version'] = $kv['VERSION_ID'];
            }
        }

        // 2) Fallback: lsb_release (ne všude k dispozici)
        if ($result['name'] === '') {
            $name = @shell_exec('lsb_release -si 2>/dev/null');
            if ($name) $result['name'] = trim($name);
        }
        if ($result['version'] === '') {
            $ver = @shell_exec('lsb_release -sr 2>/dev/null');
            if ($ver) $result['version'] = trim($ver);
        }

        // 3) Poslední záchrana: uname (pro nelinuxové systémy, kontejnery, BSD ap.)
        if ($result['name'] === '')    $result['name'] = php_uname('s'); // např. "Darwin", "FreeBSD", "Linux"
        if ($result['version'] === '') $result['version'] = php_uname('r');

        return $result;
    }


    private function detectServerIp(): ?string
    {
        // 1) klasika ze serveru (pokud webový request a NGINX to předává)
        if (!empty($_SERVER['SERVER_ADDR'])) {
            return $_SERVER['SERVER_ADDR'];
        }

        // 2) za proxy – vlastní hlavička, pokud si ji nastavíš
        if (!empty($_SERVER['HTTP_X_SERVER_ADDR'])) {
            return $_SERVER['HTTP_X_SERVER_ADDR'];
        }

        // 3) Docker/K8s hostname → DNS
        $host = getenv('HOSTNAME') ?: gethostname();
        if ($host) {
            $ip = gethostbyname($host);
            if ($ip && $ip !== $host) {
                return $ip;
            }
        }

        // 4) „odchozí“ IP přes UDP socket (spolehlivý fallback)
        $s = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
        if ($s) {
            $name = stream_socket_get_name($s, false);
            fclose($s);
            if ($name) {
                return explode(':', $name)[0];
            }
        }

        return null;
    }

    /**
     * Detekuje typ a verzi databáze.
     * @return array{0:?string,1:?string} [driver, version]
     */
    private function detectDatabase(): array
    {
        $driver = null;
        $version = null;

        try {
            $conn = $this->doctrine?->getConnection();
            if (!$conn) {
                return [null, null];
            }

            $platform = $conn->getDatabasePlatform()->getName(); // mysql, postgresql, sqlite, ...
            $params   = $conn->getParams();
            $driverId = $params['driver'] ?? null;               // pdo_mysql, mysqli, pdo_pgsql, ...

            // Určení typu
            $driver = match ($platform) {
                'mysql'      => 'MySQL',
                'postgresql' => 'PostgreSQL',
                'sqlite'     => 'SQLite',
                default      => $platform ?: $driverId,
            };

            // Získání verze - univerzálně přes SQL dotaz
            switch ($platform) {
                case 'mysql':
                    $version = $conn->fetchOne('SELECT VERSION()');
                    if ($version && stripos($version, 'mariadb') !== false) {
                        $driver = 'MariaDB';
                    }
                    break;

                case 'postgresql':
                    $version = $conn->fetchOne('SHOW server_version');
                    if (!$version) {
                        $version = $conn->fetchOne('SELECT version()');
                    }
                    break;

                case 'sqlite':
                    $version = $conn->fetchOne('SELECT sqlite_version()');
                    break;

                default:
                    $version = $conn->fetchOne('SELECT VERSION()');
                    break;
            }
        } catch (\Throwable $e) {
            // necháme null - endpoint nesmí kvůli DB selhat
            // případně log: error_log('DB detect failed: '.$e->getMessage());
        }

        return [$driver, $version];
    }
}
