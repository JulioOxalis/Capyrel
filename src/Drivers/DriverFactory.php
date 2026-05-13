<?php

namespace Julio\Capyrel\Drivers;

use Illuminate\Support\Facades\DB;

class DriverFactory
{
    private static array $mongoDriverNames = ['mongodb', 'mongo'];

    public static function make(string $connection = ''): SchemaDriverInterface
    {
        try {
            $driverName = strtolower(
                DB::connection($connection ?: config('database.default'))->getDriverName()
            );
        } catch (\Throwable) {
            $driverName = 'sqlite';
        }

        if (in_array($driverName, self::$mongoDriverNames)) {
            return new MongoDriver($connection);
        }

        return new SqlDriver($connection);
    }
}
