<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
echo "debug start\n";
try {
    require 'config.php';
    echo "config loaded OK\n";
    echo "PLATS: " . implode(',', wf_catalog_plats()) . "\n";
    $c = jload('catalog_counts.json', []);
    echo "catalog_counts: " . json_encode($c, JSON_UNESCAPED_UNICODE) . "\n";
    echo "mnfean count: " . count(jload('mnfean.json', [])) . "\n";
    echo "debug end\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
