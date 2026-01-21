<?php

declare(strict_types=1);

namespace CAH\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CAH\Services\AdminAuthService;
use CAH\Services\RateLimitService;
use CAH\Services\CardImportService;
use CAH\Models\Card;
use CAH\Models\Tag;
use CAH\Models\Game;
use CAH\Exceptions\ValidationException;
use CAH\Utils\Response as JsonResponse;
use CAH\Utils\Validator;

class AdminController
{
    /**
     * Admin login
     */
    public function login(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody() ?? [];
            
            $validator = (new Validator())
                ->required($data['password'] ?? null, 'password');
            
            if ($validator->fails()) {
                throw new ValidationException('Validation failed', $validator->getErrors());
            }
            
            $clientIp = RateLimitService::getClientIp($request);
            $userAgent = $request->getHeaderLine('User-Agent');
            
            $result = AdminAuthService::login(
                $data['password'],
                $clientIp,
                $userAgent
            );
            
            if ($result === null) {
                return JsonResponse::error($response, 'Invalid password', 401);
            }
            
            return JsonResponse::success($response, [
                'token' => $result['token'],
                'expires_at' => $result['expires_at'],
            ]);
            
        } catch (ValidationException $e) {
            return JsonResponse::validationError($response, $e->getErrors(), $e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }
    
    /**
     * Admin logout
     */
    public function logout(Request $request, Response $response): Response
    {
        try {
            // Get token from Authorization header
            $authHeader = $request->getHeaderLine('Authorization');
            $token = $authHeader;
            if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
                $token = $matches[1];
            }

            AdminAuthService::logout($token);

            return JsonResponse::success($response, ['message' => 'Logged out successfully']);

        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * List cards with optional filtering
     *
     * Query parameters:
     * - type: 'white' or 'black' (optional)
     * - tag_id: filter by tag ID (optional)
     * - active: 1 or 0 (optional, default: 1)
     * - limit: number of cards to return (optional, default: 100)
     * - offset: pagination offset (optional, default: 0)
     */
    public function listCards(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();

            $cardType = $queryParams['type'] ?? null;
            $tagIdParam = $queryParams['tag_id'] ?? null;
            $tagId = null;
            $noTags = false;

            if ($tagIdParam !== null) {
                if ($tagIdParam === 'none' || $tagIdParam === '0') {
                    $noTags = true;
                } else {
                    $tagId = (int) $tagIdParam;
                }
            }

            $active = isset($queryParams['active']) ? (int) $queryParams['active'] : 1;
            $limit = isset($queryParams['limit']) ? (int) $queryParams['limit'] : 100;
            $offset = isset($queryParams['offset']) ? (int) $queryParams['offset'] : 0;

            // Validate parameters
            if ($cardType !== null && $cardType !== 'white' && $cardType !== 'black') {
                throw new ValidationException('Invalid card type. Must be "white" or "black"');
            }

            if ($limit < 0 || $limit > 10000) {
                throw new ValidationException('Limit must be between 0 and 10000 (0 = no limit)');
            }

            if ($offset < 0) {
                throw new ValidationException('Offset must be non-negative');
            }

            // Build query
            $sql = "SELECT c.* FROM cards c";
            $params = [];
            $conditions = [];

            // Join with tags if filtering by tag
            if ($tagId !== null) {
                $sql .= " INNER JOIN cards_to_tags ct ON c.card_id = ct.card_id";
                $conditions[] = "ct.tag_id = ?";
                $params[] = $tagId;
            } elseif ($noTags) {
                // Filter for cards with no tags using LEFT JOIN
                $sql .= " LEFT JOIN cards_to_tags ct ON c.card_id = ct.card_id";
                $conditions[] = "ct.card_id IS NULL";
            }

            // Add filters
            if ($cardType !== null) {
                $conditions[] = "c.card_type = ?";
                $params[] = $cardType;
            }

            $conditions[] = "c.active = ?";
            $params[] = $active;

            // Add WHERE clause
            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }

            // Add ordering and pagination
            $sql .= " ORDER BY c.card_id ASC";

            // Only add LIMIT if limit > 0 (0 means no limit)
            if ($limit > 0) {
                $sql .= " LIMIT ? OFFSET ?";
                $params[] = $limit;
                $params[] = $offset;
            }

            $cards = \CAH\Database\Database::fetchAll($sql, $params);

            // Get tags for each card
            foreach ($cards as &$card) {
                $card['tags'] = Tag::getCardTags((int) $card['card_id']);
            }

            // Get total count
            $countSql = "SELECT COUNT(DISTINCT c.card_id) as total FROM cards c";
            $countParams = [];
            $countConditions = [];

            if ($tagId !== null) {
                $countSql .= " INNER JOIN cards_to_tags ct ON c.card_id = ct.card_id";
                $countConditions[] = "ct.tag_id = ?";
                $countParams[] = $tagId;
            } elseif ($noTags) {
                $countSql .= " LEFT JOIN cards_to_tags ct ON c.card_id = ct.card_id";
                $countConditions[] = "ct.card_id IS NULL";
            }

            if ($cardType !== null) {
                $countConditions[] = "c.card_type = ?";
                $countParams[] = $cardType;
            }

            $countConditions[] = "c.active = ?";
            $countParams[] = $active;

            if (!empty($countConditions)) {
                $countSql .= " WHERE " . implode(' AND ', $countConditions);
            }

            $countResult = \CAH\Database\Database::fetchOne($countSql, $countParams);
            $total = (int) ($countResult['total'] ?? 0);

            return JsonResponse::success($response, [
                'cards' => $cards,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ]);

        } catch (ValidationException $e) {
            return JsonResponse::validationError($response, $e->getErrors(), $e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Import cards from CSV
     */
    public function importCards(Request $request, Response $response): Response
    {
        try {
            $uploadedFiles = $request->getUploadedFiles();
            $queryParams = $request->getQueryParams();
            $cardType = $queryParams['type'] ?? 'white';

            if ( ! isset($uploadedFiles['file'])) {
                throw new ValidationException('No file uploaded');
            }

            $file = $uploadedFiles['file'];

            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw new ValidationException('File upload error');
            }

            if ($cardType !== 'white' && $cardType !== 'black') {
                throw new ValidationException('Invalid card type. Must be "white" or "black"');
            }

            // Read CSV file
            $stream = $file->getStream();
            $csvContent = $stream->getContents();

            // Use a temporary file for proper CSV parsing
            $tmpFile = tempnam(sys_get_temp_dir(), 'csv_import_');
            file_put_contents($tmpFile, $csvContent);

            $handle = fopen($tmpFile, 'r');
            if ( ! $handle) {
                unlink($tmpFile);
                throw new \Exception('Failed to open CSV file');
            }

            // Load all existing tags once for efficiency
            $allTags = Tag::getAll();
            $tagsByName = [];
            $tagsById = [];
            foreach ($allTags as $tag) {
                $tagsByName[strtolower($tag['name'])] = $tag;
                $tagsById[$tag['tag_id']] = $tag;
            }

            $imported = 0;
            $failed = 0;
            $errors = [];
            $warnings = [];
            $index = 0;

            while (($data = fgetcsv($handle)) !== false) {
                if ($index === 0) {
                    // Skip header row
                    $index++;
                    continue;
                }

                if (empty($data[0])) {
                    $index++;
                    continue;
                }

                $cardText = trim($data[0]);

                // Get tags from columns 1-10, trim and filter out empty values
                $tagColumns = array_slice($data, 1, 10);
                $tagValues = [];
                foreach ($tagColumns as $tag) {
                    $tag = trim($tag);
                    if ( ! empty($tag)) {
                        $tagValues[] = $tag;
                    }
                }

                try {
                    $cardId = CardImportService::importCard($cardType, $cardText);

                    if ($cardId) {
                        // Process tags if any
                        if ( ! empty($tagValues)) {
                            foreach ($tagValues as $tagValue) {
                                $tagId = null;

                                // Check if tag value is numeric (tag ID)
                                if (is_numeric($tagValue)) {
                                    $numericTagId = (int) $tagValue;

                                    // Verify tag ID exists
                                    if (isset($tagsById[$numericTagId])) {
                                        $tagId = $numericTagId;
                                    } else {
                                        $warnings[] = "Row {$index}: Tag ID {$numericTagId} does not exist, skipping";
                                        continue;
                                    }
                                } else {
                                    // Tag value is a string (tag name)
                                    $tagNameLower = strtolower($tagValue);

                                    // Check if tag name exists
                                    if (isset($tagsByName[$tagNameLower])) {
                                        $tagId = $tagsByName[$tagNameLower]['tag_id'];
                                    } else {
                                        // Create new tag
                                        $tagId = Tag::create($tagValue, null, true);

                                        // Add to our lookup arrays for future rows
                                        $newTag = Tag::find($tagId);
                                        if ($newTag) {
                                            $tagsByName[strtolower($newTag['name'])] = $newTag;
                                            $tagsById[$tagId] = $newTag;
                                        }

                                        $warnings[] = "Row {$index}: Created new tag '{$tagValue}' (ID: {$tagId})";
                                    }
                                }

                                // Add tag to card
                                if ($tagId !== null) {
                                    Tag::addToCard($cardId, $tagId);
                                }
                            }
                        }

                        $imported++;
                    } else {
                        $errors[] = "Row {$index}: Failed to import card";
                        $failed++;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Row {$index}: " . $e->getMessage();
                    $failed++;
                }

                $index++;
            }

            // Clean up
            fclose($handle);
            unlink($tmpFile);

            return JsonResponse::success($response, [
                'imported' => $imported,
                'failed' => $failed,
                'errors' => $errors,
                'warnings' => $warnings,
            ]);

        } catch (ValidationException $e) {
            return JsonResponse::validationError($response, $e->getErrors(), $e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Edit a card
     */
    public function editCard(Request $request, Response $response, array $args): Response
    {
        try {
            $cardId = (int) $args['cardId'];
            $data = $request->getParsedBody() ?? [];

            $card = Card::getById($cardId);
            if ( ! $card) {
                return JsonResponse::notFound($response, 'Card not found');
            }

            $updateData = [];
            if (isset($data['value'])) {
                $updateData['value'] = $data['value'];
            }
            if (isset($data['card_type'])) {
                $updateData['card_type'] = $data['card_type'];
            }
            if (isset($data['choices'])) {
                $updateData['choices'] = $data['choices'];
            }
            if (isset($data['active'])) {
                $updateData['active'] = $data['active'] ? 1 : 0;
            }

            $affected = Card::update($cardId, $updateData);

            return JsonResponse::success($response, [
                'card_id' => $cardId,
                'updated' => $affected > 0,
            ]);

        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Delete a card
     */
    public function deleteCard(Request $request, Response $response, array $args): Response
    {
        try {
            $cardId = (int) $args['cardId'];

            $card = Card::getById($cardId);
            if ( ! $card) {
                return JsonResponse::notFound($response, 'Card not found');
            }

            $affected = Card::softDelete($cardId);

            return JsonResponse::success($response, [
                'card_id' => $cardId,
                'deleted' => $affected > 0,
            ]);

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

            $validator = (new Validator())
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

    /**
     * Delete a game
     */
    public function deleteGame(Request $request, Response $response, array $args): Response
    {
        try {
            $gameId = $args['gameId'];

            if ( ! Game::exists($gameId)) {
                return JsonResponse::notFound($response, 'Game not found');
            }

            $affected = Game::delete($gameId);

            return JsonResponse::success($response, [
                'game_id' => $gameId,
                'deleted' => $affected > 0,
            ]);

        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Get all tags for a specific card
     */
    public function getCardTags(Request $request, Response $response, array $args): Response
    {
        try {
            $cardId = (int) $args['cardId'];

            // Validate card exists
            $card = Card::getById($cardId);
            if (!$card) {
                return JsonResponse::notFound($response, 'Card not found');
            }

            $tags = Tag::getCardTags($cardId);

            return JsonResponse::success($response, [
                'tags' => $tags
            ]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, 'Failed to get card tags: ' . $e->getMessage());
        }
    }

    /**
     * Add a tag to a card
     */
    public function addCardTag(Request $request, Response $response, array $args): Response
    {
        try {
            $cardId = (int) $args['cardId'];
            $tagId = (int) $args['tagId'];

            // Validate card exists
            $card = Card::getById($cardId);
            if (!$card) {
                return JsonResponse::notFound($response, 'Card not found');
            }

            // Validate tag exists
            $tag = Tag::find($tagId);
            if (!$tag) {
                return JsonResponse::notFound($response, 'Tag not found');
            }

            $added = Tag::addToCard($cardId, $tagId);

            return JsonResponse::success($response, [
                'message' => $added ? 'Tag added to card' : 'Tag already assigned to card',
                'added' => $added
            ]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, 'Failed to add tag to card: ' . $e->getMessage());
        }
    }

    /**
     * Remove a tag from a card
     */
    public function removeCardTag(Request $request, Response $response, array $args): Response
    {
        try {
            $cardId = (int) $args['cardId'];
            $tagId = (int) $args['tagId'];

            $removed = Tag::removeFromCard($cardId, $tagId);

            return JsonResponse::success($response, [
                'message' => $removed > 0 ? 'Tag removed from card' : 'Tag was not assigned to card',
                'removed' => $removed > 0
            ]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, 'Failed to remove tag from card: ' . $e->getMessage());
        }
    }

    /**
     * List all games with optional filtering
     *
     * Query parameters:
     * - state: filter by game state (optional)
     * - limit: number of games to return (optional, default: 50)
     * - offset: pagination offset (optional, default: 0)
     */
    public function listGames(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();

            $state = $queryParams['state'] ?? null;
            $limit = isset($queryParams['limit']) ? (int) $queryParams['limit'] : 50;
            $offset = isset($queryParams['offset']) ? (int) $queryParams['offset'] : 0;

            // Validate parameters
            if ($limit < 0 || $limit > 10000) {
                throw new ValidationException('Limit must be between 0 and 10000 (0 = no limit)');
            }

            if ($offset < 0) {
                throw new ValidationException('Offset must be non-negative');
            }

            // Build query
            $sql = "SELECT * FROM games";
            $params = [];
            $conditions = [];

            // Add state filter if provided
            if ($state !== null) {
                // State is stored in player_data JSON, so we need to extract it
                $conditions[] = "JSON_EXTRACT(player_data, '$.state') = ?";
                $params[] = $state;
            }

            // Add WHERE clause
            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }

            // Add ordering and pagination
            $sql .= " ORDER BY created_at DESC";

            // Only add LIMIT if limit > 0 (0 means no limit)
            if ($limit > 0) {
                $sql .= " LIMIT ? OFFSET ?";
                $params[] = $limit;
                $params[] = $offset;
            }

            $games = \CAH\Database\Database::fetchAll($sql, $params);

            // Parse JSON fields for each game
            foreach ($games as &$game) {
                $game['tags'] = json_decode($game['tags'], true);
                $game['draw_pile'] = json_decode($game['draw_pile'], true);
                $game['discard_pile'] = json_decode($game['discard_pile'], true);
                $game['player_data'] = json_decode($game['player_data'], true);
            }

            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM games";
            $countParams = [];

            if ($state !== null) {
                $countSql .= " WHERE JSON_EXTRACT(player_data, '$.state') = ?";
                $countParams[] = $state;
            }

            $countResult = \CAH\Database\Database::fetchOne($countSql, $countParams);
            $total = (int) ($countResult['total'] ?? 0);

            return JsonResponse::success($response, [
                'games' => $games,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ]);

        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }
}
