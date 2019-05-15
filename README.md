# Connect Migration Middleware

[![Build Status](https://travis-ci.com/ingrammicro/connect-php-sdk-migration-framework.svg?branch=master)](https://travis-ci.com/ingrammicro/connect-php-sdk-migration-framework) [![Latest Stable Version](https://poser.pugx.org/apsconnect/connect-sdk-migration-framework/v/stable)](https://packagist.org/packages/apsconnect/connect-sdk-migration-framework) [![License](https://poser.pugx.org/apsconnect/connect-sdk-migration-framework/license)](https://packagist.org/packages/apsconnect/connect-sdk-migration-framework) [![codecov](https://codecov.io/gh/ingrammicro/connect-php-sdk-migration-framework/branch/master/graph/badge.svg)](https://codecov.io/gh/ingrammicro/connect-php-sdk-migration-framework)

Small middleware to ease the service migration from legacy to Connect

## Installation

Install via ***composer***:

```json
{
    "require": {
      "apsconnect/connect-sdk-migration-framework": "*"
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
| validation      | `callable`                | Custom validation function. Not defined by default. |
| onSuccess       | `callable`                | Custom function to execute on migration success. Not defined by default. |
| onFail          | `callable`                | Custom function to execute on migration fail. Not defined by default. |
| transformations | `array`                   | Assoc array with the connect param id as key and the rule to process the parameter value from the legacy data. Default value is an empty array. |

Input parameters: 

- `validation`: `$migrationData`, `Request $request`, `Config $config`, `LoggerInterface $logger`
- `onSuccess`: `$migrationData`, `Request $request`, `Config $config`, `LoggerInterface $logger`
- `onFail`: `$migrationData`, `Request $request`, `Config $config`, `LoggerInterface $logger`, `MigrationAbortException $e`
- `transformations`: `$migrationData`, `Request $request`, `Config $config`, `LoggerInterface $logger`

```php
<?php

namespace App\Providers;

use GuzzleHttp\Client;
use Connect\Config;
use Connect\Request;
use Connect\Fail;
use Connect\Middleware\Migration\Handler as MigrationHandler;
use Connect\Runtime\ServiceProvider;
use Pimple\Container;
use Psr\Log\LoggerInterface;
use Connect\Middleware\Migration\Exceptions\MigrationParameterFailException;
use Connect\Middleware\Migration\Exceptions\MigrationAbortException;

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
            'config' => $container['config'],
            'transformations' => [
                'email' => function ($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                    $logger->info("[MIGRATION::{$request->id}] Processing teamAdminEmail parameter.");
                    
                    $client = new Client();
                    $response = $client->request('GET', strtr($config->service->migration->url, [
                        '{instance}' => $migrationData->instance,
                        '{subscription}' => $migrationData->subscription
                    ]) . '/teamAdminEmail', [
                        'headers' => [
                            'http_errors' => false,
                            'Authorization' => 'Basic ' . $migrationData->token
                        ]
                    ]);
                    
                    if ($response->getStatusCode() !== 200) {
                        throw new MigrationParameterFailException("Missing field teamAdminEmail", $response->getStatusCode());
                    }
                    
                    $data = json_decode($response->getBody()->getContents());
                    
                    if(empty($data->value)) {
                        throw new MigrationParameterFailException("Missing field teamAdminEmail.", 400);
                    }
                    
                    if(!filter_var($data->value, FILTER_VALIDATE_EMAIL)) {
                        throw new MigrationParameterFailException("Wrong field teamAdminEmail must be an email.", 400);
                    }
                    
                    return strtolower($data->value);
                },
                'team_id' => function ($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                    $logger->info("[MIGRATION::{$request->id}] Processing teamId parameter.");
                    
                    $client = new Client();
                    $response = $client->request('GET', strtr($config->service->migration->url, [
                        '{instance}' => $migrationData->instance,
                        '{subscription}' => $migrationData->subscription
                    ]) . '/teamId', [
                        'headers' => [
                            'http_errors' => false,
                            'Authorization' => 'Basic ' . $migrationData->token
                        ]
                    ]);
                    
                    if ($response->getStatusCode() !== 200) {
                        throw new MigrationParameterFailException("Missing field teamId", $response->getStatusCode());
                    }
                    
                    $data = json_decode($response->getBody()->getContents());
                    
                    if(empty($data->value)) {
                        throw new MigrationParameterFailException("Missing field teamId.", 400);
                    }
                    
                    return strtolower($data->value);
                },
                'team_name' => function ($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                    $logger->info("[MIGRATION::{$request->id}] Processing teamName parameter.");
                    
                    $client = new Client();
                    $response = $client->request('GET', strtr($config->service->migration->url, [
                        '{instance}' => $migrationData->instance,
                        '{subscription}' => $migrationData->subscription
                    ]) . '/teamName', [
                        'headers' => [
                            'http_errors' => false,
                            'Authorization' => 'Basic ' . $migrationData->token
                        ]
                    ]);
                    
                    if ($response->getStatusCode() !== 200) {
                        throw new MigrationParameterFailException("Missing field teamName", $response->getStatusCode());
                    }
                    
                    $data = json_decode($response->getBody()->getContents());
                    
                    if(empty($data->value)) {
                        throw new MigrationParameterFailException("Missing field teamName.", 400);
                    }
                    
                    return ucwords($data->teamName);
                },
            ],
            'onSuccess' => function($migrationData, Request $request, Config $config, LoggerInterface $logger) {
                $logger->info("Migration for request {$request->id} successful!");
            },
            'onFail' => function($migrationData, Request $request, Config $config, LoggerInterface $logger, MigrationAbortException $e) {
                throw new Fail("Failing request {$request->id} due: " . $e->getMessage());
            }
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

And in our `ProductFulfillment.php`:

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
| `MigrationParameterPassException` | Parameter process will be omitted, other parameters will continue normally. | 



