<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// Display-only image. `src` is a URL (never inline base64 -- a grid frame is capped at 256KB, so
// image bytes must not ride the wire). `enlargeable()` opts the image into a client-side
// click-to-enlarge lightbox; `full`, when set, is the hi-res source shown enlarged (else `src`).
// No events allowlist -- the enlarge interaction is entirely client-side.
class Image extends Node
{
    public function __construct(string $src)
    {
        parent::__construct('image', ['src' => $src, 'alt' => '']);
    }

    public static function make(string $src): static
    {
        return new static($src);
    }

    public function alt(string $alt): static
    {
        $this->props['alt'] = $alt;

        return $this;
    }

    public function enlargeable(?string $full = null): static
    {
        $this->props['enlarge'] = true;
        if ($full !== null) {
            $this->props['full'] = $full;
        }

        return $this;
    }
}
