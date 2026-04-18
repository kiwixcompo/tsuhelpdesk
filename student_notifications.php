<?php
ob_start();
session_start();

if (!isset($_SESSION["student_loggedin"]) || $_SESSION["student_loggedin"] !== true) {
    header("location: student_login.php");
    exit;
}

require_once "config.php";

$student_id = (int) $_SESSION["student_id"];

// Mark single notification as read
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['mark_read'])) {
    $nid = (int) $_POST['notification_id'];
    mysqli_query($conn,
        "UPDATE student_notifications SET is_read=1
         WHERE notification_id=$nid AND student_id=$student_id");
    header("Location: student_notifications.php");
    exit;
}

// Mark all as read
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['mark_all_read'])) {
    mysqli_query($conn,
        "UPDATE student_notifications SET is_read=1 WHERE student_id=$student_id");
    header("Location: student_notifications.php");
    exit;
}

// Fetch all notifications
$notifications = [];
$sql = "SELECT * FROM student_notifications
        WHERE student_id = ?
        ORDER BY created_at DESC
        LIMIT 100";
if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, 'i', $student_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $notifications[] = $row;
    mysqli_stmt_close($stmt);
}

$unread_count = count(array_filter($notifications, function($n) { return !$n['is_read']; }));

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Notifications — TSU ICT Help Desk</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<style>
body { background:#f4f7fb; font-family:'Segoe UI',sans-serif; }
.nb { background:#1e3c72; padding:.75rem 1rem; display:flex; align-items:center;
      gap:1rem; position:sticky; top:0; z-index:100;
      box-shadow:0 1px 6px rgba(0,0,0,.2); }
.nb a { color:rgba(255,255,255,.85); text-decoration:none; font-size:.85rem; }
.nb a:hover { color:#fff; }
.nb-title { color:#fff; font-weight:700; font-size:.95rem; margin-left:auto; margin-right:auto; }

.notif-card {
    background:#fff;
    border-radius:10px;
    box-shadow:0 2px 8px rgba(30,60,114,.08);
    margin-bottom:.75rem;
    padding:1rem 1.25rem;
    border-left:4px solid transparent;
    transition:border-color .15s;
}
.notif-card.unread { border-left-color:#1e3c72; background:#f8fbff; }
.notif-card.unread .notif-title { font-weight:700; }
.notif-title { color:#1e3c72; font-size:.95rem; margin-bottom:.25rem; }
.notif-msg   { color:#495057; font-size:.88rem; margin-bottom:.35rem; }
.notif-meta  { font-size:.75rem; color:#adb5bd; }
.notif-type-ict    { background:#d1ecf1; color:#0c5460; }
.notif-type-result { background:#d4edda; color:#155724; }
.notif-type-auto   { background:#e2e3e5; color:#383d41; }
</style>
</head>
<body>

<nav class="nb">
    <a href="student_dashboard.php"><i class="fas fa-arrow-left mr-1"></i> Dashboard</a>
    <span class="nb-title"><i class="fas fa-bell mr-2"></i>My Notifications</span>
</nav>

<div class="container mt-4" style="max-width:720px">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">
            Notifications
            <?php if ($unread_count > 0): ?>
                <span class="badge badge-danger ml-1"><?php echo $unread_count; ?> unread</span>
            <?php endif; ?>
        </h5>
        <?php if ($unread_count > 0): ?>
        <form method="POST" style="display:inline">
            <button type="submit" name="mark_all_read" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-check-double mr-1"></i>Mark all as read
            </button>
        </form>
        <?php endif; ?>
    </div>

    <?php if (empty($notifications)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-bell-slash fa-3x mb-3"></i>
            <p>No notifications yet.</p>
        </div>
    <?php else: ?>
        <?php foreach ($notifications as $n):
            $type = $n['complaint_type'] ?? 'result';
            $type_map = [
                'ict'  => ['ICT',    'notif-type-ict'],
                'auto' => ['Auto',   'notif-type-auto'],
            ];
            $type_label = $type_map[$type] ?? ['Result', 'notif-type-result'];
            $is_unread = !$n['is_read'];
        ?>
        <div class="notif-card <?php echo $is_unread ? 'unread' : ''; ?>">
            <div class="d-flex justify-content-between align-items-start">
                <div class="flex-grow-1">
                    <div class="notif-title">
                        <?php if ($is_unread): ?>
                            <span class="text-primary mr-1">●</span>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($n['title']); ?>
                        <span class="badge <?php echo $type_label[1]; ?> ml-1" style="font-size:.7rem">
                            <?php echo $type_label[0]; ?>
                        </span>
                    </div>
                    <div class="notif-msg"><?php echo htmlspecialchars($n['message']); ?></div>
                    <div class="notif-meta">
                        <i class="fas fa-clock mr-1"></i>
                        <?php echo date('M d, Y H:i', strtotime($n['created_at'])); ?>
                    </div>
                </div>
                <?php if ($is_unread): ?>
                <form method="POST" class="ml-3 flex-shrink-0">
                    <input type="hidden" name="notification_id" value="<?php echo $n['notification_id']; ?>">
                    <button type="submit" name="mark_read" class="btn btn-sm btn-outline-secondary"
                            title="Mark as read">
                        <i class="fas fa-check"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
