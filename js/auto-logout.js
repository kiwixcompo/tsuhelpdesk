// Set timeout variables
const INACTIVE_TIMEOUT = 30 * 60 * 1000; // 30 minutes in milliseconds
let inactivityTimer;

// Function to reset the timer
function resetInactivityTimer() {
    clearTimeout(inactivityTimer);
    inactivityTimer = setTimeout(logout, INACTIVE_TIMEOUT);
}

// Function to perform logout
function logout() {
    window.location.href = 'logout.php';
}

// Reset timer on user activity
document.addEventListener('mousemove', resetInactivityTimer);
document.addEventListener('keypress', resetInactivityTimer);
document.addEventListener('click', resetInactivityTimer);
document.addEventListener('scroll', resetInactivityTimer);

// Start the timer when the page loads
window.addEventListener('load', resetInactivityTimer); 