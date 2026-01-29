<?php

declare(strict_types=1);

namespace CAH\Controllers;

use CAH\Exceptions\ValidationException;
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
     * List cards with filtering and pagination
     */
    public function listCards(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();

            $cardType = $queryParams['type'] ?? null;
            $tagIdParam = $queryParams['tag_id'] ?? null;
            $packIdParam = $queryParams['pack_id'] ?? null;
            $packActiveParam = $queryParams['pack_active'] ?? null;
            
            $tagId = null;
            $noTags = false;
            $packId = null;
            $noPacks = false;
            $packActive = null;

            if ($tagIdParam !== null) {
                if ($tagIdParam === 'none' || $tagIdParam === '0') {
                    $noTags = true;
                } else {
                    $tagId = (int) $tagIdParam;
                }
            }

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

            $active = isset($queryParams['active']) ? (bool) ( (int) $queryParams['active'] ) : true;
            $limit = isset($queryParams['limit']) ? (int) $queryParams['limit'] : 100;
            $offset = isset($queryParams['offset']) ? (int) $queryParams['offset'] : 0;

            // Validate parameters
            if ( ! in_array($cardType, [null, 'response', 'prompt'], true)) {
                throw new ValidationException('Invalid card type. Must be "response" or "prompt"');
            }

            if ($limit < 0 || $limit > 10000) {
                throw new ValidationException('Limit must be between 0 and 10000 (0 = no limit)');
            }

            if ($offset < 0) {
                throw new ValidationException('Offset must be non-negative');
            }

            // Use Card model to handle the complex query logic
            $result = Card::listWithFilters($cardType, $tagId, $noTags, $packId, $noPacks, $packActive, $active, $limit, $offset);
            $cards = $result['cards'];
            $total = $result['total'];

            // Get tags for all cards in one query (batch fetch to avoid N+1)
            $cardIds = array_column($cards, 'card_id');
            $cardIds = array_map(intval(...), $cardIds);
            $tagsByCardId = Tag::getCardTagsForMultipleCards($cardIds);

            // Attach tags to each card
            foreach ($cards as &$card) {
                $cardId = (int) $card['card_id'];
                $card['tags'] = $tagsByCardId[$cardId] ?? [];
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

            if ($cardType !== 'response' && $cardType !== 'prompt') {
                throw new ValidationException('Invalid card type. Must be "response" or "prompt"');
            }

            // Read CSV file
            $stream = $file->getStream();
            $csvContent = $stream->getContents();

            // Use CardImportService to handle the import
            $result = CardImportService::importFromCsv($csvContent, $cardType);

            return JsonResponse::success($response, $result);
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
     * Get all tags for a specific card
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
