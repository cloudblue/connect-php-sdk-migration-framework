# Connect Migration Middleware

[![Build Status](https://travis-ci.com/ingrammicro/connect-migration-middleware.svg?branch=master)](https://travis-ci.com/ingrammicro/connect-migration-middleware) [![codecov](https://codecov.io/gh/ingrammicro/connect-migration-middleware/branch/master/graph/badge.svg)](https://codecov.io/gh/ingrammicro/connect-migration-middleware)

Small middleware to ease the service migration from legacy to Connect

## Installation

Install via ***composer***:

```json
{
    "require": {
      "ingrammicro/connect-migration-middleware": "*"
    }
}
```

## Usage 

Once we have the package installed we need to create a new service provider to inject the middleware 
into our connector. We need to provide some basic configuration to our migrations service in order to 
properly migrate the incoming old data.

### Configuration parameters

| Parameter       | Type                      | Description                           |
| --------------- | ------------------------- | ------------------------------------- |
| logger          | `Psr\Log\LoggerInterface` | The logger instance of our connector. |
| migrationFlag   | `string`                  | The name of the Connect parameter that stores the legacy data in json format. Default value is `migration_info`|
| serialize       | `bool`                    | If true will automatically serialize any non-string value in the migration data on direct assignation flow. Default value is `false` |
| transformations | `array`                   | Assoc array with the connect param id as key and the rule to process the parameter value from the legacy data. Default value is an empty array. |

```php
<?php

namespace App\Providers;

use Connect\Middleware\Migration\Handler as MigrationHandler;
use Connect\Runtime\ServiceProvider;
use Pimple\Container;
use Psr\Log\LoggerInterface;
use Connect\Middleware\Migration\Exceptions\MigrationParameterFailException;

/**
 * Class MigrationServiceProvider
 * @package App\Providers
 */
class MigrationServiceProvider extends ServiceProvider
{
    /**
     * Create a Migrate middleware
     * @param Container $container
     * @return MigrationHandler
     */
    public function register(Container $container)
    {
        return new MigrationHandler([
            'logger' => $container['logger'],
            'transformations' => [
                'email' => function ($migrationData, LoggerInterface $logger, $rid) {
                    $logger->info("[MIGRATION::{$rid}] Processing teamAdminEmail parameter.");
                    
                    if(empty($migrationData->teamAdminEmail)) {
                        throw new MigrationParameterFailException("Missing field teamAdminEmail.", 400);
                    }
                    
                    if(!filter_var($migrationData->teamAdminEmail, FILTER_VALIDATE_EMAIL)) {
                        throw new MigrationParameterFailException("Wrong field teamAdminEmail must be an email.", 400);
                    }
                    
                    return strtolower($migrationData->teamAdminEmail);
                },
                'team_id' => function ($migrationData, LoggerInterface $logger, $rid) {
                    $logger->info("[MIGRATION::{$rid}] Processing teamId parameter.");
                    
                    if(empty($migrationData->teamId)) {
                        throw new MigrationParameterFailException("Missing field teamId.", 400);
                    }
                    
                    return strtolower($migrationData->teamId);
                },
                'team_name' => function ($migrationData, LoggerInterface $logger, $rid) {
                    $logger->info("[MIGRATION::{$rid}] Processing teamName parameter.");
                    
                    if(empty($migrationData->teamName)) {
                        throw new MigrationParameterFailException("Missing field teamName.", 400);
                    }
                    
                    return ucwords($migrationData->teamName);
                },
            ]
        ]);
    }
}
```

Next we need to add this service provider to our configuration json:

```json 
{
  "runtimeServices": {
    "migration": "\\App\\Providers\\MigrationServiceProvider",
  }  
}

```

Finally we only need to call the migration service inside our `processRequest()` method of 
the `FulfillmentAutomation` class:

```php

<?php

namespace App;

use Connect\Logger;
use Connect\Middleware\Migration\Handler;
use Connect\FulfillmentAutomation;

/**
 * Class ProductFulfillment
 * @package App
 * @property Logger $logger
 * @property Handler $migration
 */
class ProductFulfillment extends FulfillmentAutomation
 {
    public function processRequest($request)
    {
        switch ($request->type) {
            case "purchase":
                
                $request = $this->migration->migrate($request);
                
                // the migrate() method returns a new request object with the
                // migrated data populated, we only need to update the params 
                // and approve the fulfillment to complete the migration.
                
                $this->updateParameters($request, $request->asset->params);
                
                // more code...
        }

    }
    
    public function processTierConfigRequest($tierConfigRequest)
    {
        // NOT MIGRABLE! (YET)
    }
}
```

### Exceptions

The connect migration middleware uses two different exceptions:

| Exception | Description | 
| --------- | ------------------------------ | 
| `MigrationParameterFailException` | Can be thrown if any parameter fails on validation and/or transformation, an error will be logged for that parameter, the migration will fail, the fulfillment will be skipped. | 
| `MigrationAbortException` | The migration will directly fail, the fulfillment will be skipped. | 



