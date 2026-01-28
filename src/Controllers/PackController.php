<?php

declare(strict_types=1);

namespace CAH\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CAH\Models\Pack;
use CAH\Utils\Response as JsonResponse;

class PackController
{
    /**
     * Get all active packs with card counts (for game use)
     */
    public function list(Request $request, Response $response): Response
    {
        try {
            // Only return active packs for game use
            $packs = Pack::getAllWithCounts(true);

            return JsonResponse::success($response, ['packs' => $packs]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Get all packs with card counts (for admin use, includes inactive)
     */
    public function listAll(Request $request, Response $response): Response
    {
        try {
            $packs = Pack::getAllWithCounts(null);

            return JsonResponse::success($response, ['packs' => $packs]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }
}
