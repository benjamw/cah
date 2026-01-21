# CSV Import Tests

This document describes the test coverage for CSV import functionality.

## Test Files

- `tests/Unit/CsvImportTest.php` - Unit tests for CSV parsing logic
- `tests/Integration/CardImportTest.php` - Integration tests for card/tag operations
- `tests/Integration/AdminControllerImportTest.php` - **Integration tests for actual AdminController import method**

## Unit Tests (CSV Parsing)

### CsvImportTest

Tests the low-level CSV parsing logic to ensure proper handling of:

1. **Parse line with no tags** - Verifies empty tag columns are handled correctly
2. **Parse line with one tag** - Verifies single tag is extracted properly
3. **Parse line with multiple tags** - Verifies multiple tags are extracted in order
4. **Parse line with quoted text** - Verifies quoted fields containing commas are parsed correctly
5. **Parse line with whitespace in tags** - Verifies tag values are trimmed properly

**Total: 5 tests, 14 assertions**

## Integration Tests (Full Import Process)

### CardImportTest

Tests the complete import workflow including database operations:

#### Basic Card Import Tests
1. **Import card without tags** - Verifies cards can be imported without any tags
2. **Import card with tags** - Verifies tags can be added to imported cards
3. **Duplicate tags not created** - Verifies tag reuse instead of duplication
4. **Case insensitive tag matching** - Verifies "Profanity" matches "PROFANITY"
5. **Import multiple cards with shared tags** - Verifies tag sharing across cards

#### CSV Import Tests
6. **Import CSV with no tags** - Verifies importing multiple cards without tags
7. **Import CSV with tags** - Verifies importing cards with various tag combinations
8. **Import CSV with quoted text** - Verifies quoted fields with commas are handled
9. **Import CSV with whitespace in tags** - Verifies tag trimming during import
10. **Import CSV with empty lines** - Verifies empty lines are skipped
11. **CSV import does not create duplicate tags** - Verifies case-insensitive tag deduplication

**Total: 11 tests, 48 assertions**

### AdminControllerImportTest

Tests the actual `AdminController::importCards()` method with real HTTP requests and file uploads:

1. **Controller import CSV with no tags** - Tests importing cards without tags via controller
2. **Controller import CSV with tags** - Tests importing cards with tags via controller
3. **Controller import CSV with quoted text** - Tests quoted fields containing commas
4. **Controller import CSV with whitespace in tags** - Tests tag trimming in controller
5. **Controller import CSV with empty lines** - Tests empty line handling in controller
6. **Controller import does not create duplicate tags** - Tests case-insensitive deduplication
7. **Controller import with invalid card type** - Tests error handling for invalid type
8. **Controller import CSV with newlines in card text** - Tests cards with embedded newlines
9. **Controller import CSV with newlines and commas** - Tests cards with both newlines and commas
10. **Controller import CSV with quoted quotes** - Tests escaped quotes in card text

**Total: 10 tests, 53 assertions**

## CSV Format

The expected CSV format is:

```csv
Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10
"Card text here",Profanity,Violence,,,,,,,,
"Another card",,,,,,,,,,
```

### Rules

- **Column 0**: Card text (required)
- **Columns 1-10**: Tag names (optional)
- Empty tag columns are ignored
- Whitespace in tag names is trimmed
- Tag matching is case-insensitive
- Quoted fields can contain commas
- Empty lines are skipped

## Tag Categories

The auto-tagging script (`scripts/add_tags.py`) detects these content warning tags:

- **Sexually Explicit** - Sexual content
- **Sexist** - Sexist language or content
- **Racist** - Racist language or content
- **Profanity** - Profane language
- **Violence** - Violent content
- **Drugs** - Drug-related content

## Running Tests

```bash
# Run all CSV import tests
vendor/bin/phpunit tests/Unit/CsvImportTest.php
vendor/bin/phpunit tests/Integration/CardImportTest.php
vendor/bin/phpunit tests/Integration/AdminControllerImportTest.php

# Run with detailed output
vendor/bin/phpunit tests/Integration/AdminControllerImportTest.php --testdox

# Run all tests
vendor/bin/phpunit
```

## Summary

- **Total Test Files:** 3
- **Total Tests:** 26 (5 unit + 11 integration + 10 controller)
- **Total Assertions:** 115

## Test Coverage

CSV parsing with various formats
Tag extraction and trimming
Empty value handling
Quoted field handling
Card import without tags
Card import with tags
Tag creation and reuse
Case-insensitive tag matching
Duplicate tag prevention
Empty line handling
Multi-card import with shared tags
**Newlines in card text**
**Newlines and commas combined**
**Escaped quotes in card text**

## Example Test Data

### Valid CSV with Tags
```csv
Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10
"A profane card",Profanity,,,,,,,,,
"A violent and sexual card",Violence,Sexually Explicit,,,,,,,,
"A clean card",,,,,,,,,,
```

### CSV with Quoted Text
```csv
Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10
"A card with, commas in it",Profanity,,,,,,,,,
"Another card, with commas, and more commas",Violence,Sexually Explicit,,,,,,,,
```

### CSV with Whitespace
```csv
Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10
"Test card",  Profanity  , Sexually Explicit ,Violence  ,,,,,,
```

All whitespace is properly trimmed, resulting in clean tag names.

### CSV with Newlines in Card Text
```csv
Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10
"A card with
a newline in it",Profanity,,,,,,,,,
"Another card
with multiple
newlines",Violence,,,,,,,,,
```

Newlines are preserved in the card text when properly quoted.

### CSV with Newlines and Commas
```csv
Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10
"A card with,
commas and newlines",Profanity,Violence,,,,,,,,
```

Both commas and newlines are preserved when the field is quoted.

### CSV with Escaped Quotes
```csv
Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10
"A card with ""quoted"" text",Profanity,,,,,,,,,
```

Quotes are escaped by doubling them (`""`) and are preserved as single quotes in the imported text.
