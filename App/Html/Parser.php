<?php

/**
 * Dom Parser
 */

namespace App\Html;

use Exception;
use Symfony\Component\DomCrawler\Crawler;

class Parser
{
    public static function getSerieData(string $serieHtml): array
    {
        $data = self::getData($serieHtml);

        return self::mapSerieData($data['props']['series']);
    }

    public static function mapSerieData(array $serie): array
    {
        return [
            'slug' => $serie['slug'],
            'path' => LARACASTS_BASE_URL.$serie['path'],
            'episode_count' => $serie['episodeCount'],
            'is_complete' => $serie['complete'],
        ];
    }

    /**
     * Return full list of episodes for given series HTML page.
     *
     * @param  number[]  $filteredEpisodes
     */
    public static function getEpisodesData(string $episodeHtml, $filteredEpisodes = []): array
    {
        $episodes = [];

        $data = self::getData($episodeHtml);

        $chapters = $data['props']['series']['chapters'];

        foreach ($chapters as $chapter) {
            foreach ($chapter['episodes'] as $episode) {
                // TODO: It's not the parser responsibility to filter episodes
                if (! empty($filteredEpisodes) && ! in_array($episode['position'], $filteredEpisodes)) {
                    continue;
                }

                // playback is absent for upcoming/scheduled episodes
                if (! isset($episode['cloudflarePlayback']['src'])) {
                    continue;
                }

                $episodes[] = [
                    'title' => $episode['title'],
                    'hls_url' => $episode['cloudflarePlayback']['src'],
                    'number' => $episode['position'],
                ];
            }
        }

        return $episodes;
    }

    public static function getUserData(string $html): array
    {

        $data = self::getData($html);

        $props = $data['props'];

        return [
            'error' => empty($props['errors']) ? null : $props['errors']['auth'],
            'signedIn' => $props['auth']['signedIn'],
            'data' => $props['auth']['user'],
        ];
    }

    /**
     * Returns the decoded Inertia page payload from the HTML page
     *
     * @return array
     */
    public static function getData(string $html): mixed
    {
        $parser = new Crawler($html);

        $node = $parser->filter('script[data-page="app"]');

        if ($node->count() === 0) {
            throw new Exception('Could not find the inertia page payload in the HTML response.');
        }

        $data = json_decode($node->text(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to decode the inertia page payload: '.json_last_error_msg());
        }

        return $data;
    }
}
