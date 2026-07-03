<?php

namespace SystemX\Core\Wire;

class Serializer
{
    /** @return array<string, mixed> */
    public function serialize(Node $node): array
    {
        return [
            'type' => $node->type,
            'id' => $node->id,
            // props is a map on the wire: empty props must encode as JSON `{}`, not `[]`,
            // so the display server (and any future typed consumer) always sees an object.
            // Non-empty props stay a plain array (string keys already encode as an object).
            'props' => $node->props === [] ? (object) [] : $node->props,
            'children' => array_map(fn (Node $child): array => $this->serialize($child), $node->children),
        ];
    }
}
