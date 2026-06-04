<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use VeronaLabs\WpScoper\Config\Config;

class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    /** @var Composer */
    private $composer;

    /** @var IOInterface */
    private $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => ['onPostInstallOrUpdate', 0],
            ScriptEvents::POST_UPDATE_CMD => ['onPostInstallOrUpdate', 0],
        ];
    }

    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }

    public function onPostInstallOrUpdate(Event $event): void
    {
        $extra = $this->composer->getPackage()->getExtra();

        if (!isset($extra['wp-scoper'])) {
            return;
        }

        $this->io->write('<info>wp-scoper:</info> Prefixing dependencies...');

        try {
            $composerJsonPath = null;
            $vendorDir = $this->composer->getConfig()->get('vendor-dir');
            $workingDir = dirname($vendorDir);

            // Read host project's PSR-4 autoload config
            $autoload = $this->composer->getPackage()->getAutoload();
            $hostPsr4 = $autoload['psr-4'] ?? [];

            $profile = getenv('SCOPER_PROFILE');
            $profile = ($profile === false || $profile === '') ? null : $profile;

            if ($profile !== null) {
                $this->io->write("  <comment>Using profile: {$profile}</comment>");
            }

            $phpConstraint = self::detectPhpConstraint($this->composer);

            $config = Config::fromArray($extra['wp-scoper'], $workingDir, $hostPsr4, $profile, $phpConstraint);

            $prefixer = new Prefixer($config, function (string $message) {
                $this->io->write("  <comment>{$message}</comment>");
            });

            $prefixer->run();

            $this->io->write('');
            foreach (Prefixer::formatSummaryTable($prefixer->getStats()) as $line) {
                $this->io->write("  <info>{$line}</info>");
            }
            $this->io->write('');
        } catch (\Exception $e) {
            $this->io->writeError("<error>wp-scoper error: {$e->getMessage()}</error>");
        }
    }

    /**
     * The host project's PHP version constraint for php_compat floor detection:
     * the pinned platform version if set, else the declared `require.php`.
     * Shared by the plugin hook and the `wp-scope` command.
     */
    public static function detectPhpConstraint(Composer $composer): ?string
    {
        $platform = $composer->getConfig()->get('platform');
        if (is_array($platform) && !empty($platform['php'])) {
            return $platform['php'];
        }

        $requires = $composer->getPackage()->getRequires();
        if (isset($requires['php'])) {
            return $requires['php']->getPrettyConstraint();
        }

        return null;
    }
}
