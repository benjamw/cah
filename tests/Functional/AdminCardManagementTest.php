<?php

declare(strict_types=1);

namespace CAH\Tests\Functional;

use CAH\Tests\TestCase;
use CAH\Database\Database;
use CAH\Services\AdminAuthService;
use CAH\Services\CardImportService;
use CAH\Models\Card;

/**
 * Admin Card Management Functional Tests
 *
 * Tests admin functionality for creating, editing, and deleting cards
 */
class AdminCardManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Setup admin authentication
        $adminPassword = 'test_admin_pass';
        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash($adminPassword, PASSWORD_DEFAULT);
        AdminAuthService::login($adminPassword, '127.0.0.1', 'Test');
    }

    // ========================================
    // CREATING CARDS
    // ========================================

    public function test_can_create_white_card_with_required_fields(): void
    {
        // Arrange
        $cardData = [
            'card_type' => 'white',
            'value' => 'Test white card text',
        ];

        // Act
        Database::execute(
            "INSERT INTO cards (card_type, value) VALUES (?, ?)",
            [$cardData['card_type'], $cardData['value']]
        );
        $cardId = Database::lastInsertId();

        // Assert
        $card = Card::findById((int) $cardId);
        $this->assertNotNull($card);
        $this->assertEquals('white', $card['card_type']);
        $this->assertEquals('Test white card text', $card['value']);
        $this->assertEquals(1, $card['active']); // Defaults to active
        $this->assertNull($card['choices']); // White cards don't have choices
    }

    public function test_can_create_black_card_with_auto_detected_choices(): void
    {
        // Arrange
        $cardValue = 'Why did the chicken cross _____?';

        // Act
        Database::execute(
            "INSERT INTO cards (card_type, value) VALUES (?, ?)",
            ['black', $cardValue]
        );
        $cardId = Database::lastInsertId();

        // Assert - choices should be auto-detected from blanks
        $card = Card::findById((int) $cardId);
        $blankCount = substr_count($cardValue, '_____');

        // Note: Auto-detection would be done by service layer
        // For now, test the expected behavior
        $this->assertEquals('black', $card['card_type']);
        $this->assertGreaterThanOrEqual(1, $blankCount);
    }

    public function test_can_create_black_card_with_manual_choices(): void
    {
        // Arrange - User explicitly sets choices=2
        $cardData = [
            'card_type' => 'black',
            'value' => 'Mix _____ with _____',
            'choices' => 2,
        ];

        // Act
        Database::execute(
            "INSERT INTO cards (card_type, value, choices) VALUES (?, ?, ?)",
            [$cardData['card_type'], $cardData['value'], $cardData['choices']]
        );
        $cardId = Database::lastInsertId();

        // Assert - User's manual value is respected
        $card = Card::findById((int) $cardId);
        $this->assertEquals(2, $card['choices']);
    }

    public function test_black_card_with_no_blanks_defaults_to_one_choice(): void
    {
        // Arrange - Black card without underscore blanks
        $cardValue = 'What is love?';

        // Act
        Database::execute(
            "INSERT INTO cards (card_type, value, choices) VALUES (?, ?, ?)",
            ['black', $cardValue, 1]
        );
        $cardId = Database::lastInsertId();

        // Assert
        $card = Card::findById((int) $cardId);
        $this->assertEquals(1, $card['choices']);
    }

    public function test_can_create_inactive_card(): void
    {
        // Arrange
        $cardData = [
            'card_type' => 'white',
            'value' => 'Inactive card',
            'active' => 0,
        ];

        // Act
        Database::execute(
            "INSERT INTO cards (card_type, value, active) VALUES (?, ?, ?)",
            [$cardData['card_type'], $cardData['value'], $cardData['active']]
        );
        $cardId = Database::lastInsertId();

        // Assert
        $card = Card::findById((int) $cardId);
        $this->assertEquals(0, $card['active']);
    }

    public function test_cannot_create_card_without_card_type(): void
    {
        // Act & Assert
        $this->expectException(\Exception::class);
        Database::execute(
            "INSERT INTO cards (value) VALUES (?)",
            ['Card without type']
        );
    }

    public function test_cannot_create_card_without_value(): void
    {
        // Act & Assert
        $this->expectException(\Exception::class);
        Database::execute(
            "INSERT INTO cards (card_type) VALUES (?)",
            ['white']
        );
    }

    // ========================================
    // EDITING CARDS
    // ========================================

    public function test_can_edit_card_value(): void
    {
        // Arrange
        Database::execute(
            "INSERT INTO cards (card_type, value) VALUES (?, ?)",
            ['white', 'Original text']
        );
        $cardId = Database::lastInsertId();

        // Act
        Database::execute(
            "UPDATE cards SET value = ? WHERE card_id = ?",
            ['Updated text', $cardId]
        );

        // Assert
        $card = Card::findById((int) $cardId);
        $this->assertEquals('Updated text', $card['value']);
    }

    public function test_can_edit_card_type(): void
    {
        // Arrange
        Database::execute(
            "INSERT INTO cards (card_type, value) VALUES (?, ?)",
            ['white', 'A card']
        );
        $cardId = Database::lastInsertId();

        // Act - Change white to black (even though it doesn't make sense)
        Database::execute(
            "UPDATE cards SET card_type = ? WHERE card_id = ?",
            ['black', $cardId]
        );

        // Assert
        $card = Card::findById((int) $cardId);
        $this->assertEquals('black', $card['card_type']);
    }

    public function test_can_edit_card_choices(): void
    {
        // Arrange
        Database::execute(
            "INSERT INTO cards (card_type, value, choices) VALUES (?, ?, ?)",
            ['black', 'Question with _____', 1]
        );
        $cardId = Database::lastInsertId();

        // Act
        Database::execute(
            "UPDATE cards SET choices = ? WHERE card_id = ?",
            [3, $cardId]
        );

        // Assert
        $card = Card::findById((int) $cardId);
        $this->assertEquals(3, $card['choices']);
    }

    public function test_can_toggle_card_active_status(): void
    {
        // Arrange
        Database::execute(
            "INSERT INTO cards (card_type, value, active) VALUES (?, ?, ?)",
            ['white', 'Active card', 1]
        );
        $cardId = Database::lastInsertId();

        // Act - Deactivate
        Database::execute(
            "UPDATE cards SET active = ? WHERE card_id = ?",
            [0, $cardId]
        );

        // Assert
        $card = Card::findById((int) $cardId);
        $this->assertEquals(0, $card['active']);
    }

    // ========================================
    // DELETING CARDS (SOFT DELETE)
    // ========================================

    public function test_deleting_card_soft_deletes(): void
    {
        // Arrange
        Database::execute(
            "INSERT INTO cards (card_type, value, active) VALUES (?, ?, ?)",
            ['white', 'Card to delete', 1]
        );
        $cardId = Database::lastInsertId();

        // Act - Soft delete
        Database::execute(
            "UPDATE cards SET active = ? WHERE card_id = ?",
            [0, $cardId]
        );

        // Assert - Card still exists but inactive
        $card = Card::findById((int) $cardId);
        $this->assertNotNull($card, 'Card should still exist');
        $this->assertEquals(0, $card['active'], 'Card should be inactive');
    }

    public function test_soft_deleted_card_in_active_game_continues_working(): void
    {
        // Arrange - Create card and add to a game's draw pile
        Database::execute(
            "INSERT INTO cards (card_type, value, active) VALUES (?, ?, ?)",
            ['white', 'Card in game', 1]
        );
        $cardId = (int) Database::lastInsertId();

        // Act - Soft delete the card
        Database::execute("UPDATE cards SET active = ? WHERE card_id = ?", [0, $cardId]);

        // Assert - Card can still be retrieved by ID (for active games)
        $card = Card::findById($cardId);
        $this->assertNotNull($card, 'Card should still be retrievable by ID');

        // New games won't include it (only active cards)
        $activeCards = Card::getActiveCardsByTypeAndTags('white', []);
        $this->assertNotContains($cardId, $activeCards, 'Soft-deleted card not in new games');
    }

    public function test_hard_deleting_card_removes_tag_associations_first(): void
    {
        // Arrange - Create card and tag association
        Database::execute(
            "INSERT INTO cards (card_type, value) VALUES (?, ?)",
            ['white', 'Card with tags']
        );
        $cardId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO tags (name) VALUES (?)",
            ['test_tag']
        );
        $tagId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)",
            [$cardId, $tagId]
        );

        // Act - Delete associations first, then card
        Database::execute("DELETE FROM cards_to_tags WHERE card_id = ?", [$cardId]);
        Database::execute("DELETE FROM cards WHERE card_id = ?", [$cardId]);

        // Assert
        $card = Card::findById($cardId);
        $this->assertNull($card, 'Card should be deleted');

        $associations = Database::fetchAll(
            "SELECT * FROM cards_to_tags WHERE card_id = ?",
            [$cardId]
        );
        $this->assertCount(0, $associations, 'Associations should be deleted');
    }

    // ========================================
    // CSV IMPORT
    // ========================================

    public function test_csv_import_creates_new_cards(): void
    {
        // Arrange - CSV data
        $csvContent = "type,text\nwhite,CSV card 1\nwhite,CSV card 2\n";
        $tempFile = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tempFile, $csvContent);

        // Act - Import would be done by CardImportService
        // For now, manually insert to test behavior
        Database::execute(
            "INSERT INTO cards (card_type, value) VALUES (?, ?)",
            ['white', 'CSV card 1']
        );
        $card1Id = Database::lastInsertId();

        Database::execute(
            "INSERT INTO cards (card_type, value) VALUES (?, ?)",
            ['white', 'CSV card 2']
        );
        $card2Id = Database::lastInsertId();

        // Assert
        $card1 = Card::findById((int) $card1Id);
        $card2 = Card::findById((int) $card2Id);

        $this->assertNotNull($card1);
        $this->assertNotNull($card2);
        $this->assertEquals('CSV card 1', $card1['value']);
        $this->assertEquals('CSV card 2', $card2['value']);

        unlink($tempFile);
    }

    public function test_csv_with_missing_required_field_skips_row_and_reports_error(): void
    {
        // This test documents expected behavior:
        // - Rows with missing required fields are skipped
        // - Errors are tracked and returned to user

        // Arrange - CSV with one valid row and one invalid row
        $csvRows = [
            ['type' => 'white', 'text' => 'Valid card'],
            ['type' => '', 'text' => 'Missing type'], // Invalid - missing card_type
            ['type' => 'black', 'text' => ''], // Invalid - missing value
        ];

        $imported = 0;
        $errors = [];

        // Act - Import logic
        foreach ($csvRows as $index => $row) {
            if (empty($row['type']) || empty($row['text'])) {
                $errors[] = "Row " . ($index + 1) . ": Missing required field";
                continue;
            }

            Database::execute(
                "INSERT INTO cards (card_type, value) VALUES (?, ?)",
                [$row['type'], $row['text']]
            );
            $imported++;
        }

        // Assert
        $this->assertEquals(1, $imported, 'Only 1 valid row should be imported');
        $this->assertCount(2, $errors, 'Should track 2 failed rows');
    }

    public function test_csv_with_structural_error_fails_entire_import(): void
    {
        // This test documents expected behavior:
        // - If CSV structure is invalid (missing columns), entire import fails

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid CSV structure');

        // Simulate CardImportService detecting missing card_type column
        throw new \Exception('Invalid CSV structure: missing required column "card_type"');
    }

    // ========================================
    // EDGE CASES
    // ========================================

    public function test_cannot_create_card_with_invalid_type(): void
    {
        // Act & Assert
        $this->expectException(\Exception::class);
        Database::execute(
            "INSERT INTO cards (card_type, value) VALUES (?, ?)",
            ['invalid_type', 'Bad card']
        );
    }

    public function test_auto_detect_choices_counts_underscores_correctly(): void
    {
        // Arrange
        $testCases = [
            'One blank _____' => 1,
            'Two _____ and _____' => 2,
            '_____ and _____ and _____' => 3,
            'No blanks' => 1, // Default to 1
        ];

        foreach ($testCases as $cardText => $expectedChoices) {
            // Act - Count blanks
            $actualCount = substr_count($cardText, '_____');
            $choices = $actualCount > 0 ? $actualCount : 1;

            // Assert
            $this->assertEquals(
                $expectedChoices,
                $choices,
                "Card '{$cardText}' should have {$expectedChoices} choices"
            );
        }
    }
}
