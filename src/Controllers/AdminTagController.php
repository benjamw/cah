<?php

declare(strict_types=1);

namespace CAH\Controllers;

use CAH\Exceptions\ValidationException;
use CAH\Models\Tag;
use CAH\Utils\Response as JsonResponse;
use CAH\Utils\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin Tag Controller
 *
 * Handles admin operations for tags: creating, editing, deleting
 */
class AdminTagController
{
    /**
     * Get a single tag by ID
     *
     * @param array<string, string> $args Route arguments
     */
    public function getTag(Request $request, Response $response, array $args): Response
    {
        try {
            $tagId = (int) $args['tagId'];

            $tag = Tag::find($tagId);
            if ( ! $tag) {
                return JsonResponse::notFound($response, 'Tag not found');
            }

            return JsonResponse::success($response, ['tag' => $tag]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Create a tag
     */
    public function createTag(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody() ?? [];

            $validator = ( new Validator() )
                ->required($data['name'] ?? null, 'name');

            if ($validator->fails()) {
                throw new ValidationException('Validation failed', $validator->getErrors());
            }

            $tagId = Tag::create(
                $data['name'],
                $data['description'] ?? null,
                $data['active'] ?? true
            );

            return JsonResponse::success($response, [
                'tag_id' => $tagId,
                'name' => $data['name'],
            ], null, 201);
        } catch (ValidationException $e) {
            return JsonResponse::validationError($response, $e->getErrors(), $e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Edit a tag
     *
     * @param array<string, string> $args Route arguments
     */
    public function editTag(Request $request, Response $response, array $args): Response
    {
        try {
            $tagId = (int) $args['tagId'];
            $data = $request->getParsedBody() ?? [];

            $tag = Tag::find($tagId);
            if ( ! $tag) {
                return JsonResponse::notFound($response, 'Tag not found');
            }

            $updateData = [];
            if (isset($data['name'])) {
                $updateData['name'] = $data['name'];
            }
            if (isset($data['description'])) {
                $updateData['description'] = $data['description'];
            }
            if (isset($data['active'])) {
                $updateData['active'] = $data['active'] ? 1 : 0;
            }

            $affected = Tag::update($tagId, $updateData);

            return JsonResponse::success($response, [
                'tag_id' => $tagId,
                'updated' => $affected > 0,
            ]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Delete a tag
     *
     * @param array<string, string> $args Route arguments
     */
    public function deleteTag(Request $request, Response $response, array $args): Response
    {
        try {
            $tagId = (int) $args['tagId'];

            $tag = Tag::find($tagId);
            if ( ! $tag) {
                return JsonResponse::notFound($response, 'Tag not found');
            }

            $affected = Tag::softDelete($tagId);

            return JsonResponse::success($response, [
                'tag_id' => $tagId,
                'deleted' => $affected > 0,
            ]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }
}
