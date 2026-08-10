<?php

declare(strict_types=1);

namespace Ewebovky\StatusBundle\Service;

use Composer\InstalledVersions;
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
        $os      = $this->detectOs();
        $opcache = $this->detectOpcache();

        return [
            'bundleVersion'                 => $this->detectBundleVersion(),
            'framework'                     => 'symfony',
            'frameworkVersion'              => Kernel::VERSION,
            'frameworkMajorVersion'         => Kernel::MAJOR_VERSION . '.' . Kernel::MINOR_VERSION,
            // Symfony vrací obě data jako řetězec ve tvaru „11/2027“.
            'frameworkEndOfMaintenance'     => Kernel::END_OF_MAINTENANCE,
            'frameworkEndOfLife'            => Kernel::END_OF_LIFE,
            'environment'                   => $this->appEnv,
            'phpMajorVersion'               => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'phpVersion'                    => PHP_VERSION,
            'phpMemoryLimit'                => (string) ini_get('memory_limit'),
            'phpUploadMaxFilesize'          => (string) ini_get('upload_max_filesize'),
            'phpPostMaxSize'                => (string) ini_get('post_max_size'),
            'opcacheEnabled'                => $opcache['enabled'],
            'opcacheMemoryUsedPercent'      => $opcache['memoryUsedPercent'],
            'opcacheHitRate'                => $opcache['hitRate'],
            'opcacheOomRestarts'            => $opcache['oomRestarts'],
            'opcacheHashRestarts'           => $opcache['hashRestarts'],
            'opcacheManualRestarts'         => $opcache['manualRestarts'],
            'serverSoftware'                => (string) ($_SERVER['SERVER_SOFTWARE'] ?? php_sapi_name()),
            'host'                          => $host,
            'serverOperatingSystem'         => $os['name'],
            'serverOperatingSystemVersion'  => $os['version'],
            'serverName'                    => php_uname('n'),
            'serverIp'                      => $this->detectServerIp(),
            'dbServer'                      => $dbDriver,
            'dbVersion'                     => $dbVersion,
            'dbError'                       => $dbError,
            'phpExtensions'                 => $this->phpExtensions(),
            'generatedAt'                   => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /**
     * Verze bundlu podle Composeru. Vlastní konstanta by se dřív nebo později
     * rozešla s composer.json.
     */
    private function detectBundleVersion(): ?string
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            return InstalledVersions::getPrettyVersion('ewebovky/status-bundle');
        } catch (\Throwable) {
            // Balíček není v installed.php — typicky při vývoji mimo Composer.
            return null;
        }
    }

    /**
     * @return array{
     *     enabled:bool, memoryUsedPercent:?float, hitRate:?float,
     *     oomRestarts:?int, hashRestarts:?int, manualRestarts:?int
     * }
     */
    private function detectOpcache(): array
    {
        $prazdne = [
            'enabled'           => false,
            'memoryUsedPercent' => null,
            'hitRate'           => null,
            'oomRestarts'       => null,
            'hashRestarts'      => null,
            'manualRestarts'    => null,
        ];

        if (!function_exists('opcache_get_status')) {
            return $prazdne;
        }

        try {
            // false = bez seznamu cachovaných skriptů, ten bývá obrovský
            $status = @opcache_get_status(false);
        } catch (\Throwable) {
            return $prazdne;
        }

        // false vrací i opcache.restrict_api, když volání nepovolí
        if (!is_array($status)) {
            return $prazdne;
        }

        $memory = $status['memory_usage'] ?? [];
        $stats  = $status['opcache_statistics'] ?? [];

        $used   = (float) ($memory['used_memory'] ?? 0);
        $free   = (float) ($memory['free_memory'] ?? 0);
        $wasted = (float) ($memory['wasted_memory'] ?? 0);
        $celkem = $used + $free + $wasted;

        return [
            'enabled'           => (bool) ($status['opcache_enabled'] ?? false),
            'memoryUsedPercent' => $celkem > 0 ? round($used / $celkem * 100, 1) : null,
            'hitRate'           => isset($stats['opcache_hit_rate']) ? round((float) $stats['opcache_hit_rate'], 1) : null,
            // oom = došla paměť, hash = došly sloty; obojí znamená malý opcache
            'oomRestarts'       => isset($stats['oom_restarts']) ? (int) $stats['oom_restarts'] : null,
            'hashRestarts'      => isset($stats['hash_restarts']) ? (int) $stats['hash_restarts'] : null,
            'manualRestarts'    => isset($stats['manual_restarts']) ? (int) $stats['manual_restarts'] : null,
        ];
    }

    /**
     * Názvy se normalizují a řadí, aby bylo pořadí stabilní — jinak by se
     * mezi requesty měnil ETag.
     *
     * @return list<string>
     */
    private function phpExtensions(): array
    {
        $rozsireni = array_map('strtolower', get_loaded_extensions());
        sort($rozsireni);

        return array_values(array_unique($rozsireni));
    }

    /** @return array{name:string,version:string} */
    private function detectOs(): array
    {
        $result = ['name' => '', 'version' => ''];

        // 1) Linux: /etc/os-release
        if (is_readable('/etc/os-release')) {
            // file() vrací false, když čtení mezi kontrolou a otevřením selže
            // (práva, race condition) — foreach nad false je v PHP 8 fatální chyba.
            $data = @file('/etc/os-release', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

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
            $result['name'] = $this->lsbRelease('-si') ?? '';
        }
        if ($result['version'] === '') {
            $result['version'] = $this->lsbRelease('-sr') ?? '';
        }

        // 3) Poslední záchrana: uname (pro nelinuxové systémy, kontejnery, BSD ap.)
        if ($result['name'] === '')    $result['name'] = php_uname('s'); // např. "Darwin", "FreeBSD", "Linux"
        if ($result['version'] === '') $result['version'] = php_uname('r');

        return $result;
    }


    /**
     * Spustí `lsb_release` a vrátí oříznutý výstup, nebo null.
     *
     * shell_exec bývá na sdílených hostinzích v disable_functions. Od PHP 8 je
     * volání zakázané funkce Error, který operátor @ neztlumí — bez téhle
     * ochrany by endpoint vrátil 500 místo JSONu. function_exists() vrací pro
     * zakázanou funkci false; catch je pojistka pro open_basedir a spol.
     */
    private function lsbRelease(string $flag): ?string
    {
        if (!\function_exists('shell_exec')) {
            return null;
        }

        try {
            $out = @shell_exec('lsb_release ' . $flag . ' 2>/dev/null');
        } catch (\Throwable) {
            return null;
        }

        if (!\is_string($out)) {
            return null;
        }

        $out = trim($out);

        return $out === '' ? null : $out;
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
                return $this->extractIp($name);
            }
        }

        return null;
    }

    /**
     * Odřízne port z „adresa:port“ vráceného ze stream_socket_get_name().
     *
     * IPv4 se chová stejně jako dřív (explode na první dvojtečce dával u
     * „192.168.1.5:54321“ tentýž výsledek). Navíc zvládne IPv6 v obou tvarech —
     * „[2001:db8::1]:54321“ i „2001:db8::1:54321“ —, kde by dělení podle první
     * dvojtečky vrátilo jen „2001“.
     *
     * IPv6 bez závorek je nejednoznačné (poslední skupina může být port i část
     * adresy), proto se kandidát ověřuje přes FILTER_VALIDATE_IP.
     */
    private function extractIp(string $name): ?string
    {
        // [2001:db8::1]:54321
        if (str_starts_with($name, '[') && ($end = strpos($name, ']')) !== false) {
            $ip = substr($name, 1, $end - 1);

            return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
        }

        // 192.168.1.5:54321 i 2001:db8::1:54321
        $pos = strrpos($name, ':');
        if ($pos !== false) {
            $candidate = substr($name, 0, $pos);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        // adresa bez portu
        return filter_var($name, FILTER_VALIDATE_IP) ? $name : null;
    }

    /**
     * Detekuje typ a verzi databáze pro DBAL 3/4 bez volání getName().
     * Vrací [driver, version, error].
     *
     * @return array{0:?string,1:?string,2:?string}
     */
    private function detectDatabase(): array
    {
        $driver = null;
        $version = null;
        $error = null;
    
        try {
            $conn = $this->doctrine?->getConnection();
            if (!$conn) {
                return [null, null, 'ManagerRegistry is null (not injected)'];
            }
    
            // 1) Pokus #1: zkusíme verzi přes SQL – funguje i na DBAL 4
            try {
                // MySQL/MariaDB – funguje na obou
                $version = $conn->fetchOne('SELECT VERSION()');
            } catch (\Throwable) {
                // PostgreSQL (někdy je lepší SHOW server_version)
                try {
                    $version = $conn->fetchOne('SHOW server_version') ?: $conn->fetchOne('SELECT version()');
                } catch (\Throwable) {
                    // SQLite
                    try {
                        $version = $conn->fetchOne('SELECT sqlite_version()');
                    } catch (\Throwable $e) {
                        $error = 'Version query failed: ' . $e->getMessage();
                    }
                }
            }
    
            // 2) Urči vendor (driver) bez getName()
            //    a) z názvu třídy platformy (DBAL 4 má např. MariaDB1010Platform)
            $platformClass = ($p = $conn->getDatabasePlatform()) ? $p::class : null;
            $platformGuess = null;
            if ($platformClass) {
                $lc = strtolower($platformClass);
                if (str_contains($lc, 'mariadb'))      { $platformGuess = 'MariaDB'; }
                elseif (str_contains($lc, 'mysql'))    { $platformGuess = 'MySQL'; }
                elseif (str_contains($lc, 'postgres')) { $platformGuess = 'PostgreSQL'; }
                elseif (str_contains($lc, 'sqlite'))   { $platformGuess = 'SQLite'; }
            }
    
            //    b) pokud je k dispozici verze, upřesníme MariaDB vs MySQL
            if ($version && stripos($version, 'mariadb') !== false) {
                $driver = 'MariaDB';
            } elseif ($platformGuess) {
                $driver = $platformGuess;
            } else {
                //    c) poslední fallback: connection params (DBAL 3/4)
                $params   = $conn->getParams();
                $driverId = $params['driver'] ?? null; // pdo_mysql / mysqli / pdo_pgsql / pdo_sqlite …
                $driver = match (true) {
                    is_string($driverId) && str_contains($driverId, 'mysql')    => 'MySQL',
                    is_string($driverId) && str_contains($driverId, 'mariadb')  => 'MariaDB',
                    is_string($driverId) && str_contains($driverId, 'pgsql')    => 'PostgreSQL',
                    is_string($driverId) && str_contains($driverId, 'sqlite')   => 'SQLite',
                    default => $driverId,
                };
            }
    
        } catch (\Throwable $e) {
            return [null, null, 'Connection/platform failed: ' . $e->getMessage()];
        }
    
        return [$driver, $version, $error];
    }

}
