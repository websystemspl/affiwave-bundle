<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Tests;

use AffiWave\Bundle\Tests\Fixtures\FakeStripeGateway;
use AffiWave\Bundle\Tests\Fixtures\TestKernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Boots the bundle in a test kernel (in-memory SQLite, fake Stripe) and creates the schema from the entity mapping.
 */
abstract class BundleTestCase extends TestCase
{
    protected TestKernel $kernel;
    protected EntityManagerInterface $em;

    /** @var callable|null */
    private $exceptionHandlerBefore;
    /** @var callable|null */
    private $errorHandlerBefore;

    /**
     * @param array<string, mixed> $config `affiwave:` section
     */
    protected function boot(array $config = []): void
    {
        $this->exceptionHandlerBefore = self::topExceptionHandler();
        $this->errorHandlerBefore = self::topErrorHandler();
        $this->kernel = new TestKernel($config);
        $this->kernel->boot();

        $this->em = $this->service('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
            // Older FrameworkBundle versions leave the error/exception handlers they registered at boot behind.
            while (self::topExceptionHandler() !== $this->exceptionHandlerBefore) {
                restore_exception_handler();
            }
            while (self::topErrorHandler() !== $this->errorHandlerBefore) {
                restore_error_handler();
            }
        }
    }

    private static function topExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    private static function topErrorHandler(): ?callable
    {
        $handler = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $handler;
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : object)
     */
    protected function service(string $id): object
    {
        return $this->kernel->getContainer()->get($id) ?? throw new \LogicException($id);
    }

    protected function stripe(): FakeStripeGateway
    {
        $gateway = $this->service(FakeStripeGateway::class);
        \assert($gateway instanceof FakeStripeGateway);

        return $gateway;
    }

    protected function memoryLogger(): LoggerInterface
    {
        return new class extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message];
            }
        };
    }
}
