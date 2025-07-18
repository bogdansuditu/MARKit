<?php
// Buffer output to prevent headers already sent errors
ob_start();

// Include dependencies
include 'db.php';
include 'auth.php';

$error = '';
$db = Database::getInstance();

// Check for remember token first
if (!isLoggedIn() && isset($_COOKIE['remember_token'])) {
    if (verifyRememberToken()) {
        ob_end_clean(); // Clear buffer before redirect
        header('Location: index.php');
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']) ? true : false;

    // Get user from database
    $user = $db->getUserByUsername($username);
    
    if ($user && password_verify($password, $user['password_hash'])) {
        // Session is already started in auth.php with proper parameters
        $_SESSION['userid'] = $user['userid'];
        $_SESSION['username'] = $user['username'];
        
        // Handle remember me functionality
        if ($remember) {
            storeRememberToken($username); // This function is in auth.php and handles Safari compatibility
        }
        
        ob_end_clean(); // Clear buffer before redirect
        header('Location: index.php');
        exit();
    } else {
        $error = 'Invalid username or password';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Sign in to Minimal.md - A beautiful markdown editor">
    <title>Login - Minimal.md</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    
    <!-- Styles -->
    <link rel="stylesheet" href="styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--light-bg);
            margin: 0;
            font-family: 'Inter', sans-serif;
        }
        
        .login-container {
            background: var(--light-surface);
            padding: 2.5rem;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            width: 100%;
            max-width: 400px;
            margin: 1rem;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .login-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--light-accent);
            margin: 0;
        }
        
        .login-form {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .form-group label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--light-text);
        }
        
        .form-group input {
            padding: 0.75rem 1rem;
            border: 1px solid var(--light-border);
            border-radius: 0.5rem;
            background: var(--light-bg);
            color: var(--light-text);
            font-size: 1rem;
            transition: all 0.2s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--light-accent);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        .login-button {
            background: var(--light-accent);
            color: white;
            border: none;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 0.5rem;
        }
        
        .login-button:hover {
            background: var(--light-accent);
            filter: brightness(110%);
        }
        
        .error-message {
            background: #fee2e2;
            border: 1px solid #ef4444;
            color: #b91c1c;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Dark theme support */
        body.dark-theme .login-container {
            background: var(--dark-surface);
        }
        
        body.dark-theme .form-group input {
            background: var(--dark-bg);
            border-color: var(--dark-border);
            color: var(--dark-text);
        }
        
        body.dark-theme .form-group input:focus {
            border-color: var(--dark-accent);
        }
        
        .login-icon {
            width: 80px;
            height: 80px;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body class="<?php echo isset($_COOKIE['theme']) ? $_COOKIE['theme'] . '-theme' : ''; ?>">
    <div class="login-container">
        <div class="login-header">
            <img src="mmd_512.png" class="login-icon" alt="MARKit">
            <h1>MARKit</h1>
            <p>Sign in to continue</p>
        </div>
        
        <?php if ($error): ?>
        <div class="error-message">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>
        
        <form method="post" class="login-form">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required 
                       value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                       autocomplete="username">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required
                       autocomplete="current-password">
            </div>
            
            <div class="form-group">
                <label for="remember">
                    <input type="checkbox" id="remember" name="remember">
                    Remember me
                </label>
            </div>
            
            <button type="submit" class="login-button">
                Sign In
            </button>
        </form>
    </div>
    
    <script>
        // Preserve theme across pages
        const theme = localStorage.getItem('theme') || 'light';
        document.body.classList.add(`${theme}-theme`);
    </script>
</body>
</html>