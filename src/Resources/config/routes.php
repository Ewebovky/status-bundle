<?php
declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Routa je definovaná atributem #[Route] na StatusControlleru, tenhle soubor ji
 * jen zpřístupní aplikaci jedním importem. Zdroj pravdy tak zůstává na jednom
 * místě — viz README, sekce „Registrace routy“.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import(__DIR__ . '/../../Controller/', 'attribute');
};
