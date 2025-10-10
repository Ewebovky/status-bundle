<?php
declare(strict_types=1);

namespace Ewebovky\StatusBundle\Controller;

use Ewebovky\StatusBundle\Service\WebStatusCollector;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class StatusController
{
    public function __construct(
        private readonly WebStatusCollector $collector,
        private readonly string $statusToken,
    ) {}

    #[Route(path: '/status.json', name: 'ewebovky_status_json', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $provided = $this->extractToken($request);
        if (!$provided || !\hash_equals($this->statusToken, $provided)) {
            return new JsonResponse(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $data = $this->collector->collect($request->getHost());

        foreach (['frameworkEndOfMaintenance', 'frameworkEndOfLife'] as $k) {
            if (($data[$k] ?? null) instanceof \DateTimeInterface) {
                $data[$k] = $data[$k]->format('Y-m-d');
            }
        }

        $json = \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = '"' . sha1((string)$json) . '"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return new JsonResponse(null, JsonResponse::HTTP_NOT_MODIFIED, ['ETag' => $etag]);
        }

        return new JsonResponse(
            \json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR),
            JsonResponse::HTTP_OK,
            [
                'Content-Type'  => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-cache',
                'ETag'          => $etag,
            ]
        );
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
