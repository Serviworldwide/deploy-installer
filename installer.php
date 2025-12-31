<?php
/**
 * Deploy Installer Script
 * 
 * Automated installer for simple-php-git-deploy on cPanel servers
 * 
 * @version 1.0.0
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Set error handler to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo '<!DOCTYPE html><html><head><title>Error</title><style>body{font-family:Arial;padding:20px;background:#fee;color:#c00;}</style></head><body>';
        echo '<h1>PHP Fatal Error</h1>';
        echo '<p><strong>File:</strong> ' . htmlspecialchars($error['file']) . '</p>';
        echo '<p><strong>Line:</strong> ' . htmlspecialchars($error['line']) . '</p>';
        echo '<p><strong>Message:</strong> ' . htmlspecialchars($error['message']) . '</p>';
        echo '</body></html>';
    }
});

// Check if deployment is already configured
$configFile = __DIR__ . '/deploy-config.php';
$isAlreadyConfigured = false;
$configToken = '';

if (file_exists($configFile)) {
    // Try to read and check the config file
    $configContent = @file_get_contents($configFile);
    if ($configContent !== false) {
        // Check if SECRET_ACCESS_TOKEN is defined and not the default value
        if (preg_match("/define\s*\(\s*['\"]SECRET_ACCESS_TOKEN['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $configContent, $matches)) {
            $configToken = $matches[1];
            // Check if it's not the default value and not empty
            if (!empty($configToken) && $configToken !== 'BetterChangeMeNowOrSufferTheConsequences') {
                // Also check if other required configs exist
                if (preg_match("/define\s*\(\s*['\"]REMOTE_REPOSITORY['\"]/", $configContent) &&
                    preg_match("/define\s*\(\s*['\"]TARGET_DIR['\"]/", $configContent)) {
                    $isAlreadyConfigured = true;
                }
            }
        }
    }
}

// If already configured, show message and exit
if ($isAlreadyConfigured) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Deployment Already Configured</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 20px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .container {
                max-width: 600px;
                background: white;
                border-radius: 12px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                padding: 40px;
                text-align: center;
            }
            h1 {
                color: #4caf50;
                margin-bottom: 20px;
                font-size: 32px;
            }
            .alert {
                background: #e8f5e9;
                border-left: 4px solid #4caf50;
                padding: 20px;
                border-radius: 6px;
                margin: 20px 0;
                text-align: left;
            }
            .alert-info {
                background: #e3f2fd;
                border-left: 4px solid #2196f3;
                color: #1565c0;
            }
            code {
                background: #f5f5f5;
                padding: 2px 6px;
                border-radius: 3px;
                font-family: 'Courier New', monospace;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>✓ Deployment Already Configured</h1>
            <div class="alert">
                <strong>Installation Complete</strong><br><br>
                The deployment system has already been set up. The <code>deploy-config.php</code> file exists with a valid configuration.
            </div>
            <div class="alert alert-info">
                <strong>Next Steps:</strong><br><br>
                • Your deployment is active and will automatically deploy when you push to GitHub<br>
                • To test deployment, push changes to your repository<br>
                • To reconfigure, delete <code>deploy-config.php</code> and run the installer again<br>
                • For security, consider removing <code>installer.php</code> after successful deployment
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Handle form submission
$step = (isset($_POST['step']) && is_numeric($_POST['step'])) ? (int)$_POST['step'] : 1;
$errors = [];
$success = [];

// Ensure $_POST is set
if (!isset($_POST)) {
    $_POST = [];
}

// Helper functions
function isFunctionEnabled($functionName) {
    if (!function_exists($functionName)) {
        return false;
    }
    $disabled = ini_get('disable_functions');
    if ($disabled) {
        $disabled = explode(',', $disabled);
        $disabled = array_map('trim', $disabled);
        return !in_array($functionName, $disabled);
    }
    return true;
}

function checkBinary($command) {
    // Use the same path-finding logic as deploy.php
    $commonPaths = array(
        '/usr/local/cpanel/3rdparty/lib/path-bin',
        '/usr/local/bin',
        '/usr/bin',
        '/opt/cpanel/ea-git-core/bin',
        '/usr/local/cpanel/3rdparty/bin',
    );
    
    // First try 'which' command if shell_exec is available
    if (function_exists('shell_exec')) {
        $pathResult = @shell_exec('which ' . escapeshellarg($command) . ' 2>/dev/null');
        $path = is_string($pathResult) ? trim($pathResult) : '';
        if ($path && file_exists($path)) {
            return true;
        }
    }
    
    // Try common paths directly
    foreach ($commonPaths as $basePath) {
        $fullPath = $basePath . '/' . $command;
        if (file_exists($fullPath) && is_executable($fullPath)) {
            return true;
        }
    }
    
    return false;
}

function getHomeDir() {
    $home = getenv('HOME');
    if (empty($home)) {
        // Try $_SERVER['HOME'] first
        if (isset($_SERVER['HOME']) && !empty($_SERVER['HOME'])) {
            $home = $_SERVER['HOME'];
        } elseif (function_exists('shell_exec') && isFunctionEnabled('shell_exec')) {
            $user = @shell_exec('whoami 2>/dev/null');
            if (!empty($user)) {
                $user = trim($user);
                if (!empty($user)) {
                    $home = '/home/' . $user;
                }
            }
        }
    }
    // Fallback to current directory's parent or common cPanel paths
    if (empty($home)) {
        $scriptDir = __DIR__;
        // Try to extract home from common cPanel paths like /home/username/public_html
        if (preg_match('#^/home/([^/]+)#', $scriptDir, $matches)) {
            $home = '/home/' . $matches[1];
        } else {
            $home = dirname(dirname($scriptDir)); // Fallback
        }
    }
    return $home;
}

function generateSecureToken($length = 16) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
}

function convertGithubUrlToSsh($url) {
    // Remove trailing slash if present
    $url = rtrim(trim($url), '/');
    
    // If it's already in SSH format, return as is
    if (preg_match('/^git@github\.com:/', $url)) {
        // Ensure it ends with .git
        return rtrim($url, '.git') . '.git';
    }
    
    // Handle https://github.com/username/repo format
    if (preg_match('#^https?://(www\.)?github\.com/([^/]+)/([^/]+?)(?:\.git)?/?$#', $url, $matches)) {
        $username = $matches[2];
        $repo = rtrim($matches[3], '.git');
        return "git@github.com:{$username}/{$repo}.git";
    }
    
    // Handle github.com/username/repo format (without protocol)
    if (preg_match('#^(?:www\.)?github\.com/([^/]+)/([^/]+?)(?:\.git)?/?$#', $url, $matches)) {
        $username = $matches[1];
        $repo = rtrim($matches[2], '.git');
        return "git@github.com:{$username}/{$repo}.git";
    }
    
    // If we can't parse it, return as is and let user fix it
    return $url;
}

// Process form submissions
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 2) {
        // Step 2: Download files and generate SSH keys
        $currentDir = __DIR__;
        
        // Download deploy.php from our repository (public repo)
        $deployPhpUrl = 'https://raw.githubusercontent.com/Serviworldwide/deploy-installer/main/deploy.php';
        $githubToken = getenv('GITHUB_TOKEN'); // Optional: Set GITHUB_TOKEN environment variable for private repos
        
        // Try downloading with authentication if token is available
        $deployPhpContent = false;
        if (!empty($githubToken)) {
            // Use GitHub API for authenticated access to private repos
            $apiUrl = 'https://api.github.com/repos/Serviworldwide/deploy-installer/contents/deploy.php?ref=main';
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: PHP',
                        'Authorization: token ' . $githubToken,
                        'Accept: application/vnd.github.v3.raw'
                    ]
                ]
            ]);
            $deployPhpContent = @file_get_contents($apiUrl, false, $context);
        }
        
        // Fallback to direct raw URL (works for public repos)
        if ($deployPhpContent === false) {
            $deployPhpContent = @file_get_contents($deployPhpUrl);
        }
        
        // Try with curl if file_get_contents fails
        if ($deployPhpContent === false && function_exists('curl_init')) {
            $ch = curl_init($deployPhpUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            if (!empty($githubToken)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: token ' . $githubToken,
                    'User-Agent: PHP'
                ]);
            }
            $deployPhpContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            // If still failed and token exists, try API endpoint
            if ($deployPhpContent === false && !empty($githubToken) && $httpCode == 404) {
                $apiUrl = 'https://api.github.com/repos/Serviworldwide/deploy-installer/contents/deploy.php?ref=main';
                $ch = curl_init($apiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: token ' . $githubToken,
                    'Accept: application/vnd.github.v3.raw',
                    'User-Agent: PHP'
                ]);
                $deployPhpContent = curl_exec($ch);
                curl_close($ch);
            }
        }
        
        if ($deployPhpContent !== false) {
            $deployPhpFile = $currentDir . '/deploy.php';
            $deployExists = file_exists($deployPhpFile);
            if (file_put_contents($deployPhpFile, $deployPhpContent)) {
                if ($deployExists) {
                    $success[] = 'deploy.php updated from Serviworldwide/deploy-installer repository';
                } else {
                    $success[] = 'deploy.php downloaded successfully from Serviworldwide/deploy-installer repository';
                }
            } else {
                $errors[] = 'Failed to write deploy.php - check file permissions';
            }
        } else {
            $errors[] = 'Failed to download deploy.php - check internet connection';
        }
        
        // Generate SSH keys
        $homeDir = getHomeDir();
        if (empty($homeDir)) {
            $errors[] = 'Could not determine home directory. Please set HOME environment variable or contact your hosting provider.';
        } else {
            $sshDir = $homeDir . '/.ssh';
            $deployKey = $sshDir . '/deploy_key';
            $deployKeyPub = $deployKey . '.pub';
            
            if (!is_dir($sshDir)) {
                @mkdir($sshDir, 0700, true);
            }
            
            if (!file_exists($deployKey)) {
                // Generate SSH key
                $keygenCmd = sprintf(
                    'ssh-keygen -t ed25519 -f %s -N "" -q 2>&1',
                    escapeshellarg($deployKey)
                );
                $keygenOutput = @shell_exec($keygenCmd);
                
                if (file_exists($deployKeyPub)) {
                    $success[] = 'SSH key pair generated successfully';
                    
                    // Add to authorized_keys
                    $pubKeyContent = trim(file_get_contents($deployKeyPub));
                    $authorizedKeysFile = $sshDir . '/authorized_keys';
                    
                    if (!file_exists($authorizedKeysFile)) {
                        @touch($authorizedKeysFile);
                        @chmod($authorizedKeysFile, 0600);
                    }
                    
                    $authorizedKeys = file_exists($authorizedKeysFile) ? file_get_contents($authorizedKeysFile) : '';
                    if (strpos($authorizedKeys, $pubKeyContent) === false) {
                        file_put_contents($authorizedKeysFile, $pubKeyContent . "\n", FILE_APPEND);
                        $success[] = 'Public key added to authorized_keys';
                    }
                } else {
                    $errors[] = 'SSH key generation may have failed - check permissions';
                }
            } else {
                // Key already exists - ensure it's in authorized_keys
                $success[] = 'SSH key already exists';
                
                if (file_exists($deployKeyPub)) {
                    $pubKeyContent = trim(file_get_contents($deployKeyPub));
                    $authorizedKeysFile = $sshDir . '/authorized_keys';
                    
                    if (!file_exists($authorizedKeysFile)) {
                        @touch($authorizedKeysFile);
                        @chmod($authorizedKeysFile, 0600);
                    }
                    
                    $authorizedKeys = file_exists($authorizedKeysFile) ? file_get_contents($authorizedKeysFile) : '';
                    if (strpos($authorizedKeys, $pubKeyContent) === false) {
                        file_put_contents($authorizedKeysFile, $pubKeyContent . "\n", FILE_APPEND);
                        $success[] = 'Public key added to authorized_keys';
                    }
                }
            }
        }
        
        if (empty($errors)) {
            $step = 3; // Move to configuration step
        }
    } elseif ($step === 3) {
        // Step 3: Generate config file
        $secretToken = isset($_POST['secret_token']) ? trim($_POST['secret_token']) : '';
        $repoUrlInput = isset($_POST['repo_url']) ? trim($_POST['repo_url']) : '';
        $branch = isset($_POST['branch']) ? trim($_POST['branch']) : 'main';
        $targetDir = isset($_POST['target_dir']) ? trim($_POST['target_dir']) : '';
        
        // Validation
        if (empty($secretToken)) {
            $errors[] = 'Secret access token is required';
        }
        if (empty($repoUrlInput)) {
            $errors[] = 'Repository URL is required';
        }
        if (empty($targetDir)) {
            $errors[] = 'Target directory is required';
        }
        
        // Convert GitHub URL to SSH format
        $repoUrl = !empty($repoUrlInput) ? convertGithubUrlToSsh($repoUrlInput) : '';
        
        // Ensure target directory has trailing slash
        if (!empty($targetDir) && substr($targetDir, -1) !== '/') {
            $targetDir .= '/';
        }
        
        if (empty($errors)) {
            // Generate config file from scratch
            $configContent = "<?php
/**
 * Deploy Configuration
 * 
 * This file contains the deployment configuration for simple-php-git-deploy
 * Generated by deploy-installer
 */

