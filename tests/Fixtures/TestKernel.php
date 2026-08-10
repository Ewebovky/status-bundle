<?php
declare(strict_types=1);

namespace Ewebovky\StatusBundle\Tests\Fixtures;

use Ewebovky\StatusBundle\EwebovkyStatusBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Minimální aplikace pro funkční testy. Routy se importují přesně tak, jak to
 * README doporučuje uživatelům — kdyby se ten postup rozbil, testy to zachytí.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** @param array<string,mixed> $bundleConfig */
    public function __construct(private readonly array $bundleConfig = [])
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new EwebovkyStatusBundle()];
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/ewebovky-status-tests/' . md5(serialize($this->bundleConfig));
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/ewebovky-status-tests/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'test',
            'http_method_override' => false,
            'test'                 => true,
        ]);

        $container->extension('ewebovky_status', $this->bundleConfig);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@EwebovkyStatusBundle/Resources/config/routes.php');
    }
}
