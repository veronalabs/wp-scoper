<?php

namespace GeoIp2\Database;

use GeoIp2\Exception\AddressNotFoundException;
use GeoIp2\Model\City;
use MaxMind\Db\Reader as DbReader;

class Reader
{
    private $dbReader;
    private $locales;

    public function __construct(string $filename, array $locales = ['en'])
    {
        $this->dbReader = new DbReader($filename);
        $this->locales = $locales;
    }

    public function city(string $ipAddress): City
    {
        $record = $this->getRecord('City', $ipAddress);
        return new City($record, $this->locales);
    }

    private function getRecord(string $type, string $ipAddress): array
    {
        $record = $this->dbReader->get($ipAddress);
        if ($record === null) {
            throw new AddressNotFoundException(
                "The address {$ipAddress} is not in the database."
            );
        }
        return $record;
    }

    public function close(): void
    {
        $this->dbReader->close();
    }
}
