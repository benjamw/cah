<?php

declare(strict_types=1);

namespace CAH\Tests\Integration;

use CAH\Tests\TestCase;
use CAH\Controllers\AdminCardController;
use CAH\Models\Tag;
use CAH\Models\Card;
use CAH\Database\Database;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/**
 * Admin Card Controller CSV Import Test
 *
 * Tests the actual AdminCardController::importCards method with real file uploads
 */
class AdminControllerImportTest extends TestCase
{
    private AdminCardController $controller;
    private static bool $needsReseed = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new AdminCardController();
        
        // Don't delete between tests - let each test use unique names
        if ( ! self::$needsReseed) {
            // Only delete once at the start
            Database::execute('DELETE FROM cards_to_tags');
            Database::execute('DELETE FROM tags');
            Database::execute('DELETE FROM cards');
            self::$needsReseed = true;
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
    
    public static function tearDownAfterClass(): void
    {
        // Re-seed base test data once after all tests in this class complete
        if (self::$needsReseed) {
            $connection = Database::getConnection();

            // Clean up all test data first (in reverse order of dependencies)
            $connection->exec("DELETE FROM cards_to_tags");
            $connection->exec("DELETE FROM tags");
            $connection->exec("DELETE FROM cards");

            // Reset auto-increment for cards and tags
            $connection->exec("ALTER TABLE cards AUTO_INCREMENT = 1");
            $connection->exec("ALTER TABLE tags AUTO_INCREMENT = 1");

            // Insert test response cards
            $stmt = $connection->prepare("INSERT INTO cards (type, copy) VALUES ('response', ?)");
            for ($i = 1; $i <= 300; $i++) {
                $stmt->execute([sprintf('White Card %03d', $i)]);
            }

            // Insert test prompt cards
            $stmt = $connection->prepare("INSERT INTO cards (type, copy, choices) VALUES ('prompt', ?, ?)");
            for ($i = 1; $i <= 40; $i++) {
                $stmt->execute([sprintf('Black Card %03d with ____.', $i), 1]);
            }
            for ($i = 41; $i <= 55; $i++) {
                $stmt->execute([sprintf('Black Card %03d with ____ and ____.', $i), 2]);
            }
            for ($i = 56; $i <= 70; $i++) {
                $stmt->execute([sprintf('Black Card %03d with ____, ____, and ____.', $i), 3]);
            }

            // Insert test tag
            $connection->exec("INSERT INTO tags (name) VALUES ('test_base')");
            $tagId = $connection->lastInsertId();

            // Tag all cards
            $totalCards = 370; // 300 response + 70 prompt
            $stmt = $connection->prepare("INSERT INTO cards_to_tags (card_id, tag_id) VALUES (?, ?)");
            for ($i = 1; $i <= $totalCards; $i++) {
                $stmt->execute([$i, $tagId]);
            }

            self::$needsReseed = false;
        }

        parent::tearDownAfterClass();
    }

    /**
     * Create a mock uploaded file from CSV content
     */
    private function createUploadedFile(string $csvContent, string $filename = 'test.csv'): UploadedFile
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv_test_');
        file_put_contents($tmpFile, $csvContent);

        $streamFactory = new StreamFactory();
        $stream = $streamFactory->createStreamFromFile($tmpFile);

        return new UploadedFile(
            $stream,
            $filename,
            'text/csv',
            filesize($tmpFile),
            UPLOAD_ERR_OK
        );
    }

    /**
     * Test importing CSV with no tags using actual controller method
     */
    public function testControllerImportCsvWithNoTags(): void
    {
        $csvContent = "Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10\n";
        $csvContent .= "First test card,,,,,,,,,,\n";
        $csvContent .= "Second test card,,,,,,,,,,\n";

        $uploadedFile = $this->createUploadedFile($csvContent);

        $requestFactory = new ServerRequestFactory();
        $request = $requestFactory->createServerRequest('POST', '/api/admin/cards/import?type=response')
            ->withUploadedFiles(['file' => $uploadedFile]);

        $responseFactory = new ResponseFactory();
        $response = $responseFactory->createResponse();

        $result = $this->controller->importCards($request, $response);

        // Check response
        $this->assertEquals(200, $result->getStatusCode());
        
        $body = (string) $result->getBody();
        $data = json_decode($body, true);

        $this->assertTrue($data['success']);
        $this->assertEquals(2, $data['data']['imported']);
        $this->assertEquals(0, $data['data']['failed']);

        // Verify cards were created in database
        $cards = Card::getActiveByType('response');
        $this->assertCount(2, $cards);
    }

