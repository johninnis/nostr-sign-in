<?php

declare(strict_types=1);

namespace Innis\Nostr\SignIn\Tests\Integration\Support;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Application\Port\Nip98ReplayGuardInterface;
use Innis\Nostr\Core\Application\Service\Nip98Validator;
use Innis\Nostr\Core\Application\Service\Nip98ValidatorInterface;
use Innis\Nostr\Core\Domain\Service\Nip98EventChecker;
use Innis\Nostr\Core\Domain\Service\Nip98EventCheckerInterface;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\SignIn\Application\Port\CurrentUserInterface;
use Innis\Nostr\SignIn\Application\Port\RoleAssignerInterface;
use Innis\Nostr\SignIn\Infrastructure\Authorisation\ConfiguredKeyRoleAssigner;
use Innis\Nostr\SignIn\Infrastructure\Cache\CachePoolNip98ReplayGuard;
use Innis\Nostr\SignIn\Infrastructure\Identity\TokenCurrentUser;
use Innis\Nostr\SignIn\Infrastructure\Security\Nip98Authenticator;
use Innis\Nostr\SignIn\Infrastructure\Security\NostrUserProvider;
use Innis\Nostr\SignIn\Infrastructure\Security\RefusalResponder;
use Innis\Nostr\SignIn\Infrastructure\Security\SignOutResponder;
use Innis\Nostr\SignIn\Presentation\Web\ArgumentResolver\CurrentPubkeyResolver;
use Innis\Nostr\SignIn\Presentation\Web\Controller\SignInController;
use Innis\Nostr\SignIn\Presentation\Web\EventSubscriber\SameOriginActionSubscriber;
use Innis\Nostr\SignIn\Presentation\Web\EventSubscriber\SlidingSessionSubscriber;
use Innis\Nostr\SignIn\Tests\Integration\Support\Infrastructure\TogglingRoleAssigner;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    public const string ADMIN_NPUB = 'npub10xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqpkge6d';

    public function __construct()
    {
        parent::__construct('test', true);
    }

    /**
     * @return iterable<BundleInterface>
     */
    #[Override]
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new SecurityBundle()];
    }

    #[Override]
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'integration',
                'test' => true,
                'handle_all_throwables' => true,
                'http_method_override' => false,
                'php_errors' => ['log' => true],
                'session' => [
                    'storage_factory_id' => 'session.storage.factory.mock_file',
                    'cookie_secure' => 'auto',
                    'cookie_samesite' => 'lax',
                    'cookie_lifetime' => 7200,
                ],
                'router' => [
                    'resource' => __DIR__.'/routes.php',
                    'type' => 'php',
                    'utf8' => true,
                ],
            ]);

            $container->loadFromExtension('security', [
                'providers' => [
                    'nostr' => ['id' => NostrUserProvider::class],
                ],
                'firewalls' => [
                    'main' => [
                        'lazy' => true,
                        'provider' => 'nostr',
                        'custom_authenticators' => [Nip98Authenticator::class],
                        'entry_point' => RefusalResponder::class,
                        'access_denied_handler' => RefusalResponder::class,
                        'login_throttling' => ['max_attempts' => 2],
                        'logout' => ['path' => 'nostr_sign_out'],
                    ],
                ],
            ]);

            foreach ([
                SignInController::class,
                CurrentPubkeyResolver::class,
                TokenCurrentUser::class,
                NostrUserProvider::class,
                Nip98Authenticator::class,
                RefusalResponder::class,
                SignOutResponder::class,
                ProbeController::class,
                SystemClock::class,
                Nip98EventChecker::class,
                Nip98Validator::class,
            ] as $class) {
                $container->register($class, $class)->setAutowired(true)->setAutoconfigured(true);
            }

            $container->register(SameOriginActionSubscriber::class, SameOriginActionSubscriber::class)
                ->setAutoconfigured(true);
            $container->register(SlidingSessionSubscriber::class, SlidingSessionSubscriber::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$sessionOptions', '%session.storage.options%');

            $container->register(ArrayAdapter::class, ArrayAdapter::class);
            $container->register('cache.rate_limiter', ArrayAdapter::class);
            $container->register(CachePoolNip98ReplayGuard::class, CachePoolNip98ReplayGuard::class)
                ->setArgument('$pool', new Reference(ArrayAdapter::class));

            $container->register(ConfiguredKeyRoleAssigner::class, ConfiguredKeyRoleAssigner::class)
                ->setArgument('$administratorNpubs', self::ADMIN_NPUB);
            $container->register(TogglingRoleAssigner::class, TogglingRoleAssigner::class)
                ->setAutowired(true)
                ->setPublic(true);

            $container->register(SignatureServiceInterface::class, SignatureServiceInterface::class)
                ->setFactory([Secp256k1Signer::class, 'create']);

            $container->setAlias(ClockInterface::class, SystemClock::class);
            $container->setAlias(Nip98EventCheckerInterface::class, Nip98EventChecker::class);
            $container->setAlias(Nip98ValidatorInterface::class, Nip98Validator::class);
            $container->setAlias(Nip98ReplayGuardInterface::class, CachePoolNip98ReplayGuard::class);
            $container->setAlias(RoleAssignerInterface::class, TogglingRoleAssigner::class);
            $container->setAlias(CurrentUserInterface::class, TokenCurrentUser::class);
        });
    }

    #[Override]
    public function getCacheDir(): string
    {
        return dirname(__DIR__, 3).'/var/tests/integration-kernel/cache';
    }

    #[Override]
    public function getLogDir(): string
    {
        return dirname(__DIR__, 3).'/var/tests/integration-kernel/log';
    }
}
