<?php

declare(strict_types=1);

namespace CAH\Enums;

enum GameEndReason: string
{
    case MAX_SCORE_REACHED = 'max_score_reached';
    case NO_BLACK_CARDS_LEFT = 'no_prompt_cards_left';
    case TOO_FEW_PLAYERS = 'too_few_players';
}