    /**
     * Test importing CSV with tags using actual controller method
     */
    public function testControllerImportCsvWithTags(): void
    {
        $csvContent = "Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10\n";
        $csvContent .= "A profane card,Profanity,,,,,,,,,\n";
        $csvContent .= "A violent card,Violence,Sexually Explicit,,,,,,,,\n";
        $csvContent .= "A clean card,,,,,,,,,,\n";

        $uploadedFile = $this->createUploadedFile($csvContent);

        $requestFactory = new ServerRequestFactory();
        $request = $requestFactory->createServerRequest('POST', '/api/admin/cards/import?type=prompt')
            ->withUploadedFiles(['file' => $uploadedFile])
            ->withQueryParams(['type' => 'prompt']);

        $responseFactory = new ResponseFactory();
        $response = $responseFactory->createResponse();

        $result = $this->controller->importCards($request, $response);

        // Check response
        $this->assertEquals(200, $result->getStatusCode());
        
        $body = (string) $result->getBody();
        $data = json_decode($body, true);

        $this->assertTrue($data['success']);
        $this->assertEquals(3, $data['data']['imported']);
        $this->assertEquals(0, $data['data']['failed']);

        // Verify cards were created
        $cards = Card::getActiveByType('prompt');
        $this->assertCount(3, $cards);

        // Verify tags were created
        $allTags = Tag::getAll();
        $tagNames = array_column($allTags, 'name');
        $this->assertContains('Profanity', $tagNames);
        $this->assertContains('Violence', $tagNames);
        $this->assertContains('Sexually Explicit', $tagNames);

        // Verify first card has Profanity tag
        $card1Tags = Tag::getCardTags($cards[0]['card_id']);
        $this->assertCount(1, $card1Tags);
        $this->assertEquals('Profanity', $card1Tags[0]['name']);

        // Verify second card has 2 tags
        $card2Tags = Tag::getCardTags($cards[1]['card_id']);
        $this->assertCount(2, $card2Tags);
    }

    /**
     * Test importing CSV with quoted text containing commas
     */
    public function testControllerImportCsvWithQuotedText(): void
    {
        $csvContent = "Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10\n";
        $csvContent .= "\"A card with, commas in it\",Profanity,,,,,,,,,\n";
        $csvContent .= "\"Another card, with commas, and more\",Violence,,,,,,,,,\n";

        $uploadedFile = $this->createUploadedFile($csvContent);

        $requestFactory = new ServerRequestFactory();
        $request = $requestFactory->createServerRequest('POST', '/api/admin/cards/import?type=response')
            ->withUploadedFiles(['file' => $uploadedFile])
            ->withQueryParams(['type' => 'response']);

        $responseFactory = new ResponseFactory();
        $response = $responseFactory->createResponse();

        $result = $this->controller->importCards($request, $response);

        $body = (string) $result->getBody();
        $data = json_decode($body, true);

        $this->assertTrue($data['success']);
        $this->assertEquals(2, $data['data']['imported']);

        // Verify cards have correct text with commas preserved
        $cards = Card::getActiveByType('response');
        $cardTexts = array_column($cards, 'copy');
        $this->assertContains('A card with, commas in it', $cardTexts);
        $this->assertContains('Another card, with commas, and more', $cardTexts);
    }

    /**
     * Test importing CSV with whitespace in tags
     */
    public function testControllerImportCsvWithWhitespaceInTags(): void
    {
        $csvContent = "Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10\n";
        $csvContent .= "Test card,  Profanity  , Violence ,,,,,,,\n";

        $uploadedFile = $this->createUploadedFile($csvContent);

        $requestFactory = new ServerRequestFactory();
        $request = $requestFactory->createServerRequest('POST', '/api/admin/cards/import?type=response')
            ->withUploadedFiles(['file' => $uploadedFile])
            ->withQueryParams(['type' => 'response']);

        $responseFactory = new ResponseFactory();
        $response = $responseFactory->createResponse();

        $result = $this->controller->importCards($request, $response);

        $body = (string) $result->getBody();
        $data = json_decode($body, true);

        $this->assertTrue($data['success']);
        $this->assertEquals(1, $data['data']['imported']);

        // Verify tags were trimmed correctly
        $allTags = Tag::getAll();
        $tagNames = array_column($allTags, 'name');
        $this->assertContains('Profanity', $tagNames);
        $this->assertContains('Violence', $tagNames);

        // Make sure no tags with extra whitespace were created
        foreach ($allTags as $tag) {
            $this->assertEquals(trim($tag['name']), $tag['name']);
        }
    }

