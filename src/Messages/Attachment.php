<?php

declare(strict_types=1);

namespace AiWorkflow\Messages;

use InvalidArgumentException;

class Attachment
{
    public function __construct(
        public readonly AttachmentKind $kind,
        public readonly ?string $url = null,
        public readonly ?string $base64 = null,
        public readonly ?string $mimeType = null,
        public readonly ?string $title = null,
        public readonly ?string $text = null,
    ) {}

    public static function fromBase64(AttachmentKind $kind, string $base64, string $mimeType, ?string $title = null): self
    {
        return new self($kind, base64: $base64, mimeType: $mimeType, title: $title);
    }

    public static function fromUrl(AttachmentKind $kind, string $url, ?string $mimeType = null, ?string $title = null): self
    {
        return new self($kind, url: $url, mimeType: $mimeType, title: $title);
    }

    public static function fromPath(AttachmentKind $kind, string $path, ?string $mimeType = null, ?string $title = null): self
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new InvalidArgumentException("Cannot read attachment file [{$path}].");
        }

        $detected = mime_content_type($path);

        return new self(
            $kind,
            base64: base64_encode($contents),
            mimeType: $mimeType ?? ($detected === false ? 'application/octet-stream' : $detected),
            title: $title,
        );
    }

    public static function text(string $text): self
    {
        return new self(AttachmentKind::Text, text: $text);
    }
}
