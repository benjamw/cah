<?php

declare(strict_types=1);

namespace CAH\Controllers;

use CAH\Exceptions\ValidationException;
use CAH\Enums\CardType;
use CAH\Models\Card;
use CAH\Models\Pack;
use CAH\Models\Tag;
use CAH\Services\CardImportService;
use CAH\Utils\Response as JsonResponse;
use CAH\Utils\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin Card Controller
 *
 * Handles admin operations for cards: listing, importing, editing, deleting, and tag management
 */
class AdminCardController
{
    /**
     * Parse tag filter parameters from query params
     *
     * @param array<string, mixed> $queryParams Query parameters
     * @return array{tag_id: ?int, no_tags: bool, exclude_tag_id: ?int}
     */
    private function parseTagFilters(array $queryParams): array
    {
        $tagIdParam = $queryParams['tag_id'] ?? null;
        $excludeTagIdParam = $queryParams['exclude_tag_id'] ?? null;

        $tagId = null;
        $noTags = false;
        $excludeTagId = null;

        if ($tagIdParam !== null) {
            if ($tagIdParam === 'none' || $tagIdParam === '0') {
                $noTags = true;
            } else {
                $tagId = (int) $tagIdParam;
            }
        }

        if ($excludeTagIdParam !== null) {
            $excludeTagId = (int) $excludeTagIdParam;
        }

        return ['tag_id' => $tagId, 'no_tags' => $noTags, 'exclude_tag_id' => $excludeTagId];
    }

    /**
     * Parse pack filter parameters from query params
     *
     * @param array<string, mixed> $queryParams Query parameters
     * @return array{pack_id: ?int, no_packs: bool, pack_active: ?bool}
     */
    private function parsePackFilters(array $queryParams): array
    {
        $packIdParam = $queryParams['pack_id'] ?? null;
        $packActiveParam = $queryParams['pack_active'] ?? null;

        $packId = null;
        $noPacks = false;
        $packActive = null;

        if ($packIdParam !== null) {
            if ($packIdParam === 'none' || $packIdParam === '0') {
                $noPacks = true;
            } else {
                $packId = (int) $packIdParam;
            }
        }

        if ($packActiveParam !== null && $packActiveParam !== '') {
            $packActive = (bool) ( (int) $packActiveParam );
        }

        return ['pack_id' => $packId, 'no_packs' => $noPacks, 'pack_active' => $packActive];
    }

    /**
     * Parse and validate card type from query params
     *
     * @param array<string, mixed> $queryParams Query parameters
     * @return ?CardType Card type enum or null
     * @throws ValidationException If card type is invalid
     */
    private function parseCardType(array $queryParams): ?CardType
    {
        $cardType = $queryParams['type'] ?? null;
        if ($cardType === null) {
            return null;
        }

        $cardTypeEnum = CardType::tryFrom($cardType);
        if ($cardTypeEnum === null) {
            throw new ValidationException('Invalid card type. Must be "response" or "prompt"');
        }

        return $cardTypeEnum;
    }

