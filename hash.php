<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Animation Snippet</title>
    <style>
        :root {
            --login-success: #244b88;
            --login-success-soft: #dce9ff;
            --login-error: #c5354f;
            --login-error-soft: #ffe0e6;
            --login-overlay: rgba(22, 33, 57, 0.38);
            --login-card: rgba(255, 255, 255, 0.96);
            --login-text: #1a3768;
            --login-muted: #647a9f;
            --login-shadow: 0 30px 80px rgba(20, 39, 79, 0.26);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(80, 129, 221, 0.2), transparent 28%),
                radial-gradient(circle at bottom right, rgba(36, 75, 136, 0.18), transparent 30%),
                linear-gradient(135deg, #eff4ff 0%, #f9fbff 100%);
            color: var(--login-text);
        }

        .snippet-card {
            width: min(92vw, 700px);
            padding: 28px;
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 18px 50px rgba(19, 44, 87, 0.14);
        }

        .snippet-card h1 {
            margin: 0 0 12px;
            font-size: 2rem;
        }

        .snippet-card p {
            margin: 0 0 14px;
            line-height: 1.6;
            color: var(--login-muted);
        }

        .snippet-card code,
        .snippet-card pre {
            font-family: Consolas, "Courier New", monospace;
        }

        .snippet-card pre {
            margin: 18px 0 0;
            padding: 16px;
            overflow: auto;
            border-radius: 16px;
            background: #0f1e38;
            color: #e8f0ff;
            font-size: 0.92rem;
        }

        .preview-actions {
            margin-top: 18px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .preview-actions button {
            border: 0;
            border-radius: 999px;
            padding: 12px 18px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .preview-actions .success-btn {
            background: var(--login-success);
            color: #fff;
        }

        .preview-actions .error-btn {
            background: #fff1f4;
            color: var(--login-error);
        }

        .login-result-overlay {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--login-overlay);
            backdrop-filter: blur(7px);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
            z-index: 9999;
        }

        .login-result-overlay.is-visible {
            opacity: 1;
            visibility: visible;
        }

        .login-result-modal {
            width: min(92vw, 370px);
            padding: 34px 30px;
            border-radius: 30px;
            text-align: center;
            background: var(--login-card);
            box-shadow: var(--login-shadow);
            transform: translateY(18px) scale(0.96);
            transition: transform 0.28s ease;
        }

        .login-result-overlay.is-visible .login-result-modal {
            transform: translateY(0) scale(1);
        }

        .login-result-badge {
            width: 136px;
            height: 136px;
            margin: 0 auto 20px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            color: var(--login-success);
            background: var(--login-success-soft);
            transition: background-color 0.25s ease, color 0.25s ease;
        }

        .login-result-modal.is-error .login-result-badge {
            color: var(--login-error);
            background: var(--login-error-soft);
        }

        .login-result-badge svg {
            width: 96px;
            height: 96px;
            overflow: visible;
        }

        .login-result-badge circle,
        .login-result-badge path,
        .login-result-badge line {
            fill: none;
            stroke: currentColor;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .login-ring {
            stroke-width: 6;
            stroke-dasharray: 252;
            stroke-dashoffset: 252;
            animation: draw-ring 0.72s ease-out forwards;
        }

        .login-check {
            stroke-width: 7;
            stroke-dasharray: 60;
            stroke-dashoffset: 60;
            animation: draw-mark 0.36s ease-out 0.6s forwards;
        }

        .login-cross {
            stroke-width: 7;
            stroke-dasharray: 34;
            stroke-dashoffset: 34;
            animation: draw-mark 0.24s ease-out forwards;
        }

        .login-cross.second {
            animation-delay: 0.24s;
        }

        .login-result-title {
            margin: 0;
            color: var(--login-text);
            font-size: 1.95rem;
            line-height: 1.08;
        }

        .login-result-message {
            margin: 12px 0 0;
            color: var(--login-muted);
            line-height: 1.5;
            font-size: 1rem;
        }

        @keyframes draw-ring {
            from {
                stroke-dashoffset: 252;
                transform: rotate(-90deg);
                transform-origin: center;
            }
            to {
                stroke-dashoffset: 0;
                transform: rotate(-90deg);
                transform-origin: center;
            }
        }

        @keyframes draw-mark {
            to {
                stroke-dashoffset: 0;
            }
        }
    </style>
</head>
<body>
    <div class="snippet-card">
        <h1>Login Result Animation</h1>
        <p>This file now acts as a drop-in reference. The important part is the <code>showLoginResult(type, message)</code> function below.</p>
        <p>Use <code>showLoginResult("success", "Login successful")</code> after a good login, and <code>showLoginResult("error", errorMessage)</code> when login fails.</p>

        <div class="preview-actions">
            <button class="success-btn" type="button" id="previewSuccess">Preview Success</button>
            <button class="error-btn" type="button" id="previewError">Preview Error</button>
        </div>

        <pre>if (response.success) {
  showLoginResult("success", "Login successful");

  setTimeout(() =&gt; {
    window.location.href = "/dashboard.html";
  }, 1400);
} else {
  showLoginResult("error", response.error || "Invalid credentials");
}</pre>
    </div>

    <div class="login-result-overlay" id="loginResultOverlay">
        <div class="login-result-modal" id="loginResultModal" role="dialog" aria-modal="true" aria-labelledby="loginResultTitle">
            <div class="login-result-badge" id="loginResultBadge"></div>
            <h2 class="login-result-title" id="loginResultTitle">Login successful</h2>
            <p class="login-result-message" id="loginResultMessage">Redirecting you now.</p>
        </div>
    </div>

    <script>
        const loginResultOverlay = document.getElementById("loginResultOverlay");
        const loginResultModal = document.getElementById("loginResultModal");
        const loginResultBadge = document.getElementById("loginResultBadge");
        const loginResultTitle = document.getElementById("loginResultTitle");
        const loginResultMessage = document.getElementById("loginResultMessage");

        function getSuccessIcon() {
            return `
                <svg viewBox="0 0 100 100" aria-hidden="true">
                    <circle class="login-ring" cx="50" cy="50" r="40"></circle>
                    <path class="login-check" d="M32 52 L45 65 L69 39"></path>
                </svg>
            `;
        }

        function getErrorIcon() {
            return `
                <svg viewBox="0 0 100 100" aria-hidden="true">
                    <circle class="login-ring" cx="50" cy="50" r="40"></circle>
                    <line class="login-cross" x1="36" y1="36" x2="64" y2="64"></line>
                    <line class="login-cross second" x1="64" y1="36" x2="36" y2="64"></line>
                </svg>
            `;
        }

        function showLoginResult(type, message) {
            const isSuccess = type === "success";

            loginResultModal.classList.toggle("is-error", !isSuccess);
            loginResultTitle.textContent = isSuccess ? "Login successful" : "Login failed";
            loginResultMessage.textContent = message || (isSuccess ? "Redirecting you now." : "Invalid credentials.");
            loginResultBadge.innerHTML = isSuccess ? getSuccessIcon() : getErrorIcon();
            loginResultOverlay.classList.add("is-visible");
        }

        function hideLoginResult() {
            loginResultOverlay.classList.remove("is-visible");
        }

        document.getElementById("previewSuccess").addEventListener("click", () => {
            showLoginResult("success", "Welcome back. Redirecting to your dashboard.");
        });

        document.getElementById("previewError").addEventListener("click", () => {
            showLoginResult("error", "Invalid email or password.");
        });

        loginResultOverlay.addEventListener("click", event => {
            if (event.target === loginResultOverlay) {
                hideLoginResult();
            }
        });

        /*
         * Real login usage:
         *
         * async function handleLogin(event) {
         *   event.preventDefault();
         *
         *   const response = await fetch("/controllers/login.php", {
         *     method: "POST",
         *     headers: { "Content-Type": "application/json" },
         *     body: JSON.stringify({
         *       email: emailInput.value,
         *       password: passwordInput.value
         *     })
         *   });
         *
         *   const result = await response.json();
         *
         *   if (result.success) {
         *     showLoginResult("success", "Login successful");
         *
         *     setTimeout(() => {
         *       window.location.href = "/dashboard.html";
         *     }, 1400);
         *   } else {
         *     showLoginResult("error", result.error || "Invalid credentials");
         *   }
         * }
         */
    </script>
</body>
</html>
