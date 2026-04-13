<?php
// Navbar component

if (!isset($notification_count)) {
    require_once "includes/notifications.php";
    $notification_count = getUnreadNotificationCount($conn, $_SESSION["user_id"]);
}

if (!isset($unread_count)) {
    $unread_count = 0;
    $msg_sql = "SELECT COUNT(*) as c FROM messages WHERE (recipient_id = ? OR is_broadcast = 1) AND is_read = 0";
    if ($s = mysqli_prepare($conn, $msg_sql)) {
        mysqli_stmt_bind_param($s, "i", $_SESSION["user_id"]);
        mysqli_stmt_execute($s);
        $r = mysqli_stmt_get_result($s);
        if ($row = mysqli_fetch_assoc($r)) $unread_count = $row['c'];
        mysqli_stmt_close($s);
    }
}

$role_id = $_SESSION["role_id"];
$dashboard_file = match($role_id) {
    3 => 'director_dashboard.php',
    4 => 'dvc_dashboard.php',
    5 => 'i4cus_staff_dashboard.php',
    6 => 'payment_admin_dashboard.php',
    7 => 'department_dashboard.php',
    8 => 'deputy_director_dashboard.php',
    default => 'dashboard.php',
};

$app_logo = $app_logo ?? '';
$app_name = $app_name ?? 'TSU ICT Help Desk';
$current_page = basename($_SERVER['PHP_SELF']);

function nb_active(string $page, string $current): string {
    return $page === $current ? ' nb-active' : '';
}
?>

<style>
/* ── Navbar ─────────────────────────────────────────── */
.nb {
    background: #1e3c72;
    position: sticky;
    top: 0;
    z-index: 1040;
    box-shadow: 0 1px 6px rgba(0,0,0,.18);
}
.nb-inner {
    display: flex;
    align-items: center;
    height: 56px;
    padding: 0 1rem;
    gap: .5rem;
}