    /**
     * List cards with filtering and pagination
     */
    public function listCards(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();

            // Parse filter parameters
            $tagFilters = $this->parseTagFilters($queryParams);
            $packFilters = $this->parsePackFilters($queryParams);
            $cardTypeEnum = $this->parseCardType($queryParams);

            // Parse search query
            $searchQuery = $queryParams['search'] ?? null;
            if ($searchQuery !== null) {
                $searchQuery = trim($searchQuery);
                if ($searchQuery === '') {
                    $searchQuery = null;
                }
            }

            // Parse pagination parameters
            $active = isset($queryParams['active']) ? (bool) ( (int) $queryParams['active'] ) : true;
            $limit = isset($queryParams['limit']) ? (int) $queryParams['limit'] : 100;
            $offset = isset($queryParams['offset']) ? (int) $queryParams['offset'] : 0;

            // Validate pagination
            if ($limit < 0 || $limit > 10000) {
                throw new ValidationException('Limit must be between 0 and 10000 (0 = no limit)');
            }
            if ($offset < 0) {
                throw new ValidationException('Offset must be non-negative');
            }

            // Use Card model to handle the complex query logic
            $result = Card::listWithFilters(
                $cardTypeEnum,
                $tagFilters['tag_id'],
                $tagFilters['no_tags'],
                $tagFilters['exclude_tag_id'],
                $packFilters['pack_id'],
                $packFilters['no_packs'],
                $packFilters['pack_active'],
                $searchQuery,
                $active,
                $limit,
                $offset
            );
            $cards = $result['cards'];
            $total = $result['total'];

            // Get tags for all cards in one query (batch fetch to avoid N+1)
            $cardIds = array_column($cards, 'card_id');
            $cardIds = array_map(intval(...), $cardIds);
            $tagsByCardId = Tag::getCardTagsForMultipleCards($cardIds);
            $packsByCardId = Pack::getCardPacksForMultipleCards($cardIds, false); // Get all packs, not just active

            // Attach tags and packs to each card
            foreach ($cards as &$card) {
                $cardId = (int) $card['card_id'];
                $card['tags'] = $tagsByCardId[$cardId] ?? [];
                $card['packs'] = $packsByCardId[$cardId] ?? [];
            }

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
            $cardType = $queryParams['type'] ?? 'response';

            if ( ! isset($uploadedFiles['file'])) {
                throw new ValidationException('No file uploaded');
            }

            $file = $uploadedFiles['file'];

            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw new ValidationException('File upload error');
            }

            // Validate and convert card type to enum
            $cardTypeEnum = CardType::tryFrom($cardType);
            if ($cardTypeEnum === null) {
                throw new ValidationException('Invalid card type. Must be "response" or "prompt"');
            }

            // Read CSV file
            $stream = $file->getStream();
            $csvContent = $stream->getContents();

            // Use CardImportService to handle the import
            $result = CardImportService::importFromCsv($csvContent, $cardTypeEnum);

