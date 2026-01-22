<?php
// Start output buffering to prevent header issues
ob_start();

session_start();

// Check if user is already logged in
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    header("location: dashboard.php");
    exit;
}

// End output buffering and flush
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TSU ICT Help Desk - Portal</title>
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
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            cursor: pointer;
            height: 300px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-decoration: none;
            color: inherit;
        }
        
        .portal-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.2);
            text-decoration: none;
            color: inherit;
        }
        
        .staff-card {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
        }
        
        .student-card {
            background: linear-gradient(135deg, #4a90e2 0%, #6bb6ff 100%);
            color: white;
        }
        
        .portal-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
        }
        
        .portal-title {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .portal-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            text-align: center;
        }
        
        .header-section {
            text-align: center;
            margin-bottom: 4rem;
            color: white;
        }
        
        .header-title {
            font-size: 3rem;
            font-weight: bold;
            margin-bottom: 1rem;
        }
        
        .header-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
        }
        
        @media (max-width: 768px) {
            .header-title {
                font-size: 2rem;
            }
            .portal-card {
                height: 250px;
                margin-bottom: 2rem;
            }
            .portal-icon {
                font-size: 3rem;
            }
            .portal-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-12">
                <!-- Header Section -->
                <div class="header-section">
                    <h1 class="header-title">
                        <i class="fas fa-laptop mr-3"></i>
                        TSU ICT Help Desk
                    </h1>
                    <p class="header-subtitle">Choose your portal to access the system</p>
                </div>
                
                <!-- Portal Cards -->
                <div class="row justify-content-center">
                    <div class="col-md-5 col-lg-4 mb-4">
                        <a href="staff_login.php" class="portal-card staff-card">
                            <i class="fas fa-users-cog portal-icon"></i>
                            <h2 class="portal-title">Staff Portal</h2>
                            <p class="portal-subtitle">
                                Access for administrators, directors, and department staff
                            </p>
                        </a>
                    </div>
                    
                    <div class="col-md-5 col-lg-4 mb-4">
                        <a href="student_portal.php?back=1" class="portal-card student-card">
                            <i class="fas fa-graduation-cap portal-icon"></i>
                            <h2 class="portal-title">Student Portal</h2>
                            <p class="portal-subtitle">
                                Lodge complaints for result verification and academic issues
                            </p>
                        </a>
                    </div>
                </div>
                
                <!-- Footer -->
                <div class="text-center mt-5">
                    <small class="text-white-50">
                        <i class="fas fa-shield-alt mr-1"></i>
                        Secure access for authorized users only
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Add hover effects
            $('.portal-card').hover(
                function() {
                    $(this).addClass('shadow-lg');
                },
                function() {
                    $(this).removeClass('shadow-lg');
                }
            );
        });
    </script>
</body>
</html>