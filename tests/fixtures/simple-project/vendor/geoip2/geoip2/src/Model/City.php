<?php

namespace GeoIp2\Model;

class City
{
    private $raw;
    private $locales;

    public function __construct(array $raw, array $locales = ['en'])
    {
        $this->raw = $raw;
        $this->locales = $locales;
    }

    public function getName(): ?string
    {
        return $this->raw['city']['names'][$this->locales[0]] ?? null;
    }
}
