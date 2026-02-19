<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Command;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use VeronaLabs\WpScoper\Config\Config;
use VeronaLabs\WpScoper\Prefixer;

class PrefixCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('wp-scope')
            ->setDescription('Prefix namespaces in WordPress plugin dependencies')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();
        $extra = $composer->getPackage()->getExtra();

        if (!isset($extra['wp-scoper'])) {
            $output->writeln('<error>No "extra.wp-scoper" configuration found in composer.json</error>');
            return 1;
        }

        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $workingDir = dirname($vendorDir);

        if ($input->getOption('dry-run')) {
            $output->writeln('<info>Dry run mode - no changes will be made</info>');
            $output->writeln('');
            $output->writeln('Configuration:');
            $output->writeln(sprintf('  Namespace prefix: %s', $extra['wp-scoper']['namespace_prefix'] ?? 'N/A'));
            $output->writeln(sprintf('  Packages: %s', implode(', ', $extra['wp-scoper']['packages'] ?? [])));
            $output->writeln(sprintf('  Target directory: %s', $extra['wp-scoper']['target_directory'] ?? 'vendor-prefixed'));
            return 0;
        }

        try {
            $autoload = $composer->getPackage()->getAutoload();
            $hostPsr4 = $autoload['psr-4'] ?? [];

            $config = Config::fromArray($extra['wp-scoper'], $workingDir, $hostPsr4);

            $prefixer = new Prefixer($config, function (string $message) use ($output) {
                $output->writeln("  <comment>{$message}</comment>");
            });

            $prefixer->run();

            $output->writeln('');
            foreach (Prefixer::formatSummaryTable($prefixer->getStats()) as $line) {
                $output->writeln("  <info>{$line}</info>");
            }
            $output->writeln('');
            return 0;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return 1;
        }
    }
}
