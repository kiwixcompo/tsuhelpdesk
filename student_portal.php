<?php
ob_start();
session_start();

if (isset($_SESSION["student_loggedin"]) && $_SESSION["student_loggedin"] === true) {
    header("location: student_dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal - TSU ICT Help Desk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .portal-card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(30,60,114,.2);
            transition: all .3s ease;
            cursor: pointer;
            height: 280px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-decoration: none;
            color: inherit;
        }
        .portal-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 30px 60px rgba(30,60,114,.3);
            text-decoration: none;
            color: inherit;
        }
        .login-card  { background: linear-gradient(135deg,#1e3c72,#2a5298); color:#fff; }
        .signup-card { background: linear-gradient(135deg,#4a90e2,#6bb6ff); color:#fff; }
        .portal-icon  { font-size:4rem; margin-bottom:1.5rem; }
        .portal-title { font-size:2rem; font-weight:bold; margin-bottom:.5rem; }
        .portal-subtitle { font-size:1.1rem; opacity:.9; text-align:center; padding:0 1rem; }
        .header-section { text-align:center; margin-bottom:4rem; color:#fff; }
        .header-title { font-size:3rem; font-weight:bold; margin-bottom:1rem; text-shadow:0 2px 4px rgba(0,0,0,.1); }
        .header-subtitle { font-size:1.2rem; opacity:.9; }
        .back-link { color:#fff; text-decoration:none; margin-bottom:2rem; display:inline-block; }
        .back-link:hover { color:#f8f9fa; text-decoration:none; }
        @media (max-width:768px) {
            .header-title { font-size:2rem; }
            .portal-card  { height:250px; margin-bottom:2rem; }
            .portal-icon  { font-size:3rem; }
            .portal-title { font-size:1.5rem; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-12">

                <?php
                // Show back link only when coming from another page on the same domain
                $show_back = false;
                if (!empty($_SERVER['HTTP_REFERER'])) {
                    $ref = parse_url($_SERVER['HTTP_REFERER']);
                    if (isset($ref['host']) && $ref['host'] === $_SERVER['HTTP_HOST']) {
                        $ref_page = basename($ref['path'] ?? '');
                        if ($ref_page !== 'student_portal.php' && $ref_page !== '') {
                            $show_back = true;
                        }
                    }
                }
                if (isset($_GET['back']) && $_GET['back'] === '1') $show_back = true;
                if ($show_back): ?>
                <a href="index.php" class="back-link">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Portal
                </a>
                <?php endif; ?>

                <!-- Header -->
                <div class="header-section">
                    <h1 class="header-title">
                        <i class="fas fa-graduation-cap mr-3"></i>
                        Student Portal
                    </h1>
                    <p class="header-subtitle">Access the student complaint and support system</p>
                </div>

                <!-- Cards -->
                <div class="row justify-content-center">
                    <div class="col-md-5 col-lg-4 mb-4">
                        <a href="student_login.php" class="portal-card login-card">
                            <i class="fas fa-sign-in-alt portal-icon"></i>
                            <h2 class="portal-title">Login</h2>
                            <p class="portal-subtitle">
                                Sign in with your registration number or email if you already have an account
                            </p>
                        </a>
                    </div>
                    <div class="col-md-5 col-lg-4 mb-4">
                        <a href="student_signup.php" class="portal-card signup-card">
                            <i class="fas fa-user-plus portal-icon"></i>
                            <h2 class="portal-title">Sign Up</h2>
                            <p class="portal-subtitle">
                                Create a new account to lodge complaints and access student support services
                            </p>
                        </a>
                    </div>
                </div>

                <!-- Info -->
                <div class="text-center mt-4">
                    <div class="card" style="background:rgba(255,255,255,.1);border:none;border-radius:15px">
                        <div class="card-body text-white">
                            <h5><i class="fas fa-info-circle mr-2"></i>About the Student Portal</h5>
                            <p class="mb-0">
                                This portal allows students to lodge and track complaints on academic and
                                administrative issues — including result verification (FA, F, Incorrect Grade),
                                portal access problems, course registration, payments, and other ICT-related concerns.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <small class="text-white-50">
                        <i class="fas fa-shield-alt mr-1"></i>
                        Secure access for registered students only
                    </small>
                </div>

            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
