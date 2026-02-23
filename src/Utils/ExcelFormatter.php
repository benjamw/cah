<?php

declare(strict_types=1);

namespace CAH\Utils;

use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Cell\Cell;

class ExcelFormatter
{
    /**
     * Convert Excel cell formatting to Markdown
     *
     * @param Cell $cell The Excel cell
     * @param list<string>|null $warnings Optional array to collect warnings
     * @return string Markdown-formatted text
     */
    public static function toMarkdown(Cell $cell, ?array &$warnings = null): string
    {
        // Reset warnings for this cell
        $cellWarnings = [];
        $value = $cell->getValue();

        // Handle null values
        if ($value === null) {
            return '';
        }

        // Handle RichText (parts of text with different formatting)
        if ($value instanceof RichText) {
            $markdown = self::richTextToMarkdown($value, $cellWarnings);
            self::mergeWarnings($warnings, $cellWarnings);
            return self::cleanWhitespace($markdown);
        }

        // Handle plain text with cell-level formatting
        $text = (string) $value;

        // Excel hyperlink formulas can contain the visible card text as the second argument.
        // Convert '=HYPERLINK("url","Visible text")' to just 'Visible text'.
        $hyperlinkText = self::extractHyperlinkDisplayText($text);
        if ($hyperlinkText !== null) {
            $text = $hyperlinkText;
        }

        if ($text === '' || $text === '0') {
            return '';
        }

        $formatted = self::formatPlainCellText($cell, $text, $cellWarnings);
        $formatted = self::stripWholeCellUnderlineWrapper($formatted);
        self::mergeWarnings($warnings, $cellWarnings);
        return self::cleanWhitespace($formatted);
    }

    private static function extractHyperlinkDisplayText(string $value): ?string
    {
        $formula = trim($value);
        if (!preg_match('/^=HYPERLINK\(/i', $formula)) {
            return null;
        }

        // Match '=HYPERLINK("url","text")' and locale variants using ';' separator.
        $matched = preg_match(
            '/^=HYPERLINK\(\s*"(?:""|[^"])*"\s*[,;]\s*"((?:""|[^"])*)"\s*\)$/i',
            $formula,
            $matches
        );
        if ($matched !== 1) {
            return null;
        }

        return str_replace('""', '"', $matches[1]);
    }

    private static function stripWholeCellUnderlineWrapper(string $text): string
    {
        $trimmed = trim($text);
        if (str_starts_with($trimmed, '<u>') && str_ends_with($trimmed, '</u>')) {
            return substr($trimmed, 3, -4);
        }

        return $text;
    }

    /**
     * @param list<string> $warnings
     */
    private static function formatPlainCellText(Cell $cell, string $text, array &$warnings): string
    {
        $style = $cell->getStyle();
        $font = $style->getFont();

        if ($font->getColor()->getRGB() !== '000000') {
            $warnings[] = 'color';
        }

        if ($font->getSize() && $font->getSize() != 11) {
            $warnings[] = 'font-size';
        }

        $fill = $style->getFill();
        if ($fill->getFillType() !== \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE) {
            $warnings[] = 'background-color';
        }

        return self::applyInlineFormatting(
            $text,
            $font->getBold(),
            $font->getItalic(),
            $font->getStrikethrough(),
            $font->getUnderline() !== \PhpOffice\PhpSpreadsheet\Style\Font::UNDERLINE_NONE,
            $font->getSuperscript(),
            $font->getSubscript()
        );
    }

    private static function applyInlineFormatting(
        string $text,
        bool $isBold,
        bool $isItalic,
        bool $isStrikethrough,
        bool $isUnderline,
        bool $isSuperscript,
        bool $isSubscript
    ): string {
        if ($isSuperscript) {
            $text = "<sup>$text</sup>";
        }

        if ($isSubscript) {
            $text = "<sub>$text</sub>";
        }

        if ($isStrikethrough) {
            $text = "~~$text~~";
        }

        if ($isUnderline) {
            $text = "<u>$text</u>";
        }

        if ($isBold && $isItalic) {
            return "***$text***";
        }

        if ($isBold) {
            return "**$text**";
        }

        if ($isItalic) {
            return "*$text*";
        }

        return $text;
    }

    /**
     * @param list<string>|null $warnings
     * @param list<string> $cellWarnings
     */
    private static function mergeWarnings(?array &$warnings, array $cellWarnings): void
    {
        if ($warnings !== null && $cellWarnings !== []) {
            $warnings = array_merge($warnings, $cellWarnings);
        }
    }

