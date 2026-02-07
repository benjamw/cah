import csv
import re

# All keywords are matched as whole words only (\b) to avoid substring false positives.
# Exclusion phrases: if text contains one of these, we skip that category for precision.

def _word_regex(word):
    """Match as whole word (word boundary) only."""
    return re.compile(r'\b' + re.escape(word) + r'\b', re.IGNORECASE)

def _compile_keywords(keywords):
    return [_word_regex(k) for k in keywords]

# --- Sexually explicit: unambiguous body/act terms only ---
SEXUALLY_EXPLICIT_KEYWORDS = [
    'sex', 'cum', 'masturbate', 'masturbation', 'porn', 'orgasm', 'erection',
    'vagina', 'penis', 'handjob', 'dildo', 'vibrator', 'horny', 'naked', 'nude',
    'dick', 'cock', 'pussy', 'virginity', 'virgin', 'blowjob', 'blow job',
    'sex position', 'go down on', 'anal sex', 'oral sex',
    'ass', 'butt',
]
# Skip if text is clearly innocent context
SEXUALLY_EXPLICIT_EXCLUDE = re.compile(
    r'chicken\s*breast|breast\s*stroke|oral\s*exam|oral\s*presentation|oral\s*history',
    re.IGNORECASE
)

# --- Sexist: slurs only (whole word) ---
SEXIST_KEYWORDS = ['bitch', 'slut', 'whore', 'cunt']
_SEXIST = _compile_keywords(SEXIST_KEYWORDS)

# --- Racist: specific phrases / unambiguous terms ---
RACIST_KEYWORDS = [
    'slavery', 'white man', 'black man', 'black woman', 'aboriginal',
    'tribe', 'mexican', 'racist', 'racism',
]
RACIST_EXCLUDE = re.compile(
    r'primitive\s+version|indian\s+food|indian\s+cuisine|indian\s+summer|asian\s+cuisine|asian\s+food|tribe\s+called',
    re.IGNORECASE
)

# --- Profanity: whole words only ---
PROFANITY_KEYWORDS = [
    'shit', 'fuck', 'fucking', 'fucked', 'damn', 'hell', 'ass', 'bastard', 'crap',
]
_PROFANITY = _compile_keywords(PROFANITY_KEYWORDS)

# --- Violence: unambiguous harm / weapons ---
VIOLENCE_KEYWORDS = [
    'kill', 'killing', 'murder', 'murdered', 'death', 'blood', 'bloody', 'torture',
    'rape', 'raped', 'abuse', 'abused', 'violence', 'weapon', 'weapons', 'gun', 'guns',
    'knife', 'bomb', 'bombs', 'stab', 'stabbing', 'shoot', 'shooting',
]
VIOLENCE_EXCLUDE = re.compile(
    r'dead\s+tired|dead\s+end|buzzkill|overkill|kill\s+two\s+birds|glue\s+gun|nail\s+gun|blood\s+orange|blood\s+type',
    re.IGNORECASE
)

# --- Drugs: specific substances / use ---
DRUGS_KEYWORDS = [
    'cocaine', 'heroin', 'meth', 'marijuana', 'lsd', 'cigarette', 'cigarettes',
    'alcohol', 'drunk', 'tripping on acid', 'getting high', 'getting drunk',
    'drugs', 'overdose',
]
DRUGS_EXCLUDE = re.compile(
    r'weed\s+killer|weeding|drug\s+store|pharmacy|vitamin\s+pills|sleeping\s+pills\b|pain\s+relief',
    re.IGNORECASE
)

_SEX = _compile_keywords(SEXUALLY_EXPLICIT_KEYWORDS)
_RACIST = _compile_keywords(RACIST_KEYWORDS)
_VIOLENCE = _compile_keywords(VIOLENCE_KEYWORDS)
_DRUGS = _compile_keywords(DRUGS_KEYWORDS)


