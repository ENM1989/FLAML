<?php

use Flaml\Tune\Searcher\FLOW2;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../FLOW2.php';

class FLOW2Test extends TestCase
{
    public function testSuggest()
    {
        $space = [
            'x' => ['lower' => -5, 'upper' => 5],
            'y' => ['lower' => -5, 'upper' => 5],
        ];

        $searcher = new FLOW2(
            ['x' => 0, 'y' => 0],
            'objective',
            'min',
            $space
        );

        $config = $searcher->suggest('trial_1');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('x', $config);
        $this->assertArrayHasKey('y', $config);
    }
}
