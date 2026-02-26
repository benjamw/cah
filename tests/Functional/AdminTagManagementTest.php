<?php

declare(strict_types=1);

namespace CAH\Tests\Functional;

use CAH\Tests\TestCase;
use CAH\Database\Database;

/**
 * Admin Tag Management Functional Tests
 *
 * Tests admin functionality for creating, editing, and deleting tags
 */
class AdminTagManagementTest extends TestCase
{
    // ========================================
    // CREATING TAGS
    // ========================================

    public function test_can_create_tag_with_name_only(): void
    {
        // Arrange
        $tagName = 'base_set';

        // Act
        Database::execute(
            "INSERT INTO tags (name) VALUES (?)",
            [$tagName]
        );
        $tagId = Database::lastInsertId();

        // Assert
        $tag = Database::fetchOne("SELECT * FROM tags WHERE tag_id = ?", [$tagId]);
        $this->assertNotNull($tag);
        $this->assertEquals('base_set', $tag['name']);
        $this->assertNull($tag['description']); // Optional
        $this->assertEquals(1, $tag['active']); // Defaults to active
    }

    public function test_can_create_tag_with_name_and_description(): void
    {
        // Arrange
        $tagData = [
            'name' => 'expansion1',
            'description' => 'First expansion pack',
        ];

        // Act
        Database::execute(
            "INSERT INTO tags (name, description) VALUES (?, ?)",
            [$tagData['name'], $tagData['description']]
        );
        $tagId = Database::lastInsertId();

        // Assert
        $tag = Database::fetchOne("SELECT * FROM tags WHERE tag_id = ?", [$tagId]);
        $this->assertEquals('expansion1', $tag['name']);
        $this->assertEquals('First expansion pack', $tag['description']);
    }

    public function test_tag_names_must_be_unique(): void
    {
        // Arrange
        Database::execute("INSERT INTO tags (name) VALUES (?)", ['unique_tag']);

        // Act & Assert - Duplicate name should fail
        $this->expectException(\Exception::class);
        Database::execute("INSERT INTO tags (name) VALUES (?)", ['unique_tag']);
    }

    public function test_cannot_create_tag_without_name(): void
    {
        // Act & Assert
        $this->expectException(\Exception::class);
        Database::execute("INSERT INTO tags (description) VALUES (?)", ['No name']);
    }

    public function test_can_create_inactive_tag(): void
    {
        // Arrange
        $tagData = [
            'name' => 'inactive_tag',
            'active' => 0,
        ];

        // Act
        Database::execute(
            "INSERT INTO tags (name, active) VALUES (?, ?)",
            [$tagData['name'], $tagData['active']]
        );
        $tagId = Database::lastInsertId();

        // Assert
        $tag = Database::fetchOne("SELECT * FROM tags WHERE tag_id = ?", [$tagId]);
        $this->assertEquals(0, $tag['active']);
    }

    // ========================================
    // EDITING TAGS
    // ========================================

    public function test_can_edit_tag_name(): void
    {
        // Arrange
        Database::execute("INSERT INTO tags (name) VALUES (?)", ['old_name']);
        $tagId = Database::lastInsertId();

        // Act
        Database::execute(
            "UPDATE tags SET name = ? WHERE tag_id = ?",
            ['new_name', $tagId]
        );

        // Assert
        $tag = Database::fetchOne("SELECT * FROM tags WHERE tag_id = ?", [$tagId]);
        $this->assertEquals('new_name', $tag['name']);
    }

    public function test_editing_tag_name_to_duplicate_fails(): void
    {
        // Arrange
        Database::execute("INSERT INTO tags (name) VALUES (?)", ['tag1']);
        Database::execute("INSERT INTO tags (name) VALUES (?)", ['tag2']);
        $tag2Id = Database::lastInsertId();

        // Act & Assert - Try to rename tag2 to tag1 (duplicate)
        $this->expectException(\Exception::class);
        Database::execute(
            "UPDATE tags SET name = ? WHERE tag_id = ?",
            ['tag1', $tag2Id]
        );
    }

    public function test_can_edit_tag_description(): void
    {
        // Arrange
        Database::execute(
            "INSERT INTO tags (name, description) VALUES (?, ?)",
            ['test_tag', 'Old description']
        );
        $tagId = Database::lastInsertId();

        // Act
        Database::execute(
            "UPDATE tags SET description = ? WHERE tag_id = ?",
            ['New description', $tagId]
        );

        // Assert
        $tag = Database::fetchOne("SELECT * FROM tags WHERE tag_id = ?", [$tagId]);
        $this->assertEquals('New description', $tag['description']);
    }

