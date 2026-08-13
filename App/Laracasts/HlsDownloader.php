<?php

namespace App\Laracasts;

use App\Utils\Utils;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\Request;

/**
 * Downloads an episode from the HLS playlist Laracasts publishes in the
 * `cloudflarePlayback.src` field of its page payload.
 *
 * Only the credentials the site itself hands a signed-in subscriber are used:
 * the Laravel session cookie jar and a laracasts.com referer. No attempt is
 * made to disguise the client as a browser.
 */
class HlsDownloader
{
    private readonly Client $client;

    public function __construct(private readonly CookieJar $cookies)
    {
        $this->client = new Client(['http_errors' => false]);
    }

    public function download(string $masterUrl, string $filepath): bool
    {
        $master = $this->fetchPlaylist($masterUrl);

        $variant = $this->pickVariant($master, $masterUrl);

        Utils::writeln('Muxing '.($variant['label'] ?? 'best').' stream with ffmpeg...');

        return $this->mux($variant['url'], $filepath);
    }

    /**
     * Fetches a playlist with the subscriber's session attached.
     *
     * @throws Exception with an accurate reason when the media host refuses.
     */
    private function fetchPlaylist(string $url): string
    {
        $response = $this->client->get($url, [
            'cookies' => $this->cookies,
            'headers' => ['Referer' => LARACASTS_BASE_URL.'/'],
        ]);

        $status = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        if ($status === 200 && str_starts_with(trim($body), '#EXTM3U')) {
            return $body;
        }

        if ($status === 403) {
            throw new Exception(
                "media.laracasts.com refused this playlist (HTTP 403).\n"
                .'  Playback is authorised per lesson: opening the lesson page issues an '
                ."`lc_video_auth` cookie that the media host checks.\n"
                .'  Either that page visit failed, or the session no longer covers this episode.'
            );
        }

        throw new Exception("Unexpected response from $url (HTTP $status).");
    }

    /**
     * Picks the variant matching VIDEO_QUALITY, falling back to the highest available.
     *
     * @return array{url: string, label: string|null}
     */
    private function pickVariant(string $master, string $masterUrl): array
    {
        preg_match_all(
            '/#EXT-X-STREAM-INF:([^\n]*)\n([^\n#]+)/',
            $master,
            $matches,
            PREG_SET_ORDER
        );

        if ($matches === []) {
            // A media playlist rather than a master playlist - use it directly.
            return ['url' => $masterUrl, 'label' => null];
        }

        $wanted = (int) rtrim((string) ($_ENV['VIDEO_QUALITY'] ?? ''), 'p');

        $variants = [];

        foreach ($matches as [, $attributes, $uri]) {
            preg_match('/RESOLUTION=\d+x(\d+)/', $attributes, $resolution);

            $variants[] = [
                'height' => isset($resolution[1]) ? (int) $resolution[1] : 0,
                'url' => $this->resolveUrl(trim($uri), $masterUrl),
            ];
        }

        usort($variants, fn ($a, $b): int => $b['height'] <=> $a['height']);

        foreach ($variants as $variant) {
            if ($wanted > 0 && $variant['height'] === $wanted) {
                return ['url' => $variant['url'], 'label' => $variant['height'].'p'];
            }
        }

        $best = $variants[0];

        return ['url' => $best['url'], 'label' => $best['height'] > 0 ? $best['height'].'p' : null];
    }

    private function resolveUrl(string $uri, string $base): string
    {
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }

        return substr($base, 0, strrpos($base, '/') + 1).$uri;
    }

    /**
     * Muxes to a `.part` file and only takes the episode's real name once ffmpeg
     * has exited cleanly.
     *
     * Progress is inferred from the filenames on disk, so a truncated file under
     * the final name would be counted as a finished episode and skipped forever.
     * The rename is atomic, and an interruption this process does not survive
     * (SIGKILL, crash, power loss) leaves only a `.part` that is never mistaken
     * for a download and is overwritten on the next attempt.
     */
    private function mux(string $playlistUrl, string $outputPath): bool
    {
        $partialPath = $outputPath.'.part';

        $headers = 'Referer: '.LARACASTS_BASE_URL."/\r\nCookie: ".$this->cookieHeader($playlistUrl)."\r\n";

        $command = sprintf(
            // -f mp4 is required: the `.part` suffix stops ffmpeg inferring the
            // container from the file extension.
            'ffmpeg -y -headers %s -i %s -c copy -bsf:a aac_adtstoasc -f mp4 %s',
            escapeshellarg($headers),
            escapeshellarg($playlistUrl),
            escapeshellarg($partialPath)
        );

        $command .= PHP_OS === 'WINNT' ? ' 2> nul' : ' >/dev/null 2>&1';

        $output = [];
        $code = 0;

        exec($command, $output, $code);

        if ($code !== 0) {
            if (file_exists($partialPath)) {
                unlink($partialPath);
            }

            return false;
        }

        return rename($partialPath, $outputPath);
    }

    /**
     * Builds the Cookie header for a URL using Guzzle's own domain/path matching.
     *
     * Hand-rolling this is a trap: the jar accumulates one `lc_video_auth` token
     * per lesson visited, and emitting them all sends a stale token first, which
     * the media host rejects.
     */
    private function cookieHeader(string $url): string
    {
        $request = $this->cookies->withCookieHeader(new Request('GET', $url));

        return $request->getHeaderLine('Cookie');
    }
}
