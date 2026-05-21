/**
 * Session Timeout Warning System
 * Shows a countdown timer 10 seconds before session expires
 * Allows user to extend session by any activity
 * Built in robust Vanilla JS to prevent jQuery console crashes
 */

class SessionTimeoutManager {
    constructor(options = {}) {
        this.warningTime = options.warningTime || 10; // seconds before expiry to show warning
        this.sessionDuration = options.sessionDuration || 1800; // 30 minutes default
        this.checkInterval = options.checkInterval || 1000; // check every second
        this.lastActivity = Date.now();
        this.warningShown = false;
        this.countdownInterval = null;
        
        this.init();
    }
    
    init() {
        this.createWarningModal();
        this.bindActivityEvents();
        this.startMonitoring();
    }
    
    createWarningModal() {
        const modalHtml = `
            <div class="modal fade" id="sessionTimeoutModal" tabindex="-1" role="dialog" aria-labelledby="sessionTimeoutModalLabel" aria-hidden="true" data-backdrop="static" data-keyboard="false">
                <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content">
                        <div class="modal-header bg-warning text-dark">
                            <h5 class="modal-title" id="sessionTimeoutModalLabel">
                                <i class="fas fa-exclamation-triangle"></i> Session Timeout Warning
                            </h5>
                        </div>
                        <div class="modal-body text-center">
                            <div class="mb-3">
                                <i class="fas fa-clock text-warning" style="font-size: 48px;"></i>
                            </div>
                            <h5>Your session will expire due to inactivity</h5>
                            <p class="mb-3">You will be automatically logged out in:</p>
                            <div class="countdown-display">
                                <span id="countdownTimer" class="badge badge-danger" style="font-size: 24px; padding: 10px 20px;">10</span>
                                <div class="mt-2">seconds</div>
                            </div>
                            <p class="mt-3 text-muted">Click any button below to stay logged in</p>
                        </div>
                        <div class="modal-footer justify-content-center">
                            <button type="button" class="btn btn-success" id="stayLoggedInBtn">
                                <i class="fas fa-check"></i> Stay Logged In
                            </button>
                            <button type="button" class="btn btn-secondary" id="logoutNowBtn">
                                <i class="fas fa-sign-out-alt"></i> Logout Now
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = modalHtml.trim();
        const modalElement = tempDiv.firstChild;
        document.body.appendChild(modalElement);
        
        // Bind modal button events
        document.getElementById('stayLoggedInBtn').addEventListener('click', () => this.extendSession());
        document.getElementById('logoutNowBtn').addEventListener('click', () => this.logoutNow());
    }
    
    bindActivityEvents() {
        // Track user activity
        const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
        
        events.forEach(event => {
            document.addEventListener(event, () => {
                this.updateLastActivity();
            }, true);
        });
    }
    
    updateLastActivity() {
        this.lastActivity = Date.now();
        
        // If warning is shown, hide it
        if (this.warningShown) {
            this.hideWarning();
        }
    }
    
    startMonitoring() {
        setInterval(() => {
            this.checkSession();
        }, this.checkInterval);
    }
    
    checkSession() {
        const now = Date.now();
        const timeSinceActivity = (now - this.lastActivity) / 1000;
        const timeUntilExpiry = this.sessionDuration - timeSinceActivity;
        
        if (timeUntilExpiry <= 0) {
            // Session expired
            this.sessionExpired();
        } else if (timeUntilExpiry <= this.warningTime && !this.warningShown) {
            // Show warning
            this.showWarning(Math.ceil(timeUntilExpiry));
        }
    }
    
    showWarning(secondsLeft) {
        this.warningShown = true;
        
        const modalElement = document.getElementById('sessionTimeoutModal');
        if (window.$ && typeof $.fn.modal === 'function') {
            $(modalElement).modal('show');
        } else {
            // Fallback: manually show modal
            modalElement.style.display = 'block';
            // Trigger browser reflow to allow transition
            modalElement.offsetHeight;
            modalElement.classList.add('show');
            document.body.classList.add('modal-open');
            
            // Add backdrop
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            backdrop.id = 'session-timeout-backdrop';
            document.body.appendChild(backdrop);
        }
        
        // Start countdown
        this.startCountdown(secondsLeft);
    }
    
    startCountdown(seconds) {
        let remaining = seconds;
        const timerElement = document.getElementById('countdownTimer');
        if (timerElement) {
            timerElement.textContent = remaining;
        }
        
        this.countdownInterval = setInterval(() => {
            remaining--;
            if (timerElement) {
                timerElement.textContent = remaining;
                
                // Change color as time runs out
                if (remaining <= 3) {
                    timerElement.classList.remove('badge-danger', 'badge-warning');
                    timerElement.classList.add('badge-danger');
                    timerElement.parentElement.classList.add('animate__animated', 'animate__pulse');
                } else if (remaining <= 5) {
                    timerElement.classList.remove('badge-danger', 'badge-warning');
                    timerElement.classList.add('badge-warning');
                }
            }
            
            if (remaining <= 0) {
                clearInterval(this.countdownInterval);
                this.sessionExpired();
            }
        }, 1000);
    }
    
    hideWarning() {
        this.warningShown = false;
        
        const modalElement = document.getElementById('sessionTimeoutModal');
        if (window.$ && typeof $.fn.modal === 'function') {
            $(modalElement).modal('hide');
        } else {
            // Fallback: manually hide modal
            modalElement.classList.remove('show');
            document.body.classList.remove('modal-open');
            const backdrop = document.getElementById('session-timeout-backdrop');
            if (backdrop) {
                backdrop.classList.remove('show');
                setTimeout(() => {
                    if (backdrop.parentNode) {
                        backdrop.remove();
                    }
                }, 150);
            }
            setTimeout(() => {
                if (!this.warningShown) {
                    modalElement.style.display = 'none';
                }
            }, 150);
        }
        
        if (this.countdownInterval) {
            clearInterval(this.countdownInterval);
            this.countdownInterval = null;
        }
    }
    
    extendSession() {
        // Make fetch call to extend session
        fetch('extend_session.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (response.ok) {
                this.updateLastActivity();
                this.hideWarning();
                // Show brief success message
                this.showToast('Session extended successfully', 'success');
            } else {
                this.showToast('Failed to extend session', 'error');
            }
        })
        .catch(error => {
            console.error('Session extension error:', error);
            this.showToast('Failed to extend session', 'error');
        });
    }
    
    logoutNow() {
        window.location.href = 'logout.php';
    }
    
    sessionExpired() {
        // Clear any intervals
        if (this.countdownInterval) {
            clearInterval(this.countdownInterval);
        }
        
        // Show expiry message and redirect
        alert('Your session has expired due to inactivity. You will be redirected to the login page.');
        window.location.href = 'logout.php';
    }
    
    showToast(message, type = 'info') {
        const container = document.createElement('div');
        container.className = 'toast-container';
        container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; transition: opacity 0.5s ease;';
        
        const toastHtml = `
            <div class="toast show" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header bg-${type === 'success' ? 'success' : 'danger'} text-white">
                    <i class="fas fa-${type === 'success' ? 'check' : 'exclamation-triangle'} mr-2"></i>
                    <strong class="mr-auto">Session Manager</strong>
                    <button type="button" class="ml-2 mb-1 close text-white" onclick="this.closest('.toast-container').remove()">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="toast-body">
                    ${message}
                </div>
            </div>
        `;
        
        container.innerHTML = toastHtml.trim();
        document.body.appendChild(container);
        
        // Auto remove after 3 seconds
        setTimeout(() => {
            container.style.opacity = '0';
            setTimeout(() => {
                if (container.parentNode) {
                    container.remove();
                }
            }, 500);
        }, 3000);
    }
}

// Initialize session timeout manager when document is ready
document.addEventListener('DOMContentLoaded', function() {
    // Only initialize if user is logged in
    if (typeof sessionTimeoutEnabled !== 'undefined' && sessionTimeoutEnabled) {
        window.sessionManager = new SessionTimeoutManager({
            warningTime: 10,        // Show warning 10 seconds before expiry
            sessionDuration: 1800,  // 30 minutes session duration
            checkInterval: 1000     // Check every second
        });
    }
});