/**
 * The exam player — one Vue app on /exam/{slug}/{paper}.
 *
 * Every write is a storefront.actions call through window.ThemeApi.request(), which carries the
 * customer's token; the server owns the rules (clock only moves one way, timed papers end
 * themselves, ended papers refuse reports, half-step marks, model answers only after the end)
 * and this file only keeps the page honest about what the server said.
 *
 * Zero-build: written against the global Vue the layout loads, no imports, no bundler. The
 * markup is in pages/exam-paper.blade.php so its wording goes through __(); this is behaviour.
 */
(function () {
    'use strict';

    window.LumenPlayer = window.LumenPlayer || {};

    const ACTIONS = '/api/storefront/actions/';

    const call = (name, body) => window.ThemeApi.request(ACTIONS + name, 'POST', body);

    const messageOf = (err, fallback) => {
        const data = (err && err.data) || {};
        if (data.errors) {
            const first = Object.values(data.errors).flat()[0];
            if (first) return String(first);
        }
        return data.message || fallback;
    };

    const pad = (n) => String(n).padStart(2, '0');

    const formatClock = (seconds) => {
        const s = Math.max(0, Math.floor(seconds));
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const r = s % 60;
        return h > 0 ? `${h}:${pad(m)}:${pad(r)}` : `${pad(m)}:${pad(r)}`;
    };

    const isTyping = (target) => {
        if (!target) return false;
        const tag = (target.tagName || '').toLowerCase();
        return tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable;
    };

    const freshView = () => ({ scale: 1, x: 0, y: 0, rot: 0, brightness: 1, contrast: 1 });

    window.LumenPlayer.mount = function (selector, config) {
        const { createApp, ref, reactive, computed, onMounted, onBeforeUnmount } = Vue;
        const strings = config.strings || {};

        createApp({
            setup() {
                // ── state ────────────────────────────────────────────────────────
                const state = ref('loading');
                const error = ref('');
                const notice = ref('');
                const busy = ref(false);

                const attempt = ref(null);
                const cases = ref([]);
                const reports = reactive({});
                const answers = reactive({});
                const modelAnswers = reactive({});
                const marks = reactive({});
                const markState = reactive({});
                const visited = reactive({});
                const total = ref({ scored: 0, max: 0 });
                const settings = ref({ progress_ping_seconds: 10, require_all_answered_to_end: true });

                const index = ref(0);
                const imageIndex = ref(0);
                const view = reactive(freshView());
                const tool = ref('pan');
                const saveState = ref('idle');

                // The clock. `duration` is the sitting's own, frozen when it was opened;
                // `baseSpent` is what the server had when we opened, and elapsed is ours.
                const tick = ref(0);
                let duration = 0;
                let baseSpent = 0;
                let openedAt = 0;
                let ended = ref(false);

                const timed = computed(() => attempt.value && attempt.value.mode === 'timed');
                const current = computed(() => cases.value[index.value] || null);
                const image = computed(() => current.value ? (current.value.images[imageIndex.value] || null) : null);

                const spent = () => ended.value
                    ? baseSpent
                    : baseSpent + Math.floor((performance.now() - openedAt) / 1000);

                const remaining = computed(() => { void tick.value; return Math.max(0, duration - spent()); });

                const clock = computed(() => {
                    void tick.value;
                    return timed.value ? formatClock(remaining.value) : formatClock(spent());
                });

                const saveLabel = computed(() => ({
                    saving: strings.saving, saved: strings.saved, error: strings.unsaved,
                }[saveState.value] || ''));

                const imageStyle = computed(() => ({
                    transform: `translate(${view.x}px, ${view.y}px) scale(${view.scale}) rotate(${view.rot}deg)`,
                    filter: `brightness(${view.brightness}) contrast(${view.contrast})`,
                }));

                const hasReport = (id) => typeof reports[id] === 'string' && reports[id].trim() !== '';

                // ── loading ──────────────────────────────────────────────────────
                const applyOpen = (data) => {
                    attempt.value = data.attempt;
                    cases.value = data.cases || [];
                    settings.value = Object.assign(settings.value, data.settings || {});
                    ended.value = !!data.attempt.ended_at;

                    Object.entries(data.answers || {}).forEach(([caseId, a]) => {
                        answers[caseId] = a;
                        if (reports[caseId] === undefined) reports[caseId] = a.report || '';
                        if (a.score !== null && a.score !== undefined) marks[caseId] = a.score;
                    });
                    cases.value.forEach((c) => { if (reports[c.id] === undefined) reports[c.id] = ''; });
                    (data.attempt.visited_case_ids || []).forEach((id) => { visited[id] = true; });

                    if (data.model_answers) Object.assign(modelAnswers, data.model_answers);
                    if (data.total) total.value = data.total;

                    duration = (data.attempt.seconds_remaining || 0) + (data.attempt.seconds_spent || 0);
                    baseSpent = data.attempt.seconds_spent || 0;
                    openedAt = performance.now();
                };

                const open = async () => {
                    const res = await call('attempts.open', { paper: config.paper });
                    applyOpen(res.data);
                    return res.data;
                };

                // Signed image links live ten minutes; a sitting lives longer. Re-opening is
                // idempotent and returns fresh links, so it is the refresh.
                let refreshing = false;
                const refreshImages = async () => {
                    if (refreshing) return;
                    refreshing = true;
                    try {
                        const res = await call('attempts.open', { paper: config.paper });
                        const fresh = new Map((res.data.cases || []).map((c) => [c.id, c.images]));
                        cases.value.forEach((c) => { if (fresh.has(c.id)) c.images = fresh.get(c.id); });
                    } catch (e) { /* the next scheduled refresh will try again */ }
                    finally { refreshing = false; }
                };

                // ── progress ─────────────────────────────────────────────────────
                const enterReview = async (data) => {
                    if (!data) {
                        try { data = (await call('attempts.end', { attempt: attempt.value.id })).data; }
                        catch (e) { notice.value = messageOf(e, strings.failed); return; }
                    }
                    ended.value = true;
                    baseSpent = data.attempt ? data.attempt.seconds_spent : spent();
                    if (data.attempt) attempt.value = data.attempt;
                    Object.assign(modelAnswers, data.model_answers || {});
                    Object.entries(data.answers || {}).forEach(([caseId, a]) => {
                        answers[caseId] = a;
                        if (a.score !== null && a.score !== undefined) marks[caseId] = a.score;
                    });
                    if (data.total) total.value = data.total;
                };

                const ping = async () => {
                    if (!attempt.value || ended.value) return;
                    try {
                        const res = await call('attempts.progress', {
                            attempt: attempt.value.id,
                            seconds_spent: spent(),
                            case: current.value ? current.value.id : null,
                        });
                        // The server holds the larger figure; never let ours fall behind it.
                        const serverSpent = res.data.seconds_spent || 0;
                        if (serverSpent > spent()) {
                            baseSpent = serverSpent;
                            openedAt = performance.now();
                        }
                        // Only a ping that *discovers* the end is the timer firing. One that was
                        // in flight while the candidate pressed End comes back `ended` too, and
                        // must not announce time up over a paper they just ended themselves.
                        if (res.data.ended && !ended.value) {
                            notice.value = strings.timeUp;
                            await enterReview();
                        }
                    } catch (e) {
                        // A lost ping costs nothing: the next one carries the larger figure.
                    }
                };

                // ── reports ──────────────────────────────────────────────────────
                const dirty = new Set();
                let debounce = null;

                const saveReport = async (caseId) => {
                    if (!attempt.value || ended.value) return;
                    dirty.delete(caseId);
                    saveState.value = 'saving';
                    try {
                        const res = await call('answers.save', {
                            attempt: attempt.value.id, case: caseId, report: reports[caseId] || '',
                        });
                        answers[caseId] = res.data.answer;
                        saveState.value = 'saved';
                    } catch (e) {
                        saveState.value = 'error';
                        const msg = messageOf(e, strings.failed);
                        notice.value = msg;
                        // "This paper has ended" — the timer fired on the server between pings.
                        if (e && e.data && e.data.errors && e.data.errors.attempt) await enterReview();
                    }
                };

                const flush = async () => {
                    if (debounce) { clearTimeout(debounce); debounce = null; }
                    for (const id of Array.from(dirty)) await saveReport(id);
                };

                const onReportInput = (caseId) => {
                    if (ended.value) return;
                    dirty.add(caseId);
                    saveState.value = 'idle';
                    if (debounce) clearTimeout(debounce);
                    debounce = setTimeout(() => saveReport(caseId), 800);
                };

                // ── ending ───────────────────────────────────────────────────────
                const endPaper = async () => {
                    if (ended.value || busy.value) return;
                    await flush();

                    const blank = cases.value.filter((c) => !hasReport(c.id)).length;
                    if (settings.value.require_all_answered_to_end && blank > 0 && (!timed.value || remaining.value > 0)) {
                        notice.value = blank === 1 ? strings.blankOne : strings.blank.replace(':count', blank);
                        return;
                    }
                    if (!window.confirm(strings.confirmEnd)) return;

                    busy.value = true;
                    try {
                        const res = await call('attempts.end', { attempt: attempt.value.id });
                        notice.value = '';
                        await enterReview(res.data);
                    } catch (e) {
                        notice.value = messageOf(e, strings.failed);
                    } finally {
                        busy.value = false;
                    }
                };

                const saveMark = async (caseId) => {
                    const answer = answers[caseId];
                    if (!answer || busy.value) return;
                    busy.value = true;
                    markState[caseId] = strings.saving;
                    try {
                        const res = await call('answers.mark', { answer: answer.id, score: Number(marks[caseId]) });
                        answers[caseId] = Object.assign({}, answer, res.data.answer);
                        total.value = res.data.total;
                        markState[caseId] = strings.markSaved;
                    } catch (e) {
                        markState[caseId] = messageOf(e, strings.failed);
                    } finally {
                        busy.value = false;
                    }
                };

                // ── navigation ───────────────────────────────────────────────────
                const go = async (i) => {
                    if (i < 0 || i >= cases.value.length) return;
                    await flush();
                    index.value = i;
                    imageIndex.value = 0;
                    Object.assign(view, freshView());
                    tool.value = 'pan';
                    if (current.value) visited[current.value.id] = true;
                };
                const prev = () => go(index.value - 1);
                const next = () => go(index.value + 1);

                // ── viewer ───────────────────────────────────────────────────────
                const showImage = (i) => { imageIndex.value = i; Object.assign(view, freshView()); };
                const zoomBy = (factor) => { view.scale = Math.min(8, Math.max(0.2, view.scale * factor)); };
                const rotate = () => { view.rot = (view.rot + 90) % 360; };
                const toggleWindow = () => { tool.value = tool.value === 'window' ? 'pan' : 'window'; };
                const resetView = () => { Object.assign(view, freshView()); };
                const onWheel = (e) => zoomBy(e.deltaY < 0 ? 1.1 : 0.9);

                let drag = null;
                const onDown = (e) => {
                    if (e.button !== 0) return;
                    drag = { x: e.clientX, y: e.clientY, view: Object.assign({}, view) };
                };
                const onMove = (e) => {
                    if (!drag) return;
                    const dx = e.clientX - drag.x;
                    const dy = e.clientY - drag.y;
                    if (tool.value === 'window') {
                        view.contrast = Math.min(3, Math.max(0.2, drag.view.contrast + dx * 0.005));
                        view.brightness = Math.min(3, Math.max(0.2, drag.view.brightness - dy * 0.005));
                    } else {
                        view.x = drag.view.x + dx;
                        view.y = drag.view.y + dy;
                    }
                };
                const onUp = () => { drag = null; };

                const failedImages = new Set();
                const onImageError = (e) => {
                    const src = e && e.target ? e.target.src : '';
                    if (failedImages.has(src)) return;
                    failedImages.add(src);
                    refreshImages();
                };
                const onImageLoad = () => { /* nothing to do; kept for symmetry with @error */ };

                const onKey = (e) => {
                    if (isTyping(e.target) || e.ctrlKey || e.metaKey || e.altKey) return;
                    switch (e.key) {
                        case 'ArrowLeft': prev(); break;
                        case 'ArrowRight': next(); break;
                        case '+': case '=': zoomBy(1.25); break;
                        case '-': case '_': zoomBy(0.8); break;
                        case 'r': case 'R': rotate(); break;
                        case 'w': case 'W': toggleWindow(); break;
                        case 'f': case 'F': resetView(); break;
                        default: return;
                    }
                    e.preventDefault();
                };

                // ── lifecycle ────────────────────────────────────────────────────
                let clockTimer = null;
                let pingTimer = null;
                let refreshTimer = null;

                const onBeforeUnload = (e) => {
                    if (dirty.size === 0 || ended.value) return;
                    e.preventDefault();
                    e.returnValue = '';
                };
                const onVisibility = () => { if (document.visibilityState === 'hidden') flush(); };

                onMounted(async () => {
                    try {
                        await open();
                        state.value = 'ready';
                    } catch (e) {
                        error.value = messageOf(e, strings.noAccess);
                        state.value = 'error';
                        return;
                    }

                    if (current.value) visited[current.value.id] = true;

                    clockTimer = setInterval(() => {
                        tick.value++;
                        if (timed.value && !ended.value && remaining.value <= 0) {
                            notice.value = strings.timeUp;
                            enterReview();
                        }
                    }, 1000);

                    pingTimer = setInterval(ping, Math.max(3, settings.value.progress_ping_seconds || 10) * 1000);
                    refreshTimer = setInterval(refreshImages, 8 * 60 * 1000);

                    window.addEventListener('mousemove', onMove);
                    window.addEventListener('mouseup', onUp);
                    window.addEventListener('keydown', onKey);
                    window.addEventListener('beforeunload', onBeforeUnload);
                    document.addEventListener('visibilitychange', onVisibility);
                });

                onBeforeUnmount(() => {
                    clearInterval(clockTimer); clearInterval(pingTimer); clearInterval(refreshTimer);
                    window.removeEventListener('mousemove', onMove);
                    window.removeEventListener('mouseup', onUp);
                    window.removeEventListener('keydown', onKey);
                    window.removeEventListener('beforeunload', onBeforeUnload);
                    document.removeEventListener('visibilitychange', onVisibility);
                });

                return {
                    strings,
                    state, error, notice, busy,
                    attempt, cases, reports, answers, modelAnswers, marks, markState, visited, total, settings,
                    index, imageIndex, view, tool, saveState, saveLabel, ended, timed, current, image,
                    remaining, clock, imageStyle, hasReport,
                    onReportInput, flush, endPaper, saveMark,
                    go, prev, next, showImage, zoomBy, rotate, toggleWindow, resetView,
                    onWheel, onDown, onImageError, onImageLoad,
                };
            },
        }).mount(selector);
    };

})();
