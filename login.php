<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Plan&Go</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { 
            --primary: #1e293b; 
            --accent: #3b82f6; 
            --accent-hover: #2563eb;
            --bg-app: #0f172a;
            --glass: rgba(30, 41, 59, 0.7);
            --border: rgba(255, 255, 255, 0.1);
        }
        * { box-sizing: border-box; }
        body { 
            margin: 0; 
            padding: 0; 
            width: 100%; 
            height: 100vh; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-family: 'Outfit', sans-serif; 
            background: var(--bg-app);
            color: white;
            overflow: hidden;
        }
        
        /* Animated Background */
        .bg-blobs {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            z-index: -1;
            filter: blur(80px);
        }
        .blob {
            position: absolute;
            width: 500px;
            height: 500px;
            border-radius: 50%;
            opacity: 0.2;
            animation: float 20s infinite alternate;
        }
        .blob-1 { background: var(--accent); top: -10%; left: -10%; }
        .blob-2 { background: #9333ea; bottom: -10%; right: -10%; animation-delay: -5s; }
        .blob-3 { background: #06b6d4; top: 40%; right: 20%; animation-delay: -10s; }
        @keyframes float {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(100px, 100px) scale(1.1); }
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            background: var(--glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo-container {
            text-align: center;
            margin-bottom: 32px;
        }
        .logo-container img {
            height: 64px;
            margin-bottom: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .logo-container h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .logo-container p {
            margin: 8px 0 0 0;
            color: #94a3b8;
            font-size: 0.95em;
        }

        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 0.85em;
            font-weight: 600;
            color: #94a3b8;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .input-wrapper {
            position: relative;
        }
        .input-wrapper i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
        }
        input {
            width: 100%;
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 16px 12px 44px;
            color: white;
            font-family: inherit;
            font-size: 16px;
            transition: all 0.2s;
        }
        input:focus {
            outline: none;
            border-color: var(--accent);
            background: rgba(15, 23, 42, 0.8);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        button {
            width: 100%;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 14px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        button:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
            box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.3);
        }
        button:active {
            transform: translateY(0);
        }
        button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        #message {
            margin-top: 20px;
            padding: 12px;
            border-radius: 10px;
            font-size: 0.9em;
            text-align: center;
            display: none;
        }
        .error { background: rgba(239, 68, 68, 0.1); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.2); }
        .success { background: rgba(34, 197, 94, 0.1); color: #86efac; border: 1px solid rgba(34, 197, 94, 0.2); }

        .setup-mode {
            border-top: 1px solid var(--border);
            margin-top: 30px;
            padding-top: 20px;
        }
    </style>
</head>
<body>
    <div class="bg-blobs">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>
    </div>

    <div class="login-card">
        <div class="logo-container">
            <img src="logo.png" alt="Plan&Go Logo">
            <h1 id="title">Welcome back to Plan&Go</h1>
            <p id="subtitle">Please enter your details to sign in.</p>
        </div>

        <form id="login-form" onsubmit="handleAuth(event)">
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="username" required autocomplete="username" placeholder="admin">
                </div>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" required autocomplete="current-password" placeholder="••••••••">
                </div>
            </div>
            <button type="submit" id="submit-btn">
                <span>Sign In</span>
                <i class="fas fa-arrow-right"></i>
            </button>
        </form>

        <div id="message"></div>
    </div>

    <script>
        let isSetup = false;

        async function checkSetup() {
            try {
                const res = await fetch('api.php?action=get_user_count');
                const data = await res.json();
                if (data.count === 0) {
                    isSetup = true;
                    document.getElementById('title').textContent = 'Create Admin';
                    document.getElementById('subtitle').textContent = 'Initial setup: Create your primary account.';
                    document.querySelector('#submit-btn span').textContent = 'Setup Account';
                }
            } catch (err) {
                console.error('Failed to check setup state');
            }
        }

        async function handleAuth(e) {
            e.preventDefault();
            const btn = document.getElementById('submit-btn');
            const msg = document.getElementById('message');
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            msg.style.display = 'none';

            const action = isSetup ? 'setup_admin' : 'login';
            
            try {
                const res = await fetch(`api.php?action=${action}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password })
                });
                const data = await res.json();

                if (data.success) {
                    msg.textContent = isSetup ? 'Setup successful! Logging you in...' : 'Login successful!';
                    msg.className = 'success';
                    msg.style.display = 'block';
                    
                    if (isSetup) {
                        // Automatically login after setup
                        isSetup = false;
                        handleAuth(e);
                    } else {
                        setTimeout(() => window.location.href = 'index.php', 1000);
                    }
                } else {
                    msg.textContent = data.error || 'Authentication failed';
                    msg.className = 'error';
                    msg.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = isSetup ? '<span>Setup Account</span> <i class="fas fa-arrow-right"></i>' : '<span>Sign In</span> <i class="fas fa-arrow-right"></i>';
                }
            } catch (err) {
                msg.textContent = 'Connection error. Please try again.';
                msg.className = 'error';
                msg.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = 'Sign In';
            }
        }

        checkSetup();
    </script>
</body>
</html>
