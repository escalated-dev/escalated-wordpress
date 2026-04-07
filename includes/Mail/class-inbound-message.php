<?php

namespace Escalated\Mail;

class Inbound_Message
{
    public function __construct(
        public string $fromEmail,
        public ?string $fromName,
        public string $toEmail,
        public string $subject,
        public ?string $bodyText,
        public ?string $bodyHtml,
        public ?string $messageId = null,
        public ?string $inReplyTo = null,
        public ?string $references = null,
        public array $headers = [],
        public array $attachments = [],
    ) {}

    public function get_body(): string
    {
        if (! empty($this->bodyText)) {
            return $this->bodyText;
        }
        if (! empty($this->bodyHtml)) {
            return wp_strip_all_tags($this->bodyHtml);
        }

        return '';
    }

    public function get_raw_headers_string(): ?string
    {
        if (empty($this->headers)) {
            return null;
        }
        $lines = [];
        foreach ($this->headers as $key => $value) {
            $lines[] = "{$key}: {$value}";
        }

        return implode("\r\n", $lines);
    }
}