    /**
     * Test importing CSV with empty lines
     */
    public function testControllerImportCsvWithEmptyLines(): void
    {
        $csvContent = "Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10\n";
        $csvContent .= "First card,Profanity,,,,,,,,,\n";
        $csvContent .= ",,,,,,,,,,\n"; // Empty line
        $csvContent .= "Second card,Violence,,,,,,,,,\n";

        $uploadedFile = $this->createUploadedFile($csvContent);

        $requestFactory = new ServerRequestFactory();
        $request = $requestFactory->createServerRequest('POST', '/api/admin/cards/import?type=response')
            ->withUploadedFiles(['file' => $uploadedFile])
            ->withQueryParams(['type' => 'response']);

        $responseFactory = new ResponseFactory();
        $response = $responseFactory->createResponse();

        $result = $this->controller->importCards($request, $response);

        $body = (string) $result->getBody();
        $data = json_decode($body, true);

        $this->assertTrue($data['success']);
        $this->assertEquals(2, $data['data']['imported']); // Only 2, empty line skipped
        $this->assertEquals(0, $data['data']['failed']);
    }

    /**
     * Test that duplicate tags are not created during import
     */
    public function testControllerImportDoesNotCreateDuplicateTags(): void
    {
        $csvContent = "Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10\n";
        $csvContent .= "First card,Profanity,,,,,,,,,\n";
        $csvContent .= "Second card,Profanity,,,,,,,,,\n";
        $csvContent .= "Third card,PROFANITY,,,,,,,,,\n"; // Different case

        $uploadedFile = $this->createUploadedFile($csvContent);

        $requestFactory = new ServerRequestFactory();
        $request = $requestFactory->createServerRequest('POST', '/api/admin/cards/import?type=response')
            ->withUploadedFiles(['file' => $uploadedFile])
            ->withQueryParams(['type' => 'response']);

        $responseFactory = new ResponseFactory();
        $response = $responseFactory->createResponse();

        $result = $this->controller->importCards($request, $response);

        $body = (string) $result->getBody();
        $data = json_decode($body, true);

        $this->assertTrue($data['success']);
        $this->assertEquals(3, $data['data']['imported']);

        // Verify only one "Profanity" tag exists (case-insensitive)
        $allTags = Tag::getAll();
        $profanityTags = array_filter($allTags, function($tag) {
            return strcasecmp($tag['name'], 'Profanity') === 0;
        });
        $this->assertCount(1, $profanityTags);
    }

    /**
     * Test importing with invalid card type
     */
    public function testControllerImportWithInvalidCardType(): void
    {
        $csvContent = "Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10\n";
        $csvContent .= "Test card,,,,,,,,,,\n";

        $uploadedFile = $this->createUploadedFile($csvContent);

        $requestFactory = new ServerRequestFactory();
        $request = $requestFactory->createServerRequest('POST', '/api/admin/cards/import?type=invalid')
            ->withUploadedFiles(['file' => $uploadedFile])
            ->withQueryParams(['type' => 'invalid']);

        $responseFactory = new ResponseFactory();
        $response = $responseFactory->createResponse();

        $result = $this->controller->importCards($request, $response);

        $body = (string) $result->getBody();
        $data = json_decode($body, true);

        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Invalid card type', $data['error']);
    }

