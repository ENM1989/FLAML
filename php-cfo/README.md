# CFO for PHP

This is a PHP implementation of the CFO (Capital Flow from Operations) algorithm, a cost-frugal hyperparameter tuning algorithm.

## Installation

To use this library, you can clone the repository and include the files directly, or you can use Composer to manage the dependencies.

```bash
composer install
```

## Usage

```php
<?php

require_once 'vendor/autoload.php';

use Flaml\Tune\Searcher\CFO;

// Define the search space
$space = [
    'x' => ['lower' => -5, 'upper' => 5],
    'y' => ['lower' => -5, 'upper' => 5],
];

// Define the objective function
$objective = function ($config) {
    return $config['x'] ** 2 + $config['y'] ** 2;
};

// Create a new CFO searcher
$searcher = new CFO(
    'objective',
    'min',
    $space
);

// Run the search
for ($i = 0; $i < 100; $i++) {
    $trial_id = "trial_{$i}";
    $config = $searcher->suggest($trial_id);
    if ($config === null) {
        break;
    }
    $result = [
        'objective' => $objective($config),
        'config' => $config,
    ];
    $searcher->on_trial_complete($trial_id, $result);
}

// Get the best config
$best_config = $searcher->get_best_config();
```

## Contributing

Contributions are welcome! Please feel free to submit a pull request.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.