def check_tags(text):
    tags = []
    if not text or not text.strip():
        return tags

    # Sexually explicit (whole words + exclusions)
    if not SEXUALLY_EXPLICIT_EXCLUDE.search(text):
        for pattern in _SEX:
            if pattern.search(text):
                tags.append('Sexually Explicit')
                break

    # Sexist
    if any(p.search(text) for p in _SEXIST):
        tags.append('Sexist')

    # Racist (whole words + exclusions)
    if not RACIST_EXCLUDE.search(text):
        for pattern in _RACIST:
            if pattern.search(text):
                tags.append('Racist')
                break

    # Profanity
    for pattern in _PROFANITY:
        if pattern.search(text):
            tags.append('Profanity')
            break

    # Violence (whole words + exclusions)
    if not VIOLENCE_EXCLUDE.search(text):
        if any(p.search(text) for p in _VIOLENCE):
            tags.append('Violence')

    # Drugs (whole words + exclusions)
    if not DRUGS_EXCLUDE.search(text):
        for pattern in _DRUGS:
            if pattern.search(text):
                tags.append('Drugs')
                break

    return tags


# Content level order: basic < mild < medium < severe. If no tag matches higher levels, use basic.
CONTENT_LEVEL_SEVERE = {'Sexually Explicit', 'Sexist', 'Racist', 'Violence'}
CONTENT_LEVEL_MEDIUM = {'Drugs'}
CONTENT_LEVEL_MILD = {'Profanity'}


def content_level(tags):
    """Return one of: basic, mild, medium, severe. Default is basic."""
    tag_set = set(tags)
    if tag_set & CONTENT_LEVEL_SEVERE:
        return 'severe'
    if tag_set & CONTENT_LEVEL_MEDIUM:
        return 'medium'
    if tag_set & CONTENT_LEVEL_MILD:
        return 'mild'
    return 'basic'


def process_file(filename):
    """Process a CSV file and add tags."""
    print(f'\nProcessing {filename}...')

    # Read the CSV using csv module
    with open(filename, 'r', encoding='utf-8') as f:
        reader = csv.reader(f)
        rows = list(reader)

    # Process each row
    output_rows = []
    for i, row in enumerate(rows):
        if i == 0:
            # Header: first column (card text), then Level, then tag columns
            header = [row[0] if row else 'Card text', 'Level']
            header += (row[1:12] if len(row) > 1 else [''] * 10)[:10]
            output_rows.append(header)
            continue

        if not row or not row[0]:
            continue

        # Extract the card text (first column)
        card_text = row[0]

        # Get tags and content level (basic | mild | medium | severe)
        tags = check_tags(card_text)
        level = content_level(tags)

        # Build output row: card text, level, then tag columns
        tag_values = tags + [''] * (10 - len(tags))  # Pad to 10 tag columns
        output_row = [card_text, level] + tag_values[:10]
        output_rows.append(output_row)

    # Write back
    with open(filename, 'w', encoding='utf-8', newline='') as f:
        writer = csv.writer(f)
        writer.writerows(output_rows)

    print(f'Tagged {len(output_rows) - 1} cards')

    # Show sample: cards with non-basic level or any content tags
    level_col = 1
    tag_start = 2
    tagged_count = sum(1 for row in output_rows[1:] if len(row) > level_col and (row[level_col] != 'basic' or any(row[tag_start:])))
    print(f'{tagged_count} cards have content level or warning tags')

    print('\nSample (level + tags):')
    sample_count = 0
    for row in output_rows[1:]:
        if len(row) > level_col and (row[level_col] != 'basic' or any(row[tag_start:])):
            tags_str = [row[level_col]] + [t for t in row[tag_start:] if t]
            print(f'  {row[0][:55]}... -> {tags_str}')
            sample_count += 1
            if sample_count >= 10:
                break

# Process both files
process_file('data/prompt_cards.csv')
process_file('data/response_cards.csv')

print('\nAll files processed!')
