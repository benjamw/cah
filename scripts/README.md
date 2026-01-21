# Scripts Documentation

This folder contains utility scripts for managing card data.

## Quick Start

Install Python dependencies:

```bash
pip install -r scripts/requirements.txt
```

Or install individually:

```bash
# For Excel support (required)
pip install openpyxl

# For ODS support (optional)
pip install odfpy
```

## Scripts

### 1. convert_spreadsheet_to_csv.py

Converts Excel (.xlsx) or OpenDocument (.ods) spreadsheet files to CSV format while preserving text formatting (bold, italic, underline) as markdown.

**Features:**
- Reads rows where first column contains "prompt" for black cards
- Reads rows where first column contains "response" for white cards
- Card data is read from second column (and third column if present)
- Converts text formatting to markdown:
  - **Bold** -> `**text**`
  - *Italic* -> `*text*`
  - <u>Underline</u> -> `<u>text</u>`
- **Preserves newlines** within card text (for multi-line cards)
- Outputs separate CSV files for black and white cards
- Includes header row with 10 tag columns

**Installation:**

```bash
# Required for Excel (.xlsx) support
pip install openpyxl

# Optional for OpenDocument (.ods) support
pip install odfpy
```

**Usage:**

```bash
python scripts/convert_spreadsheet_to_csv.py input_file.xlsx output_black.csv output_white.csv
```

**Example:**

```bash
python scripts/convert_spreadsheet_to_csv.py data/cards.xlsx data/black_cards.csv data/white_cards.csv
```

**Input Format:**

Your spreadsheet should be structured like this:

| Column 1 (Label) | Column 2 (Card Text) | Column 3 (Optional Extra) |
|------------------|----------------------|---------------------------|
| prompt           | What is ___?         | (additional text)         |
| response         | A banana             |                           |
| prompt           | ___ is the best      |                           |
| response         | **Pizza**            | with *cheese*             |

- **Column 1**: Contains the word "prompt" (for black cards) or "response" (for white cards)
- **Column 2**: The main card text (can have formatting)
- **Column 3**: Optional additional text that will be combined with column 2

**Output Format:**

CSV files with the following structure:
```
Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10
"Card text here",,,,,,,,,
```

### 2. add_tags.py

Automatically adds content warning tags to cards based on keyword detection.

**Features:**
- Detects and tags cards with:
  - Sexually Explicit
  - Sexist
  - Racist
  - Profanity
  - Violence
  - Drugs
- Includes false-positive detection to avoid incorrect tagging
- Processes CSV files in-place

**Usage:**

```bash
python scripts/add_tags.py
```

**Note:** Currently hardcoded to process `data/black_cards.csv`. Edit the script to change the input file.

**Tag Detection Keywords:**

- **Sexually Explicit**: sex, cum, masturbate, porn, orgasm, erection, vagina, penis, anal, oral, etc.
- **Sexist**: bitch, slut, whore, cunt
- **Racist**: slavery, racial terms, stereotypes
- **Profanity**: shit, fuck, damn, hell, ass, bitch, bastard, crap
- **Violence**: kill, murder, death, blood, torture, rape, abuse, weapon, gun, knife, bomb
- **Drugs**: drug, cocaine, heroin, meth, weed, marijuana, lsd, pills, cigarette, alcohol, drunk

## Workflow

Typical workflow for importing new cards:

1. **Convert spreadsheet to CSV:**
   ```bash
   python scripts/convert_spreadsheet_to_csv.py data/cards.xlsx data/black_cards.csv data/white_cards.csv
   ```

2. **Add automatic tags:**
   ```bash
   python scripts/add_tags.py
   ```

3. **Review and manually adjust tags** in the CSV files

4. **Import to database via API:**
   ```bash
   # Get admin token
   curl -X POST http://localhost:8000/api/admin/login \
     -H "Content-Type: application/json" \
     -d '{"password":"your_admin_password"}'
   
   # Import black cards
   curl -X POST "http://localhost:8000/api/admin/cards/import?type=black" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -F "file=@data/black_cards.csv"
   
   # Import white cards
   curl -X POST "http://localhost:8000/api/admin/cards/import?type=white" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -F "file=@data/white_cards.csv"
   ```

## Example: Text Formatting Conversion

If your Excel/ODS file has formatted text like:

| Label    | Card Text                | Extra Text  |
|----------|--------------------------|-------------|
| prompt   | This is **bold** text    | and *italic* |
| response | Another _underlined_ card|             |
| prompt   | What is ___?             |             |
| response | Line 1<br>Line 2         |             |

The script will convert it to two CSV files:

**black_cards.csv:**
```csv
Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10
"This is **bold** text and *italic*",,,,,,,,,
"What is ___?",,,,,,,,,
```

**white_cards.csv:**
```csv
Card Text,Tag1,Tag2,Tag3,Tag4,Tag5,Tag6,Tag7,Tag8,Tag9,Tag10
"Another <u>underlined</u> card",,,,,,,,,
"Line 1
Line 2",,,,,,,,,
```

This preserves the formatting information (including newlines) so it can be rendered properly in the game UI.

## Notes

- All scripts use UTF-8 encoding to support special characters
- CSV files use standard comma delimiters with proper quoting for multi-line fields
- The conversion script preserves the original spreadsheet and creates new CSV files
- The tagging script modifies the CSV file in-place (make backups!)
- Text formatting in Excel/ODS is converted to markdown/HTML for preservation
- **Newlines within cells are preserved** - perfect for multi-line cards
- The script combines the main column with the adjacent column (useful for multi-part cards)
- Leading/trailing blank lines are removed, but internal newlines are kept
