<?php
declare(strict_types=1);

namespace Ewebovky\StatusBundle\Tests\Functional;

use Ewebovky\StatusBundle\Tests\Fixtures\TestKernel;
use Ewebovky\StatusBundle\Tests\Fixtures\UklidiHandlery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StatusEndpointTest extends TestCase
{
    use UklidiHandlery;

    private const TOKEN = 'tajny-token-123';

    protected function setUp(): void
    {
        $this->zapamatujHandlery();
    }

    protected function tearDown(): void
    {
        $this->obnovHandlery();
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $bundleConfig
     */
    private function volej(string $uri, array $headers = [], array $bundleConfig = ['token' => self::TOKEN]): Response
    {
        $kernel  = new TestKernel($bundleConfig);
        $request = Request::create($uri, 'GET');

        foreach ($headers as $jmeno => $hodnota) {
            $request->headers->set($jmeno, $hodnota);
        }

        return $kernel->handle($request);
    }

    public function testValidTokenReturnsData(): void
    {
        $response = $this->volej('/status.json', ['X-Status-Token' => self::TOKEN]);

        self::assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('symfony', $data['framework']);
        self::assertSame('localhost', $data['host']);
    }

    /** @return iterable<string,array{array<string,string>}> */
    public static function platneZpusobyPredaniTokenu(): iterable
    {
        yield 'hlavicka Authorization' => [['Authorization' => 'Bearer ' . self::TOKEN]];
        yield 'hlavicka X-Status-Token' => [['X-Status-Token' => self::TOKEN]];
    }

    /** @param array<string,string> $headers */
    #[DataProvider('platneZpusobyPredaniTokenu')]
    public function testAllTokenTransportsWork(array $headers): void
    {
        self::assertSame(200, $this->volej('/status.json', $headers)->getStatusCode());
    }

    public function testTokenInQueryStringStillWorks(): void
    {
        // B1 počítá s vypnutím téhle varianty — do té doby musí fungovat.
        self::assertSame(200, $this->volej('/status.json?token=' . self::TOKEN)->getStatusCode());
    }

    public function testMissingTokenIsUnauthorized(): void
    {
        self::assertSame(401, $this->volej('/status.json')->getStatusCode());
    }

    public function testWrongTokenIsUnauthorized(): void
    {
        self::assertSame(401, $this->volej('/status.json', ['X-Status-Token' => 'spatny'])->getStatusCode());
    }

    /**
     * Fail-closed: bez nakonfigurovaného tokenu je endpoint vypnutý, ale web běží dál.
     */
    public function testEndpointIsDisabledWithoutConfiguredToken(): void
    {
        $response = $this->volej('/status.json', ['X-Status-Token' => self::TOKEN], []);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testEmptyTokenAlsoDisablesEndpoint(): void
    {
        $response = $this->volej('/status.json', ['X-Status-Token' => ''], ['token' => '']);

        self::assertSame(403, $response->getStatusCode());
    }

    /** A8 */
    public function testSecurityHeaders(): void
    {
        $response = $this->volej('/status.json', ['X-Status-Token' => self::TOKEN]);

        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    /** A6: ETag musí odpovídat skutečně odeslanému tělu. */
    public function testEtagMatchesResponseBody(): void
    {
        $response = $this->volej('/status.json', ['X-Status-Token' => self::TOKEN]);

        self::assertSame('"' . sha1((string) $response->getContent()) . '"', $response->headers->get('ETag'));
    }

    /** A6: diakritika a lomítka zůstávají neescapované. */
    public function testJsonIsNotDoubleEncoded(): void
    {
        $response = $this->volej('/status.json', ['X-Status-Token' => self::TOKEN]);

        self::assertStringNotContainsString('\u00', (string) $response->getContent());
        self::assertStringNotContainsString('\/', (string) $response->getContent());
    }

    /** @return iterable<string,array{string,int}> */
    public static function hodnotyIfNoneMatch(): iterable
    {
        yield 'presna shoda'  => ['%s', 304];
        yield 'seznam hodnot' => ['"jiny-hash", %s', 304];
        yield 'wildcard'      => ['*', 304];
        yield 'weak etag'     => ['W/%s', 304];
        yield 'neshoda'       => ['"uplne-jiny"', 200];
    }

    /** A7 */
    #[DataProvider('hodnotyIfNoneMatch')]
    public function testConditionalRequests(string $sablona, int $ocekavanyKod): void
    {
        $prvni = $this->volej('/status.json', ['X-Status-Token' => self::TOKEN]);
        $etag  = (string) $prvni->headers->get('ETag');

        $druhy = $this->volej('/status.json', [
            'X-Status-Token' => self::TOKEN,
            'If-None-Match'  => sprintf($sablona, $etag),
        ]);

        self::assertSame($ocekavanyKod, $druhy->getStatusCode());

        if ($ocekavanyKod === 304) {
            self::assertSame('', (string) $druhy->getContent());
        }
    }

    /** B6: cesta endpointu jde přenastavit konfigurací. */
    public function testCustomPathFromConfiguration(): void
    {
        $config = ['token' => self::TOKEN, 'path' => '/interni/stav.json'];

        self::assertSame(200, $this->volej('/interni/stav.json', ['X-Status-Token' => self::TOKEN], $config)->getStatusCode());
    }

    public function testDefaultPathIsUnchanged(): void
    {
        // Výchozí hodnota se nesmí změnit — protistrana má cestu zadanou natvrdo.
        self::assertSame(200, $this->volej('/status.json', ['X-Status-Token' => self::TOKEN])->getStatusCode());
    }
}
