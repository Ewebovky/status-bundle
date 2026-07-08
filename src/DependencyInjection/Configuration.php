<?php
declare(strict_types=1);

namespace Ewebovky\StatusBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('ewebovky_status');

        $tree->getRootNode()
            ->children()
                // Token je záměrně volitelný (default null). Když není nastavený,
                // endpoint je za běhu vypnutý (fail-closed 403) — bundle tak nikdy
                // neshodí stavbu kontejneru kvůli chybějícímu configu/env/recipe.
                ->scalarNode('token')->defaultNull()->end()
            ->end();

        return $tree;
    }
}
