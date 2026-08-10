<?php
declare(strict_types=1);

namespace Ewebovky\StatusBundle\Controller;

use Ewebovky\StatusBundle\Service\WebStatusCollector;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class StatusController
{
    /**
     * Pole, která se mění při každém požadavku, i když se stav serveru nijak
     * neposunul: čas generování a metriky opcache, jež rostou s každým načteným
     * skriptem a každým zásahem do cache.
     *
     * Do výpočtu ETagu nepatří — jinak by hash byl pokaždé jiný a odpověď 304
     * by nenastala prakticky nikdy. ETag tak identifikuje stav serveru
     * (verze, rozšíření, databáze, restarty opcache), ne konkrétní bajty těla.
     */
    private const VOLATILNI_POLE = [
        'generatedAt',
        'opcacheMemoryUsedPercent',
        'opcacheHitRate',
    ];

    public function __construct(
        private readonly WebStatusCollector $collector,
        private readonly ?string $statusToken,
    ) {}

    // Cesta se bere z konfigurace (ewebovky_status.path, default /status.json).
    // Symfony placeholder v atributu resolvuje stejně jako v YAML definici, takže
    // se nemění způsob importu rout ani jméno routy.
    #[Route(path: '%ewebovky_status.path%', name: 'ewebovky_status_json', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        // Fail-closed: bez nakonfigurovaného tokenu je endpoint vypnutý.
        // Neresolvnutý %env(...)% nebo chybějící config tak neshodí web, jen
        // zablokuje tenhle jeden endpoint.
        if ($this->statusToken === null || $this->statusToken === '') {
            return new JsonResponse(['error' => 'Status endpoint disabled'], JsonResponse::HTTP_FORBIDDEN);
        }

        $provided = $this->extractToken($request);
        if (!$provided || !\hash_equals($this->statusToken, $provided)) {
            return new JsonResponse(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $data = $this->collector->collect($request->getHost());

        // Symfony vrací konce podpory jako řetězec ve tvaru „11/2027“, takže se
        // podmínka na DateTimeInterface dnes neuplatní. Zůstává jako pojistka pro
        // zdroje, které by vracely objekt — jiný framework nebo vlastní provider.
        // Formát „m/Y“ je zvolený schválně: sjednotí výstup s tím, co posílá
        // Symfony, takže protistrana nemusí umět druhý tvar.
        foreach (['frameworkEndOfMaintenance', 'frameworkEndOfLife'] as $k) {
            if (($data[$k] ?? null) instanceof \DateTimeInterface) {
                $data[$k] = $data[$k]->format('m/Y');
            }
        }

        $json = \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // fromJsonString pošle přesně ta data, ze kterých je spočítaný ETag.
        // Předání pole do JsonResponse by je zakódovalo znovu, a to výchozími
        // flagy — tedy s odescapovanou diakritikou i lomítky, takže by ETag
        // neodpovídal skutečně odeslanému tělu.
        $response = JsonResponse::fromJsonString(
            $json,
            JsonResponse::HTTP_OK,
            [
                'Content-Type'           => 'application/json; charset=utf-8',
                'Cache-Control'          => 'no-cache',
                // Endpoint je sice pod tokenem, ale URL se může objevit v logu
                // nebo v hlavičce Referer.
                'X-Robots-Tag'           => 'noindex, nofollow',
                // Bez flagů JSON_HEX_* v odpovědi zůstávají znaky < > &, takže
                // ať prohlížeč obsah nepřetypuje na text/html.
                'X-Content-Type-Options' => 'nosniff',
            ]
        );

        // setEtag() hodnotu obalí uvozovkami sám, výsledek je stejný jako dřív.
        $response->setEtag($this->spocitejEtag($data));

        // Při shodě ETagu z odpovědi udělá 304 a vyprázdní tělo. Oproti ručnímu
        // porovnání zvládne seznam hodnot i „*“ v If-None-Match a ověří, že je
        // metoda cacheovatelná.
        $response->isNotModified($request);

        return $response;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function spocitejEtag(array $data): string
    {
        $stabilni = array_diff_key($data, array_flip(self::VOLATILNI_POLE));

        return sha1(json_encode($stabilni, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function extractToken(Request $request): ?string
    {
        $auth = (string) $request->headers->get('Authorization');
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        if ($h = $request->headers->get('X-Status-Token')) {
            return trim($h);
        }
        if ($q = $request->query->get('token')) {
            return trim((string) $q);
        }
        return null;
    }
}
