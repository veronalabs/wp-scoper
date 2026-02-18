<?php

declare(strict_types=1);

namespace VeronaLabs\WpScoper\Config;

class Package
{
    /** @var string */
    private $name;

    /** @var string */
    private $path;

    /** @var array<string, string> PSR-4 autoload: namespace => directory */
    private $autoloadPsr4;

    /** @var array<string> Classmap directories/files */
    private $autoloadClassmap;

    /** @var array<string> Files to auto-include */
    private $autoloadFiles;

    /** @var array<string> Direct dependency names */
    private $dependencies;

    /** @var array<string> Namespaces discovered from autoload config */
    private $namespaces;

    public function __construct(
        string $name,
        string $path,
        array $autoloadPsr4 = [],
        array $autoloadClassmap = [],
        array $autoloadFiles = [],
        array $dependencies = []
    ) {
        $this->name = $name;
        $this->path = $path;
        $this->autoloadPsr4 = $autoloadPsr4;
        $this->autoloadClassmap = $autoloadClassmap;
        $this->autoloadFiles = $autoloadFiles;
        $this->dependencies = $dependencies;
        $this->namespaces = array_map(function (string $ns): string {
            return rtrim($ns, '\\');
        }, array_keys($autoloadPsr4));
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /** @return array<string, string> */
    public function getAutoloadPsr4(): array
    {
        return $this->autoloadPsr4;
    }

    /** @return array<string> */
    public function getAutoloadClassmap(): array
    {
        return $this->autoloadClassmap;
    }

    /** @return array<string> */
    public function getAutoloadFiles(): array
    {
        return $this->autoloadFiles;
    }

    /** @return array<string> */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /** @return array<string> */
    public function getNamespaces(): array
    {
        return $this->namespaces;
    }

    public function setNamespaces(array $namespaces): void
    {
        $this->namespaces = $namespaces;
    }
}