// Protect the script from unauthorized access by using a secret access token.
// If it's not present in the access URL as a GET variable named `sat`
// e.g. deploy.php?sat=YourSecretToken the script is not going to deploy.
define('SECRET_ACCESS_TOKEN', '" . addslashes($secretToken) . "');

// The address of the remote Git repository that contains the code that's being deployed.
// If the repository is private, you'll need to use the SSH address.
define('REMOTE_REPOSITORY', '" . addslashes($repoUrl) . "');

// The branch that's being deployed.
// Must be present in the remote repository.
define('BRANCH', '" . addslashes($branch) . "');

// The location that the code is going to be deployed to.
// Don't forget the trailing slash!
define('TARGET_DIR', '" . addslashes($targetDir) . "');

// Whether to delete the files that are not in the repository but are on the
// local (server) machine.
// !!! WARNING !!! This can lead to a serious loss of data if you're not careful.
// All files that are not in the repository are going to be deleted,
// except the ones defined in EXCLUDE section.
define('DELETE_FILES', false);

// The directories and files that are to be excluded when updating the code.
// Normally, these are the directories containing files that are not part of
// code base, for example user uploads or server-specific configuration files.
// Use rsync exclude pattern syntax for each element.
define('EXCLUDE', serialize(array(
	'.git',
)));

