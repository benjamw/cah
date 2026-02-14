<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Services\GameService;
use CAH\Services\RoundService;
use CAH\Services\CardService;
use CAH\Models\Game;
use CAH\Models\Card;
use CAH\Enums\GameState;
use CAH\Enums\GameEndReason;
use CAH\Exceptions\InsufficientCardsException;
use CAH\Tests\TestCase;

class InsufficientCardsTest extends TestCase
{
    /**
     * Test that starting a game with insufficient response cards throws exception
     */
    public function testStartGameWithInsufficientWhiteCards(): void
    {
        // Create a test game with very few cards
        $creatorName = 'TestPlayer';
        $tagIds = [1]; // Assume tag 1 exists
        
        // Create game
        $result = GameService::createGame($creatorName, $tagIds, [
            'hand_size' => 100, // Require 100 cards per player (unrealistic)
        ]);
        
        $gameId = $result['game_id'];
        $playerId = $result['player_id'];
        
        // Add more players
        GameService::joinGame($gameId, 'Player2');
        GameService::joinGame($gameId, 'Player3');
        
        // Artificially reduce response card pile to force insufficient cards
        $game = Game::find($gameId);
        $game['draw_pile']['response'] = array_slice($game['draw_pile']['response'], 0, 50); // Only 50 cards
        Game::update($gameId, ['draw_pile' => $game['draw_pile']]);
        
        // Try to start game - should throw exception
        $this->expectException(InsufficientCardsException::class);
        GameService::startGame($gameId, $playerId);
    }
    
    /**
     * Test that starting a game with no prompt cards throws exception
     */
    public function testStartGameWithNoBlackCards(): void
    {
        // Create a test game
        $creatorName = 'TestPlayer';
        $tagIds = [1];
        
        $result = GameService::createGame($creatorName, $tagIds);
        $gameId = $result['game_id'];
        $playerId = $result['player_id'];
        
        // Add minimum players
        GameService::joinGame($gameId, 'Player2');
        GameService::joinGame($gameId, 'Player3');
        
        // Remove all prompt cards
        $game = Game::find($gameId);
        $game['draw_pile']['prompt'] = [];
        Game::update($gameId, ['draw_pile' => $game['draw_pile']]);
        
        // Try to start game - should throw exception
        $this->expectException(InsufficientCardsException::class);
        GameService::startGame($gameId, $playerId);
    }
    
    /**
     * Test that submitting cards when draw pile is empty throws exception
     * BUT player doesn't lose their submitted cards
     */
    public function testSubmitCardsWithEmptyDrawPile(): void
    {
        // Start a game
        $creatorName = 'TestPlayer';
        $tagIds = [1];
        
        $result = GameService::createGame($creatorName, $tagIds);
        $gameId = $result['game_id'];
        $player1Id = $result['player_id'];
        
        $player2Result = GameService::joinGame($gameId, 'Player2');
        $player2Id = $player2Result['player_id'];
        
        $player3Result = GameService::joinGame($gameId, 'Player3');
        $player3Id = $player3Result['player_id'];
        
        // Start game
        $gameState = GameService::startGame($gameId, $player1Id);
        
        // Get the current czar
        $czarId = $gameState['current_czar_id'];
        
        // Get prompt card ID (it might be hydrated to an array with card details)
        $promptCard = $gameState['current_prompt_card'];
        $promptCardId = is_array($promptCard) ? $promptCard['card_id'] : $promptCard;
        $choices = CardService::getPromptCardChoices($promptCardId);
        
        // Find a non-czar player
        $submittingPlayerId = null;
        foreach ([$player1Id, $player2Id, $player3Id] as $pid) {
            if ($pid !== $czarId) {
                $submittingPlayerId = $pid;
                break;
            }
        }
        
        // Get player's hand before submission
        $game = Game::find($gameId);
        $playerData = $game['player_data'];
        $playerHand = null;
        foreach ($playerData['players'] as $player) {
            if ($player['id'] === $submittingPlayerId) {
                $playerHand = $player['hand'];
                break;
            }
        }
        
        $this->assertNotNull($playerHand);
        $this->assertNotEmpty($playerHand);
        
        $cardsToSubmit = array_slice($playerHand, 0, $choices);
        
        // Empty the draw pile
        Game::update($gameId, ['draw_pile' => ['response' => [], 'prompt' => $game['draw_pile']['prompt']]]);
        
        // Try to submit cards - should throw exception
        try {
            RoundService::submitCards($gameId, $submittingPlayerId, $cardsToSubmit);
            $this->fail('Expected InsufficientCardsException to be thrown');
        } catch (InsufficientCardsException) {
            // Good - exception was thrown
            
            // Verify player still has their cards (didn't lose them)
            $gameAfter = Game::find($gameId);
            $playerDataAfter = $gameAfter['player_data'];
            $playerHandAfter = null;
            foreach ($playerDataAfter['players'] as $player) {
                if ($player['id'] === $submittingPlayerId) {
                    $playerHandAfter = $player['hand'];
                    break;
                }
            }
            
            $this->assertNotNull($playerHandAfter);
            foreach ($cardsToSubmit as $cardId) {
                $this->assertContains($cardId, $playerHandAfter, 'Player should still have their cards after failed submission');
            }
        }
    }
    
