<?php
/**
 * SSH Key Generator Helper
 * 
 * Standalone helper to generate SSH keys for GitHub deploy setup
 * Use this if the main installer can't generate keys due to disabled functions
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$homeDir = '';
$sshDir = '';
$deployKey = '';
$deployKeyPub = '';
$publicKeyContent = '';
$errors = [];
$success = [];

// Try to get home directory
if (getenv('HOME')) {
    $homeDir = getenv('HOME');
} elseif (isset($_SERVER['HOME'])) {
    $homeDir = $_SERVER['HOME'];
} else {
    // Try to extract from current path (common cPanel structure: /home/username/public_html)
    $scriptDir = __DIR__;
    if (preg_match('#^/home/([^/]+)#', $scriptDir, $matches)) {
        $homeDir = '/home/' . $matches[1];
    }
}

if (empty($homeDir)) {
    $errors[] = 'Could not determine home directory. Please manually specify it below.';
}

$formSubmitted = isset($_POST['generate_key']);

if ($formSubmitted) {
    // Use provided home directory or detected one
    $homeDir = isset($_POST['home_dir']) ? trim($_POST['home_dir']) : $homeDir;
    
    if (empty($homeDir)) {
        $errors[] = 'Home directory is required';
    } else {
        $sshDir = $homeDir . '/.ssh';
        $deployKey = $sshDir . '/deploy_key';
        $deployKeyPub = $deployKey . '.pub';
        
        // Check if key already exists
        if (file_exists($deployKey)) {
            $errors[] = 'SSH key already exists at: ' . $deployKey . '. Delete it first if you want to regenerate.';
        } else {
            // Create .ssh directory if it doesn't exist
            if (!is_dir($sshDir)) {
                if (@mkdir($sshDir, 0700, true)) {
                    $success[] = 'Created .ssh directory';
                } else {
                    $errors[] = 'Failed to create .ssh directory. Please create it manually with: mkdir -p ' . $sshDir . ' && chmod 700 ' . $sshDir;
                }
            }
            
            // Generate SSH key using ssh-keygen
            if (empty($errors) && function_exists('shell_exec')) {
                $keygenCmd = sprintf(
                    'ssh-keygen -t ed25519 -f %s -N "" -q 2>&1',
                    escapeshellarg($deployKey)
                );
                
                $output = @shell_exec($keygenCmd);
                
                if (file_exists($deployKeyPub)) {
                    $success[] = 'SSH key pair generated successfully!';
                    
                    // Read the public key
                    $publicKeyContent = trim(file_get_contents($deployKeyPub));
                    
                    // Add to authorized_keys
                    $authorizedKeysFile = $sshDir . '/authorized_keys';
                    if (!file_exists($authorizedKeysFile)) {
                        @touch($authorizedKeysFile);
                        @chmod($authorizedKeysFile, 0600);
                    }
                    
                    $authorizedKeys = file_exists($authorizedKeysFile) ? file_get_contents($authorizedKeysFile) : '';
                    if (strpos($authorizedKeys, $publicKeyContent) === false) {
                        file_put_contents($authorizedKeysFile, $publicKeyContent . "\n", FILE_APPEND);
                        $success[] = 'Public key added to authorized_keys';
                    }
                } else {
                    $errors[] = 'Key generation may have failed. Output: ' . htmlspecialchars($output);
                    $errors[] = 'Try running manually in terminal: ssh-keygen -t ed25519 -f ' . $deployKey;
                }
            } elseif (empty($errors)) {
                $errors[] = 'shell_exec is not available. You need to generate the key manually using the terminal commands shown below.';
            }
        }
    }
}

// If key exists, read it
if (file_exists($deployKeyPub)) {
    $publicKeyContent = trim(file_get_contents($deployKeyPub));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SSH Key Generator - Deploy Installer</title>
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
            max-width: 800px;
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
        
        .content {
            padding: 30px;
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
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            font-family: 'Courier New', monospace;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
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
            width: 100%;
            min-height: 120px;
            padding: 12px 50px 12px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            background: white;
            resize: vertical;
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
        
        .instructions ol, .instructions ul {
            margin-left: 20px;
            color: #856404;
        }
        
        .instructions li {
            margin-bottom: 8px;
        }
        
        .code-block {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
            margin: 10px 0;
        }
        
        .code-block code {
            color: #f8f8f2;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔑 SSH Key Generator</h1>
            <p>Generate SSH keys for GitHub deployment</p>
        </div>
        
        <div class="content">
            <div class="alert alert-info">
                <strong>About Git and rsync:</strong><br><br>
                <strong>Git:</strong> Used by the deploy script to clone/fetch code from your GitHub repository.<br><br>
                <strong>rsync:</strong> Used by the deploy script to sync files from the repository to your deployment directory.<br><br>
                These are needed for deployment to work, but not required to generate SSH keys or run the installer. They're typically pre-installed on cPanel servers.
            </div>
            
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
            
            <?php if (empty($publicKeyContent)): ?>
                <!-- Form to generate key -->
                <h2>Generate SSH Key</h2>
                <p style="margin-bottom: 20px;">This will generate an SSH key pair for GitHub deployment.</p>
                
                <form method="POST">
                    <div class="form-group">
                        <label for="home_dir">Home Directory Path *</label>
                        <input type="text" id="home_dir" name="home_dir" 
                               value="<?php echo htmlspecialchars($homeDir); ?>" 
                               placeholder="/home/username" required>
                        <small style="display: block; margin-top: 5px; color: #666;">
                            Usually: /home/your-cpanel-username
                        </small>
                    </div>
                    
                    <button type="submit" name="generate_key" class="btn">Generate SSH Key</button>
                </form>
                
                <!-- Manual instructions -->
                <div class="instructions" style="margin-top: 30px;">
                    <h4>💡 Can't generate automatically? Use terminal:</h4>
                    <p>If shell_exec is disabled, generate the key manually in cPanel Terminal:</p>
                    <div class="code-block">
                        <code>cd ~<br>
mkdir -p ~/.ssh<br>
chmod 700 ~/.ssh<br>
ssh-keygen -t ed25519 -f ~/.ssh/deploy_key<br>
<em>(Press Enter twice for no password)</em><br><br>
# Add to authorized_keys<br>
cat ~/.ssh/deploy_key.pub >> ~/.ssh/authorized_keys<br>
chmod 600 ~/.ssh/authorized_keys<br><br>
# Display the public key<br>
cat ~/.ssh/deploy_key.pub</code>
                    </div>
                </div>
            <?php else: ?>
                <!-- Display generated key -->
                <h2>✅ SSH Key Generated Successfully!</h2>
                
                <div class="copy-section">
                    <h3>Public Key (Copy this to GitHub)</h3>
                    <div class="instructions">
                        <h4>Instructions:</h4>
                        <ol>
                            <li>Go to your GitHub repository</li>
                            <li>Navigate to <strong>Settings → Deploy keys</strong></li>
                            <li>Click <strong>"Add deploy key"</strong></li>
                            <li>Title: <strong>Cpanel Deploy Key</strong></li>
                            <li>Paste the key below</li>
                            <li><strong>Keep "Allow write access" UNCHECKED</strong></li>
                            <li>Click "Add key"</li>
                        </ol>
                    </div>
                    <div class="textarea-wrapper">
                        <textarea class="copy-input" id="ssh-key" readonly><?php echo htmlspecialchars($publicKeyContent); ?></textarea>
                        <button class="copy-btn" onclick="copyToClipboard('ssh-key', this)">Copy</button>
                    </div>
                </div>
                
                <div class="alert alert-success">
                    <strong>Key Location:</strong><br>
                    Private Key: <code><?php echo htmlspecialchars($deployKey); ?></code><br>
                    Public Key: <code><?php echo htmlspecialchars($deployKeyPub); ?></code>
                </div>
                
                <div class="instructions">
                    <h4>Next Steps:</h4>
                    <ol>
                        <li>Copy the public key above and add it to GitHub (see instructions)</li>
                        <li>Test the connection (optional):</li>
                    </ol>
                    <div class="code-block">
                        <code>eval "$(ssh-agent -s)"<br>
ssh-add ~/.ssh/deploy_key<br>
ssh -T git@github.com</code>
                    </div>
                    <p style="margin-top: 10px;">You should see: "Hi username! You've successfully authenticated..."</p>
                    <li>Return to <a href="installer.php">installer.php</a> to continue setup</li>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function copyToClipboard(elementId, button) {
            const element = document.getElementById(elementId);
            element.select();
            element.setSelectionRange(0, 99999);
            
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
