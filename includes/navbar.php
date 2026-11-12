<?php
/**
 * Shared Top Navigation Bar (includes/navbar.php)
 * Expects: $active_nav ('calendar' | 'paper' | 'exam' | 'courses'), $initial_sub
 */
$active_nav = $active_nav ?? 'calendar';
$badge_map = [
    'calendar' => ['icon' => '📅', 'label' => 'برنامه‌ریز', 'cls' => 'bg-indigo-600'],
    'paper'    => ['icon' => '🌐', 'label' => 'مقاله‌خوان', 'cls' => 'bg-emerald-600'],
    'exam'     => ['icon' => '📝', 'label' => 'آزمون‌ساز',  'cls' => 'bg-rose-600'],
    'courses'  => ['icon' => '🎬', 'label' => 'آکادمی',     'cls' => 'bg-indigo-600'],
];
$cur_badge = $badge_map[$active_nav] ?? $badge_map['calendar'];
?>
<header id="main-app-header" class="no-print bg-slate-900 text-white px-3 md:px-4 py-2 flex flex-wrap items-center justify-between gap-2 shadow-md z-30 shrink-0">
    <div class="flex items-center gap-2 md:gap-3">
        <div class="flex items-center gap-1.5">
            <span class="<?php echo $cur_badge['cls']; ?> text-white px-2 py-1 rounded-lg font-extrabold text-xs">
                <?php echo $cur_badge['icon'] . ' ' . $cur_badge['label']; ?>
            </span>
            <span class="font-bold text-xs md:text-sm truncate max-w-[90px] sm:max-w-none">
                <?php echo htmlspecialchars($_SESSION['full_name'] ?? ''); ?>
            </span>
        </div>

        <nav class="flex bg-slate-800 p-0.5 rounded-xl border border-slate-700 overflow-x-auto">
            <a href="index.php" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold transition shrink-0 <?php echo $active_nav === 'calendar' ? 'bg-indigo-600 text-white shadow' : 'text-slate-300 hover:text-white'; ?>">
                📅 تقویم
            </a>
            <a href="index.php?tab=pdf" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition shrink-0">
                📚 جزوه‌خوان
            </a>
            <a href="paper_reader.php" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold transition shrink-0 <?php echo $active_nav === 'paper' ? 'bg-indigo-600 text-white shadow' : 'text-slate-300 hover:text-white'; ?>">
                🌐 مقاله‌خوان
            </a>
            <a href="exam.php" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold transition shrink-0 <?php echo $active_nav === 'exam' ? 'bg-indigo-600 text-white shadow' : 'text-slate-300 hover:text-white'; ?>">
                📝 آزمون‌ها
            </a>
            <a href="courses.php" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold transition shrink-0 <?php echo $active_nav === 'courses' ? 'bg-indigo-600 text-white shadow' : 'text-slate-300 hover:text-white'; ?>">
                🎬 ویدیوها
            </a>
        </nav>
    </div>

    <div class="flex items-center gap-1.5 md:gap-2 text-xs">
        <button type="button" id="header-sub-btn" onclick="openSubscriptionModal()" class="<?php echo !empty($initial_sub['is_vip']) ? 'bg-amber-400 text-slate-950' : 'bg-indigo-600 hover:bg-indigo-500 text-white border border-indigo-400'; ?> px-2.5 py-1.5 rounded-lg font-extrabold flex items-center gap-1 shadow transition">
            <?php if (!empty($initial_sub['is_vip'])): ?>
                <span>👑 ویژه (<?php echo (int)$initial_sub['days_left']; ?> روز)</span>
            <?php else: ?>
                <span>⭐ ارتقا به اشتراک ویژه</span>
            <?php endif; ?>
        </button>

        <?php if (!empty($initial_sub['is_admin'])): ?>
            <a href="admin.php" class="bg-slate-800 hover:bg-slate-700 border border-amber-500 text-amber-300 px-2.5 py-1.5 rounded-lg font-bold flex items-center gap-1 transition">
                <span>🛠️</span>
                <span class="hidden lg:inline">پنل مدیریت</span>
            </a>
        <?php endif; ?>

        <a href="auth.php?logout=1" class="text-slate-400 hover:text-rose-400 font-bold px-1.5 py-1">خروج</a>
    </div>
</header>