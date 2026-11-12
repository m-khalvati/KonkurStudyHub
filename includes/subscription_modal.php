<?php
/**
 * Shared VIP Subscription & Promo Code Modal (includes/subscription_modal.php)
 */
if (!defined('VIP_MAX_PDF_MB')) define('VIP_MAX_PDF_MB', 64);
if (!defined('PLAN_1M_PRICE'))  define('PLAN_1M_PRICE', '۵۰۰,۰۰۰');
if (!defined('PLAN_3M_PRICE'))  define('PLAN_3M_PRICE', '۱,۲۰۰,۰۰۰');
?>
<div id="subscription-modal" class="fixed inset-0 bg-slate-900 bg-opacity-75 hidden items-end sm:items-center justify-center sm:p-4 z-50">
    <div class="bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl w-full max-w-2xl max-h-[94vh] flex flex-col overflow-hidden">
        <div class="bg-slate-900 text-white px-5 py-4 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-2.5">
                <span class="text-2xl">👑</span>
                <div>
                    <h3 class="font-extrabold text-sm md:text-base text-amber-300">اشتراک ویژه (VIP) سامانه جامع مطالعه و آموزش</h3>
                    <p id="sub-modal-current-status" class="text-[11px] text-slate-300 mt-0.5"></p>
                </div>
            </div>
            <button type="button" onclick="closeSubscriptionModal()" class="text-slate-400 hover:text-white text-2xl leading-none">&times;</button>
        </div>

        <div class="p-4 sm:p-5 overflow-y-auto custom-scroll space-y-4 text-xs">
            <div id="sub-modal-reason-alert" class="hidden bg-rose-50 border border-rose-200 text-rose-800 px-3.5 py-2.5 rounded-xl font-bold leading-relaxed"></div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div class="border-2 border-slate-200 hover:border-indigo-500 rounded-2xl p-4 flex flex-col justify-between bg-white transition">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-extrabold text-sm text-slate-800">💎 اشتراک ۱ ماهه</span>
                            <span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded-md text-[10px] font-bold">۳۰ روز کامل</span>
                        </div>
                        <div class="my-3">
                            <span class="text-2xl font-black text-indigo-600"><?php echo PLAN_1M_PRICE; ?></span>
                            <span class="text-slate-500 font-bold">تومان</span>
                        </div>
                        <ul class="space-y-1.5 text-[11px] text-slate-600 mb-4">
                            <li>✓ دسترسی کامل به دوره‌های ویدیویی، جزوات و آزمون‌های VIP</li>
                            <li>✓ آپلود نامحدود جزوه و مقاله (تا <?php echo VIP_MAX_PDF_MB; ?>MB)</li>
                            <li>✓ مترجم تخصصی هوش مصنوعی + یادآور پیامکی تقویم</li>
                        </ul>
                    </div>
                    <a id="tg-link-1m" href="#" target="_blank" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-extrabold text-center shadow transition block">
                        ✈️ سفارش ۱ ماهه در تلگرام
                    </a>
                </div>

                <div class="border-2 border-emerald-500 rounded-2xl p-4 flex flex-col justify-between bg-emerald-50 relative shadow-md">
                    <span class="absolute -top-2.5 left-4 bg-emerald-600 text-white text-[10px] font-extrabold px-2.5 py-0.5 rounded-full shadow">
                        🔥 ۳۰۰,۰۰۰ تومان تخفیف ویژه
                    </span>
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-extrabold text-sm text-emerald-950">👑 اشتراک ۳ ماهه</span>
                            <span class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-md text-[10px] font-bold">۹۰ روز کامل</span>
                        </div>
                        <div class="my-3 flex items-baseline gap-2">
                            <span class="text-2xl font-black text-emerald-600"><?php echo PLAN_3M_PRICE; ?></span>
                            <span class="text-slate-500 font-bold">تومان</span>
                            <span class="text-slate-400 line-through text-[11px]">۱,۵۰۰,۰۰۰</span>
                        </div>
                        <ul class="space-y-1.5 text-[11px] text-slate-700 mb-4">
                            <li>✓ <b>۲۰٪ به‌صرفه‌تر</b> (ماهیانه فقط ۴۰۰ هزار تومان)</li>
                            <li>✓ دسترسی نامحدود به تمام ویدیوها، آزمون‌ها و جزوات</li>
                            <li>✓ آپلود نامحدود PDF + هوش مصنوعی + یادآور پیامکی</li>
                        </ul>
                    </div>
                    <a id="tg-link-3m" href="#" target="_blank" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-extrabold text-center shadow transition block">
                        ✈️ سفارش ۳ ماهه در تلگرام
                    </a>
                </div>
            </div>

            <div class="bg-slate-900 text-white p-3.5 rounded-2xl flex flex-col sm:flex-row items-center justify-between gap-3">
                <div class="text-center sm:text-right">
                    <div class="font-extrabold text-amber-300 text-xs">🎟️ کد فعال‌سازی اشتراک دریافت کرده‌اید؟</div>
                    <div class="text-[11px] text-slate-300 mt-0.5">کد دریافتی از پشتیبانی تلگرام را وارد کنید تا درجا فعال شود:</div>
                </div>
                <form onsubmit="handleRedeemCode(event)" class="flex items-center gap-1.5 w-full sm:w-auto">
                    <input type="text" id="redeem-code-input" required dir="ltr" placeholder="VIP90-XXXX-XXXX" class="flex-1 sm:w-44 px-3 py-2 rounded-xl bg-slate-800 border border-slate-600 text-white font-mono text-center font-bold">
                    <button type="submit" id="btn-redeem-submit" class="bg-amber-400 hover:bg-amber-300 text-slate-950 font-black px-3.5 py-2 rounded-xl shrink-0">
                        فعال‌سازی
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>