    /**
     * Clean whitespace characters (NBSP, multiple spaces, etc.)
     *
     * @return string Cleaned text
     */
    private static function cleanWhitespace(string $text): string
    {
        // Convert non-breaking spaces (NBSP, \xA0, character 160) to regular spaces
        $text = str_replace("\xC2\xA0", ' ', $text); // UTF-8 NBSP
        $text = str_replace("\xA0", ' ', $text);     // ISO-8859-1 NBSP
        $text = str_replace(chr(160), ' ', $text);   // Character code 160

        // Convert other problematic whitespace
        $text = str_replace("\xE2\x80\x89", ' ', $text); // Thin space
        $text = str_replace("\xE2\x80\x8B", '', $text);  // Zero-width space (remove)
        $text = str_replace("\xE2\x80\x8C", '', $text);  // Zero-width non-joiner (remove)
        $text = str_replace("\xE2\x80\x8D", '', $text);  // Zero-width joiner (remove)

        // Normalize multiple spaces to single space
        $text = (string) preg_replace('/  +/', ' ', $text);

        return $text;
    }

    /**
     * Convert RichText (formatted text with multiple styles) to Markdown
     *
     * @param list<string> $warnings Array to collect warnings
     * @return string Markdown-formatted text
     */
    private static function richTextToMarkdown(RichText $richText, array &$warnings = []): string
    {
        $markdown = '';

        foreach ($richText->getRichTextElements() as $element) {
            $text = $element->getText();

            if (empty($text)) {
                continue;
            }

            // Check if this is a Run (formatted text) or plain text
            if ($element instanceof \PhpOffice\PhpSpreadsheet\RichText\Run) {
                $font = $element->getFont();

                $isBold = $font->getBold();
                $isItalic = $font->getItalic();
                $isStrikethrough = $font->getStrikethrough();
                $isUnderline = $font->getUnderline() !== \PhpOffice\PhpSpreadsheet\Style\Font::UNDERLINE_NONE;
                $isSuperscript = $font->getSuperscript();
                $isSubscript = $font->getSubscript();

                // Check for unsupported formatting in rich text (colors, font sizes)
                if ($font->getColor()->getRGB() !== '000000') {
                    $warnings[] = 'color';
                }

                if ($font->getSize() != 11) {
                    $warnings[] = 'font-size';
                }

                // Apply markdown formatting in order (innermost to outermost)
                // Superscript/subscript first
                if ($isSuperscript) {
                    $text = "<sup>$text</sup>";
                }
                if ($isSubscript) {
                    $text = "<sub>$text</sub>";
                }

                // Then strikethrough
                if ($isStrikethrough) {
                    $text = "~~$text~~";
                }

                // Then underline
                if ($isUnderline) {
                    $text = "<u>$text</u>";
                }

                // Then bold/italic
                if ($isBold && $isItalic) {
                    $text = "***$text***";
                } elseif ($isBold) {
                    $text = "**$text**";
                } elseif ($isItalic) {
                    $text = "*$text*";
                }
            }

            $markdown .= $text;
        }

        return $markdown;
    }

    /**
     * Get human-readable warning messages
     *
     * @param list<string> $warnings Array of warning codes
     * @return string Formatted warning message
     */
    public static function formatWarnings(array $warnings): string
    {
        if ($warnings === []) {
            return '';
        }

        $unique = array_unique($warnings);
        $messages = [];

        foreach ($unique as $warning) {
            switch ($warning) {
                case 'color':
                    $messages[] = 'colored text';
                    break;
                case 'font-size':
                    $messages[] = 'font size';
                    break;
                case 'background-color':
                    $messages[] = 'background color';
                    break;
            }
        }

        return implode(', ', $messages);
    }

    /**
     * Strip all markdown formatting from text
     *
     * @param string $text Markdown-formatted text
     * @return string Plain text
     */
    public static function stripMarkdown(string $text): string
    {
        // Remove bold/italic
        $text = preg_replace('/\*\*\*(.*?)\*\*\*/', '$1', $text);
        $text = preg_replace('/\*\*(.*?)\*\*/', '$1', (string) $text);
        $text = preg_replace('/\*(.*?)\*/', '$1', (string) $text);

        // Remove strikethrough
        $text = preg_replace('/~~(.*?)~~/', '$1', (string) $text);

        // Remove HTML tags
        $text = preg_replace('/<u>(.*?)<\/u>/', '$1', (string) $text);
        $text = preg_replace('/<sup>(.*?)<\/sup>/', '$1', (string) $text);

        return preg_replace('/<sub>(.*?)<\/sub>/', '$1', (string) $text);
    }
}
