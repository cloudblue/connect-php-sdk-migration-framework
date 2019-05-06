<?php

namespace Connect\Middleware\Migration;

use Closure;
use Connect\Middleware\Migration\Exceptions\MigrationAbortException;
use Connect\Middleware\Migration\Exceptions\MigrationParameterFailException;
use Connect\Model;
use Connect\Param;
use Connect\Request;
use Connect\Skip;
use Psr\Log\LoggerInterface;

/**
 * Class Migration
 * @package Connect\Middleware\Migration
 */
class Handler extends Model
{
    /**
     * Defines the migration param that triggers a migration.
     * @var string
     */
    private $migrationFlag = 'migration_info';

    /**
     * Logger instance.
     * @var LoggerInterface
     */
    private $logger;

    /**
     * If true will automatically serialize any non-string value
     * in the migration data on direct assignation flow.
     * @var bool
     */
    private $serialize = false;

    /**
     * Array of Parameters transformations.
     * @var callable[]
     */
    private $transformations = [];

    /***************************************************
     *                Setters and Getters
     ***************************************************/

    /**
     * Set the migration flag.
     * @param $migrationFlag
     */
    public function setMigrationFlag($migrationFlag)
    {
        $this->migrationFlag = $migrationFlag;
    }

    /**
     * Set the logger instance.
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Return the migration flag.
     * @return string
     */
    public function getMigrationFlag()
    {
        return $this->migrationFlag;
    }

    /**
     * Return the logger instance.
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Set parameters transformations
     * @param array $transformations
     */
    public function setTransformations(array $transformations)
    {
        $this->transformations = $transformations;
    }

    /**
     * Return all the parameters transformations.
     * @return callable[]
     */
    public function getTransformations()
    {
        return $this->transformations;
    }

    /**
     * Add/replace a parameter transformation by parameter id.
     * @param string $parameterId
     * @param callable $transformation
     */
    public function setTransformation($parameterId, callable $transformation)
    {
        $this->transformations[$parameterId] = $transformation;
    }

    /**
     * Get the requested parameter transformation.
     * @param $parameterId
     * @return Closure|null
     */
    public function getTransformation($parameterId)
    {
        if (isset($this->transformations[$parameterId])) {
            return $this->transformations[$parameterId];
        }

        return null;
    }

    /**
     * Unset a transformation by id
     * @param $parameterId
     * @return bool
     */
    public function unsetTransformation($parameterId)
    {
        unset($this->transformations[$parameterId]);
        return !isset($this->transformations[$parameterId]);
    }

    /**
     * Set the serialize value
     * @param $serialize
     */
    public function setSerialize($serialize)
    {
        $this->serialize = $serialize;
    }

    /**
     * Return the serialize value
     * @return bool
     */
    public function getSerialize()
    {
        return $this->serialize;
    }

    /***************************************************
     *              Migration Operations
     ***************************************************/

    /**
     * Check if a given request contains migration data.
     * @param Request $request
     * @return bool
     */
    public function isMigration(Request $request)
    {
        return !empty($request->asset->getParameterByID($this->migrationFlag)->value);
    }

    /**
     * Execute the migration rules to populate the given request
     * @param Request $request
     * @return Request
     * @throws Skip
     */
    public function migrate(Request $request)
    {
        if (!$this->isMigration($request)) {
            return $request;
        }

        $new = clone $request;

        $this->logger->info("[MIGRATION::{$request->id}] Running migration operations for request {$request->id}.");

        $report = [
            'failed' => [],
            'success' => [],
            'processed' => [],
        ];

        try {

            $rawMigrationData = $request->asset->getParameterByID($this->migrationFlag)->value;

            $this->logger->debug("[MIGRATION::{$request->id}] Migration data {$this->migrationFlag} {$rawMigrationData}.");

            $migrationData = json_decode($rawMigrationData);

            if (json_last_error() !== 0) {
                $msg = json_last_error_msg();
                throw new MigrationAbortException(
                    "Unable to parse {$this->migrationFlag} parameter due: {$msg}."
                );
            }

            $this->logger->debug("[MIGRATION::{$request->id}] Migration data {$this->migrationFlag} parsed correctly.");

            /** @var Param $param */
            foreach ($new->asset->params as $param) {

                try {

                    /**
                     * UC#1: There is some operation (closure) registered in the
                     * transformation array by parameter id. This operation will
                     * be executed to populate que requested parameter.
                     */
                    if (array_key_exists($param->id, $this->transformations)) {

                        $this->logger->info("[MIGRATION::{$request->id}] Running transformation for parameter{$param->id}.");
                        $param->value(call_user_func_array($this->transformations[$param->id], [
                            'data' => $migrationData,
                            'logger' => $this->logger,
                            'requestId' => $request->id,
                        ]));

                    } else {

                        /**
                         * USC#2 No transformation operation defined, the system will
                         * try to search in the migration data any value using the
                         * connect parameter id as key, if exists the data will be
                         * used as parameter value, if not, no changes will be done.
                         */
                        if (is_object($migrationData) && isset($migrationData->{$param->id})) {
                            if (!is_string($migrationData->{$param->id})) {
                                if ($this->serialize) {

                                    // Use JSON_UNESCAPED_SLASHES to save characters (1 slash per double quote)
                                    $migrationData->{$param->id} = json_encode(
                                        $migrationData->{$param->id},
                                        JSON_UNESCAPED_SLASHES
                                    );
                                } else {
                                    $paramType = gettype($migrationData->{$param->id});
                                    throw new MigrationParameterFailException(
                                        "Invalid parameter {$param->id} type, must be string, given {$paramType}."
                                    );
                                }
                            }

                            $param->value($migrationData->{$param->id});
                        }
                    }

                    $report['success'][] = $param->id;

                } catch (MigrationParameterFailException $e) {

                    $this->logger->error("[MIGRATION::{$request->id}] #{$e->getCode()}: {$e->getMessage()}.");
                    $report['failed'][] = $param->id;
                }

                $report['processed'][] = $param->id;
            }

            if (count($report['failed']) > 0) {

                $failed = implode(', ', $report['failed']);
                throw new MigrationAbortException(
                    "Some parameter process has failed ({$failed}), unable to complete the migration."
                );
            }

            $success = count($report['success']);
            $processed = count($report['processed']);

            $byName = implode(', ', $report['success']);
            $this->logger->info("[MIGRATION::{$request->id}] Parameters {$success}/{$processed} ({$byName}) processed correctly.");

        } catch (MigrationAbortException $e) {

            $this->logger->error("[MIGRATION::{$request->id}] {$e->getCode()}: {$e->getMessage()}.");
            throw new Skip("Migration failed.");
        }

        return $new;
    }
}