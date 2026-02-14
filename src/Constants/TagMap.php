<?php

declare(strict_types=1);

namespace CAH\Constants;

final class TagMap
{
    /**
     * @return array<string, int>
     */
    public static function byName(): array
    {
        return [
            'Basic' => 1,
            'Mild' => 2,
            'Moderate' => 3,
            'Severe' => 4,
            'Sexual Innuendo' => 5,
            'Sexually Explicit' => 6,
            'LGBTQ' => 7,
            'Mild Profanity' => 8,
            'Strong Profanity' => 9,
            'Slurs' => 10,
            'Racism' => 11,
            'Sexism' => 12,
            'Homophobia' => 13,
            'Religion' => 14,
            'Body Shaming' => 15,
            'Mild Violence' => 16,
            'Graphic Violence' => 17,
            'Self-Harm' => 18,
            'Abuse' => 19,
            'Drugs' => 20,
            'Alcohol' => 21,
            'Tobacco' => 22,
            'Crime' => 23,
            'Toilet Humor' => 24,
            'Body Fluids' => 25,
            'Medical' => 26,
            'Dark Humor' => 27,
            'Trauma' => 28,
            'People' => 29,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function byId(): array
    {
        $byName = self::byName();
        $byId = [];

        foreach ($byName as $name => $id) {
            $byId[$id] = $name;
        }

        ksort($byId);
        return $byId;
    }
}
