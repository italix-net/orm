<?php
/**
 * Italix ORM - Manual Autoloader (for testing without Composer)
 */

declare(strict_types=1);

// PSR-4 autoloader for Italix\Orm namespace
spl_autoload_register(function ($class) {
    $prefix = 'Italix\\Orm\\';
    $base_dir = __DIR__ . '/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Load function files
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Schema/ColumnTypes.php';
require_once __DIR__ . '/Operators/Operators.php';

// Sql class is autoloaded, but ensure it's available
require_once __DIR__ . '/Sql.php';
