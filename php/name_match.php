<?php
/**
 * Compares a typed name with a class-list name. Ignores capitals, accents,
 * punctuation, extra spaces and word order ("Flores, John Paul" matches
 * "John Paul Flores"). Middle names must still agree.
 */
function names_match(string $a, string $b): bool
{
    $norm = function (string $s): array {
        $s = mb_strtolower($s, 'UTF-8');
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false && $t !== '') {
            $s = $t;
        }
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        $parts = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY);
        sort($parts);
        return $parts;
    };
    return $norm($a) === $norm($b);
}
