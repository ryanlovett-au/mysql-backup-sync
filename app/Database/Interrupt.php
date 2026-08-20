<?php

namespace App\Database;

class Interrupt
{
    protected static bool $requested = false;

    protected static bool $listening = false;

    /**
     * Take control of ctrl-c so it stops the run rather than the process. Has to be called again
     * after any progress bar starts - Laravel Prompts installs its own handler that calls exit(),
     * and resets to the default rather than to ours when the bar finishes.
     */
    public static function listen(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGINT, function () {
            // Asking twice means they want out now, not at the end of the batch
            if (self::$requested) {
                echo "\n";

                exit(130);
            }

            self::$requested = true;
        });

        self::$listening = true;
    }

    public static function requested(): bool
    {
        return self::$requested;
    }

    /**
     * Hand ctrl-c back, so it behaves normally once we are out of the run.
     */
    public static function release(): void
    {
        if (self::$listening) {
            pcntl_signal(SIGINT, SIG_DFL);

            self::$listening = false;
        }

        self::$requested = false;
    }
}
