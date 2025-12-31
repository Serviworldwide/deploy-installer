<?php
/**
 * Simple test script to verify PHP environment
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "PHP Version: " . phpversion() . "<br>";
echo "Server: " . $_SERVER['SERVER_SOFTWARE'] . "<br>";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
echo "Script Path: " . __FILE__ . "<br>";
echo "Current Directory: " . __DIR__ . "<br>";

echo "<h2>Functions Check:</h2>";
echo "exec() exists: " . (function_exists('exec') ? 'Yes' : 'No') . "<br>";
echo "shell_exec() exists: " . (function_exists('shell_exec') ? 'Yes' : 'No') . "<br>";
echo "file_get_contents() exists: " . (function_exists('file_get_contents') ? 'Yes' : 'No') . "<br>";

echo "<h2>Environment:</h2>";
echo "HOME: " . (getenv('HOME') ?: 'Not set') . "<br>";
echo "_SERVER[HOME]: " . (isset($_SERVER['HOME']) ? $_SERVER['HOME'] : 'Not set') . "<br>";

if (function_exists('shell_exec')) {
    $user = @shell_exec('whoami');
    echo "Current user: " . ($user ? trim($user) : 'Could not determine') . "<br>";
}

echo "<h2>File Permissions:</h2>";
echo "Script directory writable: " . (is_writable(__DIR__) ? 'Yes' : 'No') . "<br>";

if (function_exists('shell_exec')) {
    echo "<h2>Binary Check:</h2>";
    $binaries = ['git', 'rsync', 'ssh-keygen'];
    foreach ($binaries as $bin) {
        $path = @shell_exec('which ' . escapeshellarg($bin) . ' 2>/dev/null');
        echo "$bin: " . ($path ? trim($path) : 'Not found') . "<br>";
    }
}

echo "<h2>Test Complete</h2>";
echo "If you see this, PHP is working. Check the installer.php for the actual error.";
