<?php

declare(strict_types=1);

namespace CAH\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CAH\Models\Card;
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

            $validator = (new Validator())
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
}
