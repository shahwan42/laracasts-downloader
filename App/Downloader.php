<?php

/**
 * Main cycle of the app
 */

namespace App;

use App\Exceptions\DownloadException;
use App\Exceptions\LoginException;
use App\Http\Resolver;
use App\Laracasts\Controller as LaracastsController;
use App\System\Controller as SystemController;
use App\Utils\Utils;
use Cocur\Slugify\Slugify;
use Exception;
use GuzzleHttp\Client as HttpClient;
use League\Flysystem\Filesystem;
use Ubench;

/**
 * Class Downloader
 */
class Downloader
{
    /** How many failures to list inline before deferring to the report file. */
    private const int FAILURES_LISTED = 20;

    private readonly Resolver $client;

    private readonly \App\System\Controller $system;

    /** @array<string, int[]> */
    private array $filters = [];

    private readonly LaracastsController $laracasts;

    /** @var bool Don't scrap pages and only get from existing cache */
    private bool $cacheOnly = false;

    /** @var array<int, array{label: string, reason: string, details: string}> */
    private array $failures = [];

    public function __construct(HttpClient $httpClient, Filesystem $system, private readonly Ubench $bench)
    {
        $this->client = new Resolver($httpClient);
        $this->system = new SystemController($system);
        $this->laracasts = new LaracastsController($this->client);
    }

    public function start(array $options): void
    {
        $counter = [
            'series' => 1,
            'failed_episode' => 0,
        ];

        $this->authenticate($options['email'], $options['password']);

        Utils::box('Starting Collecting the data');

        $this->setFilters();

        $this->bench->start();

        $localSeries = $this->system->getSeries();

        if ($this->filters === []) {
            $cachedData = $this->system->getCache();

            $onlineSeries = $this->laracasts->getSeries($cachedData, $this->cacheOnly);

            $this->system->setCache($onlineSeries);
        } else {
            $onlineSeries = $this->laracasts->getFilteredSeries($this->filters);
        }

        $this->bench->end();

        Utils::box('Downloading');

        $newEpisodes = Utils::compareLocalAndOnlineSeries($onlineSeries, $localSeries);

        $newEpisodesCount = Utils::countEpisodes($newEpisodes);

        Utils::write(
            sprintf(
                '%d new episodes. %s elapsed with %s of memory usage.',
                $newEpisodesCount,
                $this->bench->getTime(),
                $this->bench->getMemoryUsage()
            )
        );

        if ($newEpisodesCount > 0) {
            $this->downloadEpisodes($newEpisodes, $counter, $newEpisodesCount);
        }

        Utils::writeln(
            sprintf(
                'Finished! Downloaded %d new episodes. Failed: %d',
                $newEpisodesCount - $counter['failed_episode'],
                $counter['failed_episode']
            )
        );

        $this->reportFailures();
    }

    /**
     * Remembers a failed episode and says so straight away, so a live watcher
     * sees the reason next to the episode it belongs to.
     */
    private function recordFailure(string $serieSlug, array $episode, Exception $e): void
    {
        $label = sprintf('%s %02d - %s', $serieSlug, $episode['number'], $episode['title']);

        $reason = $e->getMessage();

        $this->failures[] = [
            'label' => $label,
            'reason' => $reason,
            'details' => $e instanceof DownloadException ? $e->getDetails() : '',
        ];

        // Reasons can be multi-line; keep the inline form to one line.
        Utils::write('FAILED: '.$label.' - '.preg_replace('/\s+/', ' ', $reason));
    }

    /**
     * Lists the failures and leaves the full detail on disk, since a catalogue
     * run prints thousands of lines and a failure scrolls out of reach.
     */
    private function reportFailures(): void
    {
        if ($this->failures === []) {
            $this->system->saveFailureReport(null);

            return;
        }

        $shown = array_slice($this->failures, 0, self::FAILURES_LISTED);

        foreach ($shown as $failure) {
            Utils::write('  '.$failure['label'].' - '.preg_replace('/\s+/', ' ', $failure['reason']));
        }

        $remaining = count($this->failures) - count($shown);

        if ($remaining > 0) {
            Utils::write(sprintf('  ...and %d more.', $remaining));
        }

        $this->system->saveFailureReport($this->buildFailureReport());

        Utils::write('Full detail: '.BASE_FOLDER.'/'.SystemController::FAILURE_REPORT);
    }

