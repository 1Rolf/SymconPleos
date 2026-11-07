<?php

declare(strict_types=1);

// The test suite declares its own PleosFormIO (tests/VehicleForm.php) before the library loads.
if (!trait_exists('PleosFormIO')) {
    /**
     * Updates of the open configuration form, the boundary the test suite records.
     */
    trait PleosFormIO
    {
        protected function sendFormUpdate(string $field, string $parameter, mixed $value): void
        {
            $this->UpdateFormField($field, $parameter, $value);
        }
    }
}
