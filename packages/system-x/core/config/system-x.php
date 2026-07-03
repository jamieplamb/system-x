<?php

return [
    // The tray logout form action. Auth (incl. logout) is the consumer's concern, so this is
    // NOT baked into the bundle -- ClientConfig reads it into window.sxConfig at runtime. A
    // consumer with a non-default logout route publishes this config and overrides the key.
    'logout_url' => '/logout',
];