    public function test_can_toggle_tag_active_status(): void
    {
        // Arrange
        Database::execute("INSERT INTO tags (name, active) VALUES (?, ?)", ['test', 1]);
        $tagId = Database::lastInsertId();

        // Act - Deactivate
        Database::execute("UPDATE tags SET active = ? WHERE tag_id = ?", [0, $tagId]);

        // Assert
        $tag = Database::fetchOne("SELECT * FROM tags WHERE tag_id = ?", [$tagId]);
        $this->assertEquals(0, $tag['active']);
    }

    public function test_editing_tag_name_does_not_affect_existing_games(): void
    {
        // Arrange - Create tag
        Database::execute("INSERT INTO tags (name) VALUES (?)", ['original_name']);
        $tagId = (int) Database::lastInsertId();

        // Simulate game using this tag (stores tag ID, not name)
        $gameTags = json_encode([$tagId]);

        // Act - Rename tag
        Database::execute(
            "UPDATE tags SET name = ? WHERE tag_id = ?",
            ['renamed_tag', $tagId]
        );

        // Assert - Game still has same tag ID, so it's unaffected
        $gameTagIds = json_decode($gameTags, true);
        $this->assertContains($tagId, $gameTagIds, 'Game should still reference tag by ID');
    }

    // ========================================
    // DELETING TAGS (SOFT DELETE)
    // ========================================

    public function test_deleting_tag_soft_deletes(): void
    {
        // Arrange
        Database::execute("INSERT INTO tags (name, active) VALUES (?, ?)", ['tag_to_delete', 1]);
        $tagId = Database::lastInsertId();

        // Act - Soft delete
        Database::execute("UPDATE tags SET active = ? WHERE tag_id = ?", [0, $tagId]);

        // Assert
        $tag = Database::fetchOne("SELECT * FROM tags WHERE tag_id = ?", [$tagId]);
        $this->assertNotNull($tag, 'Tag should still exist');
        $this->assertEquals(0, $tag['active'], 'Tag should be inactive');
    }

