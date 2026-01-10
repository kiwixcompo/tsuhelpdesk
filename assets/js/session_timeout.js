let timeoutID;
const TIMEOUT_DURATION = 15 * 60 * 1000; // 15 minutes in milliseconds

function resetTimer() {
    clearTimeout(timeoutID);
    timeoutID = setTimeout(logout, TIMEOUT_DURATION);
}

function logout() {
    window.location.href = 'logout.php';
}

// Reset timer on user activity
function setupActivityListeners() {
    const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'];
    events.forEach(event => {
        document.addEventListener(event, resetTimer, false);
    });
}

// Initialize timer when page loads
document.addEventListener('DOMContentLoaded', function() {
    setupActivityListeners();
    resetTimer();
}); 