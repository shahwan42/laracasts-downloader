<?php

/**
 * System Controller
 */

namespace App\System;

use App\Utils\Utils;
use League\Flysystem\Filesystem;
use League\Flysystem\StorageAttributes;

/**
 * Class Controller
 */
class Controller
{
    /** Where a run leaves the detail of any episodes that failed. */
    public const string FAILURE_REPORT = 'failures.log';

    /** Keys the downloader needs on every cached episode. */
    private const array EPISODE_KEYS = ['title', 'hls_url', 'number'];

    public function __construct(
        private readonly Filesystem $system
    ) {}

    public function getSeries(): array
    {
        // we want only finished videos, and we only need their paths.
        // in-progress downloads are written as `.part` and must not count.
        $paths = $this->system->listContents(SERIES_FOLDER, true)
            ->filter(fn (StorageAttributes $attributes): bool => $attributes->isFile())
            ->filter(fn (StorageAttributes $attributes): bool => str_ends_with($attributes->path(), '.mp4'))
            ->sortByPath()
            ->map(fn (StorageAttributes $attrs): string => $attrs->path())
            ->toArray();

        $array = [];

        foreach ($paths as $path) {
            $segments = explode('/', substr((string) $path, strlen(SERIES_FOLDER) + 1));

            // this happens on MAC when "series/.DS_Store" is present
            if (! isset($segments[1])) {
                continue;
            }

            [$serie, $episodeName] = $segments;

            $episodeNo = (int) substr($episodeName, 0, strpos($episodeName, '-'));

            $array[$serie][] = $episodeNo;
        }

        return $array;
    }

    public function createSerieFolderIfNotExists(string $serieSlug): void
    {
        $this->createFolderIfNotExists(SERIES_FOLDER.'/'.$serieSlug);
    }

    public function createFolderIfNotExists($folder): void
    {
        if ($this->system->has($folder) === false) {
            $this->system->createDirectory($folder);
        }
    }

    public function setCache(array $data): void
    {
        $file = 'cache.json';

        if ($this->system->has($file)) {
            $this->system->delete($file);
        }

        $this->system->write($file, json_encode($data));
    }

    /**
     * Writes the run's failure report, or clears a previous one when passed null
     * so a stale file can never be mistaken for the current run's result.
     */
    public function saveFailureReport(?string $contents): void
    {
        $file = self::FAILURE_REPORT;

        if ($this->system->fileExists($file)) {
            $this->system->delete($file);
        }

        if ($contents !== null) {
            $this->system->write($file, $contents);
        }
    }

    public function getCache(): array
    {
        $file = 'cache.json';

        if (! $this->system->fileExists($file)) {
            return [];
        }

        $raw = $this->system->read($file);

        $cache = json_decode($raw, true);

        if (! is_array($cache)) {
            // The byte count separates a file truncated by an interrupted write --
            // the common cause -- from genuinely malformed JSON.
            Utils::write(sprintf(
                'cache.json could not be read as JSON (%s, %d bytes) and will be rebuilt.',
                json_last_error_msg(),
                strlen($raw)
            ));

            return [];
        }

        $usable = array_filter($cache, fn ($serie): bool => $this->isUsable($serie));

        $ignored = count($cache) - count($usable);

        if ($ignored > 0) {
            Utils::write(sprintf(
                'Ignoring %d cached series saved in an older format. '
                .'They will be re-scraped unless --cache-only is used.',
                $ignored
            ));
        }

        return $usable;
    }

    /**
     * Whether every episode of a cached serie still carries what the downloader needs.
     *
     * Episodes cached before Laracasts moved to HLS hold a `vimeo_id` instead of an
     * `hls_url`. Dropping the whole serie leaves no cached copy for
     * Laracasts\Controller::isSerieUpdated() to find, so it re-scrapes through the
     * path that already exists.
     */
    private function isUsable(mixed $serie): bool
    {
        if (! is_array($serie) || ! is_array($serie['episodes'] ?? null)) {
            return false;
        }

        foreach ($serie['episodes'] as $episode) {
            foreach (self::EPISODE_KEYS as $key) {
                if (! isset($episode[$key])) {
                    return false;
                }
            }
        }

        return true;
    }
}
