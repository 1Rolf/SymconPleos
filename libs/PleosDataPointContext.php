<?php

declare(strict_types=1);

include_once __DIR__ . '/PleosDataPointCatalog.php';

/**
 * What a Data Point needs from its Pleos Vehicle to decode and present values: the owner's unit choices and the
 * mile-as-km options as plain values, and the instance's translation and debug output.
 */
final readonly class PleosDataPointContext
{
    /**
     * @param array<string, string> $units quantity => the owner's unit; an unknown or missing choice means the default unit
     * @param array<string, bool> $mileIsKm each `mileIsKmOption` property (see PleosDataPointCatalog::mileIsKmOptions()) => its value
     * @param Closure(string): string $translate the instance's Translate()
     * @param Closure(string, string): mixed $debug the instance's SendDebug() with sender and text
     */
    public function __construct(
        private array $units,
        private array $mileIsKm,
        private Closure $translate,
        private Closure $debug,
    ) {
    }

    /** The owner's unit for a quantity, or its default unit. */
    public function unit(string $quantity): string
    {
        return PleosDataPointCatalog::unit($quantity, $this->units[$quantity] ?? '');
    }

    /** Whether the owner set the `mileIsKmOption` property a Data Point names: a reported `mile`/`miles` counts as km. */
    public function mileIsKm(string $option): bool
    {
        return $this->mileIsKm[$option] ?? false;
    }

    public function translate(string $text): string
    {
        return ($this->translate)($text);
    }

    public function debug(string $sender, string $text): void
    {
        ($this->debug)($sender, $text);
    }
}
