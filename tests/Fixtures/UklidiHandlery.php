<?php
declare(strict_types=1);

namespace Ewebovky\StatusBundle\Tests\Fixtures;

/**
 * Symfony v debug režimu registruje vlastní error a exception handlery a po
 * doběhnutí requestu je nechává být — PHPUnit to hlásí jako rizikový test.
 *
 * Odstraňujeme jen ty, které přibyly během testu: na začátku si zapamatujeme
 * aktuální handler a na konci odvíjíme zásobník, dokud se k němu nevrátíme.
 * Handlery PHPUnitu tak zůstanou nedotčené.
 */
trait UklidiHandlery
{
    private mixed $puvodniExceptionHandler = null;
    private mixed $puvodniErrorHandler = null;

    protected function zapamatujHandlery(): void
    {
        $this->puvodniExceptionHandler = $this->aktualniExceptionHandler();
        $this->puvodniErrorHandler     = $this->aktualniErrorHandler();
    }

    protected function obnovHandlery(): void
    {
        // Limit je pojistka proti zacyklení, kdyby se handler nikdy neshodoval.
        for ($i = 0; $i < 20 && $this->aktualniExceptionHandler() !== $this->puvodniExceptionHandler; ++$i) {
            restore_exception_handler();
        }

        for ($i = 0; $i < 20 && $this->aktualniErrorHandler() !== $this->puvodniErrorHandler; ++$i) {
            restore_error_handler();
        }
    }

    private function aktualniExceptionHandler(): mixed
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    private function aktualniErrorHandler(): mixed
    {
        $handler = set_error_handler(null);
        restore_error_handler();

        return $handler;
    }
}