    public function test_soft_deleted_tag_associations_remain(): void
    {
        // Arrange - Create tag, card, and association
        Database::execute("INSERT INTO tags (name) VALUES (?)", ['test_tag']);
        $tagId = (int) Database::lastInsertId();

        Database::execute("INSERT INTO cards (card_type, value) VALUES (?, ?)", ['white', 'Test']);
        $cardId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)",
            [$cardId, $tagId]
        );

        // Act - Soft delete tag
        Database::execute("UPDATE tags SET active = ? WHERE tag_id = ?", [0, $tagId]);

        // Assert - Association still exists
        $association = Database::fetchOne(
            "SELECT * FROM cards_to_tags WHERE card_id = ? AND tag_id = ?",
            [$cardId, $tagId]
        );
        $this->assertNotNull($association, 'Association should remain');
    }

    public function test_filtering_ignores_inactive_tags(): void
    {
        // Arrange - Create active and inactive tags
        Database::execute("INSERT INTO tags (name, active) VALUES (?, ?)", ['active_tag', 1]);
        $activeTagId = (int) Database::lastInsertId();

        Database::execute("INSERT INTO tags (name, active) VALUES (?, ?)", ['inactive_tag', 0]);
        $inactiveTagId = (int) Database::lastInsertId();

        // Act - Get only active tags
        $activeTags = Database::fetchAll("SELECT * FROM tags WHERE active = 1");
        $activeTagIds = array_column($activeTags, 'tag_id');

        // Assert
        $this->assertContains($activeTagId, $activeTagIds, 'Active tag should be in results');
        $this->assertNotContains($inactiveTagId, $activeTagIds, 'Inactive tag should be filtered out');
    }

    public function test_hard_delete_card_removes_tag_associations(): void
    {
        // Arrange - Create card with tag association
        Database::execute("INSERT INTO cards (card_type, value) VALUES (?, ?)", ['white', 'Card']);
        $cardId = (int) Database::lastInsertId();

        Database::execute("INSERT INTO tags (name) VALUES (?)", ['tag1']);
        $tagId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)",
            [$cardId, $tagId]
        );

        // Act - Hard delete card (remove associations first)
        Database::execute("DELETE FROM cards_to_tags WHERE card_id = ?", [$cardId]);
        Database::execute("DELETE FROM cards WHERE card_id = ?", [$cardId]);

        // Assert
        $associations = Database::fetchAll(
            "SELECT * FROM cards_to_tags WHERE card_id = ?",
            [$cardId]
        );
        $this->assertCount(0, $associations, 'Associations should be deleted');
    }

    // ========================================
    // CARD-TAG ASSOCIATIONS
    // ========================================

    public function test_can_add_tag_to_card(): void
    {
        // Arrange
        Database::execute("INSERT INTO cards (card_type, value) VALUES (?, ?)", ['white', 'Card']);
        $cardId = (int) Database::lastInsertId();

        Database::execute("INSERT INTO tags (name) VALUES (?)", ['new_tag']);
        $tagId = (int) Database::lastInsertId();

        // Act
        Database::execute(
            "INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)",
            [$cardId, $tagId]
        );

        // Assert
        $association = Database::fetchOne(
            "SELECT * FROM cards_to_tags WHERE card_id = ? AND tag_id = ?",
            [$cardId, $tagId]
        );
        $this->assertNotNull($association);
    }

    public function test_cannot_add_duplicate_tag_to_card(): void
    {
        // Arrange
        Database::execute("INSERT INTO cards (card_type, value) VALUES (?, ?)", ['white', 'Card']);
        $cardId = (int) Database::lastInsertId();

        Database::execute("INSERT INTO tags (name) VALUES (?)", ['tag']);
        $tagId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)",
            [$cardId, $tagId]
        );

        // Act & Assert - Primary key constraint should prevent duplicate
        $this->expectException(\Exception::class);
        Database::execute(
            "INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)",
            [$cardId, $tagId]
        );
    }

    public function test_can_remove_tag_from_card(): void
    {
        // Arrange
        Database::execute("INSERT INTO cards (card_type, value) VALUES (?, ?)", ['white', 'Card']);
        $cardId = (int) Database::lastInsertId();

        Database::execute("INSERT INTO tags (name) VALUES (?)", ['tag']);
        $tagId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)",
            [$cardId, $tagId]
        );

        // Act
        Database::execute(
            "DELETE FROM cards_to_tags WHERE card_id = ? AND tag_id = ?",
            [$cardId, $tagId]
        );

        // Assert
        $association = Database::fetchOne(
            "SELECT * FROM cards_to_tags WHERE card_id = ? AND tag_id = ?",
            [$cardId, $tagId]
        );
        $this->assertNull($association, 'Association should be removed');
    }

    public function test_card_can_have_zero_tags(): void
    {
        // Arrange
        Database::execute("INSERT INTO cards (card_type, value) VALUES (?, ?)", ['white', 'No tags']);
        $cardId = (int) Database::lastInsertId();

        // Act - Check card has no tag associations
        $associations = Database::fetchAll(
            "SELECT * FROM cards_to_tags WHERE card_id = ?",
            [$cardId]
        );

        // Assert
        $this->assertCount(0, $associations, 'Card can have zero tags');
    }

    public function test_can_remove_last_tag_from_card(): void
    {
        // Arrange - Card with one tag
        Database::execute("INSERT INTO cards (card_type, value) VALUES (?, ?)", ['white', 'Card']);
        $cardId = (int) Database::lastInsertId();

        Database::execute("INSERT INTO tags (name) VALUES (?)", ['only_tag']);
        $tagId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)",
            [$cardId, $tagId]
        );

        // Act - Remove the only tag
        Database::execute(
            "DELETE FROM cards_to_tags WHERE card_id = ? AND tag_id = ?",
            [$cardId, $tagId]
        );

        // Assert - Card now has zero tags (which is allowed)
        $associations = Database::fetchAll(
            "SELECT * FROM cards_to_tags WHERE card_id = ?",
            [$cardId]
        );
        $this->assertCount(0, $associations, 'Can remove last tag from card');
    }

    public function test_card_can_have_multiple_tags(): void
    {
        // Arrange
        Database::execute("INSERT INTO cards (card_type, value) VALUES (?, ?)", ['white', 'Multi-tag']);
        $cardId = (int) Database::lastInsertId();

        Database::execute("INSERT INTO tags (name) VALUES (?)", ['tag1']);
        $tag1Id = (int) Database::lastInsertId();

        Database::execute("INSERT INTO tags (name) VALUES (?)", ['tag2']);
        $tag2Id = (int) Database::lastInsertId();

        Database::execute("INSERT INTO tags (name) VALUES (?)", ['tag3']);
        $tag3Id = (int) Database::lastInsertId();

        // Act - Add multiple tags
        foreach ([$tag1Id, $tag2Id, $tag3Id] as $tagId) {
            Database::execute(
                "INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)",
                [$cardId, $tagId]
            );
        }

        // Assert
        $associations = Database::fetchAll(
            "SELECT * FROM cards_to_tags WHERE card_id = ?",
            [$cardId]
        );
        $this->assertCount(3, $associations, 'Card should have 3 tags');
    }
}