    /**
     * Test that game ends gracefully when prompt cards run out
     */
    public function testGameEndsWhenBlackCardsRunOut(): void
    {
        // Create a game with only 2 prompt cards
        $creatorName = 'TestPlayer';
        $tagIds = [1];
        
        $result = GameService::createGame($creatorName, $tagIds);
        $gameId = $result['game_id'];
        $player1Id = $result['player_id'];
        
        $player2Result = GameService::joinGame($gameId, 'Player2');
        $player2Id = $player2Result['player_id'];
        
        $player3Result = GameService::joinGame($gameId, 'Player3');
        $player3Id = $player3Result['player_id'];
        
        // Limit prompt cards to just 1 (one for start, then empty)
        $game = Game::find($gameId);
        $game['draw_pile']['prompt'] = array_slice($game['draw_pile']['prompt'], 0, 1);
        Game::update($gameId, ['draw_pile' => $game['draw_pile']]);
        
        // Start game
        $gameState = GameService::startGame($gameId, $player1Id);
        $this->assertEquals(GameState::PLAYING->value, $gameState['state']);
        
        // Play one round
        $czarId = $gameState['current_czar_id'];
        
        // Get the prompt card ID (might be hydrated to array)
        $promptCard = $gameState['current_prompt_card'];
        $promptCardId = is_array($promptCard) ? $promptCard['card_id'] : $promptCard;
        $choices = CardService::getPromptCardChoices($promptCardId);
        
        // Submit cards from non-czar players
        foreach ([$player1Id, $player2Id, $player3Id] as $pid) {
            if ($pid === $czarId) {
                continue;
            }
            
            $game = Game::find($gameId);
            $playerData = $game['player_data'];
            $playerHand = null;
            foreach ($playerData['players'] as $player) {
                if ($player['id'] === $pid) {
                    $playerHand = $player['hand'];
                    break;
                }
            }
            
            // Submit the required number of cards
            $cardsToSubmit = array_slice($playerHand, 0, $choices);
            RoundService::submitCards($gameId, $pid, $cardsToSubmit);
        }
        
        // Czar picks a winner
        $game = Game::find($gameId);
        $playerData = $game['player_data'];
        $winnerSubmission = $playerData['submissions'][0];
        $winnerId = $winnerSubmission['player_id'];
        
        RoundService::pickWinner($gameId, $czarId, $winnerId);
        
        // Check for game over
        $game = Game::find($gameId);
        $playerData = $game['player_data'];
        $winner = RoundService::checkForWinner($playerData);
        
        if ( ! $winner) {
            // Determine next czar and advance round
            $nextCzarId = $czarId; // For simplicity, same czar
            foreach ([$player1Id, $player2Id, $player3Id] as $pid) {
                if ($pid !== $czarId) {
                    $nextCzarId = $pid;
                    break;
                }
            }
            
            GameService::setNextCzar($gameId, $czarId, $nextCzarId);
            
            // This should end the game due to no prompt cards
            $gameState = RoundService::advanceToNextRound($gameId);
            
            $this->assertEquals(GameState::FINISHED->value, $gameState['state']);
            $this->assertEquals(GameEndReason::NO_BLACK_CARDS_LEFT->value, $gameState['end_reason']);
        }
    }
}
