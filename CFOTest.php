<?php

use Flaml\Tune\Searcher\CFO;
use PHPUnit\Framework\TestCase;

require_once 'cfo_all_classes.php';

class CFOTest extends TestCase
{
    public function testSuggest()
    {
        $space = [
            'x' => ['lower' => -5, 'upper' => 5],
            'y' => ['lower' => -5, 'upper' => 5],
        ];

        $searcher = new CFO(
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