// Temporary directory we'll use to stage the code before the update.
// If it already exists, script assumes that it contains an already cloned copy
// of the repository with the correct remote origin and only fetches changes instead
// of cloning the entire thing.
define('TMP_DIR', '/tmp/spgd-' . md5(REMOTE_REPOSITORY) . '/');

// Whether to remove the TMP_DIR after the deployment.
// It's useful NOT to clean up in order to only fetch changes on the next deployment.
define('CLEAN_UP', true);

// Output the version of the deployed code.
define('VERSION_FILE', TMP_DIR . 'VERSION');

// Time limit for each command.
define('TIME_LIMIT', 30);

// OPTIONAL: Backup the TARGET_DIR into BACKUP_DIR before deployment.
define('BACKUP_DIR', false);

// OPTIONAL: Whether to invoke composer after the repository is cloned or changes are fetched.
define('USE_COMPOSER', false);

// OPTIONAL: The options that the composer is going to use.
define('COMPOSER_OPTIONS', '--no-dev');

// OPTIONAL: The COMPOSER_HOME environment variable is needed only if the script is
// executed by a system user that has no HOME defined, e.g. `www-data`.
define('COMPOSER_HOME', false);

// OPTIONAL: Email address to be notified on deployment failure.
define('EMAIL_ON_ERROR', false);
";
                
                // Write the config file
                $configFile = __DIR__ . '/deploy-config.php';
                $configExists = file_exists($configFile);
                if (file_put_contents($configFile, $configContent)) {
                    @chmod($configFile, 0600);
                    if ($configExists) {
                        $success[] = 'deploy-config.php updated successfully (previous configuration was overwritten)';
                    } else {
                        $success[] = 'deploy-config.php created successfully';
                    }
                    $step = 4; // Move to results step
                } else {
                    $errors[] = 'Failed to write deploy-config.php - check file permissions';
                }
        }
    }
}

