<?php

namespace Test;

use Mockery;

/**
 * Class TestCase
 * @package Test
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * Mockery integration
     * return @void
     */
    public function tearDown()
    {
        Mockery::close();
    }

    /**
     * Return the unserialized json file content
     * @param $file
     * @return mixed|null
     */
    public function getJSON($file)
    {
        if (is_readable($file)) {
            return json_decode(file_get_contents($file));
        }

        return null;
    }
}