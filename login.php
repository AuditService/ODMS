<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// If already logged in, redirect to the correct page.
if (isLoggedIn()) {
    if (!empty($_SESSION['must_change_password'])) {
        header('Location: /odms/change-password.php');
    } else {
        header('Location: /odms/index.php');
    }
    exit;
}

$error = '';
$success = false;
$redirectUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken()) {
        $error = 'Your session has expired. Please refresh the page and try again.';

    } else {

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '') {
            $error = 'Please enter your username.';

        } elseif ($password === '') {
            $error = 'Please enter your password.';

        } else {

            $stmt = $pdo->prepare(
                'SELECT user_id, full_name, username, password, role,
                        is_active, must_change_password
                 FROM users
                 WHERE username = :username
                 LIMIT 1'
            );

            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {

                if ((int) $user['is_active'] !== 1) {

                    $error = 'Your account is inactive. Please contact the administrator.';

                } else {

                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int) $user['user_id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['must_change_password'] =
                        (int) $user['must_change_password'];

                    // Record successful login.
                    try {
                        $log = $pdo->prepare(
                            "INSERT INTO activity_logs
                             (user_id, action, description)
                             VALUES (?, 'LOGIN', 'User logged in.')"
                        );

                        $log->execute([(int) $user['user_id']]);

                    } catch (PDOException $e) {
                        error_log(
                            'ODMS login activity logging error: ' .
                            $e->getMessage()
                        );
                    }

                    // Show success notification before redirecting.
                    $success = true;

                    if ($_SESSION['must_change_password'] === 1) {
                        $redirectUrl = '/odms/change-password.php';
                    } else {
                        $redirectUrl = '/odms/index.php';
                    }
                }

            } else {
                // Keep incorrect username/password messages generic.
                $error = 'Incorrect username or password. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>ODMS | Internal Audit Service</title>

<style>

/* =========================================
   GLOBAL SETTINGS
========================================= */

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

:root {
    --navy: #082f5c;
    --deep-navy: #031d3b;
    --dark-navy: #02152e;
    --blue: #0c477c;
    --gold: #e8bd42;
    --light-gold: #ffe58a;
    --white: #ffffff;
}

html,
body {
    width: 100%;
    min-height: 100%;
    font-family: Arial, Helvetica, sans-serif;
}

body {
    background: var(--deep-navy);
    color: #102b4b;
}

/* =========================================
   MAIN PAGE
========================================= */

.login-page {
    position: relative;

    width: 100%;
    min-height: 100vh;
    min-height: 100svh;

    display: grid;
    grid-template-columns: 65% 35%;

    overflow: hidden;
}

/* =========================================
   LEFT SIDE - BACKGROUND IMAGE
========================================= */

.branding-section {
    position: relative;
    isolation: isolate;

    min-width: 0;
    min-height: 100vh;
    min-height: 100svh;

    display: flex;
    flex-direction: column;

    padding: 28px 5.2% 20px;

    background-image:
        linear-gradient(
            90deg,
            rgba(255,255,255,.08),
            rgba(255,255,255,.02)
        ),
        url('/odms/assets/images/odms-login-background.jpg');

    background-size: cover;
    background-position: center center;
    background-repeat: no-repeat;
}

/* =========================================
   LAYERED BOTTOM WAVE DESIGN
========================================= */

/* Gold base wave */

.branding-section::before {
    content: "";

    position: absolute;
    z-index: -1;

    bottom: -1px;
    left: -5%;

    width: 115%;
    height: 170px;

    background: linear-gradient(
        110deg,
        #f8d65e,
        #c99a24,
        #f5d15b
    );

    clip-path: polygon(
        0 27%,
        20% 12%,
        43% 16%,
        65% 34%,
        84% 51%,
        100% 43%,
        100% 100%,
        0 100%
    );
}

/* Main navy wave */

.branding-section::after {
    content: "";

    position: absolute;
    z-index: -1;

    bottom: 0;
    left: -5%;

    width: 115%;
    height: 158px;

    background: linear-gradient(
        115deg,
        #052b58,
        #031e42 65%,
        #02152e
    );

    clip-path: polygon(
        0 29%,
        20% 15%,
        43% 20%,
        65% 37%,
        84% 54%,
        100% 46%,
        100% 100%,
        0 100%
    );
}

/* =========================================
   EXTRA CURVED FOOTER LAYERS
========================================= */

.footer-wave {
    position: absolute;
    z-index: 0;

    bottom: 0;
    left: 0;

    width: 100%;
    height: 145px;

    pointer-events: none;
    overflow: hidden;
}

.footer-wave::before {
    content: "";

    position: absolute;

    width: 120%;
    height: 100px;

    left: -8%;
    bottom: 20px;

    border-top: 4px solid rgba(16, 126, 199, .8);

    border-radius: 50% 50% 0 0;

    transform: rotate(7deg);
}

.footer-wave::after {
    content: "";

    position: absolute;

    width: 120%;
    height: 85px;

    left: -8%;
    bottom: 5px;

    border-top: 2px solid rgba(255,255,255,.15);

    border-radius: 50% 50% 0 0;

    transform: rotate(7deg);
}

/* =========================================
   CITY BRANDING
========================================= */

.city-brand {
    position: relative;
    z-index: 2;

    display: flex;
    align-items: center;

    gap: 15px;
}

/* Circular seal without square corners */

.city-seal {
    display: block;

    width: 82px;
    height: 82px;

    flex-shrink: 0;

    object-fit: contain;
    object-position: center;

    border-radius: 50%;

    clip-path: circle(49% at 50% 50%);
}

.brand-divider {
    width: 2px;
    height: 65px;

    flex-shrink: 0;

    background: #082b55;
}

.city-name {
    color: #082b55;

    font-size: clamp(21px, 2vw, 30px);
    font-weight: 600;

    letter-spacing: .3px;
    line-height: 1.15;
}

.office-name {
    margin-top: 6px;

    color: #082b55;

    font-size: clamp(10px, 1.05vw, 15px);
    font-weight: 700;

    letter-spacing: .19em;
    white-space: nowrap;
}

/* =========================================
   SYSTEM TITLE
========================================= */

.introduction {
    position: relative;
    z-index: 2;

    width: 100%;
    max-width: 760px;

    margin-top: 25px;
    margin-bottom: auto;
}

.system-title {
    color: #082b55;

    font-size: clamp(32px, 3.4vw, 51px);
    font-weight: 700;

    line-height: 1.08;
    letter-spacing: -1.3px;

    text-align: left;
}

.tagline {
    margin-top: 12px;

    color: #082b55;

    font-size: clamp(13px, 1.2vw, 18px);
    font-weight: 600;
    font-style: italic;

    line-height: 1.4;
}

/* =========================================
   BOTTOM FEATURE LABELS
========================================= */

.features {
    position: relative;
    z-index: 3;

    display: flex;
    align-items: center;

    width: min(100%, 620px);
    min-height: 85px;

    color: white;
}

.feature {
    flex: 1;

    display: flex;
    align-items: center;
    justify-content: center;

    gap: 10px;
    padding: 0 8px;
}

.feature:first-child {
    justify-content: flex-start;
}

.feature:last-child {
    justify-content: flex-end;
}

.feature-divider {
    height: 43px;

    border-left: 1px solid rgba(255,255,255,.5);
}

.feature-icon {
    width: 43px;
    height: 43px;

    flex-shrink: 0;

    display: flex;
    align-items: center;
    justify-content: center;

    border: 2px solid var(--gold);
    border-radius: 50%;

    color: white;
    font-size: 19px;
}

.feature-label {
    color: white;

    font-size: 11px;
    font-weight: 700;

    line-height: 1.35;
}

/* =========================================
   RIGHT SIDE - DECORATIVE NAVY BACKGROUND
========================================= */

.login-section {
    position: relative;
    isolation: isolate;

    min-width: 0;
    min-height: 100vh;
    min-height: 100svh;

    display: flex;
    align-items: center;
    justify-content: center;

    padding: 28px 7%;

    background:
        linear-gradient(
            135deg,
            #0c4c80 0%,
            #083b6c 30%,
            #052c55 65%,
            #02152e 100%
        );

    overflow: hidden;
}

/* Diagonal background decorations */

.login-section::after {
    content: "";

    position: absolute;
    z-index: -2;

    inset: 0;

    pointer-events: none;

    background:
        linear-gradient(
            125deg,
            transparent 0 14%,
            rgba(22,105,170,.35) 14.2% 23%,
            transparent 23.2% 34%,
            rgba(1,20,45,.35) 34.2% 45%,
            transparent 45.2% 60%,
            rgba(24,101,163,.15) 60.2% 70%,
            transparent 70.2%
        );
}

/* Gold circular corner accent */

.login-section::before {
    content: "";

    position: absolute;
    z-index: -1;

    width: 490px;
    height: 490px;

    top: -370px;
    right: -240px;

    border: 13px solid var(--gold);
    border-radius: 50%;

    box-shadow:
        0 0 0 12px rgba(232,189,66,.06),
        inset 0 0 20px rgba(232,189,66,.08);
}

/* =========================================
   LOGIN CARD - RAISED DESIGN
========================================= */

.login-card {
    position: relative;
    z-index: 2;

    width: 100%;
    max-width: 465px;

    padding: 27px 27px 23px;

    background: linear-gradient(
        145deg,
        #ffffff 0%,
        #fbfcff 55%,
        #f4f7fb 100%
    );

    border: 1px solid rgba(255,255,255,.95);
    border-radius: 25px;

    box-shadow:
        0 25px 65px rgba(0,0,0,.32),
        0 8px 18px rgba(0,0,0,.12),
        inset 0 1px 3px rgba(255,255,255,.95);

    overflow: hidden;
}

/* Raised upper-left corner effect */

.login-card::before {
    content: "";

    position: absolute;

    top: -75px;
    left: -80px;

    width: 210px;
    height: 210px;

    border: 3px solid rgba(255,255,255,.85);
    border-radius: 50%;

    box-shadow:
        0 0 0 5px rgba(226,232,240,.30),
        0 0 0 11px rgba(255,255,255,.30);

    pointer-events: none;
}

/* Subtle lower-right embossed detail */

.login-card::after {
    content: "";

    position: absolute;

    right: -100px;
    bottom: -120px;

    width: 230px;
    height: 230px;

    border: 2px solid rgba(8,47,92,.06);
    border-radius: 50%;

    box-shadow:
        0 0 0 8px rgba(8,47,92,.025),
        0 0 0 17px rgba(8,47,92,.018);

    pointer-events: none;
}

/* =========================================
   LOGIN CARD CONTENT
========================================= */

.card-header {
    position: relative;
    z-index: 1;

    text-align: center;
}

/* Circular City seal */

.card-seal {
    display: block;

    width: 88px;
    height: 88px;

    margin: 0 auto 5px;

    object-fit: contain;
    object-position: center;

    border-radius: 50%;
    clip-path: circle(49% at 50% 50%);
}

.card-header h1 {
    margin-top: 3px;

    color: #082b55;

    font-size: 34px;
    font-weight: 900;

    line-height: 1.1;
    letter-spacing: -.5px;
}

.gold-line {
    width: 185px;
    height: 3px;

    margin: 9px auto 10px;

    border-radius: 5px;

    background: linear-gradient(
        90deg,
        #bd8c1d,
        #f9dd7a,
        #bd8c1d
    );

    box-shadow: 0 1px 2px rgba(189,140,29,.2);
}

.system-name {
    color: #102b4b;

    font-size: 14px;
    font-weight: 700;

    line-height: 1.4;
}

.department-name {
    margin-top: 3px;

    color: #526174;
    font-size: 12px;
}

/* =========================================
   SIGN IN HEADING
========================================= */

.signin-heading {
    position: relative;
    z-index: 1;

    display: flex;
    align-items: center;

    gap: 14px;

    margin: 22px 0 18px;
}

.signin-heading span {
    margin-top: 10px;
    height: 1px;
    flex: 1;

    background: linear-gradient(
        90deg,
        transparent,
        #b7c4d1
    );
}

.signin-heading span:last-child {
    margin-top: 10px;
    background: linear-gradient(
        90deg,
        #b7c4d1,
        transparent
    );
}

.signin-heading h2 {
    color: #082b55;
margin-top: 10px;
    font-size: 15px;
    font-weight: 600;

    letter-spacing: .7px;
}

/* =========================================
   LOGIN NOTIFICATIONS
========================================= */

.error-message,
.success-message {
    position: relative;
    z-index: 5;

    width: 100%;
    margin: 0 auto 15px;
    padding: 12px 14px;

    border-radius: 10px;

    text-align: center;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.5;

    animation: notificationAppear .25s ease-out;
}

.error-message {
    color: #b91c1c;
    background: #fef2f2;
    border: 1px solid #fecaca;
}

.success-message {
    color: #166534;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
}

@keyframes notificationAppear {
    from {
        opacity: 0;
        transform: translateY(-7px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* =========================================
   LOGIN FORM
========================================= */

.login-form {
    margin-top: 20px;
    position: relative;
    z-index: 1;

    display: flex;
    flex-direction: column;

    gap: 13px;
}

.input-wrapper {
    height: 53px;

    display: flex;
    align-items: center;

    gap: 11px;
    padding: 0 14px;

    background: linear-gradient(
        180deg,
        #ffffff,
        #f9fbfd
    );

    border: 1px solid #cbd5e1;
    border-radius: 12px;

    box-shadow:
        inset 0 2px 4px rgba(0,0,0,.025),
        0 1px 2px rgba(0,0,0,.025);

    transition: .2s;
}

.input-wrapper:focus-within {
    border-color: #164d80;

    box-shadow:
        0 0 0 3px rgba(22,77,128,.10),
        inset 0 1px 3px rgba(0,0,0,.025);
}

.input-icon {
    flex-shrink: 0;

    color: #123b65;
    font-size: 18px;
}

.input-wrapper input {
    width: 100%;
    height: 100%;

    border: none;
    outline: none;

    background: transparent;

    color: #1e293b;
    font-size: 13px;
}

.input-wrapper input::placeholder {
    color: #94a3b8;
}

.toggle-password {
    border: none;
    background: transparent;

    color: #64748b;

    font-size: 10px;
    font-weight: 800;

    cursor: pointer;
}

.toggle-password:hover {
    color: #082f5c;
}

/* =========================================
   SIGN IN BUTTON
========================================= */

.signin-button {
    width: 100%;
    height: 52px;

    margin-top: 2px;

    border: none;
    border-radius: 12px;

    background: linear-gradient(
        110deg,
        #0b3767,
        #082f5c,
        #06264d
    );

    color: white;

    font-size: 15px;
    font-weight: 700;

    cursor: pointer;

    box-shadow:
        0 6px 14px rgba(8,47,92,.22),
        inset 0 1px 1px rgba(255,255,255,.12);

    transition: .2s;
}

.signin-button:hover {
    background: linear-gradient(
        110deg,
        #12518a,
        #0c3d70,
        #082f5c
    );

    box-shadow: 0 8px 19px rgba(8,47,92,.28);
}

.signin-button:active {
    transform: scale(.99);
}

/* =========================================
   AUTHORIZED PERSONNEL
========================================= */

.authorized {
    position: relative;
    z-index: 1;

    display: flex;
    align-items: center;

    gap: 10px;

    margin-top: 21px;

    color: #64748b;

    font-size: 11px;
    font-weight: 600;

    white-space: nowrap;
}

.authorized::before,
.authorized::after {
    content: "";

    height: 1px;
    flex: 1;

    background: linear-gradient(
        90deg,
        transparent,
        #cbd5e1
    );
}

.authorized::after {
    background: linear-gradient(
        90deg,
        #cbd5e1,
        transparent
    );
}

/* =========================================
   SHORT DESKTOP SCREENS
========================================= */

@media (max-height: 760px) and (min-width: 761px) {

    .branding-section {
        padding-top: 18px;
        padding-bottom: 10px;
    }

    .city-seal {
        width: 65px;
        height: 65px;
    }

    .brand-divider {
        height: 52px;
    }

    .introduction {
        margin-top: 14px;
    }

    .system-title {
        font-size: clamp(27px, 3vw, 41px);
    }

    .tagline {
        margin-top: 8px;
    }

    .features {
        min-height: 72px;
    }

    .branding-section::before {
        height: 135px;
    }

    .branding-section::after {
        height: 125px;
    }

    .login-card {
        padding: 17px 23px;
    }

    .card-seal {
        width: 67px;
        height: 67px;
    }

    .card-header h1 {
        font-size: 28px;
    }

    .gold-line {
        margin: 6px auto;
    }

    .signin-heading {
        margin: 13px 0 11px;
    }

    .login-form {
        gap: 9px;
    }

    .input-wrapper,
    .signin-button {
        height: 43px;
    }

    .authorized {
        margin-top: 12px;
    }
}

/* =========================================
   TABLET AND MOBILE
========================================= */

@media (max-width: 760px) {

    .login-page {
        min-height: 100svh;
        height: auto;

        display: flex;
        flex-direction: column;

        overflow: visible;
    }

    .branding-section {
        min-height: 360px;
        height: auto;

        padding: 23px 22px 18px;

        background-position: center center;
    }

    .branding-section::before {
        height: 0;
    }

    .branding-section::after {
        height: 0;
    }

    .city-seal {
        width: 58px;
        height: 58px;
    }

    .brand-divider {
        height: 47px;
        background: #082b55;
    }

    .city-name {
        color: #082b55;
        font-size: 18px;
    }

    .office-name {
        color: #082b55;
        font-size: 8px;
        letter-spacing: .11em;
    }

    .introduction {
        margin-top: 35px;
        margin-bottom: 35px;
    }

    .system-title {
        color: #082b55;

        font-size: clamp(25px, 7vw, 37px);
        line-height: 1.1;

        text-shadow: 0 1px 3px rgba(255,255,255,.3);
    }

    .tagline {
        color: #082b55;
        font-size: 13px;
    }

    .features {
        display: none;
    }

    .login-section {
        min-height: 480px;
        height: auto;

        padding: 35px 17px;

        overflow: hidden;
    }

    .login-card {
        max-width: 420px;
        padding: 24px 21px;
    }

    .card-seal {
        width: 76px;
        height: 76px;
    }

    .card-header h1 {
        font-size: 29px;
    }

    .footer-wave {
        display: none;
    }
}

/* =========================================
   VERY SMALL PHONES
========================================= */

@media (max-width: 380px) {

    .branding-section {
        min-height: 310px;
        padding: 18px 15px;
    }

    .city-seal {
        width: 49px;
        height: 49px;
    }

    .city-name {
        font-size: 16px;
    }

    .office-name {
        font-size: 7px;
        letter-spacing: .09em;
    }

    .system-title {
        font-size: 25px;
    }

    .login-section {
        padding: 25px 12px;
    }

    .login-card {
        padding: 21px 17px;
    }

    .input-wrapper {
        height: 48px;
    }

    .signin-button {
        height: 48px;
    }
}

/* =========================================
   ODMS FINAL BACKGROUND REFINEMENT
   Original photo positioning + seamless blend
========================================= */

/* Restore the original page layout */

.login-page {
    background: #031d3b;
    background-image: none;
    grid-template-columns: 66% 34%;
}

/* RESTORE ORIGINAL LEFT PHOTO */

.branding-section {
    background-image:
        linear-gradient(
            90deg,
            rgba(255,255,255,.10),
            rgba(255,255,255,.02)
        ),
        url('/odms/assets/images/odms-login-background.jpg');

    background-size: cover;
    background-position: center center;
    background-repeat: no-repeat;
}

/* LEFT FOOTER - GOLD AND NAVY */

.branding-section::before {
    height: 135px;
}

.branding-section::after {
    height: 126px;
}

/* RIGHT LOGIN BACKGROUND */

.login-section {
    position: relative;
    isolation: isolate;

    background: linear-gradient(
        140deg,
        #0b4677 0%,
        #062f59 45%,
        #031d3b 100%
    );

    overflow: hidden;
}

/* SOFT BLEND BETWEEN PHOTO AND NAVY */

.login-section::after {
    content: "";
    position: absolute;

    left: -130px;
    top: 0;

    width: calc(100% + 130px);
    height: 100%;

    z-index: 1;
    pointer-events: none;

    background:
        linear-gradient(
            90deg,
            rgba(3,29,59,0) 0%,
            rgba(3,29,59,.30) 28%,
            rgba(3,29,59,.82) 55%,
            #031d3b 78%
        );
}

/* GOLD CORNER ACCENT */

.login-section::before {
    z-index: 2;

    border-color: #e8bd42;

    box-shadow:
        0 0 0 8px rgba(232,189,66,.04);
}

/* KEEP LOGIN CARD ABOVE BACKGROUND EFFECTS */

.login-card {
    position: relative;
    z-index: 3;
}

/* REMOVE UNNECESSARY EXTRA WAVE */

.footer-wave {
    display: none !important;
}

</style>
</head>

<body>

<main class="login-page">

    <!-- =================================
         LEFT SIDE - CITY BRANDING
    ================================== -->

    <section class="branding-section">

        <div class="city-brand">

            <img
                src="/odms/assets/images/city-of-bais-seal.jpg"
                alt="City of Bais Official Seal"
                class="city-seal"
            >

            <div class="brand-divider"></div>

            <div>
                <p class="city-name">CITY OF BAIS</p>
                <p class="office-name">INTERNAL AUDIT SERVICE</p>
            </div>

        </div>

        <div class="introduction">

            <h1 class="system-title">
                OPERATIONS DOCUMENT<br>
                MONITORING SYSTEM
            </h1>

            <p class="tagline">
                Efficient Monitoring. Transparent Records. Better Service.</p>  </div>  

    <!-- Decorative wave layers -->  

    <div class="footer-wave"></div>  

    <!-- Bottom features -->  

    <div class="features">  

        <div class="feature">  

            <div class="feature-icon">▤</div>  

            <span class="feature-label">  
                Document<br>Tracking  
            </span>  

        </div>  

        <div class="feature-divider"></div>  

        <div class="feature">

   <div class="feature-icon">✓</div>  <span class="feature-label">  
                Secure Access  
            </span>  

        </div>  

        <div class="feature-divider"></div>  

        <div class="feature">  

            <div class="feature-icon">▥</div>  

            <span class="feature-label">  
                Reliable<br>Records  
            </span>  

        </div>  

    </div>  

</section>  


<!-- =================================  
     RIGHT SIDE - LOGIN PANEL  
================================== -->  

<section class="login-section">  

    <div class="login-card">  

    <!-- Login notifications -->

<?php if ($success): ?>

    <div class="success-message" role="status">
        ✓ &nbsp; Login successful!
    </div>

<?php elseif ($error !== ''): ?>

    <div class="error-message" role="alert">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>

<?php endif; ?>

        <!-- Login card branding -->  

        <div class="card-header">  

            <img  
                src="/odms/assets/images/city-of-bais-seal.jpg"  
                alt="City of Bais Official Seal"  
                class="card-seal"  
            >  

            <h1>ODMS</h1>  

            <div class="gold-line"></div>  

            <p class="system-name">  
                Operations Document Monitoring System  
            </p>  

            <p class="department-name">  
                Internal Audit Service  
            </p>  

        </div>  


        <!-- Sign in heading -->  

        <div class="signin-heading">  

            <span></span>  

            <h2>SIGN IN</h2>  

            <span></span>  

        </div>  


        <!-- Login form -->  

        <form  
            method="POST"  
            action="/odms/login.php"  
            class="login-form"  
            autocomplete="on"  
        >  

            <input  
                type="hidden"  
                name="csrf_token"  
                value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"  
            >  


            <!-- Username -->  

            <div class="input-wrapper">  

                <span class="input-icon" aria-hidden="true">♟</span>  

                <input  
                    id="username"  
                    name="username"  
                    type="text"  
                    required  
                    autocomplete="username"  
                    placeholder="Enter your username"  
                    value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"  
                >  

            </div>  


            <!-- Password -->  

            <div class="input-wrapper">  

                <span class="input-icon" aria-hidden="true">🔒</span>  

                <input  
                    id="password"  
                    name="password"  
                    type="password"  
                    required  
                    autocomplete="current-password"  
                    placeholder="Enter your password"  
                >  

                <button  
                    type="button"  
                    id="togglePassword"  
                    class="toggle-password"  
                    aria-label="Show password"  
                >  
                    SHOW  
                </button>  

            </div>  


            <!-- Sign in button -->  

            <button type="submit" class="signin-button">  

                ➜ &nbsp; Sign In  

            </button>  

        </form>  


        <!-- Authorized personnel -->  

        <div class="authorized">  

            Authorized Personnel Only  

        </div>  

    </div>  

</section>

</main>  
<!-- =================================
     PASSWORD VISIBILITY
================================== -->

<script>

const passwordInput = document.getElementById('password');
const togglePassword = document.getElementById('togglePassword');

togglePassword.addEventListener('click', function () {

    const showing = passwordInput.type === 'text';

    passwordInput.type = showing ? 'password' : 'text';

    togglePassword.textContent = showing ? 'SHOW' : 'HIDE';

    togglePassword.setAttribute(
        'aria-label',
        showing ? 'Show password' : 'Hide password'
    );

});

</script>


<!-- =================================
     AUTOMATIC REDIRECT AFTER LOGIN
================================== -->

<?php if ($success): ?>

<script>

setTimeout(function () {
    window.location.href = <?= json_encode($redirectUrl) ?>;
}, 850);

</script>

<?php endif; ?>

</body>
</html>