            return JsonResponse::success($response, $result);
        } catch (ValidationException $e) {
            return JsonResponse::validationError($response, $e->getErrors(), $e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Get a single card by ID with its tags and packs
     *
     * @param array<string, string> $args Route arguments
     */
    public function getCard(Request $request, Response $response, array $args): Response
    {
        try {
            $cardId = (int) $args['cardId'];

            $card = Card::getById($cardId);
            if ( ! $card) {
                return JsonResponse::notFound($response, 'Card not found');
            }

            // Get card's tags and packs
            $tags = Tag::getCardTags($cardId, false); // Get all tags, not just active
            $packs = Pack::getCardPacks($cardId, false); // Get all packs, not just active

            $card['tags'] = $tags;
            $card['packs'] = $packs;

            return JsonResponse::success($response, ['card' => $card]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Edit a card
     *
     * @param array<string, string> $args Route arguments
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
            if (isset($data['copy'])) {
                $updateData['copy'] = $data['copy'];
            }
            if (isset($data['type'])) {
                $updateData['type'] = $data['type'];
            }
            if (isset($data['choices'])) {
                $updateData['choices'] = $data['choices'];
            }
            if (isset($data['active'])) {
                $updateData['active'] = $data['active'] ? 1 : 0;
            }

            $affected = Card::update($cardId, $updateData);

            // Handle tags update if provided
            if (isset($data['tags']) && is_array($data['tags'])) {
                // Get current tags
                $currentTags = Tag::getCardTags($cardId, false); // Get all tags, not just active
                $currentTagIds = array_column($currentTags, 'tag_id');
                $newTagIds = array_map('intval', $data['tags']);
                
                // Remove tags that are no longer selected
                $tagsToRemove = array_diff($currentTagIds, $newTagIds);
                foreach ($tagsToRemove as $tagId) {
                    Tag::removeFromCard($cardId, $tagId);
                }
                
                // Add new tags
                $tagsToAdd = array_diff($newTagIds, $currentTagIds);
                foreach ($tagsToAdd as $tagId) {
                    Tag::addToCard($cardId, $tagId);
                }
            }

            // Handle packs update if provided
            if (isset($data['packs']) && is_array($data['packs'])) {
                // Get current packs
                $currentPacks = Pack::getCardPacks($cardId, false); // Get all packs, not just active
                $currentPackIds = array_column($currentPacks, 'pack_id');
                $newPackIds = array_map('intval', $data['packs']);
                
                // Remove packs that are no longer selected
                $packsToRemove = array_diff($currentPackIds, $newPackIds);
                foreach ($packsToRemove as $packId) {
                    Pack::removeFromCard($cardId, $packId);
                }
                
                // Add new packs
                $packsToAdd = array_diff($newPackIds, $currentPackIds);
                foreach ($packsToAdd as $packId) {
                    Pack::addToCard($cardId, $packId);
                }
            }

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
     *
     * @param array<string, string> $args Route arguments
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
     * Get all tags for a specific card
     *
     * @param array<string, string> $args Route arguments
     */
    public function getCardTags(Request $request, Response $response, array $args): Response
    {
        try {
            $cardId = (int) $args['cardId'];

            // Validate card exists
            $card = Card::getById($cardId);
            if ( ! $card) {
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
     *
     * @param array<string, string> $args Route arguments
     */
    public function addCardTag(Request $request, Response $response, array $args): Response
    {
        try {
            $cardId = (int) $args['cardId'];
            $tagId = (int) $args['tagId'];

            // Validate card exists
            $card = Card::getById($cardId);
            if ( ! $card) {
                return JsonResponse::notFound($response, 'Card not found');
            }

            // Validate tag exists
            $tag = Tag::find($tagId);
            if ( ! $tag) {
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
     *
     * @param array<string, string> $args Route arguments
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
     * Get all packs for a specific card
     *
     * @param array<string, string> $args Route arguments
     */
    public function getCardPacks(Request $request, Response $response, array $args): Response
    {
        try {
            $cardId = (int) $args['cardId'];

            // Validate card exists
            $card = Card::getById($cardId);
            if ( ! $card) {
                return JsonResponse::notFound($response, 'Card not found');
            }

            $packs = Pack::getCardPacks($cardId);

            return JsonResponse::success($response, [
                'packs' => $packs
            ]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, 'Failed to get card packs: ' . $e->getMessage());
        }
    }

    /**
     * Add a pack to a card
     *
     * @param array<string, string> $args Route arguments
     */
    public function addCardPack(Request $request, Response $response, array $args): Response
    {
        try {
            $cardId = (int) $args['cardId'];
            $packId = (int) $args['packId'];

            // Validate card exists
            $card = Card::getById($cardId);
            if ( ! $card) {
                return JsonResponse::notFound($response, 'Card not found');
            }

            // Validate pack exists
            $pack = Pack::find($packId);
            if ( ! $pack) {
                return JsonResponse::notFound($response, 'Pack not found');
            }

            $added = Pack::addToCard($cardId, $packId);

            return JsonResponse::success($response, [
                'message' => $added ? 'Pack added to card' : 'Pack already assigned to card',
                'added' => $added
            ]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, 'Failed to add pack to card: ' . $e->getMessage());
        }
    }

    /**
     * Remove a pack from a card
     *
     * @param array<string, string> $args Route arguments
     */
    public function removeCardPack(Request $request, Response $response, array $args): Response
    {
        try {
            $cardId = (int) $args['cardId'];
            $packId = (int) $args['packId'];

            $removed = Pack::removeFromCard($cardId, $packId);

            return JsonResponse::success($response, [
                'message' => $removed > 0 ? 'Pack removed from card' : 'Pack was not assigned to card',
                'removed' => $removed > 0
            ]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, 'Failed to remove pack from card: ' . $e->getMessage());
        }
    }
}
