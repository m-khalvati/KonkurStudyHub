/**
 * Modular Frontend Logic for paper_reader.php
 */
// ====================================================
        // ۰. بارگذاری تضمین‌شده موتور PDF.js با پشتیبان چندگانه
        // ====================================================
        const PDFJS_CDN_LIST = [
            {
                lib: 'assets/pdfjs/pdf.min.js',
                worker: 'assets/pdfjs/pdf.worker.min.js',
                cmaps: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/cmaps/',
                fonts: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/standard_fonts/'
            },
            {
                lib: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js',
                worker: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js',
                cmaps: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/cmaps/',
                fonts: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/standard_fonts/'
            },
            {
                lib: 'https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.min.js',
                worker: 'https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.worker.min.js',
                cmaps: 'https://unpkg.com/pdfjs-dist@3.11.174/cmaps/',
                fonts: 'https://unpkg.com/pdfjs-dist@3.11.174/standard_fonts/'
            }
        ];
        let activeCdnConfig = PDFJS_CDN_LIST[0];

        function loadScriptDynamic(src) {
            return new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = src;
                s.async = true;
                s.onload = () => resolve();
                s.onerror = () => reject(new Error('Failed to load ' + src));
                document.head.appendChild(s);
            });
        }

        async function ensurePdfJsReady() {
            if (typeof window.pdfjsLib !== 'undefined') {
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = activeCdnConfig.worker;
                return window.pdfjsLib;
            }
            for (const cdn of PDFJS_CDN_LIST) {
                try {
                    await loadScriptDynamic(cdn.lib);
                    if (typeof window.pdfjsLib !== 'undefined') {
                        activeCdnConfig = cdn;
                        window.pdfjsLib.GlobalWorkerOptions.workerSrc = cdn.worker;
                        return window.pdfjsLib;
                    }
                } catch (e) {}
            }
            throw new Error('موتور نمایشگر PDF بارگذاری نشد. لطفاً صفحه را رفرش کنید.');
        }

        if (typeof window.pdfjsLib !== 'undefined') {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = activeCdnConfig.worker;
        }

        // ====================================================
        // متغیرهای پایه مقاله‌خوان و کش پیش‌بارگذاری (Pre-fetch Cache)
        // ====================================================

        let papersList   = [];
        let bookletsList = [];

        let activePaperMeta   = null;
        let paperDocInstance  = null;
        let activeRenderTask  = null;
        let currentPaperPage  = 1;
        let paperZoomMode     = 'fit-width';
        let paperCustomScale  = 1.0;
        let fitWidthBaseScale = 1.0;
        let unscaledPageW     = 600;
        let unscaledPageH     = 800;
        let isRenderingPaper  = false;
        let pendingPaperPage  = null;
        let isPaperDarkMode   = false;

        let selectionGranularity = 'sentence'; // 'sentence' یا 'paragraph'
        let pageSegmentsList     = [];
        let activeSegmentIndex   = -1;

        let selectedEngine      = 'google'; // 'google', 'mymemory', 'ai'
        let lastTranslatedObj   = null;
        let visitedSegNorms     = new Set();
        let paperNotes          = [];

        // کش حافظه مرورگر برای پیش‌بارگذاری مخفی بند بعدی (Background Pre-fetching)
        const clientTranslationCache = new Map();

        // وضعیت ۳ حالته پنل موبایل: 'closed' | 'minimized' | 'half' | 'expanded'
        let mobileSheetState = 'closed';

        // متغیرهای پیشروی خودکار هوشمند
        let isAutoAdvanceRunning = false;
        let autoAdvanceTimerId   = null;
        let autoAdvanceSessionId = 0;

        function normalizeClientEnglishText(str) {
            return String(str || '')
                .trim()
                .replace(/(\w)-\s+([a-z])/g, '$1$2')
                .replace(/\s+/g, ' ')
                .toLowerCase();
        }

        // ====================================================
        // مدیریت کشوی ابزارها (⚙️ Tools Drawer)
        // ====================================================
        function openToolsDrawer() {
            const m = document.getElementById('tools-drawer-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeToolsDrawer() {
            const m = document.getElementById('tools-drawer-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        function setSelectionGranularity(mode) {
            selectionGranularity = mode;
            const btnSent = document.getElementById('mode-btn-sentence');
            const btnPara = document.getElementById('mode-btn-paragraph');

            if (mode === 'sentence') {
                btnSent.className = 'py-2 px-2 rounded-xl font-extrabold bg-indigo-600 text-white transition';
                btnPara.className = 'py-2 px-2 rounded-xl font-bold bg-white text-slate-700 border border-slate-200 transition';
            } else {
                btnPara.className = 'py-2 px-2 rounded-xl font-extrabold bg-indigo-600 text-white transition';
                btnSent.className = 'py-2 px-2 rounded-xl font-bold bg-white text-slate-700 border border-slate-200 transition';
            }

            if (paperDocInstance) {
                renderPaperPage(currentPaperPage, true);
            }
        }

        // ====================================================
        // مدیریت ۳ حالته پنل ترجمه در موبایل + دستگیره لمسی (Swipe Handle)
        // ====================================================
        function setMobileSheetState(state) {
            const panel    = document.getElementById('translator-panel');
            const viewport = document.getElementById('paper-viewport-container');
            const sizeBtn  = document.getElementById('btn-mobile-sheet-size');

            mobileSheetState = state;
            panel.classList.remove('translate-y-full', 'h-[42vh]', 'h-[78vh]', 'h-[46px]');

            if (state === 'closed') {
                panel.classList.add('translate-y-full', 'h-[42vh]');
                viewport.style.paddingBottom = '';
            } else if (state === 'minimized') {
                panel.classList.add('h-[46px]');
                if (sizeBtn) sizeBtn.textContent = '⬆️';
                if (window.innerWidth < 768) viewport.style.paddingBottom = '54px';
            } else if (state === 'expanded') {
                panel.classList.add('h-[78vh]');
                if (sizeBtn) sizeBtn.textContent = '⬇️';
                if (window.innerWidth < 768) viewport.style.paddingBottom = '78vh';
            } else {
                panel.classList.add('h-[42vh]');
                if (sizeBtn) sizeBtn.textContent = '⬆️';
                if (window.innerWidth < 768) viewport.style.paddingBottom = '43vh';
            }
        }

        function toggleMobileExpandHalf() {
            if (mobileSheetState === 'expanded') {
                setMobileSheetState('half');
            } else {
                setMobileSheetState('expanded');
            }
        }

        (function initSheetSwipeHandle() {
            const handle = document.getElementById('mobile-sheet-handle');
            if (!handle) return;
            let startY = 0;

            handle.addEventListener('touchstart', (e) => {
                if (e.touches.length === 1) startY = e.touches[0].clientY;
            }, { passive: true });

            handle.addEventListener('touchend', (e) => {
                if (e.changedTouches.length !== 1) return;
                const dy = e.changedTouches[0].clientY - startY;
                if (dy > 35) {
                    if (mobileSheetState === 'expanded') setMobileSheetState('half');
                    else setMobileSheetState('minimized');
                } else if (dy < -35) {
                    if (mobileSheetState === 'minimized') setMobileSheetState('half');
                    else setMobileSheetState('expanded');
                }
            }, { passive: true });
        })();

        function scrollActiveBoxIntoVisibleArea(boxEl) {
            if (!boxEl) return;
            const container = document.getElementById('paper-viewport-container');
            if (!container) return;

            const containerRect = container.getBoundingClientRect();
            const boxRect       = boxEl.getBoundingClientRect();

            let visibleHeight = containerRect.height;
            if (window.innerWidth < 768 && mobileSheetState !== 'closed') {
                const panelRect = document.getElementById('translator-panel').getBoundingClientRect();
                visibleHeight = Math.max(120, panelRect.top - containerRect.top);
            }

            const targetScrollTop = container.scrollTop + (boxRect.top - containerRect.top) - (visibleHeight * 0.35);
            container.scrollTo({
                top: Math.max(0, targetScrollTop),
                behavior: 'smooth'
            });
        }

        // ====================================================
        // ژست لمسی زوم دو انگشتی قفل‌شده روی نقطه بین دو انگشت + زوم دوضربه‌ای ستون‌ها
        // ====================================================
        (function initTouchZoomGestures() {
            const viewport = document.getElementById('paper-viewport-container');
            const wrapper  = document.getElementById('paper-canvas-wrapper');

            let isPinching = false;
            let startDist  = 0;
            let startScale = 1.0;
            let liveScale  = 1.0;
            // مختصات نسبی نقطه بین دو انگشت روی خود برگه PDF (از 0.0 تا 1.0)
            let pinchRelX  = 0.5;
            let pinchRelY  = 0.5;
            // مکان انگشتان نسبت به کادر دید گوشی
            let pinchVpX   = 0;
            let pinchVpY   = 0;
            let reRenderTimer = null;

            let lastTapTime = 0;
            let lastTapX    = 0;
            let lastTapY    = 0;

            viewport.addEventListener('touchstart', (e) => {
                if (!paperDocInstance) return;

                if (e.touches.length === 2) {
                    isPinching = true;
                    if (reRenderTimer) clearTimeout(reRenderTimer);

                    const t1 = e.touches[0];
                    const t2 = e.touches[1];
                    const midClientX = (t1.clientX + t2.clientX) / 2;
                    const midClientY = (t1.clientY + t2.clientY) / 2;

                    startDist  = Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY);
                    startScale = paperCustomScale;
                    liveScale  = paperCustomScale;

                    const vpRect   = viewport.getBoundingClientRect();
                    const wrapRect = wrapper.getBoundingClientRect();

                    pinchVpX = midClientX - vpRect.left;
                    pinchVpY = midClientY - vpRect.top;

                    // پیدا کردن نقطه دقیق روی برگه مقاله که زیر دو انگشت قرار دارد
                    pinchRelX = Math.min(1, Math.max(0, (midClientX - wrapRect.left) / Math.max(1, wrapRect.width)));
                    pinchRelY = Math.min(1, Math.max(0, (midClientY - wrapRect.top) / Math.max(1, wrapRect.height)));
                } else if (e.touches.length === 1 && !isPinching) {
                    const now = Date.now();
                    const tx  = e.touches[0].clientX;
                    const ty  = e.touches[0].clientY;

                    if (now - lastTapTime < 280 && Math.hypot(tx - lastTapX, ty - lastTapY) < 28) {
                        e.preventDefault();
                        handleDoubleTapColumnZoom(tx, ty);
                        lastTapTime = 0;
                    } else {
                        lastTapTime = now;
                        lastTapX = tx;
                        lastTapY = ty;
                    }
                }
            }, { passive: false });

            viewport.addEventListener('touchmove', (e) => {
                if (!isPinching || e.touches.length !== 2 || !paperDocInstance) return;
                e.preventDefault();

                const t1 = e.touches[0];
                const t2 = e.touches[1];
                const curDist = Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY);
                if (startDist < 10) return;

                const midClientX = (t1.clientX + t2.clientX) / 2;
                const midClientY = (t1.clientY + t2.clientY) / 2;
                const vpRect     = viewport.getBoundingClientRect();
                pinchVpX = midClientX - vpRect.left;
                pinchVpY = midClientY - vpRect.top;

                const ratio = curDist / startDist;
                liveScale = Math.min(3.5, Math.max(0.45, startScale * ratio));

                const newW = Math.round(unscaledPageW * liveScale);
                const newH = Math.round(unscaledPageH * liveScale);
                wrapper.style.width  = newW + 'px';
                wrapper.style.height = newH + 'px';

                // نگه داشتن نقطه دقیق مقاله در زیر انگشتان در حین باز و بسته کردن دست
                viewport.scrollLeft = Math.max(0, wrapper.offsetLeft + (pinchRelX * newW) - pinchVpX);
                viewport.scrollTop  = Math.max(0, wrapper.offsetTop + (pinchRelY * newH) - pinchVpY);
            }, { passive: false });

            viewport.addEventListener('touchend', (e) => {
                if (!isPinching) return;
                if (e.touches.length < 2) {
                    isPinching = false;
                    if (Math.abs(liveScale - paperCustomScale) > 0.04) {
                        paperZoomMode    = 'custom';
                        paperCustomScale = liveScale;
                        const savedScrollLeft = viewport.scrollLeft;
                        const savedScrollTop  = viewport.scrollTop;

                        if (reRenderTimer) clearTimeout(reRenderTimer);
                        reRenderTimer = setTimeout(() => {
                            renderPaperPage(currentPaperPage, true).then(() => {
                                viewport.scrollLeft = savedScrollLeft;
                                viewport.scrollTop  = savedScrollTop;
                            });
                        }, 180);
                    }
                }
            });

            function handleDoubleTapColumnZoom(clientX, clientY) {
                if (!paperDocInstance) return;
                const wrapRect = wrapper.getBoundingClientRect();
                const relX     = Math.min(1, Math.max(0, (clientX - wrapRect.left) / Math.max(1, wrapRect.width)));
                const relY     = Math.min(1, Math.max(0, (clientY - wrapRect.top) / Math.max(1, wrapRect.height)));

                if (paperZoomMode === 'fit-width' || paperCustomScale <= fitWidthBaseScale * 1.15) {
                    paperZoomMode    = 'custom';
                    paperCustomScale = Math.min(3.2, fitWidthBaseScale * 1.85);
                    renderPaperPage(currentPaperPage, true).then(() => {
                        const newW = wrapper.offsetWidth;
                        const newH = wrapper.offsetHeight;
                        const targetLeft = (relX < 0.5)
                            ? wrapper.offsetLeft
                            : Math.max(0, wrapper.offsetLeft + newW - viewport.clientWidth);
                        const targetTop  = Math.max(0, wrapper.offsetTop + (relY * newH) - (viewport.clientHeight * 0.35));
                        viewport.scrollTo({ left: targetLeft, top: targetTop, behavior: 'smooth' });
                    });
                } else {
                    setPaperFitWidth();
                }
            }
        })();

        // ====================================================
        // سیستم «پیشروی خودکار هوشمند» + اسلایدر سرعت + پیش‌بارگذاری مخفی (Pre-fetch)
        // ====================================================
        function onAutoSpeedSliderInput(val) {
            const sec = parseInt(val, 10) || 8;
            document.getElementById('auto-speed-slider-label').textContent = `${sec} ثانیه`;
            document.getElementById('slim-speed-badge').textContent = `${sec}s`;
            localStorage.setItem('paper_auto_speed_sec', String(sec));

            if (isAutoAdvanceRunning && activeSegmentIndex >= 0) {
                autoAdvanceSessionId++;
                if (autoAdvanceTimerId) clearInterval(autoAdvanceTimerId);
                startReadingCountdownForAutoAdvance(activeSegmentIndex, autoAdvanceSessionId);
            }
        }

        function toggleAutoAdvance() {
            if (!paperDocInstance || pageSegmentsList.length === 0) {
                alert('لطفاً ابتدا یک مقاله را باز کنید.');
                return;
            }
            if (isAutoAdvanceRunning) {
                stopAutoAdvance();
            } else {
                closeToolsDrawer();
                startAutoAdvance();
            }
        }

        function startAutoAdvance() {
            isAutoAdvanceRunning = true;
            autoAdvanceSessionId++;
            const currentSession = autoAdvanceSessionId;

            const topBtn = document.getElementById('btn-top-auto-advance');
            if (topBtn) {
                topBtn.className = 'px-2.5 py-1 bg-rose-600 hover:bg-rose-500 text-white rounded-lg text-[11px] font-extrabold flex items-center gap-1 shadow-2xs transition';
                topBtn.innerHTML = '<span>⏸</span><span>توقف</span>';
            }
            const drawerBtn = document.getElementById('drawer-btn-auto-toggle');
            if (drawerBtn) {
                drawerBtn.className = 'px-3 py-1 bg-rose-600 text-white rounded-xl font-extrabold text-[11px]';
                drawerBtn.textContent = 'توقف پیشروی';
            }

            document.getElementById('auto-advance-slim-bar').classList.remove('hidden');
            document.getElementById('auto-advance-slim-bar').classList.add('flex');
            document.getElementById('auto-advance-progress-wrap').classList.remove('hidden');

            const startIdx = (activeSegmentIndex >= 0 && activeSegmentIndex < pageSegmentsList.length)
                ? activeSegmentIndex
                : 0;

            runAutoAdvanceStep(startIdx, currentSession);
        }

        function stopAutoAdvance() {
            isAutoAdvanceRunning = false;
            autoAdvanceSessionId++;
            if (autoAdvanceTimerId) {
                clearInterval(autoAdvanceTimerId);
                autoAdvanceTimerId = null;
            }

            const topBtn = document.getElementById('btn-top-auto-advance');
            if (topBtn) {
                topBtn.className = 'px-2.5 py-1 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-[11px] font-extrabold flex items-center gap-1 shadow-2xs transition';
                topBtn.innerHTML = '<span>▶️</span><span>خودکار</span>';
            }
            const drawerBtn = document.getElementById('drawer-btn-auto-toggle');
            if (drawerBtn) {
                drawerBtn.className = 'px-3 py-1 bg-emerald-600 text-white rounded-xl font-extrabold text-[11px]';
                drawerBtn.textContent = 'شروع پیشروی';
            }

            document.getElementById('auto-advance-slim-bar').classList.add('hidden');
            document.getElementById('auto-advance-slim-bar').classList.remove('flex');
            document.getElementById('auto-advance-progress-wrap').classList.add('hidden');
        }

        function computeReadingDurationMs(englishText) {
            const sliderSec = parseInt(document.getElementById('auto-advance-speed')?.value || '8', 10) || 8;
            let totalSec = sliderSec;

            if (selectedEngine === 'ai') {
                const words = String(englishText || '').trim().split(/\s+/).filter(Boolean).length;
                const aiExtraSec = Math.min(8, Math.max(3, Math.round(words / 12)));
                totalSec += aiExtraSec;
            }

            return Math.max(3000, totalSec * 1000);
        }

        async function prefetchNextSegmentInBackground(nextIdx) {
            if (!paperDocInstance || nextIdx < 0 || nextIdx >= pageSegmentsList.length) return;
            const nextSeg = pageSegmentsList[nextIdx];
            if (!nextSeg || !nextSeg.text) return;

            const cacheKey = `${selectedEngine}_translate_${nextSeg.normText}`;
            if (clientTranslationCache.has(cacheKey)) return;

            try {
                const fd = new FormData();
                fd.append('action', 'translate');
                fd.append('text', nextSeg.text);
                fd.append('engine', selectedEngine);
                fd.append('task_mode', 'translate');

                const res = await fetch('api/api_translate.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    clientTranslationCache.set(cacheKey, data);
                }
            } catch (e) {}
        }

        async function runAutoAdvanceStep(segIdx, sessionId) {
            if (!isAutoAdvanceRunning || sessionId !== autoAdvanceSessionId) return;

            if (segIdx >= pageSegmentsList.length) {
                if (paperDocInstance && currentPaperPage < paperDocInstance.numPages) {
                    document.getElementById('auto-advance-status-text').textContent = `📄 انتقال خودکار به صفحه ${currentPaperPage + 1}...`;
                    currentPaperPage++;
                    await renderPaperPage(currentPaperPage, false);
                    if (!isAutoAdvanceRunning || sessionId !== autoAdvanceSessionId) return;
                    if (pageSegmentsList.length > 0) {
                        runAutoAdvanceStep(0, sessionId);
                    } else {
                        stopAutoAdvance();
                    }
                } else {
                    stopAutoAdvance();
                    alert('🎉 مطالعه خودکار این مقاله به پایان رسید!');
                }
                return;
            }

            const statusEl = document.getElementById('auto-advance-status-text');
            const progBar  = document.getElementById('auto-advance-progress-bar');
            progBar.style.width = '0%';

            statusEl.textContent = (selectedEngine === 'ai')
                ? '🧠 در حال دریافت ترجمه تخصصی هوش مصنوعی...'
                : '⏳ در حال ترجمه بند...';

            await activateSegmentByIndex(segIdx, true);

            if (!isAutoAdvanceRunning || sessionId !== autoAdvanceSessionId) return;

            const ttsSync = document.getElementById('auto-advance-tts-sync')?.checked;
            if (ttsSync && pageSegmentsList[segIdx]) {
                speakTextWithCurrentSettings(pageSegmentsList[segIdx].text);
            }

            startReadingCountdownForAutoAdvance(segIdx, sessionId);
        }

        function startReadingCountdownForAutoAdvance(segIdx, sessionId) {
            const seg = pageSegmentsList[segIdx];
            if (!seg) return;

            const totalWaitMs = computeReadingDurationMs(seg.text);
            const startTime   = Date.now();
            const statusEl    = document.getElementById('auto-advance-status-text');
            const progBar     = document.getElementById('auto-advance-progress-bar');

            if (autoAdvanceTimerId) clearInterval(autoAdvanceTimerId);

            autoAdvanceTimerId = setInterval(() => {
                if (!isAutoAdvanceRunning || sessionId !== autoAdvanceSessionId) {
                    clearInterval(autoAdvanceTimerId);
                    return;
                }

                const elapsed   = Date.now() - startTime;
                const remainSec = Math.max(0, Math.ceil((totalWaitMs - elapsed) / 1000));
                const pct       = Math.min(100, (elapsed / totalWaitMs) * 100);

                progBar.style.width = `${pct}%`;

                const ttsSync       = document.getElementById('auto-advance-tts-sync')?.checked;
                const isSpeakingNow = ttsSync && ('speechSynthesis' in window) && window.speechSynthesis.speaking;

                if (elapsed < totalWaitMs) {
                    statusEl.textContent = `⏱️ ${remainSec} ثانیه تا بند بعدی (${segIdx + 1}/${pageSegmentsList.length})`;
                } else if (isSpeakingNow) {
                    statusEl.textContent = `🔊 منتظر اتمام روخوانی صوتی بند...`;
                } else {
                    clearInterval(autoAdvanceTimerId);
                    autoAdvanceTimerId = null;
                    runAutoAdvanceStep(segIdx + 1, sessionId);
                }
            }, 100);
        }

        // ====================================================
        // پلیر صوتی پیشرفته چندگوینده (Multi-Voice & Multi-Speed TTS)
        // ====================================================
        let availableEnglishVoices = [];

        function populateVoiceList() {
            if (!('speechSynthesis' in window)) return;
            const allVoices = window.speechSynthesis.getVoices() || [];
            availableEnglishVoices = allVoices.filter(v => /^en(-|_|$)/i.test(v.lang));
            if (availableEnglishVoices.length === 0) {
                availableEnglishVoices = allVoices;
            }

            const selectEl = document.getElementById('tts-voice-select');
            if (!selectEl) return;

            const savedVoiceURI = localStorage.getItem('paper_tts_voice_uri') || '';
            let html = '<option value="">🎙️ گوینده پیش‌فرض سیستم</option>';

            availableEnglishVoices.forEach(v => {
                const cleanName = v.name.replace(/Microsoft |Google |Apple /g, '');
                const selectedAttr = (v.voiceURI === savedVoiceURI) ? 'selected' : '';
                html += `<option value="${escapeHtml(v.voiceURI)}" ${selectedAttr}>🎙️ ${escapeHtml(cleanName)} (${escapeHtml(v.lang)})</option>`;
            });
            selectEl.innerHTML = html;

            const savedRate = localStorage.getItem('paper_tts_rate') || '1';
            const rateEl = document.getElementById('tts-rate-select');
            if (rateEl) rateEl.value = savedRate;
        }

        if ('speechSynthesis' in window) {
            populateVoiceList();
            if (speechSynthesis.onvoiceschanged !== undefined) {
                speechSynthesis.onvoiceschanged = populateVoiceList;
            }
        }

        function saveTtsPreferences() {
            const vUri = document.getElementById('tts-voice-select').value;
            const rate = document.getElementById('tts-rate-select').value;
            localStorage.setItem('paper_tts_voice_uri', vUri);
            localStorage.setItem('paper_tts_rate', rate);

            if (window.speechSynthesis && window.speechSynthesis.speaking) {
                stopSpeech();
                playOrResumeSpeech();
            }
        }

        function updateTtsButtonsUI(state) {
            const btnPlay  = document.getElementById('btn-tts-play');
            const btnPause = document.getElementById('btn-tts-pause');
            const btnStop  = document.getElementById('btn-tts-stop');

            if (state === 'playing') {
                btnPlay.innerHTML = '<span>🔊 در حال خواندن...</span>';
                btnPause.classList.remove('hidden');
                btnStop.classList.remove('hidden');
            } else if (state === 'paused') {
                btnPlay.innerHTML = '<span>▶ ادامه</span>';
                btnPause.classList.add('hidden');
                btnStop.classList.remove('hidden');
            } else {
                btnPlay.innerHTML = '<span>▶</span><span>خواندن</span>';
                btnPause.classList.add('hidden');
                btnStop.classList.add('hidden');
            }
        }

        function speakTextWithCurrentSettings(textToSpeak) {
            if (!textToSpeak) return;
            if (!('speechSynthesis' in window)) {
                alert('مرورگر شما از موتور صوتی پشتیبانی نمی‌کند.');
                return;
            }

            window.speechSynthesis.cancel();
            const utter = new SpeechSynthesisUtterance(textToSpeak);
            utter.lang = 'en-US';

            const selectedUri = document.getElementById('tts-voice-select')?.value || '';
            if (selectedUri && availableEnglishVoices.length > 0) {
                const matchedVoice = availableEnglishVoices.find(v => v.voiceURI === selectedUri);
                if (matchedVoice) {
                    utter.voice = matchedVoice;
                    utter.lang  = matchedVoice.lang || 'en-US';
                }
            }

            const rateVal = parseFloat(document.getElementById('tts-rate-select')?.value || '1') || 1.0;
            utter.rate = rateVal;

            utter.onstart = () => updateTtsButtonsUI('playing');
            utter.onend   = () => updateTtsButtonsUI('idle');
            utter.onerror = () => updateTtsButtonsUI('idle');

            window.speechSynthesis.speak(utter);
        }

        function playOrResumeSpeech() {
            if (!('speechSynthesis' in window)) return;
            if (window.speechSynthesis.paused) {
                window.speechSynthesis.resume();
                updateTtsButtonsUI('playing');
                return;
            }
            const text = document.getElementById('source-english-textarea').value.trim();
            if (!text) {
                alert('ابتدا یک جمله از مقاله را انتخاب کنید.');
                return;
            }
            speakTextWithCurrentSettings(text);
        }

        function pauseSpeech() {
            if ('speechSynthesis' in window && window.speechSynthesis.speaking) {
                window.speechSynthesis.pause();
                updateTtsButtonsUI('paused');
            }
        }

        function stopSpeech() {
            if ('speechSynthesis' in window) {
                window.speechSynthesis.cancel();
                updateTtsButtonsUI('idle');
            }
        }

        // ====================================================
        // تشخیص انتخاب تک‌کلمه یا عبارت کوتاه در کادر انگلیسی
        // ====================================================
        let selectedSubstringText = '';

        function checkSubSelectionInTextarea() {
            const ta = document.getElementById('source-english-textarea');
            const bar = document.getElementById('sub-selection-bar');
            if (!ta || !bar) return;

            const start = ta.selectionStart;
            const end   = ta.selectionEnd;
            const sub   = ta.value.substring(start, end).trim();

            if (sub.length >= 2 && sub.length <= 80 && sub.length < ta.value.trim().length) {
                selectedSubstringText = sub;
                document.getElementById('sub-selection-term').textContent = sub;
                bar.classList.remove('hidden');
            } else {
                bar.classList.add('hidden');
            }
        }

        function speakSelectedSubstring() {
            if (selectedSubstringText) {
                speakTextWithCurrentSettings(selectedSubstringText);
            }
        }

        async function translateSelectedSubstring() {
            if (!selectedSubstringText) return;
            const resBox = document.getElementById('sub-selection-result');
            const meanEl = document.getElementById('sub-selection-meaning');
            resBox.classList.remove('hidden');
            meanEl.textContent = `⏳ در حال ترجمه «${selectedSubstringText}»...`;

            try {
                const fd = new FormData();
                fd.append('action', 'translate');
                fd.append('text', selectedSubstringText);
                fd.append('engine', selectedEngine);
                fd.append('task_mode', 'translate');

                const res = await fetch('api/api_translate.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    meanEl.innerHTML = `<span class="text-amber-300 font-mono dir-ltr">${escapeHtml(selectedSubstringText)}</span>: ${escapeHtml(data.translated_text)}`;
                } else {
                    meanEl.textContent = data.message || 'خطا در ترجمه عبارت.';
                }
            } catch (e) {
                meanEl.textContent = 'خطا در ارتباط با سرور.';
            }
        }

        // ====================================================
        // دریافت لیست مقالات و جزوات از سرور
        // ====================================================
        async function fetchPapersAndConfig() {
            try {
                const res = await fetch('api/api_translate.php?action=list_papers');
                const data = await res.json();
                if (data.status === 'success') {
                    papersList   = data.papers || [];
                    bookletsList = data.booklets || [];
                    if (data.subscription) userSub = data.subscription;
                    if (data.ai_config)    aiConfig = data.ai_config;

                    refreshSubscriptionUI();
                    renderPapersSidebar();
                }
            } catch (err) {
                console.error('Error fetching papers:', err);
            }
        }

        function refreshSubscriptionUI() {
            const totalUserFiles = papersList.length + bookletsList.length;
            const banner = document.getElementById('paper-quota-banner');
            if (banner) {
                if (userSub.is_vip) {
                    banner.className = 'px-3 py-1.5 text-[11px] border-b border-emerald-200 bg-emerald-50 text-emerald-900 font-bold flex items-center justify-between';
                    banner.innerHTML = `<span>👑 اشتراک ویژه فعال (${userSub.days_left} روز)</span><button onclick="openSubscriptionModal()" class="text-indigo-700 underline">+تمدید</button>`;
                } else {
                    banner.className = 'px-3 py-1.5 text-[11px] border-b border-amber-200 bg-amber-50 text-amber-900 font-bold flex items-center justify-between';
                    banner.innerHTML = `<span>👤 سهمیه رایگان: ${totalUserFiles} از ${FREE_MAX_PDF_CNT} فایل</span><button onclick="openSubscriptionModal()" class="bg-indigo-600 text-white px-2 py-0.5 rounded text-[10px]">ارتقا 👑</button>`;
                }
            }

            const aiBtn = document.getElementById('eng-btn-ai');
            if (aiBtn) {
                if (!aiConfig.enabled) {
                    aiBtn.classList.add('opacity-50');
                    document.getElementById('ai-engine-sublabel').textContent = 'غیرفعال';
                } else {
                    aiBtn.classList.remove('opacity-50');
                    document.getElementById('ai-engine-sublabel').textContent = userSub.is_vip ? '✨ VIP' : '🔒 VIP';
                }
            }
        }

        function renderPapersSidebar() {
            const container = document.getElementById('papers-list-container');
            if (papersList.length === 0) {
                container.innerHTML = `
                    <div class="text-center text-slate-400 text-xs py-10 px-3 space-y-2">
                        <div>هنوز مقاله‌ای در پوشه مقالات ندارید.</div>
                        <div class="text-[11px]">می‌توانید مقاله جدید آپلود کنید یا از فایل‌های جزوه‌خوان وارد نمایید.</div>
                    </div>
                `;
                return;
            }

            container.innerHTML = papersList.map(item => {
                const isActive = activePaperMeta && Number(activePaperMeta.id) === Number(item.id);
                const total = Math.max(1, Number(item.total_pages));
                const last  = Math.min(total, Math.max(1, Number(item.last_page)));
                const isLocked = !!item.is_locked;

                return `
                    <div onclick="loadAndOpenPaper(${item.id}, ${last})" class="p-3 rounded-xl border transition cursor-pointer ${isLocked ? 'border-amber-300 bg-amber-50/50 opacity-80' : (isActive ? 'border-indigo-600 bg-indigo-50/80 ring-1 ring-indigo-500' : 'border-slate-200 hover:border-indigo-300 bg-white')}">
                        <div class="flex items-start justify-between gap-1.5">
                            <div class="min-w-0">
                                <h4 class="text-xs font-extrabold text-slate-800 leading-snug truncate" dir="auto">${isLocked ? '🔒 ' : '📄 '}${escapeHtml(item.title)}</h4>
                                ${isLocked ? `<span class="inline-block mt-1 text-[10px] bg-amber-200 text-amber-950 px-1.5 py-0.5 rounded font-bold">نیاز به تمدید اشتراک</span>` : ''}
                            </div>
                            <button type="button" onclick="deletePaperItem(event, ${item.id})" class="text-slate-400 hover:text-rose-600 text-xs px-1 shrink-0" title="حذف مقاله">🗑️</button>
                        </div>
                        <div class="mt-2 flex justify-between text-[10px] text-slate-500">
                            <span>صفحه ${last} از ${total}</span>
                            <span class="text-indigo-600 font-bold">باز کردن ❮</span>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // ====================================================
        // بارگذاری باینری مقاله
        // ====================================================
        async function loadAndOpenPaper(pdfId, targetPage = null) {
            if (papersList.length === 0) await fetchPapersAndConfig();
            const target = papersList.find(p => Number(p.id) === Number(pdfId));
            if (!target) return;

            if (target.is_locked) {
                openSubscriptionModal(`مقاله «${target.title}» به دلیل اتمام مهلت اشتراک ویژه قفل شده است. لطفاً اشتراک خود را تمدید کنید.`);
                return;
            }

            stopAutoAdvance();
            activePaperMeta = target;
            visitedSegNorms.clear();
            closePaperSidebarMobile();

            document.getElementById('active-paper-title').textContent = activePaperMeta.title;
            document.getElementById('paper-empty-state').classList.add('hidden');
            document.getElementById('paper-canvas-wrapper').classList.add('hidden');
            document.getElementById('paper-loading-overlay').classList.remove('hidden');

            try {
                const pdfjs = await ensurePdfJsReady();

                const rawResponse = await fetch(`api/api_translate.php?action=raw&pdf_id=${activePaperMeta.id}&t=${Date.now()}`, {
                    cache: 'no-store',
                    credentials: 'same-origin'
                });

                if (rawResponse.status === 403) {
                    document.getElementById('paper-loading-overlay').classList.add('hidden');
                    document.getElementById('paper-empty-state').classList.remove('hidden');
                    openSubscriptionModal('این مقاله نیازمند اشتراک ویژه است.');
                    return;
                }

                if (!rawResponse.ok) {
                    throw new Error(`خطا در دریافت فایل مقاله (کد ${rawResponse.status})`);
                }

                const arrayBuffer = await rawResponse.arrayBuffer();
                const pdfBytes = new Uint8Array(arrayBuffer);

                if (paperDocInstance && typeof paperDocInstance.destroy === 'function') {
                    await paperDocInstance.destroy();
                }

                const loadingTask = pdfjs.getDocument({
                    data: pdfBytes,
                    cMapUrl: activeCdnConfig.cmaps,
                    cMapPacked: true,
                    standardFontDataUrl: activeCdnConfig.fonts,
                    isEvalSupported: true,
                    disableFontFace: false,
                    useSystemFonts: true,
                    disableAutoFetch: true,
                    disableStream: true,
                    disableRange: true
                });

                paperDocInstance = await loadingTask.promise;

                const totalPages = paperDocInstance.numPages;
                activePaperMeta.total_pages = totalPages;
                document.getElementById('paper-total-pages').textContent = totalPages;
                document.getElementById('paper-page-input').max = totalPages;

                currentPaperPage = targetPage
                    ? Math.min(totalPages, Math.max(1, Number(targetPage)))
                    : Math.min(totalPages, Math.max(1, Number(activePaperMeta.last_page)));

                document.getElementById('paper-loading-overlay').classList.add('hidden');
                document.getElementById('paper-canvas-wrapper').classList.remove('hidden');
                document.getElementById('paper-page-controls').classList.remove('hidden');
                document.getElementById('paper-page-controls').classList.add('flex');
                document.getElementById('btn-top-auto-advance').classList.remove('hidden');
                document.getElementById('btn-top-auto-advance').classList.add('flex');

                renderPapersSidebar();
                await fetchPaperNotes(activePaperMeta.id);
                await renderPaperPage(currentPaperPage, false);
            } catch (err) {
                document.getElementById('paper-loading-overlay').classList.add('hidden');
                document.getElementById('paper-empty-state').classList.remove('hidden');
                alert('خطا در باز کردن مقاله: ' + err.message);
            }
        }

        async function renderPaperPage(num, preserveActiveSegment = false) {
            if (!paperDocInstance) return;

            if (isRenderingPaper) {
                pendingPaperPage = num;
                if (activeRenderTask && typeof activeRenderTask.cancel === 'function') {
                    try { activeRenderTask.cancel(); } catch (e) {}
                }
                return;
            }
            isRenderingPaper = true;
            const prevActiveIdx = preserveActiveSegment ? activeSegmentIndex : -1;

            try {
                const page = await paperDocInstance.getPage(num);
                const container = document.getElementById('paper-viewport-container');
                const wrapper   = document.getElementById('paper-canvas-wrapper');
                const canvas    = document.getElementById('paper-render-canvas');
                const paraLayer = document.getElementById('paper-paragraphs-layer');
                const ctx       = canvas.getContext('2d');

                const unscaledViewport = page.getViewport({ scale: 1 });
                unscaledPageW = unscaledViewport.width;
                unscaledPageH = unscaledViewport.height;

                const pad = window.innerWidth < 640 ? 4 : 28;
                const availableWidth = Math.max(260, container.clientWidth - pad);
                fitWidthBaseScale = Math.min(availableWidth / unscaledPageW, 1.85);

                let scale = paperCustomScale;
                if (paperZoomMode === 'fit-width') {
                    scale = fitWidthBaseScale;
                    paperCustomScale = scale;
                }

                const viewport = page.getViewport({ scale: scale });
                const maxSafeDpr = window.innerWidth < 768 ? 1.85 : 2.0;
                let outputScale = Math.min(window.devicePixelRatio || 1, maxSafeDpr);

                const maxCanvasDim = 3800;
                if (viewport.width * outputScale > maxCanvasDim || viewport.height * outputScale > maxCanvasDim) {
                    outputScale = Math.min(maxCanvasDim / viewport.width, maxCanvasDim / viewport.height);
                }

                const cssWidth  = Math.round(viewport.width);
                const cssHeight = Math.round(viewport.height);

                wrapper.style.width    = cssWidth + "px";
                wrapper.style.height   = cssHeight + "px";
                canvas.width           = Math.floor(cssWidth * outputScale);
                canvas.height          = Math.floor(cssHeight * outputScale);
                paraLayer.innerHTML    = '';

                ctx.save();
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.restore();

                ctx.direction = 'ltr';
                ctx.textAlign = 'left';

                const transform = outputScale !== 1 ? [outputScale, 0, 0, outputScale, 0, 0] : null;
                activeRenderTask = page.render({ canvasContext: ctx, transform, viewport });
                await activeRenderTask.promise;
                activeRenderTask = null;

                document.getElementById('paper-page-input').value = num;

                await buildInteractiveSegments(page, viewport);

                if (prevActiveIdx >= 0 && prevActiveIdx < pageSegmentsList.length) {
                    activeSegmentIndex = prevActiveIdx;
                    document.querySelectorAll(`.seg-box[data-seg-idx="${prevActiveIdx}"]`).forEach(el => {
                        el.classList.add('active-seg', 'read-seg');
                    });
                    updateSegmentPositionLabel();
                }

                savePaperProgress(activePaperMeta.id, num, paperDocInstance.numPages);
            } catch (err) {
                if (err && err.name !== 'RenderingCancelledException') {
                    console.error('Paper render error:', err);
                }
            } finally {
                isRenderingPaper = false;
                if (pendingPaperPage !== null) {
                    const next = pendingPaperPage;
                    pendingPaperPage = null;
                    await renderPaperPage(next, preserveActiveSegment);
                }
            }
        }

        // ====================================================
        // موتور تشخیص جملات با مختصات بومی کلمات و رندر درصدی (%)
        // ====================================================
        function isTrueSentenceEnd(lineText, dotIndex) {
            const ch = lineText[dotIndex];
            if (ch === '?' || ch === '!') return true;

            const afterChar = lineText[dotIndex + 1] || '';
            if (afterChar && !/[\s"'\)\]\}]/.test(afterChar)) {
                return false;
            }

            const beforeStr = lineText.slice(0, dotIndex).trimEnd();
            const wordMatch = beforeStr.match(/([a-zA-Z0-9\.]+)$/);
            if (!wordMatch) return true;

            const lastWord = wordMatch[1];
            const cleanWord = lastWord.replace(/\./g, '').toLowerCase();

            const academicAbbrevs = new Set([
                'fig', 'figs', 'eq', 'eqs', 'sec', 'secs', 'tab', 'tabs', 'ref', 'refs',
                'al', 'eg', 'ie', 'vs', 'etc', 'dr', 'prof', 'mr', 'mrs', 'ms', 'inc',
                'ltd', 'co', 'no', 'nos', 'vol', 'vols', 'pp', 'ch', 'st', 'ave', 'approx',
                'dept', 'univ', 'assn', 'proc', 'trans', 'conf', 'symp', 'intl', 'jan',
                'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep', 'sept', 'oct', 'nov', 'dec'
            ]);

            if (academicAbbrevs.has(cleanWord)) return false;
            if (/^[A-Z]$/.test(lastWord)) return false;
            if (lastWord.includes('.')) return false;

            return true;
        }

        function splitLineIntoExactSentencePieces(ln) {
            const text = ln.text;
            const len  = text.length;
            if (len === 0) return [];

            const splitIndices = [];
            for (let i = 0; i < len; i++) {
                const c = text[i];
                if (c === '.' || c === '?' || c === '!') {
                    if (isTrueSentenceEnd(text, i)) {
                        let endPos = i + 1;
                        while (endPos < len && /[\)"'\]\}]/.test(text[endPos])) {
                            endPos++;
                        }
                        splitIndices.push(endPos);
                        i = endPos - 1;
                    }
                }
            }

            function computeSpanBounds(charStart, charEnd) {
                let minX = null;
                let maxR = null;
                if (Array.isArray(ln.spans) && ln.spans.length > 0) {
                    ln.spans.forEach(sp => {
                        if (sp.endChar > charStart && sp.startChar < charEnd) {
                            const spChars  = Math.max(1, sp.endChar - sp.startChar);
                            const spW      = Math.max(2, sp.right - sp.x);
                            const localS   = Math.max(0, charStart - sp.startChar);
                            const localE   = Math.min(spChars, charEnd - sp.startChar);
                            const subLeft  = sp.x + (spW * (localS / spChars));
                            const subRight = sp.x + (spW * (localE / spChars));
                            if (minX === null || subLeft < minX) minX = subLeft;
                            if (maxR === null || subRight > maxR) maxR = subRight;
                        }
                    });
                }
                const totalW = Math.max(8, ln.right - ln.x);
                if (minX === null) minX = ln.x + totalW * (charStart / len);
                if (maxR === null) maxR = ln.x + totalW * (charEnd / len);
                return { x: minX, w: Math.max(8, maxR - minX) };
            }

            const pieces = [];
            let startIdx = 0;
            const boxH = Math.max(8, ln.bottom - ln.y);

            splitIndices.forEach(endIdx => {
                const subStr = text.slice(startIdx, endIdx).trim();
                if (subStr.length > 0) {
                    const bounds = computeSpanBounds(startIdx, endIdx);
                    pieces.push({
                        text: subStr,
                        endsSentence: true,
                        x: bounds.x,
                        y: ln.y,
                        w: bounds.w,
                        h: boxH
                    });
                }
                startIdx = endIdx;
                while (startIdx < len && text[startIdx] === ' ') startIdx++;
            });

            if (startIdx < len) {
                const remStr = text.slice(startIdx).trim();
                if (remStr.length > 0) {
                    const bounds = computeSpanBounds(startIdx, len);
                    pieces.push({
                        text: remStr,
                        endsSentence: false,
                        x: bounds.x,
                        y: ln.y,
                        w: bounds.w,
                        h: boxH
                    });
                }
            }

            return pieces;
        }

        async function buildInteractiveSegments(page, viewport) {
            const paraLayer = document.getElementById('paper-paragraphs-layer');
            paraLayer.innerHTML = '';
            pageSegmentsList = [];
            activeSegmentIndex = -1;

            const textContent = await page.getTextContent();
            const rawItems = textContent.items || [];
            const pageW = viewport.width;
            const pageH = viewport.height;
            const centerX = pageW * 0.50;

            const fragments = [];
            rawItems.forEach(item => {
                const str = item.str || '';
                if (!str.trim()) return;

                const tx = window.pdfjsLib.Util.transform(viewport.transform, item.transform);
                const fontHeight = Math.max(6, Math.hypot(tx[2], tx[3]));
                const x = tx[4];
                const y = tx[5] - (fontHeight * 0.80);
                const w = Math.max(fontHeight * 0.35, (item.width || 0) * viewport.scale);
                const h = fontHeight * 0.96;

                if (x >= -10 && y >= -10 && x <= pageW + 10 && y <= pageH + 10) {
                    fragments.push({
                        str: str.trim(),
                        x: x,
                        y: y,
                        right: x + w,
                        bottom: y + h,
                        fontH: fontHeight
                    });
                }
            });

            if (fragments.length === 0) {
                document.getElementById('detected-blocks-badge').textContent = 'صفحه تصویری';
                return;
            }

            fragments.sort((a, b) => (Math.abs(a.y - b.y) <= Math.min(a.fontH, b.fontH) * 0.42) ? (a.x - b.x) : (a.y - b.y));

            const lines = [];
            fragments.forEach(frag => {
                let added = false;
                for (let i = lines.length - 1; i >= Math.max(0, lines.length - 8); i--) {
                    const ln = lines[i];
                    const avgFont = (ln.fontH + frag.fontH) * 0.5;
                    const sameVertical = Math.abs(frag.y - ln.y) <= avgFont * 0.48;
                    const horizGap = frag.x - ln.right;

                    const crossesCenterGutter = (ln.right <= pageW * 0.515 && frag.x >= pageW * 0.485 && horizGap > avgFont * 0.65);

                    if (sameVertical && !crossesCenterGutter && horizGap >= -12 && horizGap <= avgFont * 1.45) {
                        const needSpace = (horizGap > avgFont * 0.12 && !ln.text.endsWith(' ') && !frag.str.startsWith(' '));
                        if (needSpace) ln.text += ' ';
                        const sChar = ln.text.length;
                        ln.text += frag.str;
                        const eChar = ln.text.length;

                        ln.spans.push({ startChar: sChar, endChar: eChar, x: frag.x, right: frag.right });
                        ln.x = Math.min(ln.x, frag.x);
                        ln.y = Math.min(ln.y, frag.y);
                        ln.right = Math.max(ln.right, frag.right);
                        ln.bottom = Math.max(ln.bottom, frag.bottom);
                        ln.fontH = avgFont;
                        added = true;
                        break;
                    }
                }

                if (!added) {
                    lines.push({
                        text: frag.str,
                        x: frag.x,
                        y: frag.y,
                        right: frag.right,
                        bottom: frag.bottom,
                        fontH: frag.fontH,
                        spans: [{ startChar: 0, endChar: frag.str.length, x: frag.x, right: frag.right }]
                    });
                }
            });

            lines.forEach(ln => {
                const lineW = ln.right - ln.x;
                const spansAcrossCenter = (ln.x < pageW * 0.38 && ln.right > pageW * 0.62 && lineW > pageW * 0.44);
                if (spansAcrossCenter) {
                    ln.colZone = 'full';
                } else {
                    const lineMidX = (ln.x + ln.right) * 0.5;
                    ln.colZone = (lineMidX < centerX) ? 'left' : 'right';
                }
            });

            const splitColumnLines = lines.filter(l => l.colZone === 'left' || l.colZone === 'right');
            const firstTwoColY = splitColumnLines.length > 0 ? Math.min(...splitColumnLines.map(l => l.y)) : pageH;

            function getReadingZoneOrder(ln) {
                if (ln.colZone === 'full') {
                    return (ln.y < firstTwoColY + 25) ? 0 : 3;
                }
                return (ln.colZone === 'left') ? 1 : 2;
            }

            lines.sort((a, b) => {
                const zA = getReadingZoneOrder(a);
                const zB = getReadingZoneOrder(b);
                if (zA !== zB) return zA - zB;
                return a.y - b.y;
            });

            const paragraphs = [];
            lines.forEach(ln => {
                const cleanTxt = ln.text.trim();
                if (!cleanTxt) return;

                let merged = false;
                if (paragraphs.length > 0) {
                    const prev = paragraphs[paragraphs.length - 1];
                    const lastLine = prev.lines[prev.lines.length - 1];

                    if (prev.colZone === ln.colZone) {
                        const vertGap = ln.y - lastLine.bottom;
                        const maxFont = Math.max(lastLine.fontH, ln.fontH);
                        const tightVert = (vertGap >= -4 && vertGap <= maxFont * 0.72);
                        const sameFont  = Math.abs(lastLine.fontH - ln.fontH) <= 1.6;

                        const prevLineW = lastLine.right - lastLine.x;
                        const paraMaxW  = Math.max(prevLineW, prev.maxRight - prev.minLeft);
                        const prevEndedShort = (paraMaxW > maxFont * 8) && (lastLine.right < prev.maxRight - maxFont * 2.2) && /[.?!:]$/.test(lastLine.text.trim());
                        const hasNewIndent = (prev.lines.length >= 2) && (ln.x > prev.minLeft + maxFont * 0.85);
                        const isSectionHeader = /^(\d+(\.\d+)*|[IVX]+\.)\s+[A-Z]/.test(cleanTxt) && cleanTxt.length < 65;

                        if (tightVert && sameFont && !prevEndedShort && !hasNewIndent && !isSectionHeader) {
                            prev.lines.push(ln);
                            prev.minLeft  = Math.min(prev.minLeft, ln.x);
                            prev.maxRight = Math.max(prev.maxRight, ln.right);
                            merged = true;
                        }
                    }
                }

                if (!merged) {
                    paragraphs.push({
                        colZone: ln.colZone,
                        minLeft: ln.x,
                        maxRight: ln.right,
                        lines: [ln]
                    });
                }
            });

            if (selectionGranularity === 'paragraph') {
                paragraphs.forEach(para => {
                    let combinedText = '';
                    const boxes = [];
                    para.lines.forEach(ln => {
                        const t = ln.text.trim();
                        if (combinedText.endsWith('-')) {
                            combinedText = combinedText.slice(0, -1) + t;
                        } else {
                            combinedText += (combinedText ? ' ' : '') + t;
                        }
                        boxes.push({
                            x: ln.x,
                            y: ln.y,
                            w: Math.max(10, ln.right - ln.x),
                            h: Math.max(8, ln.bottom - ln.y)
                        });
                    });
                    if (combinedText.length >= 3) {
                        pageSegmentsList.push({
                            text: combinedText,
                            colZone: para.colZone,
                            boxes: boxes
                        });
                    }
                });
            } else {
                paragraphs.forEach(para => {
                    let currentSentText = '';
                    let currentSentBoxes = [];

                    para.lines.forEach(ln => {
                        const pieces = splitLineIntoExactSentencePieces(ln);
                        pieces.forEach(pc => {
                            if (currentSentText.endsWith('-')) {
                                currentSentText = currentSentText.slice(0, -1) + pc.text;
                            } else {
                                currentSentText += (currentSentText ? ' ' : '') + pc.text;
                            }
                            currentSentBoxes.push({
                                x: pc.x,
                                y: pc.y,
                                w: pc.w,
                                h: pc.h
                            });

                            if (pc.endsSentence) {
                                if (currentSentText.trim().length >= 3) {
                                    pageSegmentsList.push({
                                        text: currentSentText.trim(),
                                        colZone: para.colZone,
                                        boxes: currentSentBoxes
                                    });
                                }
                                currentSentText = '';
                                currentSentBoxes = [];
                            }
                        });
                    });

                    if (currentSentText.trim().length >= 3 && currentSentBoxes.length > 0) {
                        pageSegmentsList.push({
                            text: currentSentText.trim(),
                            colZone: para.colZone,
                            boxes: currentSentBoxes
                        });
                    }
                });
            }

            pageSegmentsList.forEach((seg, segIdx) => {
                const normStr = normalizeClientEnglishText(seg.text);
                seg.normText = normStr;

                seg.boxes.forEach((bx, bIdx) => {
                    const div = document.createElement('div');
                    div.className = 'seg-box';
                    div.dataset.segIdx = String(segIdx);
                    if (bIdx === 0) div.dataset.isFirstBox = '1';

                    const leftPct   = Math.max(0, (bx.x / pageW) * 100);
                    const topPct    = Math.max(0, (bx.y / pageH) * 100);
                    const widthPct  = Math.min(100 - leftPct, (bx.w / pageW) * 100);
                    const heightPct = Math.min(100 - topPct, (bx.h / pageH) * 100);

                    div.style.left   = leftPct.toFixed(3) + '%';
                    div.style.top    = topPct.toFixed(3) + '%';
                    div.style.width  = widthPct.toFixed(3) + '%';
                    div.style.height = heightPct.toFixed(3) + '%';

                    if (visitedSegNorms.has(normStr)) {
                        div.classList.add('read-seg');
                    }

                    div.onmouseenter = () => {
                        document.querySelectorAll(`.seg-box[data-seg-idx="${segIdx}"]`).forEach(el => el.classList.add('hover-seg'));
                    };
                    div.onmouseleave = () => {
                        document.querySelectorAll(`.seg-box[data-seg-idx="${segIdx}"]`).forEach(el => el.classList.remove('hover-seg'));
                    };

                    div.onclick = (e) => {
                        e.stopPropagation();
                        if (isAutoAdvanceRunning) {
                            autoAdvanceSessionId++;
                            if (autoAdvanceTimerId) clearInterval(autoAdvanceTimerId);
                            runAutoAdvanceStep(segIdx, autoAdvanceSessionId);
                        } else {
                            activateSegmentByIndex(segIdx, false);
                        }
                    };

                    paraLayer.appendChild(div);
                });
            });

            refreshNotedSegmentsHighlight();
            const unitLabel = (selectionGranularity === 'sentence') ? 'جمله' : 'بند';
            document.getElementById('detected-blocks-badge').textContent = `${pageSegmentsList.length} ${unitLabel}`;
            updateSegmentPositionLabel();
        }

        async function activateSegmentByIndex(segIdx, fromAutoAdvance = false) {
            if (segIdx < 0 || segIdx >= pageSegmentsList.length) return;
            activeSegmentIndex = segIdx;
            const seg = pageSegmentsList[segIdx];

            document.querySelectorAll('.seg-box').forEach(el => el.classList.remove('active-seg'));
            const matchingBoxes = document.querySelectorAll(`.seg-box[data-seg-idx="${segIdx}"]`);
            matchingBoxes.forEach(el => {
                el.classList.add('active-seg', 'read-seg');
            });

            visitedSegNorms.add(seg.normText);
            updateSegmentPositionLabel();

            stopSpeech();
            const textarea = document.getElementById('source-english-textarea');
            textarea.value = seg.text;
            document.getElementById('sub-selection-bar').classList.add('hidden');
            document.getElementById('sub-selection-result').classList.add('hidden');

            switchTranslatorTab('translate');
            if (window.innerWidth < 768 && (mobileSheetState === 'closed' || mobileSheetState === 'minimized')) {
                setMobileSheetState('half');
            }

            if (matchingBoxes.length > 0) {
                setTimeout(() => scrollActiveBoxIntoVisibleArea(matchingBoxes[0]), 50);
            }

            await runTranslationTask('translate');

            prefetchNextSegmentInBackground(segIdx + 1);
        }

        function stepSegment(delta) {
            if (pageSegmentsList.length === 0) return;
            let nextIdx = activeSegmentIndex + delta;
            if (activeSegmentIndex === -1) nextIdx = 0;
            if (nextIdx >= 0 && nextIdx < pageSegmentsList.length) {
                if (isAutoAdvanceRunning) {
                    autoAdvanceSessionId++;
                    if (autoAdvanceTimerId) clearInterval(autoAdvanceTimerId);
                    runAutoAdvanceStep(nextIdx, autoAdvanceSessionId);
                } else {
                    activateSegmentByIndex(nextIdx, false);
                }
            }
        }

        function updateSegmentPositionLabel() {
            const lbl = document.getElementById('current-seg-Position-label');
            if (!lbl) return;
            if (pageSegmentsList.length === 0 || activeSegmentIndex === -1) {
                lbl.textContent = `0/${pageSegmentsList.length}`;
                return;
            }
            const seg = pageSegmentsList[activeSegmentIndex];
            const zoneTitle = seg.colZone === 'left' ? 'چپ' : (seg.colZone === 'right' ? 'راست' : '');
            lbl.textContent = `${activeSegmentIndex + 1}/${pageSegmentsList.length}${zoneTitle ? ' (' + zoneTitle + ')' : ''}`;
        }

        function refreshNotedSegmentsHighlight() {
            const pageNotesNorms = new Set(
                paperNotes
                    .filter(n => Number(n.page_number) === Number(currentPaperPage))
                    .map(n => normalizeClientEnglishText(n.original_text))
            );

            document.querySelectorAll('.seg-box').forEach(el => {
                const idx = Number(el.dataset.segIdx);
                const seg = pageSegmentsList[idx];
                const norm = seg ? seg.normText : '';
                if (norm && pageNotesNorms.has(norm)) {
                    el.classList.add('noted-seg');
                    if (el.dataset.isFirstBox === '1') el.classList.add('noted-seg-first');
                } else {
                    el.classList.remove('noted-seg', 'noted-seg-first');
                }
            });
        }

        // ====================================================
        // اجرای ترجمه یا خلاصه‌سازی علمی (با پشتیبانی از کش آنی Pre-fetch)
        // ====================================================
        function selectTranslationEngine(engine) {
            selectedEngine = engine;
            ['google', 'mymemory', 'ai'].forEach(eng => {
                const btn = document.getElementById('eng-btn-' + eng);
                if (!btn) return;
                if (eng === engine) {
                    btn.className = 'eng-btn py-1.5 px-2 rounded-xl font-extrabold border text-[11px] bg-indigo-600 text-white border-indigo-600 shadow-2xs transition relative';
                } else {
                    btn.className = 'eng-btn py-1.5 px-2 rounded-xl font-bold border text-[11px] bg-slate-50 text-slate-700 border-slate-200 hover:border-indigo-300 transition relative';
                }
            });

            const currentTxt = document.getElementById('source-english-textarea').value.trim();
            if (currentTxt) {
                if (isAutoAdvanceRunning && activeSegmentIndex >= 0) {
                    autoAdvanceSessionId++;
                    if (autoAdvanceTimerId) clearInterval(autoAdvanceTimerId);
                    runAutoAdvanceStep(activeSegmentIndex, autoAdvanceSessionId);
                } else {
                    runTranslationTask('translate');
                }
            }
        }

        function renderTranslationDataToUI(data, sourceText, taskMode, fromInstantCache = false) {
            const outputEl  = document.getElementById('translated-persian-output');
            const metaBadge = document.getElementById('translation-meta-badge');
            const kwBox     = document.getElementById('extracted-keywords-box');
            const kwList    = document.getElementById('extracted-keywords-list');
            const saveBar   = document.getElementById('save-note-action-bar');

            outputEl.textContent = data.translated_text;
            lastTranslatedObj = {
                original: data.clean_source || sourceText,
                translated: data.translated_text,
                para_hash: data.para_hash || ''
            };

            const cacheTag = (data.cached || fromInstantCache) ? ' • آماده ⚡' : '';
            metaBadge.textContent = `${data.engine_used}${cacheTag}`;
            metaBadge.classList.remove('hidden');

            if (Array.isArray(data.keywords) && data.keywords.length > 0) {
                kwList.innerHTML = data.keywords.map(kw => `
                    <button type="button" onclick="speakTextWithCurrentSettings('${escapeHtml(kw.en)}')" class="px-2 py-0.5 bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg text-[10px] font-bold text-slate-800 flex items-center gap-1 transition shadow-2xs">
                        <span class="text-indigo-700 font-mono dir-ltr">${escapeHtml(kw.en)}</span>
                        <span class="text-slate-400">:</span>
                        <span>${escapeHtml(kw.fa)}</span>
                        <span class="text-[9px] opacity-70">🔊</span>
                    </button>
                `).join('');
                kwBox.classList.remove('hidden');
            } else {
                kwBox.classList.add('hidden');
            }

            if (activePaperMeta) {
                saveBar.classList.remove('hidden');
            }
        }

        async function runTranslationTask(taskMode = 'translate') {
            const sourceText = document.getElementById('source-english-textarea').value.trim();
            if (!sourceText) return;

            const normKey  = normalizeClientEnglishText(sourceText);
            const cacheKey = `${selectedEngine}_${taskMode}_${normKey}`;

            const loadingEl = document.getElementById('translation-loading');
            const loadingTx = document.getElementById('translation-loading-text');
            const outputEl  = document.getElementById('translated-persian-output');
            const titleEl   = document.getElementById('output-box-title');
            const metaBadge = document.getElementById('translation-meta-badge');
            const kwBox     = document.getElementById('extracted-keywords-box');
            const saveBar   = document.getElementById('save-note-action-bar');

            titleEl.textContent = (taskMode === 'summarize') ? '✨ پیام کلیدی بند:' : '🇮🇷 ترجمه فارسی:';

            if (clientTranslationCache.has(cacheKey)) {
                loadingEl.classList.add('hidden');
                outputEl.classList.remove('hidden');
                renderTranslationDataToUI(clientTranslationCache.get(cacheKey), sourceText, taskMode, true);
                return;
            }

            loadingTx.textContent = (selectedEngine === 'ai' || taskMode === 'summarize')
                ? '🧠 در حال ترجمه تخصصی با هوش مصنوعی...'
                : '⏳ در حال ترجمه...';

            loadingEl.classList.remove('hidden');
            outputEl.classList.add('hidden');
            kwBox.classList.add('hidden');
            saveBar.classList.add('hidden');
            metaBadge.classList.add('hidden');

            try {
                const fd = new FormData();
                fd.append('action', 'translate');
                fd.append('text', sourceText);
                fd.append('engine', selectedEngine);
                fd.append('task_mode', taskMode);

                const res = await fetch('api/api_translate.php', { method: 'POST', body: fd });
                const data = await res.json();

                loadingEl.classList.add('hidden');
                outputEl.classList.remove('hidden');

                if (data.status === 'success') {
                    clientTranslationCache.set(cacheKey, data);
                    renderTranslationDataToUI(data, sourceText, taskMode, false);
                } else if (data.need_vip) {
                    if (isAutoAdvanceRunning) stopAutoAdvance();
                    outputEl.textContent = data.message;
                    openSubscriptionModal(data.message);
                } else {
                    outputEl.textContent = '⚠️ ' + (data.message || 'خطا در پردازش متن.');
                }
            } catch (err) {
                loadingEl.classList.add('hidden');
                outputEl.classList.remove('hidden');
                outputEl.textContent = 'خطا در ارتباط با سرور ترجمه.';
            }
        }

        function copyTranslatedText() {
            const text = document.getElementById('translated-persian-output').textContent.trim();
            if (!text) return;
            navigator.clipboard.writeText(text).then(() => {
                alert('متن فارسی کپی شد.');
            });
        }

        // ====================================================
        // مدیریت فیش‌های پژوهشی مقاله + خروجی Word (.doc) و PDF
        // ====================================================
        async function fetchPaperNotes(pdfId) {
            if (!pdfId) return;
            const res = await fetch(`api/api_translate.php?action=list_notes&pdf_id=${pdfId}`);
            const data = await res.json();
            if (data.status === 'success') {
                paperNotes = data.notes || [];
                document.getElementById('notes-count-badge').textContent = paperNotes.length;
                renderPaperNotesList();
                refreshNotedSegmentsHighlight();
            }
        }

        async function saveCurrentTranslationAsNote() {
            if (!activePaperMeta || !lastTranslatedObj) return;
            const comment = document.getElementById('note-user-comment').value.trim();

            const fd = new FormData();
            fd.append('action', 'save_note');
            fd.append('pdf_id', activePaperMeta.id);
            fd.append('page_number', currentPaperPage);
            fd.append('para_hash', lastTranslatedObj.para_hash || '');
            fd.append('original_text', lastTranslatedObj.original);
            fd.append('translated_text', lastTranslatedObj.translated);
            fd.append('user_comment', comment);

            const res = await fetch('api/api_translate.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                document.getElementById('note-user-comment').value = '';
                await fetchPaperNotes(activePaperMeta.id);
                refreshNotedSegmentsHighlight();
                alert('📌 در فیش‌های مقاله ذخیره شد و روی صفحه با رنگ سبز علامت خورد.');
            } else {
                alert(data.message || 'خطا در ذخیره فیش');
            }
        }

        function renderPaperNotesList() {
            const listEl = document.getElementById('paper-notes-list');
            const query  = (document.getElementById('notes-search-input')?.value || '').trim().toLowerCase();

            if (paperNotes.length === 0) {
                listEl.innerHTML = `<div class="text-center text-slate-400 py-8">هنوز فیشی برای این مقاله ذخیره نکرده‌اید.</div>`;
                return;
            }

            const filtered = paperNotes.filter(n => {
                if (!query) return true;
                return String(n.original_text).toLowerCase().includes(query) ||
                       String(n.translated_text).toLowerCase().includes(query) ||
                       String(n.user_comment || '').toLowerCase().includes(query);
            });

            if (filtered.length === 0) {
                listEl.innerHTML = `<div class="text-center text-slate-400 py-8">هیچ فیشی با عبارت جستجو شده مطابقت ندارد.</div>`;
                return;
            }

            listEl.innerHTML = filtered.map((n, idx) => `
                <div class="p-2.5 bg-slate-50 rounded-2xl border border-slate-200 space-y-1.5">
                    <div class="flex items-center justify-between">
                        <button type="button" onclick="jumpToPaperPage(${n.page_number})" class="bg-indigo-100 hover:bg-indigo-200 text-indigo-800 px-2 py-0.5 rounded-md font-extrabold text-[10px] transition">
                            📄 فیش #${idx + 1} • صفحه ${n.page_number}
                        </button>
                        <button type="button" onclick="deletePaperNote(${n.id})" class="text-slate-400 hover:text-rose-600 text-xs" title="حذف فیش">🗑️</button>
                    </div>
                    <div class="text-[11px] text-slate-500 font-sans dir-ltr text-left leading-relaxed bg-white p-2 rounded-lg border border-slate-100 select-text">${escapeHtml(n.original_text)}</div>
                    <div class="text-xs text-slate-800 font-bold leading-6 select-text">${escapeHtml(n.translated_text)}</div>
                    ${n.user_comment ? `<div class="text-[11px] bg-amber-50 border border-amber-200 text-amber-900 p-1.5 rounded-lg">💡 یادداشت: ${escapeHtml(n.user_comment)}</div>` : ''}
                </div>
            `).join('');
        }

        async function deletePaperNote(noteId) {
            if (!confirm('این فیش حذف شود؟')) return;
            const fd = new FormData();
            fd.append('action', 'delete_note');
            fd.append('note_id', noteId);
            await fetch('api/api_translate.php', { method: 'POST', body: fd });
            if (activePaperMeta) fetchPaperNotes(activePaperMeta.id);
        }

        function copyAllPaperNotes() {
            if (paperNotes.length === 0) {
                alert('فیشی برای کپی کردن وجود ندارد.');
                return;
            }
            const text = paperNotes.map((n, i) =>
                `[${i + 1}] صفحه ${n.page_number}:\n🇬🇧 ${n.original_text}\n🇮🇷 ${n.translated_text}${n.user_comment ? '\n💡 نکته: ' + n.user_comment : ''}`
            ).join('\n\n------------------------\n\n');

            navigator.clipboard.writeText(text).then(() => {
                alert('تمام فیش‌های این مقاله در کلیپ‌بورد کپی شدند!');
            });
        }

        function exportNotesToWord() {
            if (!activePaperMeta || paperNotes.length === 0) {
                alert('ابتدا حداقل یک فیش از مقاله ذخیره کنید تا فایل Word آن تولید شود.');
                return;
            }

            const paperTitle = escapeHtml(activePaperMeta.title);
            let rowsHtml = '';

            paperNotes.forEach((n, i) => {
                rowsHtml += `
                    <div style="border:1px solid #cbd5e1; border-radius:8px; padding:12px; margin-bottom:14px; background:#f8fafc;">
                        <div style="font-weight:bold; color:#312e81; margin-bottom:6px; font-size:11pt;">
                            📌 فیش شماره ${i + 1} (صفحه ${n.page_number} مقاله)
                        </div>
                        <div dir="ltr" style="text-align:left; font-family:Calibri, Arial, sans-serif; font-size:10.5pt; color:#334155; background:#ffffff; padding:8px; border:1px solid #e2e8f0; margin-bottom:8px;">
                            ${escapeHtml(n.original_text)}
                        </div>
                        <div dir="rtl" style="text-align:right; font-family:Tahoma, Arial, sans-serif; font-size:11pt; color:#0f172a; font-weight:bold; line-height:1.7;">
                            🇮🇷 ترجمه: ${escapeHtml(n.translated_text)}
                        </div>
                        ${n.user_comment ? `<div dir="rtl" style="margin-top:6px; color:#92400e; background:#fef3c7; padding:6px; font-size:10pt;">💡 یادداشت پژوهشی: ${escapeHtml(n.user_comment)}</div>` : ''}
                    </div>
                `;
            });

            const wordHtml = `
                <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40" dir="rtl">
                <head>
                    <meta charset="utf-8">
                    <title>فیش‌های پژوهشی مقاله - ${paperTitle}</title>
                </head>
                <body style="font-family:Tahoma, Arial, sans-serif; direction:rtl; text-align:right; padding:20px;">
                    <h2 style="color:#1e1b4b; border-bottom:2px solid #4f46e5; padding-bottom:8px;">📑 دفترچه فیش‌های پژوهشی و ترجمه مقاله</h2>
                    <p style="font-size:11pt; color:#475569;"><b>عنوان مقاله:</b> ${paperTitle} | <b>تعداد فیش‌ها:</b> ${paperNotes.length}</p>
                    <hr style="margin:15px 0;">
                    ${rowsHtml}
                </body>
                </html>
            `;

            const blob = new Blob(['\ufeff', wordHtml], { type: 'application/msword;charset=utf-8' });
            const url  = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            const safeFileName = activePaperMeta.title.replace(/[^a-zA-Z0-9آ-ی_\-]/g, '_');
            link.download = `Notes_${safeFileName}.doc`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }

        function exportNotesToPdfPrint() {
            if (!activePaperMeta || paperNotes.length === 0) {
                alert('ابتدا حداقل یک فیش از مقاله ذخیره کنید.');
                return;
            }

            const paperTitle = escapeHtml(activePaperMeta.title);
            const itemsHtml = paperNotes.map((n, i) => `
                <div class="note-card">
                    <div class="note-header">
                        <span>📌 فیش شماره ${i + 1}</span>
                        <span>صفحه ${n.page_number} مقاله</span>
                    </div>
                    <div class="en-box" dir="ltr">${escapeHtml(n.original_text)}</div>
                    <div class="fa-box" dir="rtl">${escapeHtml(n.translated_text)}</div>
                    ${n.user_comment ? `<div class="comment-box" dir="rtl">💡 یادداشت شخصی: ${escapeHtml(n.user_comment)}</div>` : ''}
                </div>
            `).join('');

            const printWin = window.open('', '_blank');
            if (!printWin) {
                alert('لطفاً اجازه باز شدن پنجره (Pop-up) را در مرورگر بدهید.');
                return;
            }

            printWin.document.write(`
                <!DOCTYPE html>
                <html lang="fa" dir="rtl">
                <head>
                    <meta charset="UTF-8">
                    <title>فیش‌های پژوهشی - ${paperTitle}</title>
                    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
                    <style>
                        body { font-family: 'Vazirmatn', Tahoma, sans-serif; color: #0f172a; padding: 24px; max-width: 850px; margin: 0 auto; }
                        .top-header { border-bottom: 2px solid #4f46e5; padding-bottom: 12px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
                        .top-header h1 { font-size: 18px; margin: 0; color: #1e1b4b; }
                        .note-card { border: 1px solid #cbd5e1; border-radius: 12px; padding: 14px; margin-bottom: 14px; page-break-inside: avoid; background: #fff; }
                        .note-header { display: flex; justify-content: space-between; font-weight: bold; font-size: 12px; color: #4338ca; margin-bottom: 8px; }
                        .en-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; font-family: sans-serif; font-size: 12px; color: #334155; text-align: left; line-height: 1.5; margin-bottom: 8px; }
                        .fa-box { font-size: 13px; font-weight: bold; line-height: 1.8; color: #0f172a; }
                        .comment-box { margin-top: 8px; background: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; padding: 8px; font-size: 11px; color: #92400e; }
                        @media print { .no-print { display: none; } body { padding: 0; } }
                    </style>
                </head>
                <body>
                    <div class="no-print" style="margin-bottom:16px; text-align:left;">
                        <button onclick="window.print()" style="background:#4f46e5;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-family:inherit;font-weight:bold;cursor:pointer;">
                            🖨️ ذخیره به عنوان PDF / چاپ
                        </button>
                    </div>
                    <div class="top-header">
                        <div>
                            <h1>📑 فیش‌های پژوهشی مقاله: ${paperTitle}</h1>
                        </div>
                    </div>
                    ${itemsHtml}
                    <script>
                        window.onload = function() { setTimeout(function() { window.print(); }, 400); };
                    <\/script>
                </body>
                </html>
            `);
            printWin.document.close();
        }

        // ====================================================
        // کنترل‌های صفحه، زوم، حالت شب و آپلود/ایمپورت مقاله
        // ====================================================
        function changePaperPage(delta) {
            if (!paperDocInstance) return;
            const next = currentPaperPage + delta;
            if (next >= 1 && next <= paperDocInstance.numPages) {
                stopAutoAdvance();
                stopSpeech();
                currentPaperPage = next;
                renderPaperPage(currentPaperPage, false);
                document.getElementById('paper-viewport-container').scrollTop = 0;
            }
        }

        function jumpToPaperPage(val) {
            if (!paperDocInstance) return;
            stopAutoAdvance();
            stopSpeech();
            currentPaperPage = Math.min(paperDocInstance.numPages, Math.max(1, parseInt(val) || 1));
            renderPaperPage(currentPaperPage, false);
            document.getElementById('paper-viewport-container').scrollTop = 0;
        }

        function adjustPaperZoom(delta) {
            if (!paperDocInstance) return;
            paperZoomMode = 'custom';
            paperCustomScale = Math.min(3.5, Math.max(0.45, paperCustomScale + delta));
            renderPaperPage(currentPaperPage, true);
        }

        function setPaperFitWidth() {
            if (!paperDocInstance) return;
            paperZoomMode = 'fit-width';
            renderPaperPage(currentPaperPage, true);
        }

        function togglePaperDarkMode() {
            isPaperDarkMode = !isPaperDarkMode;
            const canvas = document.getElementById('paper-render-canvas');
            const btn = document.getElementById('btn-paper-dark');
            if (isPaperDarkMode) {
                canvas.classList.add('pdf-dark-mode');
                btn.textContent = '☀️ روز';
            } else {
                canvas.classList.remove('pdf-dark-mode');
                btn.textContent = '🌙 شب';
            }
        }

        async function savePaperProgress(pdfId, lastPage, totalPages) {
            if (activePaperMeta) {
                activePaperMeta.last_page = lastPage;
                activePaperMeta.total_pages = totalPages;
                renderPapersSidebar();
            }
            const fd = new FormData();
            fd.append('action', 'save_progress');
            fd.append('pdf_id', pdfId);
            fd.append('last_page', lastPage);
            fd.append('total_pages', totalPages);
            await fetch('api/api_pdf.php', { method: 'POST', body: fd });
        }

        function openUploadPaperModal() {
            const totalFiles = papersList.length + bookletsList.length;
            if (!userSub.is_vip && totalFiles >= FREE_MAX_PDF_CNT) {
                openSubscriptionModal(`سهمیه ${FREE_MAX_PDF_CNT} فایل رایگان شما پر شده است. برای آپلود مقالات بیشتر، فایل قبلی را حذف کنید یا اشتراک ویژه تهیه نمایید.`);
                return;
            }
            document.getElementById('upload-paper-form').reset();
            const hint = document.getElementById('upload-paper-tier-hint');
            hint.textContent = userSub.is_vip
                ? `👑 اشتراک ویژه فعال: ذخیره در پوشه اختصاصی مقالات (سقف ${VIP_MAX_PDF_MB} مگابایت)`
                : `👤 حساب عادی: حداکثر ۱ فایل تا سقف ${FREE_MAX_PDF_MB} مگابایت`;

            const m = document.getElementById('upload-paper-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeUploadPaperModal() {
            const m = document.getElementById('upload-paper-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function handlePaperUpload(e) {
            e.preventDefault();
            const file = document.getElementById('upload-paper-file-input').files[0];
            if (!file) return;

            const maxMB = userSub.is_vip ? VIP_MAX_PDF_MB : FREE_MAX_PDF_MB;
            if (file.size > maxMB * 1024 * 1024) {
                closeUploadPaperModal();
                openSubscriptionModal(`حجم فایل انتخابی بیش از سقف مجاز (${maxMB} مگابایت) است.`);
                return;
            }

            const btn = document.getElementById('btn-submit-paper-upload');
            btn.disabled = true;
            btn.textContent = 'در حال آپلود...';

            try {
                const fd = new FormData(document.getElementById('upload-paper-form'));
                fd.append('action', 'upload_paper');
                const res = await fetch('api/api_translate.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.status === 'success') {
                    closeUploadPaperModal();
                    await fetchPapersAndConfig();
                    loadAndOpenPaper(data.paper.id, 1);
                } else if (data.need_vip) {
                    closeUploadPaperModal();
                    openSubscriptionModal(data.message);
                } else {
                    alert(data.message || 'خطا در آپلود مقاله');
                }
            } catch (err) {
                alert('خطا در ارتباط با سرور.');
            } finally {
                btn.disabled = false;
                btn.textContent = 'آپلود مقاله';
            }
        }

        function openImportBookletModal() {
            const listEl = document.getElementById('booklets-import-list');
            if (bookletsList.length === 0) {
                listEl.innerHTML = `<div class="text-center text-slate-400 py-6">هیچ فایلی در بخش جزوه‌خوان یافت نشد.</div>`;
            } else {
                listEl.innerHTML = bookletsList.map(b => `
                    <div class="p-2.5 bg-slate-50 border border-slate-200 rounded-xl flex items-center justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-extrabold text-slate-800 truncate">${escapeHtml(b.title)}</div>
                            <div class="text-[10px] text-slate-500">${b.total_pages} صفحه</div>
                        </div>
                        <button type="button" onclick="importBookletToPapers(${b.id})" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg font-bold text-[11px] shrink-0">
                            📥 افزودن به مقالات
                        </button>
                    </div>
                `).join('');
            }
            const m = document.getElementById('import-booklet-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeImportBookletModal() {
            const m = document.getElementById('import-booklet-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function importBookletToPapers(pdfId) {
            const fd = new FormData();
            fd.append('action', 'import_from_booklets');
            fd.append('pdf_id', pdfId);

            const res = await fetch('api/api_translate.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                closeImportBookletModal();
                await fetchPapersAndConfig();
                loadAndOpenPaper(data.paper_id, 1);
            } else {
                alert(data.message || 'خطا در افزودن فایل');
            }
        }

        async function deletePaperItem(e, pdfId) {
            e.stopPropagation();
            if (!confirm('آیا از حذف این مقاله مطمئن هستید؟')) return;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('pdf_id', pdfId);
            await fetch('api/api_pdf.php', { method: 'POST', body: fd });
            if (activePaperMeta && Number(activePaperMeta.id) === Number(pdfId)) {
                location.reload();
            } else {
                fetchPapersAndConfig();
            }
        }

        function switchTranslatorTab(tab) {
            const trPanel = document.getElementById('panel-tab-translate');
            const ntPanel = document.getElementById('panel-tab-notes');
            const btnTr   = document.getElementById('tab-btn-translate');
            const btnNt   = document.getElementById('tab-btn-notes');

            if (tab === 'translate') {
                trPanel.classList.remove('hidden');
                ntPanel.classList.add('hidden');
                ntPanel.classList.remove('flex');
                btnTr.className = 'px-2.5 py-0.5 md:py-1 rounded-lg font-extrabold bg-indigo-600 text-white transition';
                btnNt.className = 'px-2.5 py-0.5 md:py-1 rounded-lg font-bold text-slate-300 hover:text-white transition flex items-center gap-1';
            } else {
                trPanel.classList.add('hidden');
                ntPanel.classList.remove('hidden');
                ntPanel.classList.add('flex');
                btnNt.className = 'px-2.5 py-0.5 md:py-1 rounded-lg font-extrabold bg-indigo-600 text-white transition flex items-center gap-1';
                btnTr.className = 'px-2.5 py-0.5 md:py-1 rounded-lg font-bold text-slate-300 hover:text-white transition';
            }
        }

        function togglePaperSidebar() {
            const sb = document.getElementById('paper-sidebar');
            const bd = document.getElementById('paper-sidebar-backdrop');
            if (window.innerWidth < 1024) {
                if (sb.classList.contains('translate-x-full')) {
                    sb.classList.remove('translate-x-full');
                    bd.classList.remove('hidden');
                } else {
                    closePaperSidebarMobile();
                }
            } else {
                sb.classList.toggle('hidden');
                if (paperDocInstance && paperZoomMode === 'fit-width') {
                    setTimeout(() => renderPaperPage(currentPaperPage, true), 50);
                }
            }
        }

        function closePaperSidebarMobile() {
            document.getElementById('paper-sidebar').classList.add('translate-x-full');
            document.getElementById('paper-sidebar-backdrop').classList.add('hidden');
        }

        function openSubscriptionModal(reasonMessage = '') {
            const statusEl = document.getElementById('sub-modal-current-status');
            statusEl.innerHTML = userSub.is_vip
                ? `وضعیت فعلی: <b class="text-emerald-300">مشترک ویژه (${userSub.days_left} روز مانده)</b>`
                : `وضعیت فعلی: <b class="text-amber-200">کاربر عادی (رایگان)</b>`;

            const reasonBox = document.getElementById('sub-modal-reason-alert');
            if (reasonMessage) {
                reasonBox.textContent = '⚠️ ' + reasonMessage;
                reasonBox.classList.remove('hidden');
            } else {
                reasonBox.classList.add('hidden');
            }

            const actionType = userSub.is_vip ? 'تمدید اشتراک' : 'خرید اشتراک';
            const msg1m = `سلام وقت بخیر، درخواست ${actionType} ویژه ۱ ماهه (۵۰۰,۰۰۰ تومان) را دارم.\n👤 نام: ${userSub.full_name}\n🆔 نام کاربری: ${userSub.username}`;
            const msg3m = `سلام وقت بخیر، درخواست ${actionType} ویژه ۳ ماهه (۱,۲۰۰,۰۰۰ تومان) را دارم.\n👤 نام: ${userSub.full_name}\n🆔 نام کاربری: ${userSub.username}`;

            document.getElementById('tg-link-1m').href = `https://t.me/${TELEGRAM_SALES_ID}?text=${encodeURIComponent(msg1m)}`;
            document.getElementById('tg-link-3m').href = `https://t.me/${TELEGRAM_SALES_ID}?text=${encodeURIComponent(msg3m)}`;

            const m = document.getElementById('subscription-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeSubscriptionModal() {
            const m = document.getElementById('subscription-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function handleRedeemCode(e) {
            e.preventDefault();
            const code = document.getElementById('redeem-code-input').value.trim();
            if (!code) return;

            const fd = new FormData();
            fd.append('action', 'redeem_code');
            fd.append('code', code);

            const res = await fetch('api/api_subscription.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message);
            if (data.status === 'success') {
                userSub = data.subscription;
                closeSubscriptionModal();
                fetchPapersAndConfig();
            }
        }

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
        }

        window.addEventListener('resize', () => {
            if (paperDocInstance && paperZoomMode === 'fit-width') {
                renderPaperPage(currentPaperPage, true);
            }
        });

        window.addEventListener('DOMContentLoaded', () => {
            const savedSec = localStorage.getItem('paper_auto_speed_sec') || '8';
            const sliderEl = document.getElementById('auto-advance-speed');
            if (sliderEl) {
                sliderEl.value = savedSec;
                onAutoSpeedSliderInput(savedSec);
            }
            fetchPapersAndConfig();
        });