    /**
     * Test importing CSV with newlines in card text
     */
    public function testControllerImportCsvWithNewlinesInCardText(): void
    {
        // CSV with quoted fields containing newlines - use unique text
        $csvContent = "Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10\n";
        $csvContent .= "\"[NewlineTest] A card with\na newline in it\",Profanity_newlines,,,,,,,,,\n";
        $csvContent .= "\"[NewlineTest] Another card\nwith multiple\nnewlines\",Violence_newlines,,,,,,,,,\n";
        $csvContent .= "[NewlineTest] Normal card without newlines,,,,,,,,,,\n";

        $uploadedFile = $this->createUploadedFile($csvContent);

        $requestFactory = new ServerRequestFactory();
        $request = $requestFactory->createServerRequest('POST', '/api/admin/cards/import?type=response')
            ->withUploadedFiles(['file' => $uploadedFile])
            ->withQueryParams(['type' => 'response']);

        $responseFactory = new ResponseFactory();
        $response = $responseFactory->createResponse();

        $result = $this->controller->importCards($request, $response);

        $body = (string) $result->getBody();
        $data = json_decode($body, true);

        $this->assertTrue($data['success']);
        $this->assertEquals(3, $data['data']['imported']);

        // Verify cards were created with newlines preserved - filter by our prefix
        $cards = Card::getActiveByType('response');
        $ourCards = array_filter($cards, function($c) {
            return strpos($c['copy'], '[NewlineTest]') === 0;
        });
        $this->assertCount(3, $ourCards);

        $cardTexts = array_column($ourCards, 'copy');

        // Check that newlines are preserved in card text
        $this->assertContains("[NewlineTest] A card with\na newline in it", $cardTexts);
        $this->assertContains("[NewlineTest] Another card\nwith multiple\nnewlines", $cardTexts);
        $this->assertContains('[NewlineTest] Normal card without newlines', $cardTexts);

        // Verify tags were still added correctly
        $card1 = array_values(array_filter($ourCards, function($c) {
            return strpos($c['copy'], '[NewlineTest] A card with') === 0;
        }))[0];

        $card1Tags = Tag::getCardTags($card1['card_id']);
        $this->assertCount(1, $card1Tags);
        $this->assertEquals('Profanity_newlines', $card1Tags[0]['name']);
    }

    /**
     * Test importing CSV with newlines and commas in card text
     */
    public function testControllerImportCsvWithNewlinesAndCommas(): void
    {
        // CSV with quoted fields containing both newlines and commas - use unique text
        $csvContent = "Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10\n";
        $csvContent .= "\"[CommaNewlineTest] A card with,\ncommas and newlines\",Profanity_commanewline,Violence_commanewline,,,,,,,,\n";

        $uploadedFile = $this->createUploadedFile($csvContent);

        $requestFactory = new ServerRequestFactory();
        $request = $requestFactory->createServerRequest('POST', '/api/admin/cards/import?type=prompt')
            ->withUploadedFiles(['file' => $uploadedFile])
            ->withQueryParams(['type' => 'prompt']);

        $responseFactory = new ResponseFactory();
        $response = $responseFactory->createResponse();

        $result = $this->controller->importCards($request, $response);

        $body = (string) $result->getBody();
        $data = json_decode($body, true);

        $this->assertTrue($data['success']);
        $this->assertEquals(1, $data['data']['imported']);

        // Verify card text has both commas and newlines preserved - filter by our prefix
        $cards = Card::getActiveByType('prompt');
        $ourCards = array_filter($cards, function($c) {
            return strpos($c['copy'], '[CommaNewlineTest]') === 0;
        });
        $this->assertCount(1, $ourCards);
        $ourCard = array_values($ourCards)[0];
        $this->assertEquals("[CommaNewlineTest] A card with,\ncommas and newlines", $ourCard['copy']);

        // Verify both tags were added
        $cardTags = Tag::getCardTags($ourCard['card_id']);
        $this->assertCount(2, $cardTags);
        $tagNames = array_column($cardTags, 'name');
        $this->assertContains('Profanity_commanewline', $tagNames);
        $this->assertContains('Violence_commanewline', $tagNames);
    }

    /**
     * Test importing CSV with quoted quotes in card text
     */
    public function testControllerImportCsvWithQuotedQuotes(): void
    {
        // CSV with escaped quotes (doubled quotes) - use unique text
        $csvContent = "Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10\n";
        $csvContent .= "\"[QuotesTest] A card with \"\"quoted\"\" text\",Profanity_quotes,,,,,,,,,\n";

        $uploadedFile = $this->createUploadedFile($csvContent);

        $requestFactory = new ServerRequestFactory();
        $request = $requestFactory->createServerRequest('POST', '/api/admin/cards/import?type=response')
            ->withUploadedFiles(['file' => $uploadedFile])
            ->withQueryParams(['type' => 'response']);

        $responseFactory = new ResponseFactory();
        $response = $responseFactory->createResponse();

        $result = $this->controller->importCards($request, $response);

        $body = (string) $result->getBody();
        $data = json_decode($body, true);

        $this->assertTrue($data['success']);
        $this->assertEquals(1, $data['data']['imported']);

        // Verify card text has quotes preserved (CSV parser converts "" to ") - filter by our prefix
        $cards = Card::getActiveByType('response');
        $ourCards = array_filter($cards, function($c) {
            return strpos($c['copy'], '[QuotesTest]') === 0;
        });
        $this->assertCount(1, $ourCards);
        $ourCard = array_values($ourCards)[0];
        $this->assertEquals('[QuotesTest] A card with "quoted" text', $ourCard['copy']);
    }
}
