<?php

declare(strict_types=1);

namespace CAH\Controllers;

use CAH\Exceptions\ValidationException;
use CAH\Models\Pack;
use CAH\Utils\Response as JsonResponse;
use CAH\Utils\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin Pack Controller
 *
 * Handles admin operations for packs: creating, editing, deleting
 */
class AdminPackController
{
    /**
     * Create a pack
     */
    public function createPack(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody() ?? [];

            $validator = ( new Validator() )
                ->required($data['name'] ?? null, 'name');

            if ($validator->fails()) {
                throw new ValidationException('Validation failed', $validator->getErrors());
            }

            $packId = Pack::create(
                $data['name'],
                $data['version'] ?? null,
                $data['data'] ?? null,
                $data['release_date'] ?? null,
                $data['active'] ?? true
            );

            return JsonResponse::success($response, [
                'pack_id' => $packId,
                'name' => $data['name'],
            ], null, 201);
        } catch (ValidationException $e) {
            return JsonResponse::validationError($response, $e->getErrors(), $e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Edit a pack
     *
     * @param array<string, string> $args Route arguments
     */
    public function editPack(Request $request, Response $response, array $args): Response
    {
        try {
            $packId = (int) $args['packId'];
            $data = $request->getParsedBody() ?? [];

            $pack = Pack::find($packId);
            if ( ! $pack) {
                return JsonResponse::notFound($response, 'Pack not found');
            }

            $updateData = [];
            if (isset($data['name'])) {
                $updateData['name'] = $data['name'];
            }
            if (isset($data['version'])) {
                $updateData['version'] = $data['version'];
            }
            if (isset($data['data'])) {
                $updateData['data'] = $data['data'];
            }
            if (isset($data['release_date'])) {
                $updateData['release_date'] = $data['release_date'];
            }
            if (isset($data['active'])) {
                $updateData['active'] = $data['active'] ? 1 : 0;
            }

            $affected = Pack::update($packId, $updateData);

            return JsonResponse::success($response, [
                'pack_id' => $packId,
                'updated' => $affected > 0,
            ]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Delete a pack
     *
     * @param array<string, string> $args Route arguments
     */
    public function deletePack(Request $request, Response $response, array $args): Response
    {
        try {
            $packId = (int) $args['packId'];

            $pack = Pack::find($packId);
            if ( ! $pack) {
                return JsonResponse::notFound($response, 'Pack not found');
            }

            $affected = Pack::delete($packId);

            return JsonResponse::success($response, [
                'pack_id' => $packId,
                'deleted' => $affected > 0,
            ]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Toggle pack active status
     *
     * @param array<string, string> $args Route arguments
     */
    public function togglePack(Request $request, Response $response, array $args): Response
    {
        try {
            $packId = (int) $args['packId'];
            $data = $request->getParsedBody() ?? [];

            $pack = Pack::find($packId);
            if ( ! $pack) {
                return JsonResponse::notFound($response, 'Pack not found');
            }

            $active = isset($data['active']) ? (bool) $data['active'] : ! (bool) $pack['active'];
            $affected = Pack::setActive($packId, $active);

            return JsonResponse::success($response, [
                'pack_id' => $packId,
                'active' => $active,
                'updated' => $affected > 0,
            ]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Bulk toggle pack active status (idempotent)
     */
    public function bulkTogglePack(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody() ?? [];

            $validator = ( new Validator() )
                ->required($data['pack_ids'] ?? null, 'pack_ids')
                ->required($data['active'] ?? null, 'active');

            if ($validator->fails()) {
                throw new ValidationException('Validation failed', $validator->getErrors());
            }

            $packIds = $data['pack_ids'];
            if ( ! is_array($packIds) || empty($packIds)) {
                throw new ValidationException('pack_ids must be a non-empty array');
            }

            $active = (bool) $data['active'];
            $affected = Pack::setActiveBulk($packIds, $active);

            return JsonResponse::success($response, [
                'pack_ids' => $packIds,
                'active' => $active,
                'updated_count' => $affected,
            ]);
        } catch (ValidationException $e) {
            return JsonResponse::validationError($response, $e->getErrors(), $e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }
}