// Check requirements
// Requirements for installer to work
$installerRequirements = [
    'php_file_get_contents' => ['name' => 'PHP file_get_contents() function', 'status' => isFunctionEnabled('file_get_contents'), 'required' => true],
    'php_exec' => ['name' => 'PHP exec() function', 'status' => isFunctionEnabled('exec'), 'required' => false, 'note' => 'Needed for deploy script to run'],
    'php_shell_exec' => ['name' => 'PHP shell_exec() function', 'status' => isFunctionEnabled('shell_exec'), 'required' => false, 'note' => 'Needed for deploy script to run'],
];

// Requirements for deployment to work (checked but not blocking installer)
$deploymentRequirements = [
    'git' => ['name' => 'Git binary', 'status' => checkBinary('git'), 'note' => 'Required by deploy script to clone/fetch repository'],
    'rsync' => ['name' => 'rsync binary', 'status' => checkBinary('rsync'), 'note' => 'Required by deploy script to sync files'],
];

$allInstallerRequirementsMet = true;
foreach ($installerRequirements as $req) {
    if ($req['required'] && !$req['status']) {
        $allInstallerRequirementsMet = false;
        break;
    }
}

// Get SSH public key if it exists
$homeDir = getHomeDir();
$sshPublicKey = '';
if (!empty($homeDir)) {
    $sshPublicKeyFile = $homeDir . '/.ssh/deploy_key.pub';
    if (file_exists($sshPublicKeyFile)) {
        $sshPublicKey = trim(file_get_contents($sshPublicKeyFile));
    }
}

