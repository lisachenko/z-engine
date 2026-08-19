<?php

/**
 * Fixture for the shared-memory refresh tests: the whole script is a single
 * patchable long literal returned from the file-level op_array.
 *
 * Unlike answer.php it defines nothing, so the SAME worker process can include
 * it repeatedly - which is what the shared-memory legs need to observe whether
 * a re-include is served from the SHM-resident copy or re-read from the cache.
 */
declare(strict_types=1);

return 41;
