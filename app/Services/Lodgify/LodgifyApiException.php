<?php

namespace App\Services\Lodgify;

use RuntimeException;

class LodgifyApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $responseBody = '',
    ) {
        parent::__construct($message);
    }

    /**
     * Lodgify's own explanation, when it gave one.
     *
     * Business-rule rejections come back as HTTP 400 with a genuinely useful
     * message ("The minimum stay for this rental is 6 days"). Showing that to a
     * guest is far better than a generic "not available" — it tells them what to
     * change. Anything that is not a clean 4xx returns null, so infrastructure
     * failures are never dressed up as advice.
     */
    public function guestMessage(): ?string
    {
        if ($this->status < 400 || $this->status >= 500) {
            return null;
        }

        $decoded = json_decode($this->responseBody, true);
        $message = is_array($decoded) ? ($decoded['message'] ?? null) : null;

        if (!is_string($message) || trim($message) === '') {
            return null;
        }

        // Internal phrasing that would only confuse a guest.
        $internal = ['missing unit ids', 'request model is not valid', 'unauthorized'];
        foreach ($internal as $needle) {
            if (str_contains(strtolower($message), $needle)) {
                return null;
            }
        }

        return trim($message);
    }
}