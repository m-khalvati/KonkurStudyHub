/**
 * Modular Frontend Logic for admin.php
 */
// اولویت اول بارگذاری PDF.js از پوشه محلی هاست (assets/pdfjs)
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
            }
        ];
        let activeCdnConfig = PDFJS_CDN_LIST[0];

        if (typeof window.pdfjsLib !== 'undefined') {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = activeCdnConfig.worker;
        }

        function switchAdminTab(tab) {
            const sections = {
                exams:   document.getElementById('admin-section-exams'),
                courses: document.getElementById('admin-section-courses'),
                aikeys:  document.getElementById('admin-section-aikeys'),
                users:   document.getElementById('admin-section-users')
            };
            const buttons = {
                exams:   document.getElementById('adm-tab-exams'),
                courses: document.getElementById('adm-tab-courses'),
                aikeys:  document.getElementById('adm-tab-aikeys'),
                users:   document.getElementById('adm-tab-users')
            };

            const inactiveCls = 'px-3 py-1.5 rounded-lg font-bold text-slate-300 hover:text-white transition flex items-center gap-1.5';
            const activeCls   = 'px-3 py-1.5 rounded-lg font-extrabold bg-indigo-600 text-white transition flex items-center gap-1.5';

            Object.keys(sections).forEach(k => {
                if (sections[k]) sections[k].classList.add('hidden');
                if (buttons[k])  buttons[k].className = (k === tab) ? activeCls : inactiveCls;
            });

            if (sections[tab]) sections[tab].classList.remove('hidden');

            if (tab === 'courses') {
                fetchAdminCourses();
            } else if (tab === 'aikeys') {
                fetchAiKeysStatus();
            }
        }

        // ====================================================
        // ۰. مدیریت کامل دوره‌ها، سرفصل‌ها و جلسات ویدیویی (🎬 Courses Studio)
        // ====================================================
        let adminCoursesList  = [];
        let availableExamsRef = [];
        let activeCourseObj   = null;
        let courseChapters    = [];
        let courseLessons     = [];

        async function fetchAdminCourses(selectCourseId = null) {
            try {
                const res = await fetch('api/api_courses.php?action=admin_list_courses');
                const data = await res.json();
                if (data.status !== 'success') return;

                adminCoursesList  = data.courses || [];
                availableExamsRef = data.exams || [];

                document.getElementById('header-courses-badge').textContent = adminCoursesList.length;

                // پر کردن لیست آزمون‌ها در فرم جلسه ویدیویی
                const examSel = document.getElementById('le-linked-exam');
                if (examSel) {
                    const curVal = examSel.value;
                    examSel.innerHTML = '<option value="">-- بدون آزمون متصل --</option>' +
                        availableExamsRef.map(ex => `<option value="${ex.id}">📝 ${escapeHtml(ex.title)} (${escapeHtml(ex.category || 'عمومی')})</option>`).join('');
                    examSel.value = curVal;
                }

                const selectEl = document.getElementById('admin-course-select');
                if (adminCoursesList.length === 0) {
                    selectEl.innerHTML = '<option value="">-- هنوز دوره‌ای نساخته‌اید --</option>';
                    document.getElementById('course-empty-state').classList.remove('hidden');
                    document.getElementById('course-workspace').classList.add('hidden');
                    document.getElementById('btn-edit-course-meta').classList.add('hidden');
                    document.getElementById('btn-delete-course').classList.add('hidden');
                    return;
                }

                let html = '<option value="">-- یک دوره آموزشی را انتخاب کنید --</option>';
                adminCoursesList.forEach(c => {
                    const stIcon  = Number(c.is_active) === 1 ? '🟢' : '⚪';
                    const vipIcon = Number(c.is_vip_only) === 1 ? '👑 ' : '🎁 ';
                    html += `<option value="${c.id}">${stIcon} ${vipIcon}${escapeHtml(c.title)} (${c.chapters_count} فصل | ${c.lessons_count} جلسه)</option>`;
                });
                selectEl.innerHTML = html;

                const targetId = selectCourseId || (activeCourseObj ? activeCourseObj.id : adminCoursesList[0].id);
                if (targetId) {
                    selectEl.value = String(targetId);
                    await onSelectAdminCourse(targetId);
                }
            } catch (e) {
                console.error('Error fetching admin courses:', e);
            }
        }

        async function onSelectAdminCourse(courseId) {
            if (!courseId) {
                activeCourseObj = null;
                document.getElementById('course-empty-state').classList.remove('hidden');
                document.getElementById('course-workspace').classList.add('hidden');
                document.getElementById('btn-edit-course-meta').classList.add('hidden');
                document.getElementById('btn-delete-course').classList.add('hidden');
                return;
            }

            const res = await fetch(`api/api_courses.php?action=admin_get_course&course_id=${courseId}`);
            const data = await res.json();
            if (data.status === 'success') {
                activeCourseObj = data.course;
                courseChapters  = data.chapters || [];
                courseLessons   = data.lessons || [];

                document.getElementById('course-empty-state').classList.add('hidden');
                document.getElementById('course-workspace').classList.remove('hidden');
                document.getElementById('btn-edit-course-meta').classList.remove('hidden');
                document.getElementById('btn-delete-course').classList.remove('hidden');

                document.getElementById('active-course-tree-title').textContent = `📚 سرفصل‌های «${activeCourseObj.title}»`;
                document.getElementById('course-total-lessons-badge').textContent = `${courseLessons.length} جلسه`;

                syncLessonChapterDropdown();
                renderCourseChaptersTree();
            }
        }

        function syncLessonChapterDropdown() {
            const sel = document.getElementById('le-chapter-id');
            if (!sel) return;
            const prev = sel.value;
            if (courseChapters.length === 0) {
                sel.innerHTML = '<option value="">-- ابتدا یک فصل از ستون راست بسازید --</option>';
                return;
            }
            sel.innerHTML = courseChapters.map(ch => `<option value="${ch.id}">📁 ${escapeHtml(ch.title)}</option>`).join('');
            if (prev && courseChapters.some(c => String(c.id) === String(prev))) {
                sel.value = prev;
            } else {
                sel.value = String(courseChapters[0].id);
            }
        }

        function renderCourseChaptersTree() {
            const container = document.getElementById('course-chapters-list');
            if (courseChapters.length === 0) {
                container.innerHTML = `<div class="text-center text-slate-400 py-8">هنوز فصلی برای این دوره ثبت نشده است. از کادر بالا نام فصل اول را وارد کنید.</div>`;
                return;
            }

            container.innerHTML = courseChapters.map((ch, cIdx) => {
                const chLessons = courseLessons.filter(l => Number(l.chapter_id) === Number(ch.id));

                const lessonsHtml = chLessons.length === 0
                    ? `<div class="text-[11px] text-slate-400 py-2 text-center">هنوز جلسه‌ای در این فصل ثبت نشده است.</div>`
                    : chLessons.map((ls, lIdx) => {
                        const mins = ls.duration_sec > 0 ? Math.round(ls.duration_sec / 60) + ' دقیقه' : 'زمان خودکار';
                        const hasSub = !emptyStr(ls.subtitle_url);
                        const hasBkl = !emptyStr(ls.booklet_url);
                        const hasExm = Number(ls.linked_exam_id) > 0;
                        const isFree = Number(ls.is_free_preview) === 1;

                        return `
                            <div class="p-2.5 bg-white rounded-xl border border-slate-200 hover:border-indigo-300 transition space-y-1.5">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="font-extrabold text-slate-800 leading-snug">
                                        <span class="text-indigo-600 ml-1">${lIdx + 1}.</span>${escapeHtml(ls.title)}
                                    </div>
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <button type="button" onclick="loadLessonIntoEditor(${ls.id})" class="text-indigo-600 hover:underline font-bold text-[11px]">✏️ ویرایش</button>
                                        <button type="button" onclick="deleteCourseLesson(${ls.id})" class="text-rose-500 hover:text-rose-700 font-bold text-[11px]">🗑️</button>
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-1 text-[10px] font-bold">
                                    <span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded">⏱️ ${mins}</span>
                                    ${isFree ? `<span class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded">🎁 رایگان</span>` : ''}
                                    ${hasSub ? `<span class="bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded">💬 زیرنویس</span>` : ''}
                                    ${hasBkl ? `<span class="bg-amber-100 text-amber-900 px-2 py-0.5 rounded">📥 جزوه</span>` : ''}
                                    ${hasExm ? `<span class="bg-rose-50 text-rose-700 px-2 py-0.5 rounded">📝 آزمون متصل</span>` : ''}
                                </div>
                            </div>
                        `;
                    }).join('');

                return `
                    <div class="bg-slate-50 rounded-2xl border border-slate-200 overflow-hidden">
                        <div class="bg-slate-200 px-3 py-2 flex items-center justify-between gap-2">
                            <div class="font-extrabold text-slate-800 truncate">
                                📁 فصل ${cIdx + 1}: ${escapeHtml(ch.title)}
                                <span class="text-[10px] bg-white text-slate-600 px-1.5 py-0.5 rounded-full mr-1">${chLessons.length}</span>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <button type="button" onclick="prepareNewLessonForChapter(${ch.id})" class="bg-indigo-600 hover:bg-indigo-700 text-white px-2 py-0.5 rounded-lg text-[10px] font-bold">+ جلسه</button>
                                <button type="button" onclick="editChapterPrompt(${ch.id}, '${escapeHtml(ch.title)}', ${ch.sort_order})" class="text-slate-600 hover:text-indigo-600 px-1 text-xs" title="ویرایش نام فصل">✏️</button>
                                <button type="button" onclick="deleteCourseChapter(${ch.id})" class="text-slate-400 hover:text-rose-600 px-1 text-xs" title="حذف فصل">🗑️</button>
                            </div>
                        </div>
                        <div class="p-2.5 space-y-2">
                            ${lessonsHtml}
                        </div>
                    </div>
                `;
            }).join('');
        }

        function emptyStr(val) {
            return !val || String(val).trim() === '';
        }

        function openCourseMetaModal(isNew = true) {
            const form = document.getElementById('course-meta-form');
            form.reset();

            if (isNew || !activeCourseObj) {
                document.getElementById('course-meta-modal-title').textContent = '➕ ساخت دوره آموزشی جدید';
                document.getElementById('cm-id').value       = '0';
                document.getElementById('cm-vip-only').checked = true;
                document.getElementById('cm-active').checked   = true;
            } else {
                document.getElementById('course-meta-modal-title').textContent = '⚙️ ویرایش مشخصات دوره';
                document.getElementById('cm-id').value          = activeCourseObj.id;
                document.getElementById('cm-title').value       = activeCourseObj.title || '';
                document.getElementById('cm-instructor').value  = activeCourseObj.instructor || '';
                document.getElementById('cm-category').value    = activeCourseObj.category || 'عمومی';
                document.getElementById('cm-cover-url').value   = activeCourseObj.cover_image || '';
                document.getElementById('cm-description').value = activeCourseObj.description || '';
                document.getElementById('cm-vip-only').checked  = Number(activeCourseObj.is_vip_only) === 1;
                document.getElementById('cm-active').checked    = Number(activeCourseObj.is_active) === 1;
            }

            const m = document.getElementById('course-meta-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeCourseMetaModal() {
            const m = document.getElementById('course-meta-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function saveCourseMeta(e) {
            e.preventDefault();
            const fd = new FormData(document.getElementById('course-meta-form'));
            fd.append('action', 'save_course');
            fd.set('is_vip_only', document.getElementById('cm-vip-only').checked ? '1' : '0');
            fd.set('is_active', document.getElementById('cm-active').checked ? '1' : '0');

            const res = await fetch('api/api_courses.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                closeCourseMetaModal();
                await fetchAdminCourses(data.course_id);
            } else {
                alert(data.message || 'خطا در ذخیره دوره');
            }
        }

        async function deleteActiveCourse() {
            if (!activeCourseObj || !confirm(`آیا از حذف کامل دوره «${activeCourseObj.title}» و تمام فصل‌ها و جلسات آن مطمئن هستید؟`)) return;
            const fd = new FormData();
            fd.append('action', 'delete_course');
            fd.append('course_id', activeCourseObj.id);
            await fetch('api/api_courses.php', { method: 'POST', body: fd });
            activeCourseObj = null;
            fetchAdminCourses();
        }

        async function saveCourseChapter(e) {
            e.preventDefault();
            if (!activeCourseObj) return;
            const titleInput = document.getElementById('new-chapter-title');
            const title = titleInput.value.trim();
            if (!title) return;

            const fd = new FormData();
            fd.append('action', 'save_chapter');
            fd.append('course_id', activeCourseObj.id);
            fd.append('title', title);

            const res = await fetch('api/api_courses.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                titleInput.value = '';
                await onSelectAdminCourse(activeCourseObj.id);
                document.getElementById('le-chapter-id').value = String(data.chapter_id);
            } else {
                alert(data.message || 'خطا در ثبت فصل');
            }
        }

        async function editChapterPrompt(chId, oldTitle, sortOrder) {
            const newTitle = prompt('عنوان جدید فصل را وارد کنید:', oldTitle);
            if (!newTitle || !newTitle.trim() || !activeCourseObj) return;

            const fd = new FormData();
            fd.append('action', 'save_chapter');
            fd.append('id', chId);
            fd.append('course_id', activeCourseObj.id);
            fd.append('title', newTitle.trim());
            fd.append('sort_order', sortOrder || 1);

            await fetch('api/api_courses.php', { method: 'POST', body: fd });
            onSelectAdminCourse(activeCourseObj.id);
        }

        async function deleteCourseChapter(chId) {
            if (!confirm('آیا از حذف این فصل و تمام جلسات داخل آن مطمئن هستید؟')) return;
            const fd = new FormData();
            fd.append('action', 'delete_chapter');
            fd.append('chapter_id', chId);
            await fetch('api/api_courses.php', { method: 'POST', body: fd });
            if (activeCourseObj) onSelectAdminCourse(activeCourseObj.id);
        }

        function prepareNewLessonForChapter(chapterId) {
            resetLessonEditor();
            document.getElementById('le-chapter-id').value = String(chapterId);
            const chLessons = courseLessons.filter(l => Number(l.chapter_id) === Number(chapterId));
            document.getElementById('le-sort-order').value = chLessons.length + 1;
            document.getElementById('le-title').focus();
        }

        function resetLessonEditor() {
            const curChap = document.getElementById('le-chapter-id').value;
            document.getElementById('lesson-editor-form').reset();
            document.getElementById('le-id').value = '0';
            document.getElementById('lesson-editor-header-title').textContent = '➕ افزودن جلسه ویدیویی جدید';
            document.getElementById('le-booklet-title').value = 'جزوه و نکات جلسه';
            document.getElementById('le-duration-mins').value = '30';
            if (curChap) document.getElementById('le-chapter-id').value = curChap;
        }

        function loadLessonIntoEditor(lessonId) {
            const ls = courseLessons.find(x => Number(x.id) === Number(lessonId));
            if (!ls) return;

            document.getElementById('lesson-editor-header-title').textContent = `✏️ ویرایش جلسه: ${ls.title}`;
            document.getElementById('le-id').value            = ls.id;
            document.getElementById('le-chapter-id').value    = ls.chapter_id;
            document.getElementById('le-sort-order').value    = ls.sort_order || 1;
            document.getElementById('le-duration-mins').value = ls.duration_sec > 0 ? Math.round(ls.duration_sec / 60) : 0;
            document.getElementById('le-title').value         = ls.title || '';
            document.getElementById('le-video-url').value     = ls.video_url || '';
            document.getElementById('le-video-backup').value  = ls.video_url_backup || '';
            document.getElementById('le-subtitle-url').value  = ls.subtitle_url || '';
            document.getElementById('le-booklet-title').value = ls.booklet_title || 'جزوه و نکات جلسه';
            document.getElementById('le-booklet-url').value   = ls.booklet_url || '';
            document.getElementById('le-linked-exam').value   = ls.linked_exam_id || '';
            document.getElementById('le-free-preview').checked = Number(ls.is_free_preview) === 1;
            document.getElementById('le-description').value   = ls.description || '';

            document.getElementById('le-subtitle-file').value = '';
            document.getElementById('le-booklet-file').value  = '';

            document.getElementById('lesson-editor-form').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        async function saveCourseLesson(e) {
            e.preventDefault();
            if (!activeCourseObj) return;

            const btn = document.getElementById('btn-save-lesson');
            const origText = btn.textContent;
            btn.disabled = true;
            btn.textContent = '⏳ در حال آپلود و ذخیره جلسه...';

            try {
                const fd = new FormData(document.getElementById('lesson-editor-form'));
                fd.append('action', 'save_lesson');
                fd.append('id', document.getElementById('le-id').value);
                fd.append('course_id', activeCourseObj.id);
                fd.set('is_free_preview', document.getElementById('le-free-preview').checked ? '1' : '0');

                const res = await fetch('api/api_courses.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    resetLessonEditor();
                    await fetchAdminCourses(activeCourseObj.id);
                    alert(data.message);
                } else {
                    alert(data.message || 'خطا در ذخیره جلسه');
                }
            } catch (err) {
                alert('خطا در ارتباط با سرور.');
            } finally {
                btn.disabled = false;
                btn.textContent = origText;
            }
        }

        async function deleteCourseLesson(lessonId) {
            if (!confirm('این جلسه ویدیویی حذف شود؟')) return;
            const fd = new FormData();
            fd.append('action', 'delete_lesson');
            fd.append('lesson_id', lessonId);
            await fetch('api/api_courses.php', { method: 'POST', body: fd });
            if (activeCourseObj) fetchAdminCourses(activeCourseObj.id);
        }

        // ====================================================
        // ۱. داشبورد مانیتورینگ کلیدهای هوش مصنوعی
        // ====================================================
        let lastPingResultsByIndex = {};

        async function fetchAiKeysStatus() {
            try {
                const res = await fetch('api/api_exam.php?action=admin_ai_keys_status');
                const data = await res.json();
                if (data.status !== 'success') return;

                const s = data.summary || {};
                document.getElementById('header-ai-keys-badge').textContent = s.keys_count || 0;
                document.getElementById('studio-ai-summary-pill').textContent = `${s.keys_count || 0} کلید فعال | امروز: ${s.today_calls || 0} درخواست`;

                document.getElementById('aik-total-keys').textContent      = s.keys_count || 0;
                document.getElementById('aik-today-calls').textContent     = s.today_calls || 0;
                document.getElementById('aik-total-calls').textContent     = s.total_calls || 0;
                document.getElementById('aik-success-calls').textContent   = s.success_calls || 0;
                document.getElementById('aik-translate-calls').textContent = s.translate_calls || 0;
                document.getElementById('aik-exam-calls').textContent      = s.exam_calls || 0;
                document.getElementById('aik-primary-model-badge').textContent = `مدل پایه: ${data.primary_model || 'gemini-3.7-flash'}`;

                const tbody = document.getElementById('ai-keys-table-tbody');
                const keys  = data.keys || [];

                if (keys.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="7" class="p-6 text-center text-slate-400">هیچ کلید فعالی در آرایه AI_API_KEYS داخل فایل config.php یافت نشد.</td></tr>`;
                    return;
                }

                tbody.innerHTML = keys.map(k => {
                    const ping = lastPingResultsByIndex[k.index];
                    let pingHtml = `<span class="text-slate-400 text-[11px]">تست نشده</span>`;
                    if (ping) {
                        pingHtml = ping.ok
                            ? `<span class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-lg font-extrabold text-[11px]">🟢 سالم (${ping.latency_ms}ms)</span>`
                            : `<span class="bg-rose-100 text-rose-800 px-2 py-0.5 rounded-lg font-bold text-[11px]" title="${escapeHtml(ping.error)}">🔴 خطا (${ping.http_code})</span>`;
                    }

                    const codeBadge = k.last_http_code
                        ? `<span class="px-1.5 py-0.5 rounded text-[10px] font-mono font-bold ${k.last_http_code === 200 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}">HTTP ${k.last_http_code}</span>`
                        : '';

                    return `
                        <tr class="hover:bg-slate-50">
                            <td class="p-3 font-black text-slate-700">#${k.index}</td>
                            <td class="p-3 font-mono font-bold text-indigo-700" dir="ltr">${escapeHtml(k.key_masked)}</td>
                            <td class="p-3"><span class="bg-amber-100 text-amber-900 px-2.5 py-0.5 rounded-full font-black">${k.today_calls}</span></td>
                            <td class="p-3">
                                <span class="font-extrabold text-slate-800">${k.total_calls}</span>
                                <span class="text-[11px] text-slate-400 mr-1">(✅${k.success_calls} / ❌${k.error_calls})</span>
                            </td>
                            <td class="p-3 text-[11px]">
                                <span class="text-indigo-700 font-bold">🌐 مقاله: ${k.translate_calls}</span> |
                                <span class="text-purple-700 font-bold">📝 آزمون: ${k.exam_calls}</span>
                            </td>
                            <td class="p-3">
                                <span class="font-mono text-[11px] text-slate-700">${escapeHtml(k.last_model)}</span>
                                ${codeBadge}
                                ${k.last_error ? `<div class="text-[10px] text-rose-500 truncate" style="max-width:210px;" title="${escapeHtml(k.last_error)}">${escapeHtml(k.last_error)}</div>` : ''}
                            </td>
                            <td class="p-3">${pingHtml}</td>
                        </tr>
                    `;
                }).join('');
            } catch (e) {
                console.error('Error fetching AI keys status:', e);
            }
        }

        async function pingAllAiKeysHealth() {
            const btn = document.getElementById('btn-ping-ai-keys');
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span>⏳ در حال پینگ تک‌تک کلیدها...</span>';

            try {
                const res = await fetch('api/api_exam.php?action=admin_ping_ai_keys');
                const data = await res.json();
                if (data.status === 'success' && Array.isArray(data.results)) {
                    data.results.forEach(r => {
                        lastPingResultsByIndex[r.index] = r;
                    });
                    await fetchAiKeysStatus();
                }
            } catch (e) {
                alert('خطا در تست سلامت کلیدها.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        }

        async function resetAllAiKeyStats() {
            if (!confirm('آیا تمام شمارنده‌های مصرف کلیدهای هوش مصنوعی صفر شوند؟')) return;
            await fetch('api/api_exam.php?action=admin_reset_ai_stats');
            lastPingResultsByIndex = {};
            fetchAiKeysStatus();
        }

        // ====================================================
        // ۲. مدیریت آزمون‌ها و بانک سوالات در استودیو
        // ====================================================
        let adminExamsList    = [];
        let activeExamObj     = null;
        let savedQuestions    = [];
        let aiStagedQuestions = [];

        function formatRichQuestionHtml(rawText) {
            if (!rawText) return '';
            let safe = escapeHtml(rawText);

            safe = safe.replace(/```(?:[a-zA-Z0-9_+-]*\n)?([\s\S]*?)```/g, function(_, codeContent) {
                return `<pre dir="ltr" class="bg-slate-900 text-emerald-300 font-mono text-xs p-3 rounded-xl my-2 overflow-x-auto text-left leading-relaxed border border-slate-700">${codeContent.trim()}</pre>`;
            });

            safe = safe.replace(/`([^`\n]+)`/g, '<code dir="ltr" class="bg-slate-200 text-indigo-900 font-mono px-1.5 py-0.5 rounded text-[11px]">$1</code>');

            return safe;
        }

        async function fetchAdminExams(selectExamId = null) {
            try {
                const res = await fetch('api/api_exam.php?action=admin_list_exams');
                const data = await res.json();
                if (data.status === 'success') {
                    adminExamsList = data.exams || [];
                    const selectEl = document.getElementById('studio-exam-select');

                    if (adminExamsList.length === 0) {
                        selectEl.innerHTML = '<option value="">-- هنوز آزمونی نساخته‌اید --</option>';
                        document.getElementById('studio-empty-state').classList.remove('hidden');
                        document.getElementById('studio-workspace').classList.add('hidden');
                        document.getElementById('btn-edit-exam-meta').classList.add('hidden');
                        document.getElementById('btn-bulk-answer-key').classList.add('hidden');
                        document.getElementById('btn-delete-exam').classList.add('hidden');
                        return;
                    }

                    let html = '<option value="">-- یک آزمون را انتخاب کنید --</option>';
                    adminExamsList.forEach(ex => {
                        const statusIcon = Number(ex.is_active) === 1 ? '🟢' : '⚪';
                        const vipIcon    = Number(ex.is_vip_only) === 1 ? '👑 ' : '';
                        html += `<option value="${ex.id}">${statusIcon} ${vipIcon}${escapeHtml(ex.title)} (${ex.question_count} سوال | ${ex.duration_mins} دقیقه)</option>`;
                    });
                    selectEl.innerHTML = html;

                    const targetId = selectExamId || (activeExamObj ? activeExamObj.id : adminExamsList[0].id);
                    if (targetId) {
                        selectEl.value = String(targetId);
                        await onSelectStudioExam(targetId);
                    }
                }
            } catch (e) {
                console.error('Error loading exams:', e);
            }
        }

        async function onSelectStudioExam(examId) {
            if (!examId) {
                activeExamObj = null;
                document.getElementById('studio-empty-state').classList.remove('hidden');
                document.getElementById('studio-workspace').classList.add('hidden');
                document.getElementById('btn-edit-exam-meta').classList.add('hidden');
                document.getElementById('btn-bulk-answer-key').classList.add('hidden');
                document.getElementById('btn-delete-exam').classList.add('hidden');
                return;
            }

            const res = await fetch(`api/api_exam.php?action=admin_get_exam&exam_id=${examId}`);
            const data = await res.json();
            if (data.status === 'success') {
                activeExamObj  = data.exam;
                savedQuestions = data.questions || [];

                document.getElementById('studio-empty-state').classList.add('hidden');
                document.getElementById('studio-workspace').classList.remove('hidden');
                document.getElementById('btn-edit-exam-meta').classList.remove('hidden');
                document.getElementById('btn-bulk-answer-key').classList.remove('hidden');
                document.getElementById('btn-delete-exam').classList.remove('hidden');

                renderSavedQuestionsBank();
            }
        }

        function openExamMetaModal(isNew = true) {
            const form = document.getElementById('exam-meta-form');
            form.reset();

            if (isNew || !activeExamObj) {
                document.getElementById('exam-meta-modal-title').textContent = '➕ ساخت آزمون جدید';
                document.getElementById('em-id').value = '0';
                document.getElementById('em-negative').checked = true;
                document.getElementById('em-vip-only').checked = false;
                document.getElementById('em-active').checked   = true;
            } else {
                document.getElementById('exam-meta-modal-title').textContent = '⚙️ ویرایش مشخصات آزمون';
                document.getElementById('em-id').value          = activeExamObj.id;
                document.getElementById('em-title').value       = activeExamObj.title;
                document.getElementById('em-category').value    = activeExamObj.category || 'عمومی';
                document.getElementById('em-duration').value    = activeExamObj.duration_mins || 30;
                document.getElementById('em-description').value = activeExamObj.description || '';
                document.getElementById('em-negative').checked  = Number(activeExamObj.negative_marking) === 1;
                document.getElementById('em-vip-only').checked  = Number(activeExamObj.is_vip_only) === 1;
                document.getElementById('em-active').checked    = Number(activeExamObj.is_active) === 1;
            }

            const m = document.getElementById('exam-meta-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeExamMetaModal() {
            const m = document.getElementById('exam-meta-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function saveExamMeta(e) {
            e.preventDefault();
            const fd = new FormData();
            fd.append('action', 'save_exam');
            fd.append('id', document.getElementById('em-id').value);
            fd.append('title', document.getElementById('em-title').value);
            fd.append('category', document.getElementById('em-category').value);
            fd.append('duration_mins', document.getElementById('em-duration').value);
            fd.append('description', document.getElementById('em-description').value);
            fd.append('negative_marking', document.getElementById('em-negative').checked ? '1' : '0');
            fd.append('is_vip_only', document.getElementById('em-vip-only').checked ? '1' : '0');
            fd.append('is_active', document.getElementById('em-active').checked ? '1' : '0');

            const res = await fetch('api/api_exam.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                closeExamMetaModal();
                await fetchAdminExams(data.exam_id);
            } else {
                alert(data.message || 'خطا در ذخیره آزمون');
            }
        }

        async function deleteActiveExam() {
            if (!activeExamObj || !confirm(`آیا از حذف کامل آزمون «${activeExamObj.title}» و تمام سوالات آن مطمئن هستید؟`)) return;
            const fd = new FormData();
            fd.append('action', 'delete_exam');
            fd.append('exam_id', activeExamObj.id);
            await fetch('api/api_exam.php', { method: 'POST', body: fd });
            activeExamObj = null;
            fetchAdminExams();
        }

        function openBulkKeyModal() {
            if (!activeExamObj) return;
            document.getElementById('bulk-key-textarea').value = '';
            const m = document.getElementById('bulk-key-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeBulkKeyModal() {
            const m = document.getElementById('bulk-key-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function handleBulkUpdateAnswerKey(e) {
            e.preventDefault();
            if (!activeExamObj) return;
            const keyStr = document.getElementById('bulk-key-textarea').value.trim();
            if (!keyStr) return;

            const fd = new FormData();
            fd.append('action', 'bulk_update_answer_key');
            fd.append('exam_id', activeExamObj.id);
            fd.append('answer_key_str', keyStr);

            const res = await fetch('api/api_exam.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message);
            if (data.status === 'success') {
                closeBulkKeyModal();
                await fetchAdminExams(activeExamObj.id);
            }
        }

        // ====================================================
        // ۳. بارگذاری فایل PDF یا عکس سوالات + ابزار برش خودکار و دستی
        // ====================================================
        let studioPdfDoc     = null;
        let studioPdfPageNum = 1;
        let cropStartPos     = null;
        let activeCropBox    = null;

        async function handleStudioSourceFile(e) {
            const file = e.target.files[0];
            if (!file) return;

            stopMultiPageCrawler();
            clearCropSelection();
            document.getElementById('source-empty-placeholder').classList.add('hidden');
            document.getElementById('source-canvas-wrapper').classList.remove('hidden');
            document.getElementById('source-toolbar').classList.remove('hidden');
            document.getElementById('source-toolbar').classList.add('flex');

            if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) {
                document.getElementById('source-pdf-pagination').classList.remove('hidden');
                document.getElementById('source-pdf-pagination').classList.add('flex');
                document.getElementById('multi-page-crawler-bar').classList.remove('hidden');

                const arrayBuffer = await file.arrayBuffer();
                const pdfBytes = new Uint8Array(arrayBuffer);

                if (studioPdfDoc && typeof studioPdfDoc.destroy === 'function') {
                    await studioPdfDoc.destroy();
                }

                studioPdfDoc = await window.pdfjsLib.getDocument({
                    data: pdfBytes,
                    cMapUrl: activeCdnConfig.cmaps,
                    cMapPacked: true,
                    standardFontDataUrl: activeCdnConfig.fonts
                }).promise;

                studioPdfPageNum = 1;
                const totalP = studioPdfDoc.numPages;
                document.getElementById('source-total-pages').textContent = totalP;
                document.getElementById('source-page-input').max = totalP;
                document.getElementById('crawler-start-page').value = 1;
                document.getElementById('crawler-start-page').max   = totalP;
                document.getElementById('crawler-end-page').value   = totalP;
                document.getElementById('crawler-end-page').max     = totalP;

                await renderStudioPdfPage(1);
            } else {
                studioPdfDoc = null;
                document.getElementById('source-pdf-pagination').classList.add('hidden');
                document.getElementById('multi-page-crawler-bar').classList.add('hidden');

                const reader = new FileReader();
                reader.onload = (ev) => {
                    const img = new Image();
                    img.onload = () => {
                        const canvas = document.getElementById('source-render-canvas');
                        const ctx    = canvas.getContext('2d');
                        canvas.width  = img.width;
                        canvas.height = img.height;
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, canvas.width, canvas.height);
                        ctx.drawImage(img, 0, 0);
                    };
                    img.src = ev.target.result;
                };
                reader.readAsDataURL(file);
            }
        }

        async function renderStudioPdfPage(pageNum) {
            if (!studioPdfDoc) return;
            clearCropSelection();

            const page = await studioPdfDoc.getPage(pageNum);
            const canvas = document.getElementById('source-render-canvas');
            const ctx    = canvas.getContext('2d');

            const viewport = page.getViewport({ scale: 2.0 });
            canvas.width  = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);

            ctx.save();
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.restore();

            ctx.direction = 'ltr';
            ctx.textAlign = 'left';

            await page.render({ canvasContext: ctx, viewport }).promise;
            document.getElementById('source-page-input').value = pageNum;
        }

        function changeSourcePdfPage(delta) {
            if (!studioPdfDoc) return;
            const next = studioPdfPageNum + delta;
            if (next >= 1 && next <= studioPdfDoc.numPages) {
                studioPdfPageNum = next;
                renderStudioPdfPage(studioPdfPageNum);
            }
        }

        function jumpSourcePdfPage(val) {
            if (!studioPdfDoc) return;
            studioPdfPageNum = Math.min(studioPdfDoc.numPages, Math.max(1, parseInt(val) || 1));
            renderStudioPdfPage(studioPdfPageNum);
        }

        (function initSourceCropInteractions() {
            const cropLayer = document.getElementById('source-crop-layer');
            const cropRect  = document.getElementById('crop-selection-rect');
            let isDraggingCrop = false;

            function getRelPos(e) {
                const r = cropLayer.getBoundingClientRect();
                const cx = (e.touches && e.touches.length > 0) ? e.touches[0].clientX : e.clientX;
                const cy = (e.touches && e.touches.length > 0) ? e.touches[0].clientY : e.clientY;
                return {
                    x: Math.max(0, Math.min(r.width, cx - r.left)),
                    y: Math.max(0, Math.min(r.height, cy - r.top)),
                    layerW: r.width,
                    layerH: r.height
                };
            }

            function startCrop(e) {
                if (e.button !== undefined && e.button !== 0) return;
                if (e.cancelable) e.preventDefault();
                isDraggingCrop = true;
                cropStartPos = getRelPos(e);
                cropRect.style.display = 'block';
                cropRect.style.left   = cropStartPos.x + 'px';
                cropRect.style.top    = cropStartPos.y + 'px';
                cropRect.style.width  = '0px';
                cropRect.style.height = '0px';
            }

            function moveCrop(e) {
                if (!isDraggingCrop || !cropStartPos) return;
                if (e.cancelable) e.preventDefault();
                const cur = getRelPos(e);
                const x = Math.min(cropStartPos.x, cur.x);
                const y = Math.min(cropStartPos.y, cur.y);
                const w = Math.abs(cur.x - cropStartPos.x);
                const h = Math.abs(cur.y - cropStartPos.y);

                cropRect.style.left   = x + 'px';
                cropRect.style.top    = y + 'px';
                cropRect.style.width  = w + 'px';
                cropRect.style.height = h + 'px';

                activeCropBox = { x, y, w, h, layerW: cur.layerW, layerH: cur.layerH };
            }

            function endCrop() {
                if (!isDraggingCrop) return;
                isDraggingCrop = false;
                if (!activeCropBox || activeCropBox.w < 18 || activeCropBox.h < 18) {
                    clearCropSelection();
                } else {
                    document.getElementById('crop-actions-bar').classList.remove('hidden');
                    document.getElementById('crop-actions-bar').classList.add('flex');
                }
            }

            cropLayer.addEventListener('mousedown', startCrop);
            window.addEventListener('mousemove', moveCrop);
            window.addEventListener('mouseup', endCrop);

            cropLayer.addEventListener('touchstart', startCrop, { passive: false });
            window.addEventListener('touchmove', moveCrop, { passive: false });
            window.addEventListener('touchend', endCrop);
        })();

        function clearCropSelection() {
            activeCropBox = null;
            const cropRect = document.getElementById('crop-selection-rect');
            if (cropRect) cropRect.style.display = 'none';
            const bar = document.getElementById('crop-actions-bar');
            if (bar) {
                bar.classList.add('hidden');
                bar.classList.remove('flex');
            }
        }

        function getCanvasDataUrl(onlyCropped = false) {
            const srcCanvas = document.getElementById('source-render-canvas');
            if (!onlyCropped || !activeCropBox || activeCropBox.w < 15) {
                return srcCanvas.toDataURL('image/jpeg', 0.88);
            }

            const scaleX = srcCanvas.width / Math.max(1, activeCropBox.layerW);
            const scaleY = srcCanvas.height / Math.max(1, activeCropBox.layerH);

            const sx = Math.max(0, Math.floor(activeCropBox.x * scaleX));
            const sy = Math.max(0, Math.floor(activeCropBox.y * scaleY));
            const sw = Math.min(srcCanvas.width - sx, Math.floor(activeCropBox.w * scaleX));
            const sh = Math.min(srcCanvas.height - sy, Math.floor(activeCropBox.h * scaleY));

            const tempCanvas = document.createElement('canvas');
            tempCanvas.width  = sw;
            tempCanvas.height = sh;
            const tCtx = tempCanvas.getContext('2d');
            tCtx.fillStyle = '#ffffff';
            tCtx.fillRect(0, 0, sw, sh);
            tCtx.drawImage(srcCanvas, sx, sy, sw, sh, 0, 0, sw, sh);

            return tempCanvas.toDataURL('image/png');
        }

        async function autoCropFigureBoxFromCanvas(figureBox) {
            if (!Array.isArray(figureBox) || figureBox.length !== 4) return '';
            const srcCanvas = document.getElementById('source-render-canvas');
            if (!srcCanvas || srcCanvas.width < 50) return '';

            const pad  = 10;
            const ymin = Math.max(0, Number(figureBox[0]) - pad);
            const xmin = Math.max(0, Number(figureBox[1]) - pad);
            const ymax = Math.min(1000, Number(figureBox[2]) + pad);
            const xmax = Math.min(1000, Number(figureBox[3]) + pad);

            const sx = Math.floor((xmin / 1000) * srcCanvas.width);
            const sy = Math.floor((ymin / 1000) * srcCanvas.height);
            const sw = Math.floor(((xmax - xmin) / 1000) * srcCanvas.width);
            const sh = Math.floor(((ymax - ymin) / 1000) * srcCanvas.height);

            if (sw < 24 || sh < 24) return '';

            const tempCanvas = document.createElement('canvas');
            tempCanvas.width  = sw;
            tempCanvas.height = sh;
            const tCtx = tempCanvas.getContext('2d');
            tCtx.fillStyle = '#ffffff';
            tCtx.fillRect(0, 0, sw, sh);
            tCtx.drawImage(srcCanvas, sx, sy, sw, sh, 0, 0, sw, sh);

            const base64 = tempCanvas.toDataURL('image/png');
            const fd = new FormData();
            fd.append('action', 'upload_question_image');
            fd.append('image_base64', base64);

            const res = await fetch('api/api_exam.php', { method: 'POST', body: fd });
            const data = await res.json();
            return data.status === 'success' ? (data.image_path || '') : '';
        }

        async function uploadCurrentCropToServer() {
            const base64 = getCanvasDataUrl(true);
            const fd = new FormData();
            fd.append('action', 'upload_question_image');
            fd.append('image_base64', base64);

            const res = await fetch('api/api_exam.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                return data.image_path;
            }
            throw new Error(data.message || 'خطا در آپلود تصویر برش‌خورده');
        }

        async function attachCropAsDiagramToEditor() {
            try {
                const imgPath = await uploadCurrentCropToServer();
                setEditorQuestionImage(imgPath);
                clearCropSelection();
                document.getElementById('question-editor-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch (e) {
                alert(e.message);
            }
        }

        async function createFullVisualQuestionFromCrop() {
            try {
                const imgPath = await uploadCurrentCropToServer();
                resetQuestionEditor();
                setEditorQuestionImage(imgPath);
                document.getElementById('qe-text').value  = '';
                document.getElementById('qe-opt-1').value = 'گزینه ۱';
                document.getElementById('qe-opt-2').value = 'گزینه ۲';
                document.getElementById('qe-opt-3').value = 'گزینه ۳';
                document.getElementById('qe-opt-4').value = 'گزینه ۴';
                clearCropSelection();
                document.getElementById('question-editor-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch (e) {
                alert(e.message);
            }
        }

        // ====================================================
        // ۴. استخراج هوشمند تک‌صفحه‌ای و چندصفحه‌ای خودکار
        // ====================================================
        let isCrawlerRunning = false;

        async function extractSingleCurrentPageWithAi(onlyCropped = false, pageIndexForRotation = 0, shouldAutoCropFigures = true) {
            const base64Img = getCanvasDataUrl(onlyCropped);
            const hint      = document.getElementById('ai-ocr-hint').value.trim();

            const fd = new FormData();
            fd.append('action', 'extract_questions_ai');
            fd.append('image_base64', base64Img);
            fd.append('extra_hint', hint);
            fd.append('page_index', pageIndexForRotation);

            const res = await fetch('api/api_exam.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.status !== 'success' || !Array.isArray(data.questions)) {
                throw new Error(data.message || 'خطا در استخراج سوالات این صفحه');
            }

            const processed = [];
            for (const q of data.questions) {
                let figPath = '';
                if (shouldAutoCropFigures && !onlyCropped && Array.isArray(q.figure_box) && q.figure_box.length === 4) {
                    try {
                        figPath = await autoCropFigureBoxFromCanvas(q.figure_box);
                    } catch (ignore) {}
                }
                processed.push({
                    ...q,
                    question_image: figPath
                });
            }

            fetchAiKeysStatus();
            return processed;
        }

        async function runAiQuestionExtraction(onlyCropped = false) {
            if (!activeExamObj) {
                alert('ابتدا یک آزمون را انتخاب کنید.');
                return;
            }

            const btn = document.getElementById('btn-ai-extract-page');
            const origHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span>⏳ در حال استخراج با هوش مصنوعی...</span>';

            try {
                const autoCrop = document.getElementById('crawler-auto-crop-fig')?.checked ?? true;
                const extracted = await extractSingleCurrentPageWithAi(onlyCropped, studioPdfPageNum, autoCrop);
                aiStagedQuestions = [...aiStagedQuestions, ...extracted];
                clearCropSelection();
                renderAiStagedQuestions();
                document.getElementById('ai-staged-container').scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch (err) {
                alert(err.message || 'خطا در ارتباط با سرور هوش مصنوعی.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        }

        async function startMultiPageCrawler() {
            if (!activeExamObj) {
                alert('لطفاً ابتدا یک آزمون را انتخاب کنید.');
                return;
            }
            if (!studioPdfDoc) {
                alert('لطفاً ابتدا یک فایل PDF سوالات را باز کنید.');
                return;
            }

            let startP = Math.max(1, parseInt(document.getElementById('crawler-start-page').value) || 1);
            let endP   = Math.min(studioPdfDoc.numPages, parseInt(document.getElementById('crawler-end-page').value) || studioPdfDoc.numPages);
            if (endP < startP) {
                const tmp = startP; startP = endP; endP = tmp;
            }

            const autoCropFigs = document.getElementById('crawler-auto-crop-fig').checked;
            const autoSaveDb   = document.getElementById('crawler-auto-save-db').checked;
            const totalPagesToCrawl = (endP - startP) + 1;

            isCrawlerRunning = true;
            document.getElementById('btn-crawler-start').classList.add('hidden');
            document.getElementById('btn-crawler-stop').classList.remove('hidden');
            document.getElementById('crawler-status-box').classList.remove('hidden');

            const statusTx = document.getElementById('crawler-status-text');
            const progPct  = document.getElementById('crawler-progress-pct');
            const progBar  = document.getElementById('crawler-progress-bar');

            let totalExtractedCount = 0;

            for (let p = startP; p <= endP; p++) {
                if (!isCrawlerRunning) break;

                const doneStep = p - startP;
                const pct = Math.round((doneStep / totalPagesToCrawl) * 100);
                progPct.textContent = `${pct}%`;
                progBar.style.width = `${pct}%`;
                statusTx.textContent = `📄 در حال رندر و استخراج هوشمند صفحه ${p} از ${endP} (تاکنون ${totalExtractedCount} سوال استخراج شده)...`;

                studioPdfPageNum = p;
                await renderStudioPdfPage(p);

                if (!isCrawlerRunning) break;

                try {
                    const pageQuestions = await extractSingleCurrentPageWithAi(false, p, autoCropFigs);
                    totalExtractedCount += pageQuestions.length;

                    if (autoSaveDb && pageQuestions.length > 0) {
                        const fd = new FormData();
                        fd.append('action', 'save_questions_batch');
                        fd.append('exam_id', activeExamObj.id);
                        fd.append('questions_json', JSON.stringify(pageQuestions));
                        await fetch('api/api_exam.php', { method: 'POST', body: fd });
                        await onSelectStudioExam(activeExamObj.id);
                    } else if (pageQuestions.length > 0) {
                        aiStagedQuestions = [...aiStagedQuestions, ...pageQuestions];
                        renderAiStagedQuestions();
                    }
                } catch (pageErr) {
                    statusTx.textContent = `⚠️ صفحه ${p}: ${pageErr.message} — ادامه به صفحه بعد...`;
                }

                if (p < endP && isCrawlerRunning) {
                    await new Promise(r => setTimeout(r, 1200));
                }
            }

            progPct.textContent = '100%';
            progBar.style.width = '100%';
            statusTx.textContent = isCrawlerRunning
                ? `🎉 استخراج خودکار صفحات ${startP} تا ${endP} کامل شد! مجموعاً ${totalExtractedCount} سوال استخراج گردید.`
                : `⏸ استخراج خودکار در صفحه ${studioPdfPageNum} متوقف شد (${totalExtractedCount} سوال استخراج شد).`;

            isCrawlerRunning = false;
            document.getElementById('btn-crawler-start').classList.remove('hidden');
            document.getElementById('btn-crawler-stop').classList.add('hidden');
        }

        function stopMultiPageCrawler() {
            isCrawlerRunning = false;
            document.getElementById('btn-crawler-start').classList.remove('hidden');
            document.getElementById('btn-crawler-stop').classList.add('hidden');
        }

        function renderAiStagedQuestions() {
            const container = document.getElementById('ai-staged-container');
            const listEl    = document.getElementById('ai-staged-list');
            const badge     = document.getElementById('ai-staged-badge');

            if (aiStagedQuestions.length === 0) {
                container.classList.add('hidden');
                return;
            }

            container.classList.remove('hidden');
            badge.textContent = `${aiStagedQuestions.length} سوال`;

            listEl.innerHTML = aiStagedQuestions.map((q, idx) => `
                <div class="bg-white p-3.5 rounded-2xl border border-indigo-200 shadow-sm space-y-2.5 text-xs">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                        <span class="font-extrabold text-indigo-900">سوال استخراجی #${idx + 1}</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button" onclick="attachCurrentCropToStagedQuestion(${idx})" class="bg-amber-100 hover:bg-amber-200 text-amber-900 px-2.5 py-1 rounded-lg font-bold text-[10px]">
                                ✂️ جایگزینی شکل با کادر انتخابی روی PDF
                            </button>
                            <button type="button" onclick="removeStagedQuestion(${idx})" class="text-rose-500 hover:text-rose-700 font-bold px-1.5">✕ حذف</button>
                        </div>
                    </div>

                    ${q.question_image ? `
                        <div class="bg-slate-50 p-2 rounded-xl border border-emerald-200 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <img src="${escapeHtml(q.question_image)}" class="max-h-28 rounded border bg-white p-0.5">
                                <span class="text-[10px] text-emerald-700 font-extrabold">✓ شکل سوال پیوست شد</span>
                            </div>
                            <button type="button" onclick="aiStagedQuestions[${idx}].question_image='';renderAiStagedQuestions();" class="text-rose-600 text-[11px] font-bold">حذف شکل</button>
                        </div>
                    ` : ''}

                    <textarea oninput="aiStagedQuestions[${idx}].question_text=this.value" rows="3" dir="auto" class="w-full p-2 bg-slate-50 border border-slate-300 rounded-xl font-bold text-slate-800 font-mono text-[11px]">${escapeHtml(q.question_text)}</textarea>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        ${[1, 2, 3, 4].map(op => `
                            <div class="flex items-center gap-1.5">
                                <input type="radio" name="staged_corr_${idx}" ${Number(q.correct_option) === op ? 'checked' : ''} onchange="aiStagedQuestions[${idx}].correct_option=${op}" class="w-4 h-4" title="گزینه صحیح">
                                <span class="font-bold text-slate-500">${op})</span>
                                <input type="text" dir="auto" value="${escapeHtml(q['option_' + op] || '')}" oninput="aiStagedQuestions[${idx}]['option_${op}']=this.value" class="flex-1 px-2.5 py-1.5 border border-slate-300 rounded-lg">
                            </div>
                        `).join('')}
                    </div>

                    <div>
                        <input type="text" value="${escapeHtml(q.explanation || '')}" oninput="aiStagedQuestions[${idx}].explanation=this.value" placeholder="پاسخ تشریحی..." class="w-full px-2.5 py-1.5 bg-amber-50 border border-amber-200 rounded-lg text-[11px]">
                    </div>
                </div>
            `).join('');
        }

        async function attachCurrentCropToStagedQuestion(idx) {
            if (!activeCropBox || activeCropBox.w < 18) {
                alert('لطفاً ابتدا با کشیدن موس روی صفحه PDF بالا، دور شکل یا نمودار مورد نظر کادر بکشید و سپس روی این دکمه بزنید.');
                return;
            }
            try {
                const imgPath = await uploadCurrentCropToServer();
                aiStagedQuestions[idx].question_image = imgPath;
                clearCropSelection();
                renderAiStagedQuestions();
            } catch (e) {
                alert(e.message);
            }
        }

        function removeStagedQuestion(idx) {
            aiStagedQuestions.splice(idx, 1);
            renderAiStagedQuestions();
        }

        function clearStagedQuestions() {
            aiStagedQuestions = [];
            renderAiStagedQuestions();
        }

        async function saveAllStagedQuestions() {
            if (!activeExamObj || aiStagedQuestions.length === 0) return;
            const btn = document.getElementById('btn-save-all-staged');
            btn.disabled = true;
            btn.textContent = '⏳ در حال ثبت در آزمون...';

            try {
                const fd = new FormData();
                fd.append('action', 'save_questions_batch');
                fd.append('exam_id', activeExamObj.id);
                fd.append('questions_json', JSON.stringify(aiStagedQuestions));

                const res = await fetch('api/api_exam.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    alert(data.message);
                    clearStagedQuestions();
                    await fetchAdminExams(activeExamObj.id);
                } else {
                    alert(data.message || 'خطا در ذخیره دسته‌جمعی سوالات');
                }
            } catch (e) {
                alert('خطا در ارتباط با سرور.');
            } finally {
                btn.disabled = false;
                btn.textContent = '✅ تایید و افزودن همه به بانک آزمون';
            }
        }

        // ====================================================
        // ۵. فرم ویرایشگر تکی سوال و بانک سوالات آزمون
        // ====================================================
        function insertPseudocodeBlockInEditor() {
            const ta = document.getElementById('qe-text');
            const start = ta.selectionStart || 0;
            const end   = ta.selectionEnd || 0;
            const selected = ta.value.substring(start, end);
            const snippet = selected
                ? `\n\`\`\`\n${selected}\n\`\`\`\n`
                : `\n\`\`\`\nfor i = 1 to n do:\n    print(i)\n\`\`\`\n`;
            ta.value = ta.value.substring(0, start) + snippet + ta.value.substring(end);
            ta.focus();
        }

        function setEditorQuestionImage(relPath) {
            document.getElementById('qe-image-path').value = relPath || '';
            const box = document.getElementById('qe-image-preview-box');
            const img = document.getElementById('qe-image-preview-el');
            if (relPath) {
                img.src = relPath;
                box.classList.remove('hidden');
            } else {
                img.src = '';
                box.classList.add('hidden');
            }
        }

        function removeEditorQuestionImage() {
            setEditorQuestionImage('');
        }

        async function handleManualQuestionImageUpload(e) {
            const file = e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = async (ev) => {
                const fd = new FormData();
                fd.append('action', 'upload_question_image');
                fd.append('image_base64', ev.target.result);
                const res = await fetch('api/api_exam.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    setEditorQuestionImage(data.image_path);
                } else {
                    alert(data.message || 'خطا در آپلود تصویر');
                }
            };
            reader.readAsDataURL(file);
        }

        function resetQuestionEditor() {
            document.getElementById('question-editor-form').reset();
            document.getElementById('qe-id').value = '0';
            document.getElementById('question-editor-title').textContent = '➕ افزودن سوال جدید به آزمون';
            setEditorQuestionImage('');
            document.getElementById('qe-opt-1').value = 'گزینه ۱';
            document.getElementById('qe-opt-2').value = 'گزینه ۲';
            document.getElementById('qe-opt-3').value = 'گزینه ۳';
            document.getElementById('qe-opt-4').value = 'گزینه ۴';
        }

        function loadQuestionIntoEditor(qId) {
            const q = savedQuestions.find(x => Number(x.id) === Number(qId));
            if (!q) return;

            document.getElementById('question-editor-title').textContent = `✏️ ویرایش سوال #${q.sort_order}`;
            document.getElementById('qe-id').value          = q.id;
            document.getElementById('qe-text').value        = q.question_text || '';
            document.getElementById('qe-opt-1').value       = q.option_1 || 'گزینه ۱';
            document.getElementById('qe-opt-2').value       = q.option_2 || 'گزینه ۲';
            document.getElementById('qe-opt-3').value       = q.option_3 || 'گزینه ۳';
            document.getElementById('qe-opt-4').value       = q.option_4 || 'گزینه ۴';
            document.getElementById('qe-explanation').value = q.explanation || '';

            const radio = document.querySelector(`input[name="qe_correct"][value="${q.correct_option}"]`);
            if (radio) radio.checked = true;

            setEditorQuestionImage(q.question_image || '');
            document.getElementById('question-editor-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        async function saveSingleQuestion(e) {
            e.preventDefault();
            if (!activeExamObj) return;

            const corrRadio = document.querySelector('input[name="qe_correct"]:checked');
            const fd = new FormData();
            fd.append('action', 'save_question');
            fd.append('id', document.getElementById('qe-id').value);
            fd.append('exam_id', activeExamObj.id);
            fd.append('question_text', document.getElementById('qe-text').value);
            fd.append('question_image', document.getElementById('qe-image-path').value);
            fd.append('option_1', document.getElementById('qe-opt-1').value);
            fd.append('option_2', document.getElementById('qe-opt-2').value);
            fd.append('option_3', document.getElementById('qe-opt-3').value);
            fd.append('option_4', document.getElementById('qe-opt-4').value);
            fd.append('correct_option', corrRadio ? corrRadio.value : '1');
            fd.append('explanation', document.getElementById('qe-explanation').value);

            const res = await fetch('api/api_exam.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                resetQuestionEditor();
                await fetchAdminExams(activeExamObj.id);
            } else {
                alert(data.message || 'خطا در ذخیره سوال');
            }
        }

        async function deleteSavedQuestion(qId) {
            if (!confirm('این سوال از آزمون حذف شود؟')) return;
            const fd = new FormData();
            fd.append('action', 'delete_question');
            fd.append('question_id', qId);
            await fetch('api/api_exam.php', { method: 'POST', body: fd });
            if (activeExamObj) fetchAdminExams(activeExamObj.id);
        }

        function renderSavedQuestionsBank() {
            const listEl = document.getElementById('saved-questions-list');
            document.getElementById('saved-questions-count-badge').textContent = `${savedQuestions.length} سوال`;

            if (savedQuestions.length === 0) {
                listEl.innerHTML = `<div class="text-center text-slate-400 py-10">هنوز سوالی در این آزمون ذخیره نشده است.</div>`;
                return;
            }

            listEl.innerHTML = savedQuestions.map((q, idx) => {
                const corr = Number(q.correct_option);
                return `
                    <div class="p-3 bg-slate-50 rounded-2xl border border-slate-200 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="bg-slate-800 text-white px-2.5 py-0.5 rounded-lg font-extrabold text-[11px]">سوال ${idx + 1}</span>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="loadQuestionIntoEditor(${q.id})" class="text-indigo-600 hover:underline font-bold text-[11px]">✏️ ویرایش</button>
                                <button type="button" onclick="deleteSavedQuestion(${q.id})" class="text-rose-500 hover:text-rose-700 font-bold text-[11px]">🗑️ حذف</button>
                            </div>
                        </div>

                        ${q.question_image ? `<img src="${escapeHtml(q.question_image)}" class="max-h-40 rounded-xl border border-slate-300 bg-white p-1 mx-auto">` : ''}
                        ${q.question_text ? `<div class="font-bold text-slate-800 leading-relaxed whitespace-pre-line">${formatRichQuestionHtml(q.question_text)}</div>` : ''}

                        <div class="grid grid-cols-2 gap-1.5 pt-1">
                            ${[1, 2, 3, 4].map(op => `
                                <div dir="auto" class="px-2.5 py-1.5 rounded-xl border text-[11px] ${corr === op ? 'bg-emerald-100 border-emerald-400 text-emerald-950 font-extrabold' : 'bg-white border-slate-200 text-slate-600'}">
                                    ${op}) ${formatRichQuestionHtml(q['option_' + op] || '')} ${corr === op ? '✅' : ''}
                                </div>
                            `).join('')}
                        </div>

                        ${q.explanation ? `<div class="text-[11px] bg-amber-50 border border-amber-200 text-amber-900 p-2 rounded-xl">💡 <b>پاسخ تشریحی:</b> ${formatRichQuestionHtml(q.explanation)}</div>` : ''}
                    </div>
                `;
            }).join('');
        }

        // ====================================================
        // ۶. توابع تب مدیریت کاربران و کدهای اشتراک
        // ====================================================
        async function chargeUserVip(targetUid, days) {
            const msg = days > 0 ? `آیا ${days} روز اشتراک ویژه به این کاربر اضافه شود؟` : 'آیا اشتراک ویژه این کاربر لغو شود؟';
            if (!confirm(msg)) return;

            const fd = new FormData();
            fd.append('admin_ajax_action', 'charge_user_vip');
            fd.append('target_user_id', targetUid);
            fd.append('days', days);

            const res = await fetch('admin.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message);
            if (data.status === 'success') location.reload();
        }

        async function handleGenerateCodes(e) {
            e.preventDefault();
            const fd = new FormData();
            fd.append('admin_ajax_action', 'generate_codes');
            fd.append('plan_type', document.getElementById('gen-plan-type').value);
            fd.append('count', document.getElementById('gen-code-count').value);

            const res = await fetch('admin.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message);
            if (data.status === 'success') location.reload();
        }

        async function deletePromoCode(cid) {
            if (!confirm('این کد حذف شود؟')) return;
            const fd = new FormData();
            fd.append('admin_ajax_action', 'delete_code');
            fd.append('code_id', cid);
            await fetch('admin.php', { method: 'POST', body: fd });
            location.reload();
        }

        function filterAdminUsersTable() {
            const q = document.getElementById('admin-user-search').value.trim().toLowerCase();
            document.querySelectorAll('.admin-user-row').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        }

        function escapeHtml(str) {
            return String(str || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
        }

        window.addEventListener('DOMContentLoaded', () => {
            fetchAdminExams();
            fetchAdminCourses();
            fetchAiKeysStatus();

            if (new URLSearchParams(window.location.search).get('tab') === 'courses') {
                switchAdminTab('courses');
            }
        });

        async function fetchAparatMetaForEditor() {
        const urlInput = document.getElementById('le-video-url');
        const statusEl = document.getElementById('aparat-fetch-status');
        const btn      = document.getElementById('btn-fetch-aparat');
        const rawVal   = urlInput.value.trim();

        if (!rawVal) {
            alert('لطفاً ابتدا لینک صفحه آپارات یا کد امبد آن را در کادر وارد کنید.');
            return;
        }

        btn.disabled = true;
        btn.textContent = '⏳ در حال دریافت...';

        try {
            const fd = new FormData();
            fd.append('action', 'fetch_aparat_meta');
            fd.append('video_url', rawVal);

            const res = await fetch('api/api_courses.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.status === 'success') {
                urlInput.value = data.clean_url;
                const titleInput = document.getElementById('le-title');
                if (!titleInput.value.trim() && data.title) {
                    titleInput.value = data.title;
                }
                if (data.duration_mins > 0) {
                    document.getElementById('le-duration-mins').value = data.duration_mins;
                }
                if (statusEl) {
                    statusEl.textContent = `✓ ویدیوی آپارات شناسایی شد: «${data.title || data.aparat_hash}» | زمان: ${data.duration_mins} دقیقه | استریم مستقیم: ${data.has_direct_mp4 ? 'فعال ✅' : 'پلیر آی‌فریم 🟣'}`;
                    statusEl.classList.remove('hidden');
                }
            } else {
                alert(data.message || 'خطا در شناسایی لینک آپارات');
            }
        } catch (e) {
            alert('خطا در ارتباط با سرور.');
        } finally {
            btn.disabled = false;
            btn.textContent = '🟣 استعلام از آپارات';
        }
    }
