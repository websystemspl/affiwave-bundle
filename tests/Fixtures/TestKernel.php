<?php

declare(strict_types=1);

namespace AffiWave\Bundle\Tests\Fixtures;

use AffiWave\Bundle\AffiWaveBundle;
use AffiWave\Bundle\Stripe\StripeGatewayInterface;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Smallest kernel that boots the bundle with an in-memory SQLite database and a fake Stripe gateway.
 */
final class TestKernel extends Kernel
{
    /**
     * @param array<string, mixed> $affiwave config of the `affiwave:` section
     */
    public function __construct(private readonly array $affiwave = [], string $environment = 'test', bool $debug = true)
    {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new AffiWaveBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'router' => ['resource' => __DIR__.'/routes.php', 'utf8' => true],
            ]);
            $container->loadFromExtension('doctrine', [
                'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
                'orm' => [],
            ]);
            $container->loadFromExtension('affiwave', $this->affiwave);

            $container->register('logger', NullLogger::class);
            $container->register(FakeStripeGateway::class, FakeStripeGateway::class)->setPublic(true);
            $container->setAlias(StripeGatewayInterface::class, FakeStripeGateway::class)->setPublic(true);
            $container->addCompilerPass(new class implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    foreach ($container->getDefinitions() as $id => $definition) {
                        if (str_starts_with($id, 'AffiWave\\Bundle\\') && !str_contains($id, 'Tests')) {
                            $definition->setPublic(true);
                        }
                    }
                }
            });
        });
    }

    public function getProjectDir(): string
    {
        // Keeps Symfony from writing generated files (config/reference.php) into the repository.
        $dir = sys_get_temp_dir().'/affiwave-bundle-tests/project';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/affiwave-bundle-tests/'.md5(serialize($this->affiwave)).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/affiwave-bundle-tests/log';
    }
}
