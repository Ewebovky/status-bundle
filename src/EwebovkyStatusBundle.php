<?php
declare(strict_types=1);

namespace Ewebovky\StatusBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class EwebovkyStatusBundle extends AbstractBundle
{
    protected string $extensionAlias = 'ewebovky_status';

    /**
     * Konfigurace bundlu žije v src/Resources/, ne v config/ v kořeni balíčku.
     * AbstractBundle počítá s novějším rozložením a vracel by kořen balíčku —
     * tím by se změnil význam odkazů „@EwebovkyStatusBundle/…“ (typicky
     * v routingu). Držíme se proto původní hodnoty z Bundle::getPath().
     */
    public function getPath(): string
    {
        return __DIR__;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                // Token je záměrně volitelný (default null). Když není nastavený,
                // endpoint je za běhu vypnutý (fail-closed 403) — bundle tak nikdy
                // neshodí stavbu kontejneru kvůli chybějícímu configu/env/recipe.
                ->scalarNode('token')->defaultNull()->end()
                // Cesta endpointu. Hodnota se dosazuje do #[Route] na controlleru,
                // takže platí pro oba způsoby importu rout. Záměrně bez validace
                // na úvodní lomítko — u neresolvnutého %env(...)% by kontrola
                // shodila stavbu kontejneru, a tím celý web.
                ->scalarNode('path')->defaultValue('/status.json')->cannotBeEmpty()->end()
            ->end();
    }

    /** @param array<string,mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('ewebovky_status.token', $config['token'])
            ->set('ewebovky_status.path', $config['path']);

        $container->import(__DIR__ . '/Resources/config/services.php');
    }
}
