<?php

namespace CAH\Utils;

use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Cell\Cell;

class ExcelFormatter
{
    private static $warnings = [];
    
    /**
     * Convert Excel cell formatting to Markdown
     * 
     * @param Cell $cell The Excel cell
     * @param array &$warnings Optional array to collect warnings
     * @return string Markdown-formatted text
     */
    public static function toMarkdown(Cell $cell, array &$warnings = null): string
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
            
            // Clean up non-breaking spaces and other whitespace
            $markdown = self::cleanWhitespace($markdown);
            
            // Merge warnings
            if ($warnings !== null && !empty($cellWarnings)) {
                $warnings = array_merge($warnings, $cellWarnings);
            }
            
            return $markdown;
        }
        
        // Handle plain text with cell-level formatting
        $text = (string)$value;
        
        if (empty($text)) {
            return '';
        }
        
        // Get cell style
        $style = $cell->getStyle();
        $font = $style->getFont();
        
        // Apply formatting based on cell style
        $isBold = $font->getBold();
        $isItalic = $font->getItalic();
        $isStrikethrough = $font->getStrikethrough();
        $isUnderline = $font->getUnderline() !== \PhpOffice\PhpSpreadsheet\Style\Font::UNDERLINE_NONE;
        
        // Get additional formatting
        $isSuperscript = $font->getSuperscript();
        $isSubscript = $font->getSubscript();
        
        // Check for unsupported formatting (colors, font sizes)
        if ($font->getColor() && $font->getColor()->getRGB() !== '000000') {
            $cellWarnings[] = 'color';
        }
        
        if ($font->getSize() && $font->getSize() != 11) {
            $cellWarnings[] = 'font-size';
        }
        
        // Check for background color
        $fill = $style->getFill();
        if ($fill->getFillType() !== \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE) {
            $cellWarnings[] = 'background-color';
        }
        
        // Apply markdown formatting (in order: innermost to outermost)
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
        
        // Merge warnings into the provided array
        if ($warnings !== null && !empty($cellWarnings)) {
            $warnings = array_merge($warnings, $cellWarnings);
        }
        
        // Clean up non-breaking spaces and other whitespace
        $text = self::cleanWhitespace($text);
        
        return $text;
    }
    
    /**
     * Clean whitespace characters (NBSP, multiple spaces, etc.)
     * 
     * @param string $text
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
        $text = preg_replace('/  +/', ' ', $text);
        
        return $text;
    }
    
    /**
     * Convert RichText (formatted text with multiple styles) to Markdown
     * 
     * @param RichText $richText
     * @param array &$warnings Array to collect warnings
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
                
                $isBold = $font && $font->getBold();
                $isItalic = $font && $font->getItalic();
                $isStrikethrough = $font && $font->getStrikethrough();
                $isUnderline = $font && $font->getUnderline() !== \PhpOffice\PhpSpreadsheet\Style\Font::UNDERLINE_NONE;
                $isSuperscript = $font && $font->getSuperscript();
                $isSubscript = $font && $font->getSubscript();
                
                // Check for unsupported formatting in rich text (colors, font sizes)
                if ($font && $font->getColor() && $font->getColor()->getRGB() !== '000000') {
                    $warnings[] = 'color';
                }
                
                if ($font && $font->getSize() && $font->getSize() != 11) {
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
     * @param array $warnings Array of warning codes
     * @return string Formatted warning message
     */
    public static function formatWarnings(array $warnings): string
    {
        if (empty($warnings)) {
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
        $text = preg_replace('/<sub>(.*?)<\/sub>/', '$1', (string) $text);
        
        return $text;
    }
}