/* Brand */
.nb-brand {
    display: flex;
    align-items: center;
    gap: .5rem;
    color: #fff !important;
    font-weight: 700;
    font-size: .95rem;
    text-decoration: none;
    flex-shrink: 0;
    margin-right: auto;
}
.nb-brand:hover { color: #c9d9f5 !important; text-decoration: none; }
.nb-brand-logo {
    width: 30px; height: 30px;
    border-radius: 6px;
    object-fit: contain;
    background: rgba(255,255,255,.15);
    display: flex; align-items: center; justify-content: center;
    font-size: .9rem; color: #fff;
    flex-shrink: 0;
}
.nb-brand-logo img { width: 100%; height: 100%; border-radius: 6px; object-fit: contain; }

/* Desktop links */
.nb-links {
    display: flex;
    align-items: center;
    gap: .1rem;
    list-style: none;
    margin: 0; padding: 0;
}
.nb-links a {
    display: flex;
    align-items: center;
    gap: .35rem;
    color: rgba(255,255,255,.85);
    font-size: .82rem;
    font-weight: 500;
    padding: .35rem .65rem;
    border-radius: 6px;
    text-decoration: none;
    transition: background .15s, color .15s;
    white-space: nowrap;
}
.nb-links a:hover, .nb-links a.nb-active {
    background: rgba(255,255,255,.15);
    color: #fff;
    text-decoration: none;
}
.nb-links a i { font-size: .8rem; }

/* Divider */
.nb-div {
    width: 1px; height: 20px;
    background: rgba(255,255,255,.2);
    margin: 0 .4rem;
    flex-shrink: 0;
}

/* Icon buttons (bell, envelope) */
.nb-icon-btn {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px; height: 36px;
    border-radius: 8px;
    color: rgba(255,255,255,.85);
    text-decoration: none;
    transition: background .15s, color .15s;
    flex-shrink: 0;
}
.nb-icon-btn:hover { background: rgba(255,255,255,.15); color: #fff; text-decoration: none; }
.nb-icon-btn i { font-size: 1rem; }
.nb-badge {
    position: absolute;
    top: 2px; right: 2px;
    background: #e74c3c;
    color: #fff;
    font-size: .6rem;
    font-weight: 700;
    min-width: 16px; height: 16px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    padding: 0 3px;
    border: 2px solid #1e3c72;
    line-height: 1;
}

/* User pill */
.nb-user {
    display: flex;
    align-items: center;
    gap: .5rem;
    background: rgba(255,255,255,.1);
    border-radius: 20px;
    padding: .3rem .7rem .3rem .4rem;
    color: #fff;
    text-decoration: none;
    transition: background .15s;
    flex-shrink: 0;
    cursor: pointer;
    border: none;
}
.nb-user:hover { background: rgba(255,255,255,.2); color: #fff; text-decoration: none; }
.nb-avatar {
    width: 28px; height: 28px;
    border-radius: 50%;
    background: rgba(255,255,255,.2);
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem;
    flex-shrink: 0;
}
.nb-uname { font-size: .82rem; font-weight: 600; max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.nb-urole { font-size: .7rem; opacity: .75; }

/* Dropdown */
.nb-dropdown {
    position: relative;
}
.nb-dropdown-menu {
    display: none;
    position: absolute;
    right: 0; top: calc(100% + 6px);
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 8px 30px rgba(0,0,0,.15);
    min-width: 220px;
    padding: .4rem 0;
    z-index: 1050;
    animation: nbFadeIn .15s ease;
}
.nb-dropdown-menu.nb-notif-menu { min-width: 300px; max-height: 420px; overflow-y: auto; }
@keyframes nbFadeIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
.nb-dropdown.open .nb-dropdown-menu { display: block; }
.nb-dm-header {
    padding: .5rem 1rem;
    font-size: .75rem;
    font-weight: 700;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: .05em;
    border-bottom: 1px solid #f0f0f0;
    margin-bottom: .25rem;
}
.nb-dm-item {
    display: flex;
    align-items: center;
    gap: .6rem;
    padding: .55rem 1rem;
    font-size: .85rem;
    color: #333;
    text-decoration: none;
    transition: background .12s;
    cursor: pointer;
}
.nb-dm-item:hover { background: #f4f7fb; color: #1e3c72; text-decoration: none; }
.nb-dm-item.danger { color: #dc3545; }
.nb-dm-item.danger:hover { background: #fff5f5; }
.nb-dm-item i { width: 16px; text-align: center; font-size: .85rem; color: #6c757d; }
.nb-dm-item.danger i { color: #dc3545; }
.nb-dm-divider { border: none; border-top: 1px solid #f0f0f0; margin: .25rem 0; }

/* Notification items */
.nb-notif-item {
    padding: .65rem 1rem;
    border-bottom: 1px solid #f5f5f5;
    cursor: pointer;
    transition: background .12s;
}
.nb-notif-item:hover { background: #f4f7fb; }
.nb-notif-item.unread { border-left: 3px solid #1e3c72; }
.nb-notif-title { font-size: .82rem; font-weight: 600; color: #1e3c72; margin-bottom: 2px; }
.nb-notif-msg   { font-size: .78rem; color: #6c757d; margin-bottom: 2px; }
.nb-notif-time  { font-size: .72rem; color: #adb5bd; }
.nb-notif-empty { padding: 1.5rem 1rem; text-align: center; color: #adb5bd; font-size: .85rem; }

/* Hamburger */
.nb-toggle {
    display: none;
    background: rgba(255,255,255,.1);
    border: none;
    border-radius: 6px;
    width: 36px; height: 36px;
    align-items: center; justify-content: center;
    color: #fff;
    font-size: 1.1rem;
    cursor: pointer;
    flex-shrink: 0;
    transition: background .15s;
}
.nb-toggle:hover { background: rgba(255,255,255,.2); }

/* Mobile drawer */
.nb-drawer {
    display: none;
    background: #162d5a;
    padding: .75rem 1rem 1rem;
    border-top: 1px solid rgba(255,255,255,.1);
}
.nb-drawer.open { display: block; }
.nb-drawer a {
    display: flex;
    align-items: center;
    gap: .6rem;
    color: rgba(255,255,255,.85);
    font-size: .88rem;
    font-weight: 500;
    padding: .6rem .75rem;
    border-radius: 7px;
    text-decoration: none;
    transition: background .12s;
}
.nb-drawer a:hover, .nb-drawer a.nb-active {
    background: rgba(255,255,255,.12);
    color: #fff;
    text-decoration: none;
}
.nb-drawer a i { width: 18px; text-align: center; font-size: .85rem; }
.nb-drawer-section { margin-bottom: .5rem; }
.nb-drawer-label {
    font-size: .68rem;
    font-weight: 700;
    color: rgba(255,255,255,.4);
    text-transform: uppercase;
    letter-spacing: .07em;
    padding: .5rem .75rem .2rem;
}
.nb-drawer-divider { border: none; border-top: 1px solid rgba(255,255,255,.1); margin: .5rem 0; }

/* Responsive */
@media (max-width: 991px) {
    .nb-links, .nb-div { display: none !important; }
    .nb-toggle { display: flex; }
    .nb-uname, .nb-urole { display: none; }
    .nb-user { padding: .3rem; border-radius: 50%; }
}
@media (min-width: 992px) {
    .nb-drawer { display: none !important; }
}
</style>

<script>var sessionTimeoutEnabled = true;</script>
<script src="js/session-timeout.js"></script>

<!-- ── Navbar ── -->
<nav class="nb">
    <div class="nb-inner container-fluid">

        <!-- Brand -->
        <a class="nb-brand" href="<?php echo $dashboard_file; ?>">
            <div class="nb-brand-logo">
                <?php if ($app_logo && file_exists($app_logo)): ?>
                    <img src="<?php echo htmlspecialchars($app_logo); ?>" alt="Logo">
                <?php else: ?>
                    <i class="fas fa-graduation-cap"></i>
                <?php endif; ?>
            </div>
            <span><?php echo htmlspecialchars($app_name); ?></span>
        </a>

        <!-- Desktop links -->
        <ul class="nb-links">
            <li><a href="<?php echo $dashboard_file; ?>" class="<?php echo nb_active($dashboard_file, $current_page); ?>">
                <i class="fas fa-home"></i> Dashboard
            </a></li>

            <?php if ($role_id == 1): ?>
            <li><a href="admin.php" class="<?php echo nb_active('admin.php', $current_page); ?>">
                <i class="fas fa-cogs"></i> Admin
            </a></li>
            <?php endif; ?>

            <?php if (in_array($role_id, [1, 3])): ?>
            <div class="nb-div"></div>
            <?php if ($role_id == 1): ?>
            <li><a href="users.php" class="<?php echo nb_active('users.php', $current_page); ?>">
                <i class="fas fa-users"></i> Staff
            </a></li>
            <li><a href="manage_students.php" class="<?php echo nb_active('manage_students.php', $current_page); ?>">
                <i class="fas fa-user-graduate"></i> Students
            </a></li>
            <?php endif; ?>
            <li><a href="student_complaints_report.php" class="<?php echo nb_active('student_complaints_report.php', $current_page); ?>">
                <i class="fas fa-chart-bar"></i> Reports
            </a></li>
            <?php endif; ?>

            <div class="nb-div"></div>
            <li><a href="suggestions.php" class="<?php echo nb_active('suggestions.php', $current_page); ?>">
                <i class="fas fa-lightbulb"></i> Suggestions
            </a></li>

            <?php if ($role_id == 1 && !empty($_SESSION["is_super_admin"])): ?>
            <li><a href="settings.php" class="<?php echo nb_active('settings.php', $current_page); ?>">
                <i class="fas fa-cog"></i> Settings
            </a></li>
            <?php endif; ?>
        </ul>

        <!-- Right icons -->
        <div class="nb-div" style="display:flex!important"></div>

        <!-- Messages -->
        <a class="nb-icon-btn" href="messages.php" title="Messages">
            <i class="fas fa-envelope"></i>
            <?php if ($unread_count > 0): ?>
                <span class="nb-badge"><?php echo $unread_count; ?></span>
            <?php endif; ?>
        </a>

        <!-- Notifications -->
        <div class="nb-dropdown">
            <button class="nb-icon-btn" id="nbBellBtn" title="Notifications" aria-label="Notifications">
                <i class="fas fa-bell"></i>
                <?php if ($notification_count > 0): ?>
                    <span class="nb-badge" id="notificationBadge"><?php echo $notification_count; ?></span>
                <?php endif; ?>
            </button>
            <div class="nb-dropdown-menu nb-notif-menu" id="nbNotifMenu">
                <div class="nb-dm-header"><i class="fas fa-bell mr-1"></i> Notifications</div>
                <div id="notificationList">
                    <div class="nb-notif-empty"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
                </div>
                <hr class="nb-dm-divider">
                <a class="nb-dm-item" href="notifications.php"><i class="fas fa-eye"></i> View all notifications</a>
            </div>
        </div>

        <!-- User -->
        <div class="nb-dropdown">
            <button class="nb-user" id="nbUserBtn" aria-label="User menu">
                <div class="nb-avatar"><i class="fas fa-user"></i></div>
                <div>
                    <div class="nb-uname"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></div>
                    <div class="nb-urole"><?php
                        $rl = [1=>'Administrator',2=>'Staff',3=>'Director',4=>'DVC',5=>'i4Cus Staff',6=>'Payment Admin',7=>'Department',8=>'Deputy Director ICT'];
                        echo $rl[$role_id] ?? 'User';
                    ?></div>
                </div>
                <i class="fas fa-chevron-down" style="font-size:.65rem;opacity:.7;margin-left:.2rem"></i>
            </button>
            <div class="nb-dropdown-menu" id="nbUserMenu">
                <div class="nb-dm-header"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></div>
                <a class="nb-dm-item" href="account.php"><i class="fas fa-user-edit"></i> My Profile</a>
                <a class="nb-dm-item" href="change_password.php"><i class="fas fa-key"></i> Change Password</a>
                <hr class="nb-dm-divider">
                <a class="nb-dm-item danger" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <!-- Hamburger -->
        <button class="nb-toggle" id="nbToggle" aria-label="Menu">
            <i class="fas fa-bars"></i>
        </button>

    </div><!-- /nb-inner -->

    <!-- Mobile drawer -->
    <div class="nb-drawer" id="nbDrawer">

        <div class="nb-drawer-section">
            <div class="nb-drawer-label">Navigation</div>
            <a href="<?php echo $dashboard_file; ?>" class="<?php echo nb_active($dashboard_file, $current_page); ?>">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <?php if ($role_id == 1): ?>
            <a href="admin.php" class="<?php echo nb_active('admin.php', $current_page); ?>">
                <i class="fas fa-cogs"></i> Admin Panel
            </a>
            <?php endif; ?>
        </div>

        <?php if (in_array($role_id, [1, 3])): ?>
        <hr class="nb-drawer-divider">
        <div class="nb-drawer-section">
            <div class="nb-drawer-label">Management</div>
            <?php if ($role_id == 1): ?>
            <a href="users.php" class="<?php echo nb_active('users.php', $current_page); ?>">
                <i class="fas fa-users"></i> Staff Users
            </a>
            <a href="manage_students.php" class="<?php echo nb_active('manage_students.php', $current_page); ?>">
                <i class="fas fa-user-graduate"></i> Students
            </a>
            <?php endif; ?>
            <a href="student_complaints_report.php" class="<?php echo nb_active('student_complaints_report.php', $current_page); ?>">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
        </div>
        <?php endif; ?>

        <hr class="nb-drawer-divider">
        <div class="nb-drawer-section">
            <div class="nb-drawer-label">Tools</div>
            <a href="messages.php" class="<?php echo nb_active('messages.php', $current_page); ?>">
                <i class="fas fa-envelope"></i> Messages
                <?php if ($unread_count > 0): ?><span style="margin-left:auto;background:#e74c3c;color:#fff;border-radius:10px;padding:1px 7px;font-size:.7rem"><?php echo $unread_count; ?></span><?php endif; ?>
            </a>
            <a href="notifications.php" class="<?php echo nb_active('notifications.php', $current_page); ?>">
                <i class="fas fa-bell"></i> Notifications
                <?php if ($notification_count > 0): ?><span style="margin-left:auto;background:#e74c3c;color:#fff;border-radius:10px;padding:1px 7px;font-size:.7rem"><?php echo $notification_count; ?></span><?php endif; ?>
            </a>
            <a href="suggestions.php" class="<?php echo nb_active('suggestions.php', $current_page); ?>">
                <i class="fas fa-lightbulb"></i> Suggestions
            </a>
            <?php if ($role_id == 1 && !empty($_SESSION["is_super_admin"])): ?>
            <a href="settings.php" class="<?php echo nb_active('settings.php', $current_page); ?>">
                <i class="fas fa-cog"></i> Settings
            </a>
            <?php endif; ?>
        </div>

        <hr class="nb-drawer-divider">
        <div class="nb-drawer-section">
            <div class="nb-drawer-label">Account</div>
            <a href="account.php"><i class="fas fa-user-edit"></i> My Profile</a>
            <a href="change_password.php"><i class="fas fa-key"></i> Change Password</a>
            <a href="logout.php" style="color:#ff6b6b"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>

    </div><!-- /nb-drawer -->
</nav>

<script>
(function () {
    // Toggle helpers
    function closeAll() {
        document.querySelectorAll('.nb-dropdown').forEach(d => d.classList.remove('open'));
    }

    // Bell
    document.getElementById('nbBellBtn').addEventListener('click', function (e) {
        e.stopPropagation();
        const dd = this.closest('.nb-dropdown');
        const wasOpen = dd.classList.contains('open');
        closeAll();
        if (!wasOpen) {
            dd.classList.add('open');
            loadNotifications();
        }
    });

    // User
    document.getElementById('nbUserBtn').addEventListener('click', function (e) {
        e.stopPropagation();
        const dd = this.closest('.nb-dropdown');
        const wasOpen = dd.classList.contains('open');
        closeAll();
        if (!wasOpen) dd.classList.add('open');
    });

    // Hamburger
    document.getElementById('nbToggle').addEventListener('click', function () {
        document.getElementById('nbDrawer').classList.toggle('open');
    });

    // Close on outside click
    document.addEventListener('click', function () { closeAll(); });

    // ── Notifications ──────────────────────────────────
    function loadNotifications() {
        document.getElementById('notificationList').innerHTML =
            '<div class="nb-notif-empty"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';

        fetch('get_notifications_dropdown.php')
            .then(r => r.json())
            .then(data => {
                if (data && data.success) {
                    renderNotifications(data.notifications);
                    updateBadge(data.unread_count);
                } else {
                    document.getElementById('notificationList').innerHTML =
                        '<div class="nb-notif-empty">Could not load notifications.</div>';
                }
            })
            .catch(() => {
                document.getElementById('notificationList').innerHTML =
                    '<div class="nb-notif-empty">Network error.</div>';
            });
    }

    function renderNotifications(list) {
        const el = document.getElementById('notificationList');
        if (!list || !list.length) {
            el.innerHTML = '<div class="nb-notif-empty">No notifications yet.</div>';
            return;
        }
        el.innerHTML = list.map(n => `
            <div class="nb-notif-item ${n.is_read == 0 ? 'unread' : ''}"
                 onclick="nbNotifClick(${n.notification_id}, ${n.complaint_id})">
                <div class="nb-notif-title">${esc(n.title || 'Notification')}</div>
                <div class="nb-notif-msg">${esc(n.message || '')}</div>
                <div class="nb-notif-time">${timeAgo(n.created_at)}</div>
            </div>`).join('');
    }

    window.nbNotifClick = function (nid, cid) {
        fetch('mark_notification_read.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({notification_id: nid})
        }).finally(() => {
            window.location.href = 'view_complaint.php?id=' + cid;
        });
    };

    function updateBadge(count) {
        const b = document.getElementById('notificationBadge');
        if (!b) return;
        if (count > 0) { b.textContent = count; b.style.display = ''; }
        else b.style.display = 'none';
    }

    function timeAgo(ds) {
        const s = Math.floor((Date.now() - new Date(ds)) / 1000);
        if (s < 60)   return 'Just now';
        if (s < 3600) return Math.floor(s/60) + 'm ago';
        if (s < 86400)return Math.floor(s/3600) + 'h ago';
        return Math.floor(s/86400) + 'd ago';
    }

    function esc(t) {
        const d = document.createElement('div');
        d.textContent = t;
        return d.innerHTML;
    }

    // Keep legacy jQuery aliases alive for any inline calls
    window.loadNotifications = loadNotifications;
    window.updateNotificationBadge = updateBadge;
    window.escapeHtml = esc;
    window.getTimeAgo = timeAgo;
    window.handleNotificationClick = window.nbNotifClick;
})();
</script>
