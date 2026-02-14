<?php

declare(strict_types=1);

namespace CAH\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CAH\Models\Card;
use CAH\Models\Game;
use CAH\Models\Pack;
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
     * Get a random pairing of prompt card with matching number of response cards
     * Filters by active packs only
     */
    public function getRandomPairing(Request $request, Response $response): Response
    {
        try {
            // Get a random active prompt card
            $promptCard = Card::getRandomPromptCard(true); // activePacksOnly = true

            if ( ! $promptCard) {
                return JsonResponse::error($response, 'No active prompt cards available', 404);
            }

            // Get the number of response cards needed (based on choices)
            $choices = (int) ( $promptCard['choices'] ?? 1 );

            // Get random active response cards
            $responseCards = Card::getRandomResponseCards($choices, true); // activePacksOnly = true

            if (count($responseCards) < $choices) {
                return JsonResponse::error($response, 'Not enough active response cards available', 404);
            }

            // Hydrate with packs and tags
            $promptCardId = (int) $promptCard['card_id'];
            $responseCardIds = array_map(fn(array $card): int => (int) $card['card_id'], $responseCards);

            $allCardIds = array_merge([$promptCardId], $responseCardIds);

            // Get packs and tags for all cards
            $packsByCardId = Pack::getCardPacksForMultipleCards($allCardIds, true); // activeOnly = true
            $tagsByCardId = Tag::getCardTagsForMultipleCards($allCardIds, true); // activeOnly = true

            // Attach packs and tags to prompt card
            $promptCard['packs'] = $packsByCardId[$promptCardId] ?? [];
            $promptCard['tags'] = $tagsByCardId[$promptCardId] ?? [];

            // Attach packs and tags to response cards
            foreach ($responseCards as &$card) {
                $cardId = (int) $card['card_id'];
                $card['packs'] = $packsByCardId[$cardId] ?? [];
                $card['tags'] = $tagsByCardId[$cardId] ?? [];
            }

            return JsonResponse::success($response, [
                'prompt' => $promptCard,
                'responses' => $responseCards,
            ]);
        } catch (\Exception $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }
    }
}
