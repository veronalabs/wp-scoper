<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use VeronaLabs\WpScoper\Config\Config;
use VeronaLabs\WpScoper\Prefixer;

class StandalonePrefixCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('prefix')
            ->setDescription('Prefix namespaces in WordPress plugin dependencies')
            ->addArgument(
                'working-dir',
                InputArgument::OPTIONAL,
                'Path to the project directory containing composer.json',
                '.'
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workingDir = $input->getArgument('working-dir');
        $composerJsonPath = rtrim($workingDir, '/') . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            $output->writeln(sprintf('<error>composer.json not found at: %s</error>', $composerJsonPath));
            return 1;
        }

        if ($input->getOption('dry-run')) {
            $json = json_decode(file_get_contents($composerJsonPath), true);
            $config = $json['extra']['wp-scoper'] ?? [];

            $output->writeln('<info>Dry run mode - no changes will be made</info>');
            $output->writeln('');
            $output->writeln('Configuration:');
            $output->writeln(sprintf('  Namespace prefix: %s', $config['namespace_prefix'] ?? 'N/A'));
            $output->writeln(sprintf('  Packages: %s', implode(', ', $config['packages'] ?? [])));
            $output->writeln(sprintf('  Target directory: %s', $config['target_directory'] ?? 'vendor-prefixed'));
            return 0;
        }

        try {
            $config = Config::fromComposerJson($composerJsonPath);

            $prefixer = new Prefixer($config, function (string $message) use ($output) {
                $output->writeln("  <comment>{$message}</comment>");
            });

            $prefixer->run();

            $output->writeln('<info>wp-scoper: Prefixing complete!</info>');
            return 0;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));
            return 1;
        }
    }
}
