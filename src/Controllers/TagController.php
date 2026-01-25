<?php

declare(strict_types=1);

namespace CAH\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CAH\Models\Tag;
use CAH\Utils\Response as JsonResponse;

class TagController
{
    /**
     * Get all active tags with card counts
     */
    public function list(Request $request, Response $response): Response
    {
        try {
            $tags = Tag::getAllActiveWithCounts();

            return JsonResponse::success($response, ['tags' => $tags]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }
}
