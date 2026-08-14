<?php

/**
 * Download Exception
 */

namespace App\Exceptions;

use Exception;

/**
 * Class DownloadException
 *
 * Carries a short reason for the terminal alongside optional verbose output
 * (ffmpeg's stderr, typically) that belongs in the run report rather than
 * inline in a run that already prints thousands of lines.
 */
class DownloadException extends Exception
{
    public function __construct(string $message, private readonly string $details = '')
    {
        parent::__construct($message);
    }

    public function getDetails(): string
    {
        return $this->details;
    }
}
