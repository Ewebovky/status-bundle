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
                ->scalarNode('token')->isRequired()->cannotBeEmpty()->end()
            ->end();

        return $tree;
    }
}