// Generate webhook URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$deployScriptUrl = $protocol . '://' . $host . $scriptPath . '/deploy.php';
// Get secret token from POST (if on step 4) or GET, otherwise empty
$secretToken = '';
if ($step === 4 && isset($_POST['secret_token'])) {
    $secretToken = $_POST['secret_token'];
} elseif (isset($_GET['token'])) {
    $secretToken = $_GET['token'];
}
$webhookUrl = $secretToken ? $deployScriptUrl . '?sat=' . urlencode($secretToken) : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deploy Installer - simple-php-git-deploy Setup</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .content {
            padding: 30px;
        }
        
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
        }
        
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: #e0e0e0;
            z-index: 0;
        }
        
        .step {
            flex: 1;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e0e0e0;
            color: #999;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        
        .step.active .step-number {
            background: #667eea;
            color: white;
        }
        
        .step.completed .step-number {
            background: #4caf50;
            color: white;
        }
        
        .step-label {
            font-size: 12px;
            color: #666;
        }
        
        .step.active .step-label {
            color: #667eea;
            font-weight: 600;
        }
        
        .requirements {
            background: #f5f5f5;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .requirements h2 {
            margin-bottom: 15px;
            font-size: 20px;
            color: #333;
        }
        
        .requirement-item {
            display: flex;
            align-items: center;
            padding: 10px;
            margin-bottom: 8px;
            background: white;
            border-radius: 6px;
        }
        
        .requirement-item .status {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            font-size: 14px;
            font-weight: bold;
        }
        
        .requirement-item .status.pass {
            background: #4caf50;
            color: white;
        }
        
        .requirement-item .status.fail {
            background: #f44336;
            color: white;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-group small {
            display: block;
            margin-top: 5px;
            color: #666;
            font-size: 12px;
        }
        
        .btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .btn:hover {
            background: #5568d3;
        }
        
        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-generate {
            background: #28a745;
            font-size: 14px;
            padding: 8px 16px;
            margin-left: 10px;
        }
        
        .btn-generate:hover {
            background: #218838;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert-error {
            background: #fee;
            border-left: 4px solid #f44336;
            color: #c62828;
        }
        
        .alert-success {
            background: #e8f5e9;
            border-left: 4px solid #4caf50;
            color: #2e7d32;
        }
        
        .alert-info {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            color: #1565c0;
        }
        
        .copy-section {
            background: #f5f5f5;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .copy-section h3 {
            margin-bottom: 15px;
            font-size: 18px;
            color: #333;
        }
        
        .copy-wrapper {
            position: relative;
            margin-bottom: 15px;
        }
        
        .copy-input {
            width: 100%;
            padding: 12px 50px 12px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            background: white;
        }
        
        .copy-btn {
            position: absolute;
            right: 5px;
            top: 5px;
            background: #667eea;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
        }
        
        .copy-btn:hover {
            background: #5568d3;
        }
        
        .copy-btn.copied {
            background: #4caf50;
        }
        
        .textarea-wrapper {
            position: relative;
        }
        
        .textarea-wrapper textarea {
            min-height: 100px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
        }
        
        .instructions {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .instructions h4 {
            margin-bottom: 10px;
            color: #856404;
        }
        
        .instructions ol {
            margin-left: 20px;
            color: #856404;
        }
        
        .instructions li {
            margin-bottom: 8px;
        }
        
        .hidden {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Deploy Installer</h1>
            <p>Automated setup for simple-php-git-deploy on cPanel</p>
        </div>
        
        <div class="content">
            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step <?php echo $step >= 1 ? 'active' : ''; ?> <?php echo $step > 1 ? 'completed' : ''; ?>">
                    <div class="step-number">1</div>
                    <div class="step-label">Requirements</div>
                </div>
                <div class="step <?php echo $step >= 2 ? 'active' : ''; ?> <?php echo $step > 2 ? 'completed' : ''; ?>">
                    <div class="step-number">2</div>
                    <div class="step-label">Download & Setup</div>
                </div>
                <div class="step <?php echo $step >= 3 ? 'active' : ''; ?> <?php echo $step > 3 ? 'completed' : ''; ?>">
                    <div class="step-number">3</div>
                    <div class="step-label">Configuration</div>
                </div>
                <div class="step <?php echo $step >= 4 ? 'active' : ''; ?>">
                    <div class="step-number">4</div>
                    <div class="step-label">Results</div>
                </div>
            </div>
            
            <!-- Error/Success Messages -->
            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <?php foreach ($success as $msg): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- Step 1: Requirements Check -->
            <?php if ($step === 1): ?>
                <div class="requirements">
                    <h2>Server Requirements Check</h2>
                    <p style="margin-bottom: 20px;">Requirements for installer to work:</p>
                    
                    <?php foreach ($installerRequirements as $key => $req): ?>
                        <div class="requirement-item">
                            <span class="status <?php echo $req['status'] ? 'pass' : 'fail'; ?>">
                                <?php echo $req['status'] ? '✓' : '✗'; ?>
                            </span>
                            <span>
                                <?php echo htmlspecialchars($req['name']); ?>
                                <?php if (isset($req['note'])): ?>
                                    <small style="display: block; color: #666; font-weight: normal; margin-top: 3px;"><?php echo htmlspecialchars($req['note']); ?></small>
                                <?php endif; ?>
                            </span>
                            <?php if ($req['required'] && !$req['status']): ?>
                                <span style="margin-left: auto; color: #f44336; font-size: 12px;">REQUIRED</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <h3 style="margin-top: 30px; margin-bottom: 15px; font-size: 18px;">Deployment Requirements (checked but not blocking)</h3>
                    <p style="margin-bottom: 15px; color: #666; font-size: 14px;">These are needed for the deploy script to work, but won't block the installer:</p>
                    
                    <?php foreach ($deploymentRequirements as $key => $req): ?>
                        <div class="requirement-item" style="opacity: <?php echo $req['status'] ? '1' : '0.7'; ?>;">
                            <span class="status <?php echo $req['status'] ? 'pass' : 'fail'; ?>">
                                <?php echo $req['status'] ? '✓' : '✗'; ?>
                            </span>
                            <span>
                                <?php echo htmlspecialchars($req['name']); ?>
                                <?php if (isset($req['note'])): ?>
                                    <small style="display: block; color: #666; font-weight: normal; margin-top: 3px;"><?php echo htmlspecialchars($req['note']); ?></small>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (!$allInstallerRequirementsMet): ?>
                        <div class="alert alert-error" style="margin-top: 20px;">
                            <strong>Missing Required Components:</strong> Please ensure file_get_contents() is available to download files.
                            <?php if (!$installerRequirements['php_exec']['status'] || !$installerRequirements['php_shell_exec']['status']): ?>
                                <br><br>
                                <strong>Note:</strong> exec() and shell_exec() are needed for deployment. Enable in cPanel → MultiPHP Manager → PHP-FPM Settings → remove ban on exec_shell.
                                <br><br>
                                <strong>SSH Key Generation:</strong> If shell_exec is disabled, you can generate SSH keys manually in cPanel Terminal:
                                <div style="background: #2d2d2d; color: #f8f8f2; padding: 10px; margin-top: 10px; border-radius: 4px; font-family: monospace; font-size: 12px;">
                                    cd ~<br>
                                    mkdir -p ~/.ssh<br>
                                    chmod 700 ~/.ssh<br>
                                    ssh-keygen -t ed25519 -f ~/.ssh/deploy_key<br>
                                    <em>(Press Enter twice for no password)</em><br>
                                    cat ~/.ssh/deploy_key.pub >> ~/.ssh/authorized_keys<br>
                                    chmod 600 ~/.ssh/authorized_keys<br>
                                    cat ~/.ssh/deploy_key.pub
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success" style="margin-top: 20px;">
                            ✓ Installer requirements met! You can proceed to the next step.
                            <?php if (!$deploymentRequirements['git']['status'] || !$deploymentRequirements['rsync']['status']): ?>
                                <br><br>
                                <strong>Note:</strong> Some deployment requirements are missing (git, rsync). These will be needed for the deploy script to work, but you can continue with setup.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" style="margin-top: 30px;">
                        <input type="hidden" name="step" value="2">
                        <button type="submit" class="btn" <?php echo !$allInstallerRequirementsMet ? 'disabled' : ''; ?>>
                            Continue to Download & Setup →
                        </button>
                    </form>
                </div>
            <?php endif; ?>
            
            <!-- Step 2: Download Files and Generate SSH Keys -->
            <?php if ($step === 2): ?>
                <div>
                    <h2>Download Files & Generate SSH Keys</h2>
                    <p style="margin-bottom: 20px;">This step will download the required files and generate SSH keys automatically.</p>
                    
                    <form method="POST">
                        <input type="hidden" name="step" value="2">
                        <button type="submit" class="btn">Download Files & Generate SSH Keys</button>
                    </form>
                    
                    <?php if (!empty($success) && empty($errors)): ?>
                        <form method="POST" style="margin-top: 20px;">
                            <input type="hidden" name="step" value="3">
                            <button type="submit" class="btn">Continue to Configuration →</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <!-- Step 3: Configuration -->
            <?php if ($step === 3): ?>
                <div>
                    <h2>Configuration</h2>
                    <p style="margin-bottom: 20px;">Enter your deployment configuration:</p>
                    
                    <form method="POST">
                        <input type="hidden" name="step" value="3">
                        
                        <div class="form-group">
                            <label for="secret_token">Secret Access Token *</label>
                            <div style="display: flex; align-items: center;">
                                <input type="text" id="secret_token" name="secret_token" 
                                       value="<?php echo isset($_POST['secret_token']) ? htmlspecialchars($_POST['secret_token']) : generateSecureToken(16); ?>" 
                                       required>
                                <button type="button" class="btn btn-generate" onclick="generateToken()">Generate</button>
                            </div>
                            <small>This token will be used to secure your webhook URL. Keep it secret!</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="repo_url">GitHub Repository URL *</label>
                            <input type="text" id="repo_url" name="repo_url" 
                                   value="<?php echo isset($_POST['repo_url']) ? htmlspecialchars($_POST['repo_url']) : ''; ?>" 
                                   placeholder="https://github.com/username/repository" required>
                            <small>Enter any GitHub URL format (https://github.com/user/repo or git@github.com:user/repo.git)<br>
                            Will be automatically converted to SSH format: <code>git@github.com:user/repo.git</code></small>
                        </div>
                        
                        <div class="form-group">
                            <label for="branch">Branch Name *</label>
                            <input type="text" id="branch" name="branch" 
                                   value="<?php echo isset($_POST['branch']) ? htmlspecialchars($_POST['branch']) : 'main'; ?>" 
                                   required>
                            <small>Default: main</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="target_dir">Target Directory (Full Path) *</label>
                            <?php 
                            // Default to current installer directory
                            $currentDir = rtrim(__DIR__, '/') . '/';
                            $defaultTargetDir = isset($_POST['target_dir']) ? $_POST['target_dir'] : $currentDir;
                            ?>
                            <input type="text" id="target_dir" name="target_dir" 
                                   value="<?php echo htmlspecialchars($defaultTargetDir); ?>" 
                                   required>
                            <small>Full path to deployment directory (must end with /)<br>
                            <strong>Current installer location:</strong> <code><?php echo htmlspecialchars($currentDir); ?></code></small>
                        </div>
                        
                        <button type="submit" class="btn">Generate Configuration File →</button>
                    </form>
                </div>
            <?php endif; ?>
            
            <!-- Step 4: Results -->
            <?php if ($step === 4): ?>
                <div>
                    <h2>Setup Complete! 🎉</h2>
                    <p style="margin-bottom: 30px;">Your deployment system is ready. Follow these final steps:</p>
                    
                    <!-- SSH Public Key -->
                    <?php if (!empty($sshPublicKey)): ?>
                        <div class="copy-section">
                            <h3>1. Add SSH Deploy Key to GitHub</h3>
                            <div class="instructions">
                                <h4>Instructions:</h4>
                                <ol>
                                    <li>Go to your GitHub repository</li>
                                    <li>Navigate to Settings → Deploy keys</li>
                                    <li>Click "Add deploy key"</li>
                                    <li>Title: <strong>Cpanel Deploy Key</strong></li>
                                    <li>Paste the key below</li>
                                    <li><strong>Keep "Allow write access" UNCHECKED</strong></li>
                                    <li>Click "Add key"</li>
                                </ol>
                            </div>
                            <div class="textarea-wrapper">
                                <textarea class="copy-input" id="ssh-key" readonly><?php echo htmlspecialchars($sshPublicKey); ?></textarea>
                                <button class="copy-btn" onclick="copyToClipboard('ssh-key', this)">Copy</button>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Webhook URL -->
                    <?php if (!empty($webhookUrl)): ?>
                        <div class="copy-section">
                            <h3>2. Add Webhook to GitHub</h3>
                            <div class="instructions">
                                <h4>Instructions:</h4>
                                <ol>
                                    <li>Go to your GitHub repository</li>
                                    <li>Navigate to Settings → Webhooks</li>
                                    <li>Click "Add webhook"</li>
                                    <li>Payload URL: Paste the URL below</li>
                                    <li>Content type: <strong>application/json</strong></li>
                                    <li>Secret: <strong>Leave blank</strong></li>
                                    <li>SSL Verification: <strong>Enable SSL verification</strong> (checked)</li>
                                    <li>Which events: <strong>Just the push event</strong></li>
                                    <li>Click "Add webhook"</li>
                                </ol>
                            </div>
                            <div class="copy-wrapper">
                                <input type="text" class="copy-input" id="webhook-url" value="<?php echo htmlspecialchars($webhookUrl); ?>" readonly>
                                <button class="copy-btn" onclick="copyToClipboard('webhook-url', this)">Copy</button>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Configuration Summary -->
                    <?php
                    // Get repository URL and convert it for display
                    $repoUrlInput = $_POST['repo_url'] ?? '';
                    $repoUrl = !empty($repoUrlInput) ? convertGithubUrlToSsh($repoUrlInput) : '';
                    ?>
                    <div class="copy-section">
                        <h3>3. Configuration Summary</h3>
                        <div style="background: white; padding: 15px; border-radius: 6px; font-family: monospace; font-size: 13px;">
                            <strong>Secret Token:</strong> <?php echo htmlspecialchars($_POST['secret_token'] ?? ''); ?><br>
                            <strong>Repository:</strong> <?php echo htmlspecialchars($repoUrl); ?><br>
                            <?php if ($repoUrlInput !== $repoUrl): ?>
                                <small style="color: #666; display: block; margin-left: 10px;">(Converted from: <?php echo htmlspecialchars($repoUrlInput); ?>)</small>
                            <?php endif; ?>
                            <strong>Branch:</strong> <?php echo htmlspecialchars($_POST['branch'] ?? 'main'); ?><br>
                            <strong>Target Directory:</strong> <?php echo htmlspecialchars($_POST['target_dir'] ?? ''); ?><br>
                            <strong>Config File:</strong> <?php echo htmlspecialchars(__DIR__ . '/deploy-config.php'); ?>
                        </div>
                    </div>
                    
                    <!-- Final Instructions -->
                    <div class="alert alert-info">
                        <strong>Next Steps:</strong>
                        <ol style="margin-left: 20px; margin-top: 10px;">
                            <li>Add the SSH deploy key to GitHub (see above)</li>
                            <li>Add the webhook to GitHub (see above)</li>
                            <li>Test the deployment by pushing to your repository</li>
                            <li>Monitor the deployment at: <code><?php echo htmlspecialchars($deployScriptUrl); ?>?sat=<?php echo urlencode($secretToken); ?></code></li>
                        </ol>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function generateToken() {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            let token = '';
            for (let i = 0; i < 16; i++) {
                token += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('secret_token').value = token;
        }
        
        function copyToClipboard(elementId, button) {
            const element = document.getElementById(elementId);
            element.select();
            element.setSelectionRange(0, 99999); // For mobile devices
            
            try {
                document.execCommand('copy');
                const originalText = button.textContent;
                button.textContent = 'Copied!';
                button.classList.add('copied');
                
                setTimeout(() => {
                    button.textContent = originalText;
                    button.classList.remove('copied');
                }, 2000);
            } catch (err) {
                alert('Failed to copy. Please select and copy manually.');
            }
        }
    </script>
</body>
</html>