    private function buildFailureReport(): string
    {
        $blocks = [];

        foreach ($this->failures as $failure) {
            $block = $failure['label'].PHP_EOL.$failure['reason'];

            if ($failure['details'] !== '') {
                $block .= PHP_EOL.PHP_EOL.$failure['details'];
            }

            $blocks[] = $block;
        }

        return implode(PHP_EOL.str_repeat('-', 60).PHP_EOL, $blocks).PHP_EOL;
    }

    /**
     * Tries to login.
     *
     * @return bool
     *
     * @throws LoginException
     */
    public function authenticate(string $email, string $password)
    {
        Utils::box('Authenticating');

        if ($email === '' || $password === '') {
            throw new LoginException('No EMAIL and PASSWORD is set in .env file');
        }

        $user = $this->client->login($email, $password);

        if (! is_null($user['error'])) {
            throw new LoginException($user['error']);
        }

        if ($user['signedIn']) {
            Utils::write('Logged in as '.$user['data']['email']);
        }

        // Let's allow user with no subscription to download free lessons
        // https://github.com/carlosflorencio/laracasts-downloader/issues/131
        /*
        if (! $user['data']['subscribed']) {
            throw new LoginException("You don't have active subscription!");
        }
        */
        return $user['signedIn'];
    }

    /**
     * Download Episodes
     */
    public function downloadEpisodes($newEpisodes, array &$counter, $newEpisodesCount): void
    {
        $this->system->createFolderIfNotExists(SERIES_FOLDER);

        Utils::box('Downloading Series');

        foreach ($newEpisodes as $serie) {
            $this->system->createSerieFolderIfNotExists($serie['slug']);

            foreach ($serie['episodes'] as $episode) {

                try {
                    $this->client->downloadEpisode($serie['slug'], $episode);
                } catch (Exception $e) {
                    $counter['failed_episode'] += 1;

                    $this->recordFailure($serie['slug'], $episode, $e);
                }

                Utils::write(
                    sprintf(
                        'Current: %d of %d total. Left: %d              ',
                        $counter['series']++,
                        $newEpisodesCount,
                        $newEpisodesCount - $counter['series'] + 1
                    )
                );
            }
        }
    }

    protected function setFilters(): bool
    {
        $shortOptions = 's:';
        $shortOptions .= 'e:';

        $longOptions = [
            'series-name:',
            'series-episodes:',
            'cache-only',
        ];

        $options = getopt($shortOptions, $longOptions);

        if (array_key_exists('cache-only', $options)) {
            $this->cacheOnly = true;
            unset($options['cache-only']);
        }

        Utils::box(sprintf('Checking for options %s', json_encode($options)));

        if (count($options) == 0) {
            Utils::write('No options provided');

            return false;
        }

        $this->setSeriesFilter($options);

        $this->setEpisodesFilter($options);

        $newEpisodes = count($this->filters['episodes']) - count($this->filters['series']);

        $this->filters['episodes'] = array_merge(
            $this->filters['episodes'],
            array_fill(0, abs($newEpisodes), [])
        );

        $this->filters = array_combine(
            $this->filters['series'],
            $this->filters['episodes']
        );

        return true;
    }

    private function setSeriesFilter($options): void
    {
        if (isset($options['s']) || isset($options['series-name'])) {
            $series = $options['s'] ?? $options['series-name'];

            if (! is_array($series)) {
                $series = [$series];
            }

            $slugify = new Slugify;
            $slugify->addRule("'", '');

            $this->filters['series'] = array_map(fn ($serie): string => $slugify->slugify($serie), $series);

            Utils::write(sprintf('Series names provided: %s', json_encode($this->filters['series'])));
        }
    }

    private function setEpisodesFilter($options): void
    {
        $this->filters['episodes'] = [];

        if (isset($options['e']) || isset($options['series-episodes'])) {
            $episodes = $options['e'] ?? $options['series-episodes'];

            Utils::write(sprintf('Episode numbers provided: %s', json_encode($episodes)));

            if (! is_array($episodes)) {
                $episodes = [$episodes];
            }

            foreach ($episodes as $episode) {
                $positions = explode(',', (string) $episode);

                sort($positions, SORT_NUMERIC);

                $this->filters['episodes'][] = $positions;
            }
        }
    }
}
