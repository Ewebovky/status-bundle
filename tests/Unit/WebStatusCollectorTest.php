<?php
declare(strict_types=1);

namespace Ewebovky\StatusBundle\Tests\Unit;

use Ewebovky\StatusBundle\Service\WebStatusCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class WebStatusCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        // Ať detekce IP skončí hned na první větvi a testy nesahají na síť.
        $_SERVER['SERVER_ADDR'] = '127.0.0.1';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['SERVER_ADDR']);
    }

    public function testCollectReturnsExpectedKeys(): void
    {
        $data = (new WebStatusCollector())->collect('muj-web.cz');

        $ocekavaneKlice = [
            'bundleVersion', 'framework', 'frameworkVersion', 'frameworkMajorVersion',
            'frameworkEndOfMaintenance', 'frameworkEndOfLife', 'environment',
            'phpMajorVersion', 'phpVersion', 'phpMemoryLimit', 'phpUploadMaxFilesize',
            'phpPostMaxSize', 'opcacheEnabled', 'opcacheMemoryUsedPercent', 'opcacheHitRate',
            'opcacheOomRestarts', 'opcacheHashRestarts', 'opcacheManualRestarts',
            'serverSoftware', 'host', 'serverOperatingSystem', 'serverOperatingSystemVersion',
            'serverName', 'serverIp', 'dbServer', 'dbVersion', 'dbError', 'phpExtensions',
            'generatedAt',
        ];

        self::assertSame($ocekavaneKlice, array_keys($data));
    }

    public function testCollectPassesHostThrough(): void
    {
        $data = (new WebStatusCollector())->collect('muj-web.cz');

        self::assertSame('muj-web.cz', $data['host']);
        self::assertSame('symfony', $data['framework']);
    }

    public function testEnvironmentComesFromConstructor(): void
    {
        $data = (new WebStatusCollector(null, 'staging'))->collect('muj-web.cz');

        self::assertSame('staging', $data['environment']);
    }

    public function testWithoutDoctrineReportsErrorInsteadOfFailing(): void
    {
        $data = (new WebStatusCollector())->collect('muj-web.cz');

        self::assertNull($data['dbServer']);
        self::assertNull($data['dbVersion']);
        self::assertIsString($data['dbError']);
    }

    /**
     * A1: na hostinzích se zakázaným shell_exec nesmí detekce OS shodit endpoint.
     */
    public function testOsDetectionAlwaysReturnsStrings(): void
    {
        $data = (new WebStatusCollector())->collect('muj-web.cz');

        self::assertIsString($data['serverOperatingSystem']);
        self::assertIsString($data['serverOperatingSystemVersion']);
        self::assertNotSame('', $data['serverOperatingSystem']);
    }

    /**
     * A15: pořadí rozšíření musí být stabilní, jinak by se mezi requesty měnil ETag.
     */
    public function testExtensionListIsSortedAndStable(): void
    {
        $prvni  = (new WebStatusCollector())->collect('muj-web.cz')['phpExtensions'];
        $druhy  = (new WebStatusCollector())->collect('muj-web.cz')['phpExtensions'];
        $serazene = $prvni;
        sort($serazene);

        self::assertSame($serazene, $prvni, 'Seznam rozšíření není seřazený.');
        self::assertSame($prvni, $druhy, 'Seznam rozšíření není mezi voláními stabilní.');
        self::assertContains('core', $prvni);
    }

    public function testOpcacheFieldsHaveExpectedTypes(): void
    {
        $data = (new WebStatusCollector())->collect('muj-web.cz');

        self::assertIsBool($data['opcacheEnabled']);

        foreach (['opcacheMemoryUsedPercent', 'opcacheHitRate'] as $klic) {
            self::assertTrue($data[$klic] === null || is_float($data[$klic]), $klic);
        }

        foreach (['opcacheOomRestarts', 'opcacheHashRestarts', 'opcacheManualRestarts'] as $klic) {
            self::assertTrue($data[$klic] === null || is_int($data[$klic]), $klic);
        }
    }

    public function testPhpLimitsAreStrings(): void
    {
        $data = (new WebStatusCollector())->collect('muj-web.cz');

        self::assertIsString($data['phpMemoryLimit']);
        self::assertIsString($data['phpUploadMaxFilesize']);
        self::assertIsString($data['phpPostMaxSize']);
    }

    public function testGeneratedAtIsIso8601(): void
    {
        $data = (new WebStatusCollector())->collect('muj-web.cz');

        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $data['generatedAt'],
        );
    }

    public function testServerIpPrefersServerAddr(): void
    {
        $_SERVER['SERVER_ADDR'] = '10.20.30.40';

        self::assertSame('10.20.30.40', (new WebStatusCollector())->collect('muj-web.cz')['serverIp']);
    }

    /**
     * A3: extrakce IP z „adresa:port“. IPv4 se musí chovat stejně jako před opravou.
     */
    #[DataProvider('adresySocketu')]
    public function testExtractIp(string $vstup, ?string $ocekavano): void
    {
        $metoda = new ReflectionMethod(WebStatusCollector::class, 'extractIp');

        self::assertSame($ocekavano, $metoda->invoke(new WebStatusCollector(), $vstup));
    }

    /** @return iterable<string,array{string,?string}> */
    public static function adresySocketu(): iterable
    {
        yield 'IPv4 s portem'        => ['192.168.1.5:54321', '192.168.1.5'];
        yield 'IPv4 bez portu'       => ['192.168.1.5', '192.168.1.5'];
        yield 'IPv4 hranicni'        => ['255.255.255.255:65535', '255.255.255.255'];
        yield 'IPv4 nuly'            => ['0.0.0.0:0', '0.0.0.0'];
        yield 'IPv6 v zavorkach'     => ['[2001:db8::1]:54321', '2001:db8::1'];
        yield 'IPv6 bez zavorek'     => ['2001:db8::1:54321', '2001:db8::1'];
        yield 'IPv6 loopback'        => ['::1:54321', '::1'];
        yield 'IPv6 bez portu'       => ['2001:db8::1', '2001:db8::1'];
        yield 'nesmysl'              => ['neni-adresa', null];
    }
}
