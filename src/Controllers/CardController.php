<?php

declare(strict_types=1);

namespace CAH\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CAH\Models\Card;
use CAH\Models\Game;
use CAH\Models\Tag;
use CAH\Exceptions\ValidationException;
use CAH\Utils\Response as JsonResponse;
use CAH\Utils\Validator;

class CardController
{
    /**
     * Get cards by IDs
     */
    public function getByIds(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody() ?? [];

            $validator = ( new Validator() )
                ->required($data['card_ids'] ?? null, 'card_ids')
                ->array($data['card_ids'] ?? null, 'card_ids', 1);

            if ($validator->fails()) {
                throw new ValidationException('Validation failed', $validator->getErrors());
            }

            $cardValidation = Validator::validateCardIds($data['card_ids']);
            if ( ! $cardValidation['valid']) {
                throw new ValidationException($cardValidation['error']);
            }

            $cards = Card::getByIds($cardValidation['card_ids']);

            return JsonResponse::success($response, ['cards' => $cards]);
        } catch (ValidationException $e) {
            return JsonResponse::validationError($response, $e->getErrors(), $e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * Add or remove a tag from a card in the player's hand
     */
    public function updateCardTag(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody() ?? [];

            // Validate required fields
            $validator = ( new Validator() )
                ->required($data['card_id'] ?? null, 'card_id')
                ->required($data['tag_id'] ?? null, 'tag_id')
                ->required($data['action'] ?? null, 'action');

            if ($validator->fails()) {
                throw new ValidationException('Validation failed', $validator->getErrors());
            }

            $cardId = (int) $data['card_id'];
            $tagId = (int) $data['tag_id'];
            $action = $data['action'];

            // Validate action
            if ( ! in_array($action, ['add', 'remove'], true)) {
                throw new ValidationException('Action must be "add" or "remove"');
            }

            // Get session data
            $gameId = $_SESSION['game_id'] ?? null;
            $playerId = $_SESSION['player_id'] ?? null;

            if ( ! $gameId || ! $playerId) {
                return JsonResponse::unauthorized($response, 'Not in a game');
            }

            // Load game and find player's hand
            $game = Game::find($gameId);
            if ( ! $game) {
                return JsonResponse::notFound($response, 'Game not found');
            }

            $playerData = $game['player_data'];
            $playerHand = null;

            foreach ($playerData['players'] as $player) {
                if ($player['id'] === $playerId) {
                    $playerHand = $player['hand'] ?? [];
                    break;
                }
            }

            if ($playerHand === null) {
                return JsonResponse::notFound($response, 'Player not found in game');
            }

            // Check if card is in player's hand
            if ( ! in_array($cardId, $playerHand, true)) {
                return JsonResponse::error($response, 'Card is not in your hand', 403);
            }

            // Validate tag exists
            $tag = Tag::find($tagId);
            if ( ! $tag) {
                return JsonResponse::notFound($response, 'Tag not found');
            }

            // Perform the action
            if ($action === 'add') {
                $success = Tag::addToCard($cardId, $tagId);
                $message = $success ? 'Tag added to card' : 'Tag already on card';
            } else {
                $affected = Tag::removeFromCard($cardId, $tagId);
                $success = $affected > 0;
                $message = $success ? 'Tag removed from card' : 'Tag was not on card';
            }

            // Get updated tags for the card
            $cardTags = Tag::getCardTags($cardId);

            return JsonResponse::success($response, [
                'message' => $message,
                'card_id' => $cardId,
                'tags' => $cardTags,
            ]);
        } catch (ValidationException $e) {
            return JsonResponse::validationError($response, $e->getErrors(), $e->getMessage());
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }
}
