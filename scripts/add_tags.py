import csv
import re

# Define tag keywords
SEXUALLY_EXPLICIT_KEYWORDS = [
    'sex', 'cum', 'masturbate', 'porn', 'orgasm', 'erection', 'vagina', 'penis', 
    'anal', 'oral', 'blow', 'handjob', 'dildo', 'vibrator', 'horny', 'naked', 
    'nude', 'breast', 'dick', 'cock', 'pussy', 'ass', 'butt', 'laid', 'virginity',
    'go down on', 'sex position'
]

SEXIST_KEYWORDS = [
    'bitch', 'slut', 'whore', 'cunt'
]

RACIST_KEYWORDS = [
    'slavery', 'white man', 'black', 'asian', 'mexican', 'indian', 'aboriginal',
    'tribe', 'primitive'
]

PROFANITY_KEYWORDS = [
    'shit', 'fuck', 'damn', 'hell', 'ass', 'bitch', 'bastard', 'crap'
]

VIOLENCE_KEYWORDS = [
    'kill', 'murder', 'death', 'dead', 'blood', 'torture', 'rape', 'abuse',
    'violence', 'weapon', 'gun', 'knife', 'bomb'
]

DRUGS_KEYWORDS = [
    'drug', 'cocaine', 'heroin', 'meth', 'weed', 'marijuana', 'lsd',
    'pills', 'cigarette', 'alcohol', 'drunk', 'tripping on acid'
]

def check_tags(text):
    tags = []
    text_lower = text.lower()
    
    # Check for sexually explicit content
    for keyword in SEXUALLY_EXPLICIT_KEYWORDS:
        if keyword in text_lower:
            # Avoid false positives
            if keyword == 'ass' and ('class' in text_lower or 'pass' in text_lower or 'grass' in text_lower or 'bass' in text_lower):
                continue
            tags.append('Sexually Explicit')
            break
    
    # Check for sexist content
    if any(keyword in text_lower for keyword in SEXIST_KEYWORDS):
        tags.append('Sexist')
    
    # Check for racist content
    for keyword in RACIST_KEYWORDS:
        if keyword in text_lower:
            # Avoid false positives
            if keyword == 'primitive' and 'primitive version' in text_lower:
                continue
            if keyword == 'tribe' and 'tribe' not in text_lower:
                continue
            tags.append('Racist')
            break
    
    # Check for profanity
    for keyword in PROFANITY_KEYWORDS:
        if keyword in text_lower:
            # Avoid false positives
            if keyword == 'ass' and ('class' in text_lower or 'pass' in text_lower or 'grass' in text_lower or 'bass' in text_lower):
                continue
            if keyword == 'hell' and 'shell' in text_lower:
                continue
            tags.append('Profanity')
            break
    
    # Check for violence
    if any(keyword in text_lower for keyword in VIOLENCE_KEYWORDS):
        tags.append('Violence')
    
    # Check for drugs
    for keyword in DRUGS_KEYWORDS:
        if keyword in text_lower:
            # Avoid false positives - "high" in wrong context
            if keyword == 'high' and ('high five' in text_lower or 'high school' in text_lower):
                continue
            tags.append('Drugs')
            break
    
    return tags

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
            # Keep header as is
            output_rows.append(row)
            continue

        if not row or not row[0]:
            continue

        # Extract the card text (first column)
        card_text = row[0]

        # Get tags
        tags = check_tags(card_text)

        # Build output row with tags
        tag_values = tags + [''] * (10 - len(tags))  # Pad to 10 tag columns
        output_row = [card_text] + tag_values[:10]
        output_rows.append(output_row)

    # Write back
    with open(filename, 'w', encoding='utf-8', newline='') as f:
        writer = csv.writer(f)
        writer.writerows(output_rows)

    print(f'Tagged {len(output_rows) - 1} cards')

    # Show sample tagged cards
    tagged_count = sum(1 for row in output_rows[1:] if len(row) > 1 and any(row[1:]))
    print(f'{tagged_count} cards received content warning tags')

    print('\nSample tagged cards:')
    sample_count = 0
    for row in output_rows[1:]:
        if len(row) > 1 and any(row[1:]):
            print(f'  {row[0][:60]}... -> {[t for t in row[1:] if t]}')
            sample_count += 1
            if sample_count >= 10:
                break

# Process both files
process_file('data/black_cards.csv')
process_file('data/white_cards.csv')

print('\nAll files processed!')
