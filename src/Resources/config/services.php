<?php
declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Ewebovky\StatusBundle\Service\WebStatusCollector;
use Ewebovky\StatusBundle\Controller\StatusController;
use Ewebovky\StatusBundle\Command\DumpStatusCommand;

return static function (ContainerConfigurator $config): void {
    $services = $config->services()->defaults()->autowire()->autoconfigure();

    // Nullable autowiring pro Doctrine registry (pokud není, předá se null)
    $services->set(WebStatusCollector::class)
        ->args([
            service('?Doctrine\\Persistence\\ManagerRegistry'),
            param('kernel.environment'),
        ]);

    $services->set(StatusController::class)
        ->public()
        ->args([
            service(WebStatusCollector::class),
            param('ewebovky_status.token'),
        ]);

    $services->set(DumpStatusCommand::class)
        ->tag('console.command');
};
