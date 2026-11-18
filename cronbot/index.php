<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Reserved directory index; cron tasks live in individual PHP files.
exit;
