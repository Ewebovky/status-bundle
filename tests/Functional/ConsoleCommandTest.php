<?php
declare(strict_types=1);

namespace Ewebovky\StatusBundle\Tests\Functional;

use Ewebovky\StatusBundle\Tests\Fixtures\TestKernel;
use Ewebovky\StatusBundle\Tests\Fixtures\UklidiHandlery;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A14: CLI příkaz musí dávat stejná data jako endpoint.
 */
final class ConsoleCommandTest extends TestCase
{
    use UklidiHandlery;

    protected function setUp(): void
    {
        $this->zapamatujHandlery();
    }

    protected function tearDown(): void
    {
        $this->obnovHandlery();
    }

    private function tester(): CommandTester
    {
        $application = new Application(new TestKernel(['token' => 'tajny-token-123']));
        $application->setAutoExit(false);

        return new CommandTester($application->find('ewebovky:status'));
    }

    public function testCommandIsRegistered(): void
    {
        $application = new Application(new TestKernel(['token' => 'tajny-token-123']));

        self::assertTrue($application->has('ewebovky:status'));
    }

    public function testTableOutput(): void
    {
        $tester = $this->tester();
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $vystup = $tester->getDisplay();
        self::assertStringContainsString('phpVersion', $vystup);
        self::assertStringContainsString('opcacheEnabled', $vystup);
        // Pole rozšíření se musí vypsat jako text, ne shodit Table.
        self::assertStringContainsString('phpExtensions', $vystup);
    }

    public function testJsonOutputIsValid(): void
    {
        $tester = $this->tester();
        $tester->execute(['--json' => true]);
        $tester->assertCommandIsSuccessful();

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('symfony', $data['framework']);
        self::assertIsArray($data['phpExtensions']);
    }

    public function testHostOption(): void
    {
        $tester = $this->tester();
        $tester->execute(['--json' => true, '--host' => 'muj-web.cz']);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('muj-web.cz', $data['host']);
    }

    public function testCommandWorksWithoutConfiguredToken(): void
    {
        // Příkaz běží lokálně, token se ho netýká.
        $application = new Application(new TestKernel([]));
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('ewebovky:status'));

        $tester->execute(['--json' => true]);
        $tester->assertCommandIsSuccessful();
    }
}
