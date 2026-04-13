<?php
// Dashboard Header Component
$page_title    = $page_title    ?? 'Dashboard';
$page_subtitle = $page_subtitle ?? '';
$page_icon     = $page_icon     ?? 'fas fa-tachometer-alt';
$show_breadcrumb  = $show_breadcrumb  ?? false;
$breadcrumb_items = $breadcrumb_items ?? [];

$current_hour = (int) date('H');
if ($current_hour < 12)      { $greeting = 'Good morning'; }
elseif ($current_hour < 17)  { $greeting = 'Good afternoon'; }
else                          { $greeting = 'Good evening'; }

$role_labels = [
    1 => 'Administrator', 2 => 'Staff', 3 => 'Director',
    4 => 'DVC', 5 => 'i4Cus Staff', 6 => 'Payment Admin',
    7 => 'Department', 8 => 'Deputy Director ICT',
];
$user_role = $role_labels[$_SESSION['role_id']] ?? 'User';
$user_name = htmlspecialchars($_SESSION['full_name'] ?? 'User');
?>

<div class="dh-bar">
    <div class="container-fluid dh-inner">

        <!-- Left: greeting + title -->
        <div class="dh-left">
            <p class="dh-greeting"><?php echo $greeting; ?>, <?php echo $user_name; ?></p>
            <h1 class="dh-title">
                <i class="<?php echo $page_icon; ?> dh-icon"></i>
                <?php echo htmlspecialchars($page_title); ?>
            </h1>
            <?php if ($page_subtitle): ?>
                <p class="dh-sub"><?php echo htmlspecialchars($page_subtitle); ?></p>
            <?php endif; ?>

            <?php if ($show_breadcrumb && !empty($breadcrumb_items)): ?>
                <nav class="dh-breadcrumb" aria-label="breadcrumb">
                    <a href="<?php echo $dashboard_file ?? 'dashboard.php'; ?>">Home</a>
                    <?php foreach ($breadcrumb_items as $i => $item): ?>
                        <span class="dh-sep">›</span>
                        <?php if ($i < count($breadcrumb_items) - 1): ?>
                            <a href="<?php echo htmlspecialchars($item['url']); ?>"><?php echo htmlspecialchars($item['title']); ?></a>
                        <?php else: ?>
                            <span><?php echo htmlspecialchars($item['title']); ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
        </div>

        <!-- Right: quick stats (optional) -->
        <?php if (!empty($quick_stats)): ?>
        <div class="dh-stats">
            <?php foreach ($quick_stats as $s): ?>
            <div class="dh-stat">
                <span class="dh-stat-num"><?php echo htmlspecialchars($s['number']); ?></span>
                <span class="dh-stat-lbl"><?php echo htmlspecialchars($s['label']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<style>
.dh-bar {
    background: #fff;
    border-bottom: 1px solid #e9ecef;
    padding: 1.25rem 0 1rem;
    margin-bottom: 1.5rem;
}
.dh-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    padding-left: 1.25rem;
    padding-right: 1.25rem;
}
.dh-left { flex: 1; min-width: 0; }
.dh-greeting {
    font-size: .8rem;
    color: #6c757d;
    margin: 0 0 .2rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.dh-title {
    font-size: 1.4rem;
    font-weight: 700;
    color: #1e3c72;
    margin: 0 0 .15rem;
    display: flex;
    align-items: center;
    gap: .5rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dh-icon { font-size: 1.1rem; opacity: .75; }
.dh-sub  { font-size: .85rem; color: #6c757d; margin: 0; }
.dh-breadcrumb {
    font-size: .8rem;
    color: #6c757d;
    margin-top: .4rem;
}
.dh-breadcrumb a { color: #1e3c72; text-decoration: none; }
.dh-breadcrumb a:hover { text-decoration: underline; }
.dh-sep { margin: 0 .3rem; }

/* Stats row */
.dh-stats {
    display: flex;
    gap: .75rem;
    flex-wrap: wrap;
}
.dh-stat {
    background: #f4f7fb;
    border-radius: 8px;
    padding: .55rem 1rem;
    text-align: center;
    min-width: 80px;
}
.dh-stat-num {
    display: block;
    font-size: 1.3rem;
    font-weight: 700;
    color: #1e3c72;
    line-height: 1.1;
}
.dh-stat-lbl {
    display: block;
    font-size: .7rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-top: .15rem;
}

@media (max-width: 576px) {
    .dh-title { font-size: 1.15rem; }
    .dh-stats { width: 100%; justify-content: flex-start; }
    .dh-stat  { flex: 1; min-width: 70px; }
}
</style>
