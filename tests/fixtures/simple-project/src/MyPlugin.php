<?php

namespace TestProject;

use GeoIp2\Database\Reader;
use GeoIp2\Model\City;

class MyPlugin
{
    private $reader;

    public function __construct()
    {
        $this->reader = new Reader('/path/to/db.mmdb');
    }

    public function getCity(string $ip): ?City
    {
        return $this->reader->city($ip);
    }
}
