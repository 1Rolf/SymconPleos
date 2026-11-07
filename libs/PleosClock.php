<?php

declare(strict_types=1);

// The test suite declares its own PleosClock (tests/FakePleosApi.php) before the library loads.
if (!trait_exists('PleosClock')) {
    /**
     * The clock of the Pleos modules, replaceable through the same seam as the Pleos Account's HTTP access.
     */
    trait PleosClock
    {
        protected function currentTime(): int
        {
            return time();
        }
    }
}
