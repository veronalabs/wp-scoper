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

            $config = Config::fromArray($extra['wp-scoper'], $workingDir);

            $prefixer = new Prefixer($config, function (string $message) {
                $this->io->write("  <comment>{$message}</comment>");
            });

            $prefixer->run();

            $this->io->write('<info>wp-scoper:</info> Done!');
        } catch (\Exception $e) {
            $this->io->writeError("<error>wp-scoper error: {$e->getMessage()}</error>");
        }
    }
}
