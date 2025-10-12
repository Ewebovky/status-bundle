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
        [$dbDriver, $dbVersion, $dbError] = $this->detectDatabase();

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
            'dbError'                       => $dbError,
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
     * Vrací [driver, version, error] – error je string nebo null.
     * Nikdy nevyhazuje výjimku (aby endpoint nespadl), ale nepolyká důležitá hlášení.
     *
     * @return array{0:?string,1:?string,2:?string}
     */
    private function detectDatabase(): array
    {
        $driver = null;
        $version = null;
        $error = null;

        try {
            // 1) Získej default connection (Registry v DoctrineBundle ji vždy má)
            $conn = $this->doctrine?->getConnection();
            if (!$conn) {
                return [null, null, 'ManagerRegistry is null (not injected)'];
            }

            // 2) Zjisti platformu a předvyplň "driver" – ať ho máme i při chybě verze
            //    DBAL 4 může vracet 'mysql' nebo 'mariadb' jako separátní platformu.
            $platform = $conn->getDatabasePlatform()->getName(); // 'mysql' | 'mariadb' | 'postgresql' | 'sqlite' | ...
            $driver = match ($platform) {
                'mysql'      => 'MySQL',
                'mariadb'    => 'MariaDB',
                'postgresql' => 'PostgreSQL',
                'sqlite'     => 'SQLite',
                default      => $platform,
            };

            // 3) Verzi zkusit získat co nejbezpečněji
            try {
                switch ($platform) {
                    case 'mysql':
                    case 'mariadb':
                        // Platí pro MySQL i MariaDB
                        $version = $conn->fetchOne('SELECT VERSION()');
                        // Když DBAL vrátí 'mysql', ale verze obsahuje 'MariaDB', přepíšeme driver
                        if ($platform === 'mysql' && $version && stripos($version, 'mariadb') !== false) {
                            $driver = 'MariaDB';
                        }
                        break;

                    case 'postgresql':
                        // SHOW server_version bývá dostupné, fallback přes SELECT version()
                        $version = $conn->fetchOne('SHOW server_version');
                        if (!$version) {
                            $version = $conn->fetchOne('SELECT version()');
                        }
                        break;

                    case 'sqlite':
                        $version = $conn->fetchOne('SELECT sqlite_version()');
                        break;

                    default:
                        // Univerzální pokus (na řadě backendů existuje)
                        $version = $conn->fetchOne('SELECT VERSION()');
                }
            } catch (\Throwable $e) {
                // Necháme driver, ale popíšeme, proč není verze
                $error = 'Version query failed: ' . $e->getMessage();
            }
        } catch (\Throwable $e) {
            // Pokud se nepodaří ani získat platformu/connection, vraťme chybu
            return [null, null, 'Connection/platform failed: ' . $e->getMessage()];
        }

        return [$driver, $version, $error];
    }
}
