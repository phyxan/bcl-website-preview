<?php
// GoDaddy overrides ErrorDocument at the server level, so a static 404.html
// cannot set its own status code and Apache falls back to a generic
// "File Not Found" page. This shim sends a real 404 and serves the branded
// page body. Verified on the account 2026-09-08.
http_response_code(404);
readfile(__DIR__ . '/404.html');
