<?php
declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Ewebovky\StatusBundle\Service\WebStatusCollector;
use Ewebovky\StatusBundle\Controller\StatusController;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return static function (ContainerConfigurator $config): void {
    $services = $config->services()->defaults()->autowire()->autoconfigure();

    // Nullable autowiring pro Doctrine registry (pokud není k dispozici)
    $services->set(WebStatusCollector::class)
        ->args([
            service(\Doctrine\Persistence\ManagerRegistry::class)->nullOnInvalid(),
            param('kernel.environment'),
        ]);

    $services->set(StatusController::class)
        ->public()
        ->args([
            service(WebStatusCollector::class),
            param('ewebovky_status.token'),
        ]);
};
