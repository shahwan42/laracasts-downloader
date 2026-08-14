<?php

/**
 * Utilities
 */

namespace App\Utils;

/**
 * Class Utils
 */
class Utils
{
    /**
     * New line supporting cli or browser.
     */
    public static function newLine(): string
    {
        if (php_sapi_name() == 'cli') {
            return "\n";
        }

        return '<br>';
    }

    /**
     * Counts the episodes from the array.
     */
    public static function countEpisodes($array): int
    {
        $total = 0;

        foreach ($array as $serie) {
            $total += count($serie['episodes']);
        }

        return $total;
    }

    /**
     * Compare two arrays and returns the diff array.
     */
    public static function compareLocalAndOnlineSeries($onlineListArray, array $localListArray): array
    {
        $seriesCollection = new SeriesCollection([]);

        foreach ($onlineListArray as $serieSlug => $serie) {

            if (array_key_exists($serieSlug, $localListArray)) {
                if ($serie['episode_count'] == count($localListArray[$serieSlug])) {
                    continue;
                }

                $episodes = $serie['episodes'];
                $serie['episodes'] = [];

                foreach ($episodes as $episode) {
                    if (! in_array($episode['number'], $localListArray[$serieSlug])) {
                        $serie['episodes'][] = $episode;
                    }
                }

                $seriesCollection->add($serie);
            } else {
                $seriesCollection->add($serie);
            }
        }

        return $seriesCollection->get();
    }

    /**
     * Echo's text in a nice box.
     */
    public static function box(string $text): void
    {
        echo self::newLine();
        echo '===================================='.self::newLine();
        echo $text.self::newLine();
        echo '===================================='.self::newLine();
    }

    /**
     * Echo's a message.
     */
    public static function write(string $text): void
    {
        echo '> '.$text.self::newLine();
    }

    /**
     * Remove specials chars that windows does not support for filenames.
     */
    public static function parseEpisodeName(string $name): ?string
    {
        return preg_replace('/[^A-Za-z0-9\- _]/', '', $name);
    }

    /**
     * Echo's a message in a new line.
     */
    public static function writeln(string $text): void
    {
        echo self::newLine();
        echo '> '.$text.self::newLine();
    }
}
