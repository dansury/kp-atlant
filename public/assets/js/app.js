/**
 * Atlant Armour KP — SPA client.
 * Vanilla JS, hash-based routing, fetch API.
 */
const App = {
    manager: null,
    pollTimer: null,
    unread: 0,

    /**
     * Настройки, которые нужны интерфейсу, а не серверу: значения «под заказ»
     * по умолчанию, интервал опроса ящиков и звук уведомления (модуль 023).
     * Заполняются один раз при входе; до этого работают те же умолчания,
     * что стоят в `Settings::SPEC`.
     */
    ui: {
        wait_months: 3, wait_discount: 10, wait_prepay: 100,
        mail_poll_min: 10, mail_sound: '', mail_sound_volume: 60,
    },
    get waitDefaults() {
        return {months: this.ui.wait_months, discount: this.ui.wait_discount, prepay: this.ui.wait_prepay};
    },

    /**
     * Ответы GET-запросов, которые дорого получать (модуль 023).
     *
     * «Порой очень долгая загрузка карточки» — это `requests.php?action=get`:
     * он подбирает позиции по каталогу, спрашивает векторы и ищет аналоги.
     * Второй заход в ту же карточку не должен платить за это ещё раз, поэтому
     * ответ живёт здесь минуту, показывается сразу — и тут же перепроверяется
     * в фоне, так что на экране он устаревает не дольше одного мига.
     *
     * Любая запись (POST/PUT) чистит кэш целиком: что именно она изменила,
     * отсюда не видно, а показать старую цену после правки — хуже, чем
     * подождать.
     */
    _cache: new Map(),
    CACHE_TTL: 60000,

    cacheClear() { this._cache.clear(); },

    /**
     * @param {function} onFresh вызывается ещё раз, когда придёт свежий ответ,
     *   если он отличается от показанного
     */
    async apiCached(url, onFresh) {
        const hit = this._cache.get(url);
        const fresh = fetch => fetch.then(data => {
            const was = this._cache.get(url);
            this._cache.set(url, {at: Date.now(), data});
            if (onFresh && was && JSON.stringify(was.data) !== JSON.stringify(data)) onFresh(data);
            return data;
        });
        if (hit && Date.now() - hit.at < this.CACHE_TTL) {
            // Показали сохранённое, перепроверили в фоне
            fresh(this.api(url)).catch(() => {});
            return hit.data;
        }
        return fresh(this.api(url));
    },

    // API helper
    async api(url, opts = {}) {
        // Запись могла изменить что угодно — сохранённые ответы больше не в счёт
        if (opts.method && opts.method !== 'GET') this.cacheClear();
        const res = await fetch('/api/' + url, {
            method: opts.method || 'GET',
            headers: opts.body ? {'Content-Type': 'application/json'} : {},
            body: opts.body ? JSON.stringify(opts.body) : undefined,
            credentials: 'same-origin',
            cache: 'no-store',   // a cached "me" would show the login screen after a login
        });
        if (res.headers.get('content-type')?.includes('application/pdf')) return res;
        // A PHP fatal (or an empty body) is not JSON — without this the page would
        // wait for a promise that never resolves and stay on «Загрузка...»
        const raw = await res.text();
        let data;
        try {
            data = raw ? JSON.parse(raw) : {};
        } catch (e) {
            const err = new Error(`Сервер вернул не JSON (HTTP ${res.status}). ${raw.slice(0, 200)}`);
            err.status = res.status;
            throw err;
        }
        // An «error» field of a data row is not an error of the request itself:
        // a letter that failed to parse carries one, and the page must still open
        if (typeof data.error === 'string' && (!res.ok || data.code)) {
            const err = new Error(data.error);
            err.data = data;
            err.status = res.status;
            throw err;
        }
        if (!res.ok) {
            const err = new Error(`Сервер ответил HTTP ${res.status}`);
            err.status = res.status;
            throw err;
        }
        return data;
    },

    // Toast notifications
    toast(msg, type = 'info') {
        const el = document.createElement('div');
        el.className = `toast toast--${type}`;
        el.textContent = msg;
        document.getElementById('toasts').appendChild(el);
        setTimeout(() => el.remove(), 4000);
    },

    // Escape untrusted text (email bodies, client names) before injecting into HTML
    esc(v) {
        if (v === null || v === undefined) return '';
        return String(v).replace(/[&<>"']/g, c => (
            {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]
        ));
    },

    // Safe for a JS string literal inside an HTML attribute
    jsStr(v) {
        return this.esc(String(v ?? '').replace(/\\/g, '\\\\').replace(/'/g, "\\'"));
    },

    fmtDate(v, withTime = true) {
        if (!v) return '—';
        const d = new Date(v.replace(' ', 'T'));
        if (isNaN(d)) return this.esc(v);
        return withTime ? d.toLocaleString('ru-RU') : d.toLocaleDateString('ru-RU');
    },

    fmtMoney(v) {
        return (Number(v) || 0).toLocaleString('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ₽';
    },

    // "без ответа 3 ч" badge (FR-038)
    answerBadge(state) {
        if (!state || !state.unanswered) return '';
        const h = state.hours;
        const age = h < 1 ? 'меньше часа' : (h < 24 ? `${h} ч` : `${Math.floor(h / 24)} дн`);
        return `<span class="badge badge--unanswered badge--${state.level}">Без ответа ${age}</span>`;
    },

    // Triage verdict (module 006). The category picks the reply prompt and the
    // fact sources, so it is worth showing next to the row, not hiding in a log.
    categoryBadge(key, label) {
        if (!key) return '';
        const cls = {
            order: 'badge--order', kp_request: 'badge--kp',
            spam: 'badge--muted', service: 'badge--muted', supplier_offer: 'badge--muted',
            not_our_profile: 'badge--muted',
        }[key] || 'badge--kp';
        return `<span class="badge ${cls}" title="Категория запроса">${this.esc(label || key)}</span>`;
    },

    categoryLabels: {},

    async loadCategories() {
        if (Object.keys(this.categoryLabels).length) return this.categoryLabels;
        try {
            const d = await this.api('requests.php?action=categories');
            (d.categories || []).forEach(c => { this.categoryLabels[c.key] = c.label; });
        } catch { /* the selector just falls back to raw keys */ }
        return this.categoryLabels;
    },

    typeBadge(type) {
        return type === 'order'
            ? '<span class="badge badge--order">Заказ</span>'
            : '<span class="badge badge--kp">Запрос КП</span>';
    },

    // Init app
    async init() {
        this.registerServiceWorker();
        this.watchInstallPrompt();
        try {
            this.manager = await this.api('auth.php?action=me');
            this.renderNav();
            this.initPush();
            this.loadCategories();
            this.loadUiPrefs();
            this.startPolling();
            this.startMailPolling();
            this.route();
        } catch {
            let needsSetup = false;
            try { needsSetup = (await this.api('auth.php?action=state')).needs_setup; } catch {}
            needsSetup ? this.renderSetup() : this.renderLogin();
        }
        window.addEventListener('hashchange', () => this.route());

        // Coming back from the MoySklad tab must show fresh data (FR-030)
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && typeof this.onTabVisible === 'function') {
                this.onTabVisible();
            }
        });
    },

    // Set by pages that need a refresh when the tab regains focus
    onTabVisible: null,

    // Render navigation
    renderNav() {
        const nav = document.getElementById('nav');
        // «Запросы», «Почта», «Компании» и «Доски» used to be four separate
        // top-level tabs over the same underlying conversations — now one
        // «Письма» item, with the single board as its home view and the rest
        // reachable as tabs inside it (#mail/...). One «Настройки» item stays
        // the same way for the same reason.
        nav.innerHTML = `
            <a href="#mail" data-page="mail">Письма<span id="mailBadge"></span></a>
            <a href="#settings" data-page="settings">Настройки<span id="logBadge"></span></a>
            <a href="#notifications" data-page="notifications">Уведомления<span id="notifBadge"></span></a>
        `;
        document.getElementById('userBlock').innerHTML = `
            ${this.manager.name} <a onclick="App.logout()">Выход</a>
        `;
    },

    // Notification polling
    startPolling() {
        const poll = async () => {
            try {
                const data = await this.api('notifications.php?action=poll');
                this.unread = data.unread_count || 0;
                const badge = document.getElementById('notifBadge');
                if (badge) badge.innerHTML = this.unread > 0 ? `<span class="notif-dot"></span>` : '';
            } catch (err) {
                // The session expired under an open tab: polling on would only
                // fill the log with «Unauthorized» every 30 seconds
                if (err && err.status === 401) { this.stopPolling(); this.manager = null; this.renderLogin(); return; }
            }
            // Unread mail and, for admins, fresh errors are visible from any page
            try {
                const mail = await this.api('mail.php?action=list&limit=1');
                const mb = document.getElementById('mailBadge');
                if (mb) mb.innerHTML = mail.unread > 0 ? ` <span class="pill">${mail.unread}</span>` : '';
            } catch {}
            if (this.manager && this.manager.is_admin) {
                try {
                    const c = await this.api('admin.php?action=logs_counts');
                    const lb = document.getElementById('logBadge');
                    if (lb) lb.innerHTML = c.errors_24h > 0 ? ` <span class="pill pill--danger">${c.errors_24h}</span>` : '';
                } catch {}
            }
        };
        poll();
        this.pollTimer = setInterval(poll, 30000);
    },

    stopPolling() {
        if (this.pollTimer) clearInterval(this.pollTimer);
        this.pollTimer = null;
        if (this.mailTimer) clearInterval(this.mailTimer);
        this.mailTimer = null;
    },

    /** Настройки интерфейса — один запрос на вход, дальше из памяти. */
    async loadUiPrefs() {
        try {
            const d = await this.api('settings.php?action=ui');
            this.ui = Object.assign({}, this.ui, d.ui || {});
        } catch { /* умолчания уже стоят */ }
    },

    /**
     * Ящики опрашиваются сами (модуль 023).
     *
     * Раньше почта забиралась ТОЛЬКО по кнопке «Забрать почту»: вкладку держали
     * открытой весь день и не видели ни одного нового письма. Теперь — один раз
     * сразу после открытия страницы и дальше раз в `MAIL_AUTO_POLL_MIN` минут.
     * 0 в настройке возвращает прежнее поведение.
     */
    async startMailPolling() {
        if (this.mailTimer) clearInterval(this.mailTimer);
        const minutes = Number(this.ui.mail_poll_min || 0);
        // Первый заход — сразу: менеджер обновил страницу именно затем, чтобы
        // увидеть, не пришло ли что-нибудь
        this.pullMail(true);
        if (minutes <= 0) return;
        this.mailTimer = setInterval(() => this.pullMail(false), minutes * 60000);
    },

    /**
     * Забрать почту и, если пришло новое письмо, сказать об этом звуком.
     * Тихо: ошибка недоступного ящика не должна всплывать поверх работы.
     */
    async pullMail(first) {
        if (this.mailPulling) return;
        this.mailPulling = true;
        try {
            const before = this.unreadMail ?? null;
            await this.api('mail.php?action=sync', {method: 'POST', body: {}});
            const list = await this.api('mail.php?action=list&limit=1');
            const now = Number(list.unread || 0);
            this.unreadMail = now;
            const badge = document.getElementById('mailBadge');
            if (badge) badge.innerHTML = now > 0 ? ` <span class="pill">${now}</span>` : '';
            if (!first && before !== null && now > before) {
                this.playMailSound();
                this.toast(`Новых писем: ${now - before}`, 'info');
                // Открытая доска должна показать письмо, а не счётчик о нём
                if ((location.hash.slice(1) || 'mail') === 'mail') this.route();
            }
        } catch { /* недоступный ящик виден в «Настройки → Почта», а не поверх работы */ }
        finally { this.mailPulling = false; }
    },

    /** Звук нового письма — файл из папки sounds/, выбранный в настройках. */
    playMailSound() {
        const file = (this.ui.mail_sound || '').trim();
        if (!file) return;
        try {
            const audio = new Audio('/sounds/' + encodeURIComponent(file));
            audio.volume = Math.min(1, Math.max(0, Number(this.ui.mail_sound_volume ?? 60) / 100));
            // Браузер может не дать играть без действия пользователя — это не ошибка
            audio.play().catch(() => {});
        } catch { /* звук — не работа, без него всё работает */ }
    },

    // Router
    //
    // «Письма» (#mail/...) is the merger of what used to be four top-level
    // sections — Запросы, Почта, Компании, Доски (item 2 of the mobile/UX
    // pass). The single board is the home view (#mail with no sub-route); the
    // rest are internal sub-routes, exactly the way #settings/<tab> already
    // works. Every pre-merge bookmark (#requests, #board/7, a bare
    // #mail/<thread_key>, …) still resolves — via a same-tick redirect — to
    // its new #mail/... address, so nothing a manager had saved breaks.
    route() {
        const hash = location.hash.slice(1) || 'mail';
        const [page, ...params] = hash.split('/');
        document.querySelectorAll('.header__nav a').forEach(a => {
            a.classList.toggle('active', a.dataset.page === (page === 'admin' ? 'settings' : page));
        });
        const app = document.getElementById('app');
        app.innerHTML = '<div class="loading">Загрузка...</div>';
        this.onTabVisible = null; // only the company card re-syncs on focus
        this.closeModal();
        // Доска канбан идёт во всю ширину экрана: пять колонок в 1280px не
        // помещались и уезжали в горизонтальную прокрутку (модуль 023)
        const wide = page === 'mail' && (!params[0] || params[0] === 'board');
        const main = app.closest('.main') || document.querySelector('.main');
        if (main) main.classList.toggle('main--wide', wide);

        const open = () => {
            switch (page) {
                case 'mail': {
                    const seg = params[0] || '';
                    if (!seg || seg === 'board') return this.pageMailBoard();
                    if (seg === 'inbox') return this.pageMailInbox();
                    if (seg === 't') return this.pageMailThread(decodeURIComponent(params[1] || ''));
                    if (seg === 'msg') return this.pageMailMessage(params[1]);
                    // Отдельного списка запросов больше нет: всё открывается с
                    // доски, чтобы один и тот же запрос не жил на двух экранах
                    // с разной вёрсткой (модуль 023)
                    if (seg === 'requests') { location.replace('#mail'); return; }
                    if (seg === 'new') return this.pageNewRequest();
                    if (seg === 'request') return this.pageRequest(params[1]);
                    if (seg === 'proposal') return this.pageProposal(params[1]);
                    if (seg === 'companies') return this.pageCounterparties();
                    if (seg === 'company') return this.pageCounterparty(params[1]);
                    // A thread key always contains ":" (module 010's s:.../m:...
                    // keys) so it can never collide with a reserved word above —
                    // this is a bookmark from before threads got their own #mail/t/ prefix
                    location.replace('#mail/t/' + encodeURIComponent(decodeURIComponent(seg)));
                    return;
                }
                case 'notifications': return this.pageNotifications();
                case 'settings': return this.pageSettings(params[0]);
                // Bookmarks and links from before the merge still work
                case 'requests': location.replace('#mail'); return;
                case 'new': location.replace('#mail/new'); return;
                case 'request': location.replace('#mail/request/' + (params[0] || '')); return;
                case 'proposal': location.replace('#mail/proposal/' + (params[0] || '')); return;
                case 'counterparties': location.replace('#mail/companies'); return;
                case 'counterparty': location.replace('#mail/company/' + (params[0] || '')); return;
                case 'boards': location.replace('#mail/board'); return;
                case 'board': location.replace('#mail/board'); return; // exactly one board now
                case 'admin': location.replace('#settings/' + (params[0] || '')); return;
                default: return this.pageMailBoard();
            }
        };
        // Whatever the page throws, the user sees the reason and a retry button —
        // never a spinner that spins forever
        Promise.resolve().then(open)
            // Первый заход на экран — гайд по его подсказкам, по очереди
            .then(() => setTimeout(() => this.startTour(hash.split('/').slice(0, 2).join('/')), 600))
            .catch(err => this.pageFail(err));
    },

    pageFail(err) {
        console.error(err);
        const app = document.getElementById('app');
        if (app) app.innerHTML = `
            <div class="card card--alert">
                <div class="card__title">Страница не открылась</div>
                <p>${this.esc(err && err.message ? err.message : String(err))}</p>
                <button class="btn btn--outline btn--sm" onclick="App.route()">Повторить</button>
            </div>`;
    },

    // ==== «Письма»: one board, and nothing else to switch between ====
    // Companies, requests and letters used to be three lists that had to be
    // cross-checked by hand (module 011). They are one object now — a company
    // card on the board — so the section has no tabs at all: the board IS the
    // page. The old lists stay reachable by URL for a link somebody saved, and
    // each of them opens with a way back to the board.
    mailShellHtml(active, extra = '') {
        const titles = {board: 'Письма', inbox: 'Архив писем', requests: 'Запросы', companies: 'Компании'};
        return `
            <div class="flex flex--between flex--wrap" style="margin-bottom:10px;gap:10px">
                <div class="flex flex--wrap" style="gap:10px;align-items:baseline">
                    ${active === 'board' ? '' : '<a href="#mail" class="btn btn--outline btn--sm">← На доску</a>'}
                    <h2 style="margin:0">${titles[active] || 'Письма'}${active === 'board' ? this.hint('board') : ''}</h2>
                </div>
                <div class="flex flex--wrap" style="gap:8px;flex:1;justify-content:flex-end">${extra}</div>
            </div>
            <div id="mailBody"><div class="loading">Загрузка...</div></div>
        `;
    },

    // === Pages ===

    // Requests list
    async pageRequests(q) {
        q = q || '';
        document.getElementById('app').innerHTML = this.mailShellHtml('requests',
            `<a href="#mail/new" class="btn btn--primary">+ Новый запрос</a>`);
        const data = await this.api('requests.php?action=list' + (q ? '&q=' + encodeURIComponent(q) : ''));
        const statusBadge = s => {
            const map = {new:'new',processing:'draft',draft_ready:'draft',sent:'sent',ordered:'confirmed',closed:'sent'};
            const labels = {new:'Новый',processing:'Обработка',draft_ready:'Черновик',sent:'Отправлен',ordered:'Заказ создан',closed:'Закрыт'};
            return `<span class="badge badge--${map[s]||'new'}">${labels[s]||s}</span>`;
        };
        document.getElementById('mailBody').innerHTML = `
            <div class="flex flex--wrap" style="margin-bottom:12px;gap:8px">
                <input type="text" id="reqSearch" placeholder="Поиск по контрагенту, контакту, теме…" value="${this.esc(q)}"
                       style="min-width:220px" onkeydown="if(event.key==='Enter')App.pageRequests(this.value.trim())">
                <button class="btn btn--outline" onclick="App.pageRequests(document.getElementById('reqSearch').value.trim())">Найти</button>
            </div>
            <div class="card">
                <div class="table-scroll">
                <table class="table">
                    <thead><tr><th class="num">#</th><th>Контрагент</th><th>Контакт</th><th>Тип</th><th>Источник</th><th>Позиций</th><th>Менеджер</th><th>Статус</th><th>Дата</th></tr></thead>
                    <tbody>
                        ${data.items.map(r => `
                            <tr class="${r.answer_state && r.answer_state.unanswered ? 'row--unanswered row--' + r.answer_state.level : ''}"
                                style="cursor:pointer" onclick="location.hash='mail/request/${r.id}'">
                                <td class="num">${r.id}</td>
                                <td>${this.esc(r.counterparty_name) || '—'} ${this.answerBadge(r.answer_state)}</td>
                                <td>${this.esc(r.contact_person) || '<span class="muted">—</span>'}</td>
                                <td>${r.category ? this.categoryBadge(r.category, App.categoryLabels[r.category]) : this.typeBadge(r.type)}</td>
                                <td>${r.source === 'email' ? '📧 Email' : '📋 Ручной'}${r.attachments_count > 0 ? ' 📎' + r.attachments_count : ''}</td>
                                <td class="num">${r.items_count || 0}</td>
                                <td>${this.esc(r.manager_name) || '<em>пул</em>'}</td>
                                <td>${statusBadge(r.status)}</td>
                                <td>${this.fmtDate(r.created_at, false)}</td>
                            </tr>
                        `).join('')}
                        ${data.items.length === 0 ? `<tr><td colspan="9" style="text-align:center;color:var(--text-muted)">${q ? 'Ничего не найдено' : 'Нет запросов'}</td></tr>` : ''}
                    </tbody>
                </table>
                </div>
            </div>
        `;
    },

    // New request (manual paste, US2)
    pageNewRequest() {
        document.getElementById('app').innerHTML = `
            <div class="flex" style="margin-bottom:16px;gap:10px">
                <a href="#mail" class="btn btn--outline btn--sm">← К доске</a>
                <h2 style="margin:0">Новый запрос</h2>
            </div>
            <div class="card">
                <form id="newRequestForm">
                    <div class="form-group">
                        <label>Текст запроса</label>
                        <textarea id="reqText" rows="8" placeholder="Вставьте текст запроса из мессенджера, email или заметок..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Контрагент (название организации)</label>
                        <input type="text" id="reqCounterparty" placeholder="ООО Ромашка (необязательно — система определит из текста)">
                    </div>
                    <button type="submit" class="btn btn--primary btn--block">Создать запрос и сформировать КП</button>
                </form>
            </div>
        `;
        document.getElementById('newRequestForm').onsubmit = async (e) => {
            e.preventDefault();
            const text = document.getElementById('reqText').value.trim();
            if (!text) return App.toast('Введите текст запроса', 'error');
            const btn = e.target.querySelector('button');
            btn.disabled = true; btn.textContent = 'Обработка...';
            try {
                const r = await App.api('requests.php?action=create', {method:'POST', body: {
                    text, counterparty_name: document.getElementById('reqCounterparty').value.trim()
                }});
                App.toast('Запрос создан', 'success');
                location.hash = `mail/request/${r.id}`;
            } catch (err) {
                App.toast(err.message, 'error');
                btn.disabled = false; btn.textContent = 'Создать запрос и сформировать КП';
            }
        };
    },

    // Single request view
    async pageRequest(id) {
        // Запрос и письмо — одна сущность, и открываются они одним экраном:
        // у запроса, заведённого из письма, это карточка переписки (модуль 023)
        try {
            const t = await this.api(`requests.php?action=thread&id=${id}`);
            if (t && t.thread_key) { location.replace('#mail/t/' + encodeURIComponent(t.thread_key)); return; }
        } catch { /* запроса без письма это не касается — открываем как было */ }
        return this.pageRequestCard(id);
    },

    async pageRequestCard(id) {
        const req = await this.api(`requests.php?action=get&id=${id}`);
        const app = document.getElementById('app');
        const isOrder = req.type === 'order';
        const parsed = req.parsed || {};

        const actions = isOrder
            ? `<button class="btn btn--primary" id="orderBtn" onclick="App.createOrderFromRequest(${req.id})">Создать заказ в МойСклад</button>`
            : (['new','processing'].includes(req.status)
                ? `<button class="btn btn--primary" id="genBtn" onclick="App.generateKP(${req.id})">Сформировать КП</button>` : '');

        app.innerHTML = `
            <div class="flex flex--between flex--wrap" style="margin-bottom:16px;gap:10px">
                <div class="flex flex--wrap" style="gap:10px">
                    <a href="#mail" class="btn btn--outline btn--sm">← К доске</a>
                    <h2 style="margin:0">${isOrder ? 'Заказ' : 'Запрос'} #${req.id} ${this.typeBadge(req.type)}</h2>
                </div>
                <div class="flex flex--wrap">
                    ${!req.manager_id ? `<button class="btn btn--outline" onclick="App.assignRequest(${req.id})">Взять в работу</button>` : ''}
                    ${req.mail_message_id ? `<button class="btn btn--outline" onclick="App.mailCompose(${req.mail_message_id}, true)">Создать ответ</button>` : ''}
                    ${actions}
                </div>
            </div>

            <div class="card card--inline">
                <span>Тип запроса:</span>
                <select id="reqType" onchange="App.setRequestType(${req.id}, this.value)">
                    <option value="kp_request" ${!isOrder ? 'selected' : ''}>Запрос КП</option>
                    <option value="order" ${isOrder ? 'selected' : ''}>Заказ</option>
                </select>
                <span class="muted">${req.type_source === 'manual' ? 'выбрано менеджером' : 'определено автоматически'}${parsed.type_reason ? ' — ' + this.esc(parsed.type_reason) : ''}</span>
            </div>

            <div class="grid grid--2">
                <div class="card">
                    <div class="card__title">Распознанные позиции</div>
                    <p class="muted">То, что просит клиент, слово в слово.</p>
                    ${parsed.items && parsed.items.length ? `
                        <table class="table">
                            <thead><tr><th>Наименование</th><th class="num">Кол-во</th></tr></thead>
                            <tbody>
                                ${parsed.items.map(i => `<tr><td>${this.esc(i.name)}</td><td class="num">${this.esc(i.qty)}</td></tr>`).join('')}
                            </tbody>
                        </table>
                    ` : '<p class="muted">Позиции ещё не распознаны</p>'}
                    ${parsed.delivery_terms ? `<p style="margin-top:10px"><strong>Доставка:</strong> ${this.esc(parsed.delivery_terms)}</p>` : ''}
                </div>
                <div class="card" id="matchCard">
                    <div class="card__title">Подходящие позиции</div>
                    <div class="loading">Подбираем по каталогу...</div>
                </div>
            </div>

            <div class="grid grid--2">
                <div class="card">
                    <div class="card__title">Исходный запрос</div>
                    <p><strong>Источник:</strong> ${req.source === 'email' ? 'Email' : 'Ручной ввод'}</p>
                    ${req.email_from ? `<p><strong>От:</strong> ${this.esc(req.email_from)}</p>` : ''}
                    ${req.email_subject ? `<p><strong>Тема:</strong> ${this.esc(req.email_subject)}</p>` : ''}
                    <p><strong>Контрагент:</strong> ${req.counterparty_id
                        ? `<a href="#mail/company/${req.counterparty_id}">${this.esc(req.counterparty_name) || 'без названия'}</a>`
                        : 'не определён'}${req.counterparty_inn ? ' · ИНН ' + this.esc(req.counterparty_inn) : ''}</p>
                    <hr style="margin:10px 0">
                    <pre style="white-space:pre-wrap;font-size:13px">${this.esc(req.raw_text)}</pre>
                </div>
                <div>
                    ${this.attachmentsCard(req.attachments)}
                    ${this.requestDocsCard(req)}
                </div>
            </div>
        `;
        this.renderMatchedItems(req.id, req.items || []);
    },

    // ==== «Подходящие позиции»: what the letter's lines mean in our catalog ====
    // The KP is built from this table, so a wrong guess is corrected once, here,
    // and not again in every proposal generated afterwards.

    /**
     * The table, wherever it is asked for. It used to live on the request page
     * alone, behind fixed element ids; now the letter card draws the same table
     * under the conversation it belongs to (module 012), so everything is
     * scoped to the host block and several tables can be open at once.
     *
     * $host — the element to draw into (the request card by default)
     * $opts.kp — render the «Сформировать КП» line of the letter card
     */
    renderMatchedItems(requestId, items, host, opts = {}) {
        host = host || document.getElementById('matchCard');
        if (!host) return;
        host.dataset.matchHost = '1';
        host.dataset.requestId = requestId;
        const open = items.filter(i => i.needs_choice).length;
        host.innerHTML = `
            <div class="card__title">Подходящие позиции ${opts.kp ? `<span class="muted">запрос #${requestId}</span>` : ''}
                ${this.hint('match')}${this.hint('match-scope')}${this.hint('match-variant')}</div>
            <p class="muted">Подбираются сами при открытии карточки. Начните печатать название —
               подскажет локальная база товаров.</p>
            ${open ? `<div class="note note--choice">Равнозначных вариантов: <strong>${open}</strong> —
                выберите нужный, автоподбор сам не решает.</div>` : ''}
            <div data-match-rows>${items.map(i => this.matchRow(i)).join('')}</div>
            ${items.length ? '' : '<p class="muted" data-match-empty>Пока пусто — добавьте позицию или подберите по каталогу.</p>'}
            <div class="flex flex--wrap" style="margin-top:10px">
                <button class="btn btn--outline btn--sm" onclick="App.addMatchRow(this)">+ Позиция</button>
                <button class="btn btn--outline btn--sm" onclick="App.rematchItems(this, false)">Подобрать по каталогу</button>
                <button class="btn btn--outline btn--sm" onclick="App.rematchItems(this, true)"
                        title="Нейросеть сначала приведёт формулировки клиента к нашим названиям — это один запрос к модели">Подобрать нейросетью</button>
                <button class="btn btn--outline btn--sm" onclick="App.saveMatchedItems(this)">Сохранить</button>
                ${this.matchKpButton(requestId, opts.kp || {})}
            </div>
            <div data-match-total class="muted" style="margin-top:8px"></div>
        `;
        this.updateMatchTotal(host);
    },

    /** «Сформировать КП» right under the positions — the next step, in place. */
    matchKpButton(requestId, kp) {
        // «Собрать КП в файл» — то, чего под таблицей подбора не хватало: КП
        // собирается и сразу скачивается, без захода в редактор (модуль 023)
        const files = kp.proposal_id
            ? `<button class="btn btn--outline btn--sm" onclick="App.buildKpFile(${requestId}, this, 'docx')"
                       title="Собрать КП и скачать файлом">⬇ Собрать КП в файл</button>`
            : `<button class="btn btn--outline btn--sm" onclick="App.buildKpFile(${requestId}, this, 'docx')"
                       title="Сформировать КП и сразу скачать файлом">⬇ Собрать КП в файл</button>`;
        if (kp.proposal_id) {
            return `<button class="btn btn--primary btn--sm" onclick="App.openKp(${kp.proposal_id}, this)">Открыть КП</button>
                    <button class="btn btn--outline btn--sm" onclick="App.generateKP(${requestId})"
                            title="Собрать КП заново из этих позиций">Пересобрать КП</button>
                    ${files}`;
        }
        return `<button class="btn btn--primary btn--sm" onclick="App.generateKP(${requestId})">Сформировать КП</button>
                ${files}`;
    },

    /**
     * Собрать КП и отдать файлом, не уходя со страницы.
     *
     * Позиции сначала сохраняются: собирать КП из того, что менеджер видит на
     * экране, а не из того, что лежало в базе до его правок, — единственное
     * честное поведение этой кнопки.
     */
    async buildKpFile(requestId, btn, format) {
        const host = this.matchHost(btn);
        btn.disabled = true;
        const label = btn.textContent;
        btn.textContent = 'Собираем...';
        try {
            if (host) await this.saveMatchedItems(host, true);
            let proposalId = host && host.dataset.kp ? (JSON.parse(host.dataset.kp).proposal_id || 0) : 0;
            if (!proposalId) {
                const r = await this.api(`proposals.php?action=generate&request_id=${requestId}`, {method: 'POST', body: {}});
                proposalId = r.id;
            } else {
                await this.api(`proposals.php?action=update&id=${proposalId}`, {method: 'POST', body: {}});
            }
            const url = format === 'pdf'
                ? `/api/proposals.php?action=preview&id=${proposalId}`
                : `/api/proposals.php?action=docx&id=${proposalId}`;
            window.open(url, '_blank');
            this.toast('КП собрано', 'success');
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; btn.textContent = label; }
    },

    /**
     * «Открыть КП» — в ту же карточку, а не отдельной страницей (модуль 023).
     *
     * Раньше это была ссылка на #mail/proposal/N: менеджер уходил с письма, и
     * назад к переписке приходилось возвращаться через доску. А по битой
     * ссылке экран честно писал «КП #undefined не найдено» — потому что id
     * брался из ответа, которого не было.
     */
    async openKp(proposalId, btn) {
        if (!proposalId) { this.toast('КП ещё не собрано — нажмите «Сформировать КП»', 'error'); return; }
        const host = this.matchHost(btn);
        const slot = host && host.querySelector('[data-kp-slot]')
            || (host ? host.appendChild(Object.assign(document.createElement('div'), {dataset: {kpSlot: '1'}})) : null);
        if (!slot) { location.hash = '#mail/proposal/' + proposalId; return; }
        if (slot.dataset.open === String(proposalId)) { slot.innerHTML = ''; slot.dataset.open = ''; return; }
        slot.dataset.open = String(proposalId);
        slot.innerHTML = '<div class="loading">Открываем КП...</div>';
        slot.innerHTML = `
            <div class="card card--inline" style="margin-top:10px">
                <div class="flex flex--between flex--wrap">
                    <strong>КП #${proposalId}</strong>
                    <span class="flex flex--wrap">
                        <a class="btn btn--outline btn--sm" href="#mail/proposal/${proposalId}">Редактор КП →</a>
                        <a class="btn btn--outline btn--sm" target="_blank"
                           href="/api/proposals.php?action=docx&id=${proposalId}">⬇ Word</a>
                        <button class="btn btn--outline btn--sm" onclick="App.openKp(${proposalId}, this)">Свернуть</button>
                    </span>
                </div>
                <iframe class="kp-preview" src="/api/proposals.php?action=preview&id=${proposalId}"
                        title="Предпросмотр КП #${proposalId}"></iframe>
            </div>`;
    },

    /** The block one position belongs to — a page may hold several tables. */
    matchHost(el) {
        return (el && el.closest && el.closest('[data-match-host]')) || document.getElementById('matchCard');
    },

    // Where a candidate came from: the words of the letter, its meaning, or both
    matchSourceLabel(source) {
        return {words: 'по словам', meaning: 'по смыслу', both: 'по словам и смыслу',
                site_url: 'по ссылке на товар'}[source] || '';
    },

    /**
     * The «равнозначные» block. Two catalog rows within MATCH_EQUAL_DELTA of each
     * other are not a match and a runner-up — they are a question, and the card
     * asks it here instead of quietly taking the first one.
     */
    matchChoice(i) {
        const options = [
            ...(i.moysklad_product_id ? [{
                moysklad_id: i.moysklad_product_id, name: i.product_name, article: i.article,
                unit: i.unit, price: i.price, stock: i.stock, score: i.match_confidence,
                source: i.match_source,
            }] : []),
            ...(i.variants || []),
        ];
        if (!options.length) return '';
        return `
            <div class="choice">
                <div class="choice__title">Равнозначные варианты — выберите один</div>
                ${options.map(v => `
                    <button type="button" class="choice__opt" onclick="App.chooseMatch(${i.id || 0}, '${this.jsStr(v.moysklad_id)}', this)">
                        <span class="choice__name">${this.esc(v.name)}</span>
                        <span class="muted">${this.fmtMoney(v.price)}${v.stock !== null && v.stock !== undefined ? ` · остаток ${v.stock}` : ''}
                            ${v.score ? ` · ${Math.round(v.score * 100)}%` : ''}
                            ${v.source ? ` · ${this.matchSourceLabel(v.source)}` : ''}</span>
                    </button>`).join('')}
            </div>`;
    },

    /**
     * «Запрошенной позиции нет — предлагаем эту» (модуль 013).
     *
     * Показывает ровно то, что попадёт в КП: чем заменили, почему и каким
     * требованиям запроса наша позиция соответствует по её же описанию.
     * Ничего не придумывается на этом экране — это те же данные, что в PDF.
     */
    altNote(i) {
        if (!i.is_alternative) return '';
        const alt = i.alternative || {};
        const fits = (alt.matched || []).map(m =>
            `<li>соответствует: ${this.esc(m.requirement)}${m.ours ? ' — ' + this.esc(m.ours) : ''}</li>`).join('');
        const differs = (alt.differs || []).length
            ? `<div class="muted">отличается: ${this.esc((alt.differs || []).join('; '))}</div>` : '';
        return `
            <div class="note note--swap">
                <strong>Аналог.</strong> Нет в наличии: ${this.esc(i.alt_of || i.raw_name || '')}.
                ${alt.reason ? this.esc(alt.reason) : ''}
                ${fits ? `<ul class="alt-fits">${fits}</ul>` : ''}
                ${differs}
            </div>`;
    },

    matchRow(i = {}) {
        const conf = i.match_confidence ? Math.round(i.match_confidence * 100) : null;
        const src = this.matchSourceLabel(i.match_source);
        return `
            <div class="match-row ${i.needs_choice ? 'match-row--choice' : ''} ${i.is_out_of_scope ? 'match-row--out' : ''}" data-match-row>
                <input type="hidden" data-field="id" value="${this.esc(i.id || '')}">
                <input type="hidden" data-field="raw_name" value="${this.esc(i.raw_name || '')}">
                <input type="hidden" data-field="moysklad_product_id" value="${this.esc(i.moysklad_product_id || '')}">
                <input type="hidden" data-field="article" value="${this.esc(i.article || '')}">
                <input type="hidden" data-field="stock" value="${i.stock ?? ''}">
                <input type="hidden" data-field="needs_choice" value="${i.needs_choice ? 1 : 0}">
                <input type="hidden" data-field="is_alternative" value="${i.is_alternative ? 1 : 0}">
                <input type="hidden" data-field="alt_of" value="${this.esc(i.alt_of || '')}">
                <input type="hidden" data-field="is_out_of_scope" value="${i.is_out_of_scope ? 1 : 0}">
                <div class="match-row__name">
                    ${i.raw_name ? `<div class="muted">из письма: ${this.esc(i.raw_name)}${conf !== null ? ` · совпадение ${conf}%` : ''}${src ? ` · ${src}` : ''}</div>` : ''}
                    ${this.variantNote(i)}
                    ${this.scopeNote(i)}
                    ${this.altNote(i)}
                    <input type="text" data-field="product_name" autocomplete="off" placeholder="Название позиции из каталога"
                           value="${this.esc(i.product_name || '')}" oninput="App.matchSuggest(this)" onblur="App.hideSuggest(this)">
                    <div class="suggest" hidden></div>
                    ${i.needs_choice ? this.matchChoice(i) : ((i.variants || []).length ? `<div class="muted">ещё похожие:
                        ${i.variants.map(v => `<a onclick="App.pickVariant(this, '${this.jsStr(JSON.stringify(v))}')">${this.esc(v.name)}</a>`).join(' · ')}</div>` : '')}
                </div>
                <input type="number" step="0.01" min="0" data-field="quantity" value="${i.quantity ?? 1}"
                       placeholder="Кол-во" title="Количество" oninput="App.updateMatchTotal(this)">
                <input type="text" data-field="unit" value="${this.esc(i.unit || 'шт.')}" placeholder="Ед." title="Единица измерения">
                <input type="number" step="0.01" min="0" data-field="price" value="${i.price ?? 0}"
                       placeholder="Цена" title="Цена за единицу" oninput="App.updateMatchTotal(this)">
                <span class="price-opts-slot">${this.priceOptsSelect(i.price_options || {})}</span>
                <input type="text" data-field="notes" value="${this.esc(i.notes || '')}" placeholder="Примечание">
                <label title="Позиция подтверждена менеджером — автоподбор её больше не трогает">
                    <input type="checkbox" data-field="is_confirmed" ${i.is_confirmed ? 'checked' : ''}> ок
                </label>
                <div class="match-row__tools">
                    <button class="btn btn--outline btn--sm" title="Выше" onclick="App.moveMatchRow(this, -1)">↑</button>
                    <button class="btn btn--outline btn--sm" title="Ниже" onclick="App.moveMatchRow(this, 1)">↓</button>
                    <button class="btn btn--outline btn--sm ${i.is_out_of_scope ? 'btn--primary' : ''}"
                            title="${i.is_out_of_scope ? 'Вернуть строку в работу' : 'Мы этим не занимаемся: строка не попадёт ни в КП, ни в ответ клиенту'}"
                            onclick="App.setItemScope(this, ${i.id || 0}, ${i.is_out_of_scope ? 0 : 1})">${i.is_out_of_scope ? '↩' : '🚫'}</button>
                    <button class="btn btn--outline btn--sm" title="Убрать строку"
                            onclick="const h=App.matchHost(this); this.closest('[data-match-row]').remove(); App.updateMatchTotal(h)">×</button>
                </div>
                ${this.matchRowExtra(i)}
            </div>`;
    },

    /**
     * Вторая строка позиции: скидка, условия ожидания и развёрнутый комментарий
     * (модуль 023).
     *
     * Комментарий заполняется описанием товара из МойСклад, правится здесь — и
     * ровно в этом виде уходит и в файл КП, и в письмо клиенту. Разметка из
     * МойСклад в поле приходит уже текстом, а не тегами.
     */
    matchRowExtra(i = {}) {
        const backorder = Number(i.is_backorder) === 1 || (i.stock !== null && i.stock !== undefined && Number(i.stock) <= 0);
        return `
            <div class="match-extra">
                <div class="match-extra__money">
                    <label title="Скидка на эту позицию, %">скидка
                        <input type="number" step="0.01" min="0" max="100" data-field="discount_percent"
                               value="${i.discount_percent ?? 0}" oninput="App.updateMatchTotal(this)">%
                    </label>
                    <label title="Цену поставил человек: повторный подбор её не перетрёт">
                        <input type="checkbox" data-field="price_is_manual" ${i.price_is_manual ? 'checked' : ''}> цена вручную
                    </label>
                    <label class="${backorder ? '' : 'muted'}" title="Товара нет на складе: срок ожидания, скидка за ожидание и предоплата">
                        <input type="checkbox" data-field="wait_on" ${i.wait_on ? 'checked' : ''}
                               onchange="App.updateMatchTotal(this)"> под заказ
                    </label>
                    <label>ждать
                        <input type="number" min="0" max="120" data-field="wait_months" value="${i.wait_months ?? ''}"
                               placeholder="3" title="Срок ожидания, месяцев"> мес.
                    </label>
                    <label>за ожидание −
                        <input type="number" step="0.01" min="0" max="100" data-field="wait_discount"
                               value="${i.wait_discount ?? ''}" placeholder="10"
                               title="Скидка за ожидание, %" oninput="App.updateMatchTotal(this)">%
                    </label>
                    <label>предоплата
                        <input type="number" min="0" max="100" data-field="wait_prepay" value="${i.wait_prepay ?? ''}"
                               placeholder="100" title="Доля предоплаты, %">%
                    </label>
                    ${i.wait_note ? `<span class="muted">${this.esc(i.wait_note)}</span>` : ''}
                </div>
                <textarea data-field="comment_text" rows="2" class="match-extra__comment"
                          placeholder="Комментарий по товару — уйдёт в КП и в письмо. Подставляется описание из МойСклад"
                          >${this.esc(i.comment_text || '')}</textarea>
            </div>`;
    },

    /** Переставить позицию выше или ниже: порядок строк — это порядок в КП. */
    moveMatchRow(btn, delta) {
        const row = btn.closest('[data-match-row]');
        const box = row && row.parentElement;
        if (!box) return;
        const sibling = delta < 0 ? row.previousElementSibling : row.nextElementSibling;
        if (!sibling) return;
        delta < 0 ? box.insertBefore(row, sibling) : box.insertBefore(sibling, row);
        row.classList.add('match-row--moved');
        setTimeout(() => row.classList.remove('match-row--moved'), 600);
    },

    /**
     * Строка, которой мы не занимаемся (модуль 022).
     *
     * В запросе на пожарную часть рядом со шлемами стояли топор, рукав и ящики
     * для песка. КП уходило с ними по 0,00 руб., а ответ обещал «уточнить
     * наличие, сроки и цену» — обещание, которого никто не собирался
     * выполнять. Теперь такая строка видна ЗДЕСЬ и только здесь: клиент о ней
     * от нас ничего не услышит.
     */
    scopeNote(i) {
        if (!i.is_out_of_scope) return '';
        return `<div class="note note--out">
            <strong>Не наша номенклатура.</strong> В КП и в ответ клиенту эта позиция не уйдёт.
            ${i.out_of_scope_reason ? `<span class="muted">Правило: «${this.esc(i.out_of_scope_reason)}»</span>` : ''}
        </div>`;
    },

    /** Размер или цвет, который просила эта строка письма (модуль 022). */
    variantNote(i) {
        if (!i.variant_label) return '';
        const kind = i.variant_kind === 'color' ? 'цвет' : 'размер';
        return `<div class="muted">модификация · ${this.esc(kind)}: <strong>${this.esc(i.variant_label)}</strong></div>`;
    },

    /** Отметить строку «не наш профиль» — или вернуть её в работу. */
    async setItemScope(btn, itemId, outOfScope) {
        const host = this.matchHost(btn);
        if (!host) return;
        const requestId = Number(host.dataset.requestId);
        if (!itemId) { this.toast('Сначала сохраните строку', 'error'); return; }
        btn.disabled = true;
        try {
            const res = await this.api(`requests.php?action=items_scope&id=${requestId}`, {
                method: 'POST', body: {item_id: itemId, out_of_scope: !!outOfScope},
            });
            this.renderMatchedItems(requestId, res.items, host, this.matchOpts(host));
            this.toast(outOfScope
                ? 'Позиция отмечена как не наша — в КП и в ответ она не уйдёт'
                : 'Позиция вернулась в работу', 'success');
        } catch (err) { this.toast(err.message, 'error'); btn.disabled = false; }
    },

    // Prices МойСклад knows for the matched product, so the manager can pick a
    // type instead of typing a number — «как руками, так и выбором».
    priceOptsSelect(prices) {
        const entries = Object.entries(prices || {});
        if (entries.length < 2) return '';
        return `<select class="price-opts" title="Выбрать тип цены" onchange="App.applyPriceOption(this)">
            <option value="">цена…</option>
            ${entries.map(([name, val]) => `<option value="${this.esc(String(val))}">${this.esc(name)} — ${this.fmtMoney(val)}</option>`).join('')}
        </select>`;
    },

    applyPriceOption(sel) {
        if (!sel.value) return;
        const row = sel.closest('[data-match-row]');
        const price = row && row.querySelector('[data-field="price"]');
        if (price) { price.value = sel.value; this.updateMatchTotal(sel); }
    },

    // The manager answered the «равнозначные» question — the line stops asking
    async chooseMatch(itemId, moyskladId, btn) {
        const host = this.matchHost(btn);
        if (!itemId) {
            // A row that was never saved has no id yet: fill it in place
            const row = btn.closest('[data-match-row]');
            const name = btn.querySelector('.choice__name').textContent;
            const set = (f, v) => { const el = row.querySelector(`[data-field="${f}"]`); if (el) el.value = v; };
            set('product_name', name);
            set('moysklad_product_id', moyskladId);
            set('needs_choice', 0);
            row.querySelector('[data-field="is_confirmed"]').checked = true;
            row.classList.remove('match-row--choice');
            btn.closest('.choice').remove();
            this.updateMatchTotal(host);
            return;
        }
        const requestId = Number(host && host.dataset.requestId);
        try {
            const res = await this.api(`requests.php?action=items_choose&id=${requestId}`, {
                method: 'POST', body: {item_id: itemId, moysklad_id: moyskladId},
            });
            this.renderMatchedItems(requestId, res.items || [], host, this.matchOpts(host));
            this.toast('Позиция выбрана', 'success');
        } catch (err) { this.toast(err.message, 'error'); }
    },

    addMatchRow(el) {
        const host = this.matchHost(el);
        const box = host && host.querySelector('[data-match-rows]');
        if (!box) return;
        const empty = host.querySelector('[data-match-empty]');
        if (empty) empty.remove();
        box.insertAdjacentHTML('beforeend', this.matchRow({quantity: 1, unit: 'шт.', price: 0}));
    },

    // Autocomplete against the local product base (МойСклад-синхронизация или импорт Excel)
    matchSuggest(input) {
        const box = input.parentElement.querySelector('.suggest');
        const q = input.value.trim();
        // A hand-typed name is no longer the catalog row that was there before
        input.closest('[data-match-row]').querySelector('[data-field="moysklad_product_id"]').value = '';
        clearTimeout(this._suggestTimer);
        if (q.length < 2) { box.hidden = true; return; }

        this._suggestTimer = setTimeout(async () => {
            try {
                const d = await this.api('products.php?action=search&limit=8&q=' + encodeURIComponent(q));
                if (!d.items.length) { box.hidden = true; return; }
                box.innerHTML = d.items.map(p => `
                    <div class="suggest__item" onmousedown="App.pickSuggest(this, '${this.jsStr(JSON.stringify({
                        moysklad_id: p.moysklad_id, name: p.name, article: p.article || p.code || '',
                        unit: p.unit || 'шт.', price: p.price || 0, stock: p.stock ?? '', prices: p.prices || {},
                    }))}')">
                        <div>${this.esc(p.name)}</div>
                        <div class="muted">${this.esc(p.characteristics || p.article || p.code || '')}
                            · ${this.fmtMoney(p.price)}${p.stock !== null && p.stock !== undefined ? ` · остаток ${p.stock}` : ''}</div>
                    </div>`).join('');
                box.hidden = false;
            } catch { box.hidden = true; }
        }, 250);
    },

    hideSuggest(input) {
        // mousedown on a suggestion fires before blur, so the pick still lands
        setTimeout(() => {
            const box = input.parentElement.querySelector('.suggest');
            if (box) box.hidden = true;
        }, 150);
    },

    pickSuggest(el, json) {
        const p = JSON.parse(json);
        const row = el.closest('[data-match-row]');
        const set = (f, v) => { const i = row.querySelector(`[data-field="${f}"]`); if (i) i.value = v; };
        set('product_name', p.name);
        set('moysklad_product_id', p.moysklad_id);
        set('article', p.article);
        set('unit', p.unit);
        set('price', p.price);
        set('stock', p.stock);
        row.querySelector('[data-field="is_confirmed"]').checked = true;
        const slot = row.querySelector('.price-opts-slot');
        if (slot) slot.innerHTML = this.priceOptsSelect(p.prices || {});
        const suggest = el.closest ? el.closest('.suggest') : null;
        if (suggest) suggest.hidden = true;
        this.updateMatchTotal();
    },

    pickVariant(el, json) {
        const v = JSON.parse(json);
        this.pickSuggest(el, JSON.stringify({
            moysklad_id: v.moysklad_id, name: v.name, article: v.article || '',
            unit: v.unit || 'шт.', price: v.price || 0, stock: v.stock ?? '', prices: v.prices || {},
        }));
    },

    collectMatchedItems(el) {
        const host = this.matchHost(el);
        if (!host) return [];
        return [...host.querySelectorAll('[data-match-row]')].map(row => {
            const out = {};
            row.querySelectorAll('[data-field]').forEach(el => {
                out[el.dataset.field] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
            });
            out.quantity = parseFloat(out.quantity) || 0;
            out.price = parseFloat(out.price) || 0;
            out.discount_percent = parseFloat(out.discount_percent) || 0;
            return out;
        });
    },

    updateMatchTotal(from) {
        const host = this.matchHost(from);
        const el = host && host.querySelector('[data-match-total]');
        if (!el) return;
        const all = this.collectMatchedItems(host);
        // Строки «не наша номенклатура» в КП не уходят — значит и в сумме,
        // и в счётчике «без цены» им делать нечего (модуль 022)
        const rows = all.filter(r => Number(r.is_out_of_scope) !== 1);
        const dropped = all.length - rows.length;
        // Та же арифметика, что в `Terms::price()`: скидки множатся друг на
        // друга, а не складываются (модуль 023)
        const priceOf = r => {
            let p = r.price * (1 - Math.min(100, Math.max(0, r.discount_percent || 0)) / 100);
            if (Number(r.wait_on) === 1) {
                const w = r.wait_discount === '' || r.wait_discount === undefined
                    ? (this.waitDefaults.discount ?? 10) : Number(r.wait_discount);
                p *= 1 - Math.min(100, Math.max(0, w || 0)) / 100;
            }
            return Math.round(p * 100) / 100;
        };
        const total = rows.reduce((s, r) => s + priceOf(r) * r.quantity, 0);
        const noPrice = rows.filter(r => !r.price).length;
        el.innerHTML = all.length
            ? `Позиций: ${rows.length} · сумма по каталогу: ${this.fmtMoney(total)}`
              + (noPrice ? ` · без цены: ${noPrice}` : '')
              + (dropped ? ` · не наша номенклатура: ${dropped}` : '')
            : '';
    },

    // $from — any element of the table (a button of its own toolbar)
    async saveMatchedItems(from, silent = false) {
        const host = this.matchHost(from);
        if (!host) return [];
        const requestId = Number(host.dataset.requestId);
        const res = await this.api(`requests.php?action=items_save&id=${requestId}`, {
            method: 'POST', body: {items: this.collectMatchedItems(host)},
        });
        if (!silent) this.toast('Позиции сохранены', 'success');
        this.renderMatchedItems(requestId, res.items || [], host, this.matchOpts(host));
        return res.items;
    },

    /**
     * Re-pick the catalog rows. Spec 008 §5: «confirmed line is never re-picked
     * by the automatic match» — the server holds that line, and only the rows
     * still open are even sent to the model.
     *
     * The table is NOT wiped while this runs. Blanking it read as «всё сбросилось»
     * and, on an error, actually left an empty table behind — the manager's own
     * ✓ appeared to be gone when nothing had been touched (module 018).
     */
    async rematchItems(from, smart) {
        const host = this.matchHost(from);
        if (!host) return;
        const requestId = Number(host.dataset.requestId);
        const opts = this.matchOpts(host);
        // What the manager has already typed must survive the re-match
        const pending = this.collectMatchedItems(host);
        const buttons = [...host.querySelectorAll('button')];
        buttons.forEach(b => b.disabled = true);
        const note = document.createElement('div');
        note.className = 'loading';
        note.textContent = smart ? 'Спрашиваем нейросеть и подбираем...' : 'Подбираем по каталогу...';
        host.appendChild(note);
        try {
            await this.api(`requests.php?action=items_save&id=${requestId}`, {method: 'POST', body: {items: pending}});
            const res = await this.api(`requests.php?action=items_rematch&id=${requestId}&smart=${smart ? 1 : 0}`, {method: 'POST'});
            this.renderMatchedItems(requestId, res.items || [], host, opts);
            this.toast(this.rematchSummary(res), res.repicked && !res.found ? 'info' : 'success');
        } catch (err) {
            this.toast(err.message, 'error');
            // The rows are still whatever the save left in the database
            note.remove();
            buttons.forEach(b => b.disabled = false);
        }
    },

    /** What the re-match actually did — «ничего не нашлось» must not look like success. */
    rematchSummary(res) {
        const kept = res.kept ? `подтверждённых не тронуто: ${res.kept}` : '';
        if (!res.repicked) return kept ? `Пересматривать нечего — ${kept}` : 'Пересматривать нечего';
        const parts = [];
        if (res.found) parts.push(`подобрано: ${res.found}`);
        if (res.empty) parts.push(`без совпадений: ${res.empty}`);
        if (res.alternatives) parts.push(`аналогов: ${res.alternatives}`);
        if (kept) parts.push(kept);
        return `Пересмотрено строк: ${res.repicked} — ${parts.join(', ')}`;
    },

    // A redraw must not lose the letter card's «Сформировать КП» line
    matchOpts(host) {
        return host && host.dataset.kp ? {kp: JSON.parse(host.dataset.kp)} : {};
    },

    // Attachments of the incoming email (FR-021, FR-022)
    attachmentsCard(list) {
        if (!list || !list.length) return '';
        const status = {
            ok: 'текст извлечён', ocr: 'распознано OCR', empty: 'текст не найден',
            skipped: 'не разбиралось', failed: 'ошибка разбора', pending: 'в очереди',
        };
        return `
            <div class="card">
                <div class="card__title">Вложения</div>
                ${list.map(a => `
                    <div class="flex flex--between" style="padding:6px 0;border-bottom:1px solid var(--border)">
                        ${this.attachmentLink(a, 'requests.php')}
                        <span class="muted">${Math.round((a.size || 0) / 1024)} КБ · ${status[a.extract_status] || a.extract_status}</span>
                    </div>
                `).join('')}
            </div>
        `;
    },

    // KPs and MoySklad orders already created for this request
    requestDocsCard(req) {
        const proposals = req.proposals || [];
        const orders = req.orders || [];
        if (!proposals.length && !orders.length) return '';
        return `
            <div class="card">
                <div class="card__title">Документы</div>
                ${proposals.map(p => `
                    <div class="flex flex--between" style="padding:6px 0">
                        <a href="#mail/proposal/${p.id}">КП ${this.esc(p.number) || '#' + p.id}</a>
                        <span class="muted">${this.esc(p.status)} · ${this.fmtDate(p.created_at, false)}</span>
                    </div>
                `).join('')}
                ${orders.map(o => `
                    <div class="flex flex--between" style="padding:6px 0">
                        <a href="https://online.moysklad.ru/app/#customerorder/edit?id=${o.moysklad_id}" target="_blank">Заказ ${this.esc(o.name)} ↗</a>
                        <span class="muted">${this.fmtMoney(o.sum)}${o.state_name ? ' · ' + this.esc(o.state_name) : ''}</span>
                    </div>
                `).join('')}
            </div>
        `;
    },

    // Manual override of the request type (FR-024)
    async setRequestType(id, type) {
        try {
            await this.api(`requests.php?action=set_type&id=${id}`, {method: 'POST', body: {type}});
            this.toast('Тип запроса изменён', 'success');
            this.pageRequest(id);
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Create the order in MoySklad and open it in a new tab (US8, FR-026)
    async createOrderFromRequest(id) {
        const btn = document.getElementById('orderBtn');
        if (btn) { btn.disabled = true; btn.textContent = 'Создаём заказ...'; }

        // Open the tab synchronously — popup blockers reject window.open after an await
        const tab = window.open('', '_blank');
        try {
            const r = await this.api(`orders.php?action=create_from_request&request_id=${id}`, {method: 'POST'});
            if (tab) tab.location = r.url; else window.open(r.url, '_blank');
            this.toast(`Заказ ${r.order_number} создан`, 'success');
            if (r.unmatched && r.unmatched.length) {
                this.toast('Не найдено в каталоге: ' + r.unmatched.join('; '), 'error');
            }
            this.pageRequest(id);
        } catch (err) {
            if (tab) tab.close();
            if (err.data && err.data.need_counterparty) {
                this.promptLinkCounterparty(err.data, () => this.createOrderFromRequest(id));
            } else {
                this.toast(err.message, 'error');
            }
            if (btn) { btn.disabled = false; btn.textContent = 'Создать заказ в МойСклад'; }
        }
    },

    // The company is not linked to MoySklad yet — let the manager pick or create it
    promptLinkCounterparty(data, retry) {
        const candidates = data.candidates || [];
        const list = candidates.length
            ? candidates.map(c => `
                <div class="flex flex--between" style="padding:6px 0;border-bottom:1px solid var(--border)">
                    <span>${this.esc(c.name)}${c.inn ? ' · ИНН ' + this.esc(c.inn) : ''}</span>
                    <button class="btn btn--sm btn--outline" onclick="App.linkCounterparty(${data.counterparty_id}, '${this.jsStr(c.id)}')">Привязать</button>
                </div>`).join('')
            : '<p class="muted">Похожих контрагентов в МойСклад не найдено</p>';

        this.modal('Контрагент не привязан к МойСклад', `
            ${list}
            <button class="btn btn--primary btn--block" style="margin-top:12px"
                onclick="App.linkCounterparty(${data.counterparty_id}, null, true)">Создать контрагента в МойСклад</button>
        `);
        this._afterLink = retry;
    },

    async linkCounterparty(counterpartyId, moyskladId, create = false) {
        try {
            await this.api(`orders.php?action=link_counterparty&counterparty_id=${counterpartyId}`, {
                method: 'POST',
                body: create ? {create: true} : {moysklad_id: moyskladId},
            });
            this.closeModal();
            this.toast('Контрагент привязан к МойСклад', 'success');
            if (this._afterLink) { const fn = this._afterLink; this._afterLink = null; fn(); }
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Minimal modal used by the linking flow
    modal(title, html) {
        let el = document.getElementById('modal');
        if (!el) {
            el = document.createElement('div');
            el.id = 'modal';
            document.body.appendChild(el);
        }
        el.className = 'modal';
        el.innerHTML = `
            <div class="modal__box card">
                <div class="flex flex--between" style="margin-bottom:12px">
                    <strong>${this.esc(title)}</strong>
                    <button class="btn btn--sm btn--outline" onclick="App.closeModal()">✕</button>
                </div>
                ${html}
            </div>`;
    },

    closeModal() {
        const el = document.getElementById('modal');
        if (el) el.remove();
    },

    // Generate KP
    async generateKP(requestId) {
        const btn = document.getElementById('genBtn');
        if (btn) { btn.disabled = true; btn.textContent = 'Генерация...'; }
        try {
            // The KP is built from «Подходящие позиции» — send the table as it
            // looks on screen, not as it was last saved. On the letter card
            // several tables can be open, so take this request's own.
            const host = document.querySelector(`[data-match-host][data-request-id="${requestId}"]`);
            if (host && host.querySelector('[data-match-row]')) {
                await this.saveMatchedItems(host, true);
            }
            const p = await this.api(`proposals.php?action=generate&request_id=${requestId}`, {method:'POST'});
            this.toast('КП сформировано', 'success');
            location.hash = `mail/proposal/${p.id}`;
        } catch (err) {
            this.toast(err.message, 'error');
            if (btn) { btn.disabled = false; btn.textContent = 'Сформировать КП'; }
        }
    },

    // Assign request to current manager
    async assignRequest(id) {
        await this.api(`requests.php?action=assign&id=${id}`, {method:'POST'});
        this.toast('Запрос взят в работу', 'success');
        location.hash = `mail/request/${id}`;
    },

    // Proposal editor
    async pageProposal(id) {
        const proposal = await this.api(`proposals.php?action=get&id=${id}`).catch(() => null);
        if (!proposal) {
            document.getElementById('app').innerHTML = `<div class="card">КП #${id} не найдено</div>`;
            return;
        }
        this.proposal = proposal;
        const items = proposal.items || [];
        const addons = proposal.addons || [];

        document.getElementById('app').innerHTML = `
            <div class="flex flex--between flex--wrap" style="margin-bottom:16px;gap:10px">
                <div class="flex flex--wrap" style="gap:10px">
                    ${proposal.request_id ? `<a href="#mail/request/${proposal.request_id}" class="btn btn--outline btn--sm">← Запрос #${proposal.request_id}</a>` : ''}
                    <h2 style="margin:0">КП #${this.esc(proposal.number) || id}${this.hint('kp-editor')}${this.hint('kp-exclude')}</h2>
                </div>
                <div class="flex flex--wrap">
                    <button class="btn btn--outline" onclick="App.refreshPreview(${id})">Обновить PDF</button>
                    <button class="btn btn--primary" onclick="App.confirmAndSend(${id})">Подтвердить и отправить</button>
                </div>
            </div>
            ${this.proposalWarnings(proposal)}
            <div class="grid grid--2">
                <div>
                    <div class="card">
                        <div class="card__title">Сопроводительное письмо</div>
                        <div class="form-group">
                            <textarea id="coverLetter" rows="6" placeholder="Текст сопроводительного письма...">${this.esc(proposal.cover_letter_final || proposal.cover_letter || '')}</textarea>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card__title">Карточки товаров</div>
                        <div class="note" style="margin-bottom:8px">Описание, характеристики, комплектация и фото подтягиваются из МойСклад. Правки здесь попадают в PDF.</div>
                        ${items.map(it => this.itemCardEditor(it)).join('')}
                        <button class="btn btn--outline btn--block" onclick="App.refreshImages(${id})">Перезагрузить фото из МойСклад</button>
                    </div>

                    <div class="card">
                        <div class="card__title">Доукомплектование (апселл)</div>
                        <div class="form-group">
                            <label><input type="checkbox" id="showUpsell" ${proposal.show_upsell != 0 ? 'checked' : ''}> Показывать блок в КП</label>
                        </div>
                        <div class="form-group">
                            <label>Вступительный текст</label>
                            <textarea id="upsellIntro" rows="2">${this.esc(proposal.upsell_intro || '')}</textarea>
                        </div>
                        <div id="addonRows">${addons.map((a, i) => this.addonRow(a, i)).join('')}</div>
                        <div class="flex">
                            <button class="btn btn--outline" onclick="App.addAddonRow()">+ Модуль</button>
                            <button class="btn btn--outline" onclick="App.suggestAddons(${id})">Подобрать из каталога</button>
                        </div>
                        <div class="form-group" style="margin-top:8px">
                            <label>Подпись под фото полной комплектации</label>
                            <textarea id="upsellNote" rows="2">${this.esc(proposal.upsell_note || '')}</textarea>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card__title">Таблица соответствия</div>
                        <div class="form-group">
                            <label><input type="checkbox" id="showMatchTable" ${proposal.show_match_table_effective ? 'checked' : ''}>
                                Открывать КП таблицей «запрошено → предлагаем»</label>
                            <div class="muted">Запрос пришёл ${proposal.request_shape === 'table' ? 'таблицей или спецификацией — таблица включена сама' : 'текстом — по умолчанию таблица не нужна'}.</div>
                        </div>
                        <div class="form-group">
                            <label>Пояснение над таблицей</label>
                            <textarea id="matchTableNote" rows="2"
                                placeholder="Слева — позиции Вашего запроса, справа — что мы предлагаем по каждой из них.">${this.esc(proposal.match_table_note || '')}</textarea>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card__title">Текст перед таблицей</div>
                        <textarea id="preTable" rows="3" placeholder="Условия отгрузки, самовывоз...">${this.esc(proposal.pre_table_text || '')}</textarea>
                    </div>
                    <div class="card">
                        <div class="card__title">Текст после таблицы</div>
                        <textarea id="postTable" rows="3" placeholder="Дополнительные условия...">${this.esc(proposal.post_table_text || '')}</textarea>
                    </div>
                    <div class="card">
                        <div class="card__title">Гарантия и обслуживание</div>
                        <textarea id="warrantyText" rows="2">${this.esc(proposal.warranty_text || '')}</textarea>
                    </div>
                    <div class="card">
                        <div class="card__title">Фотографии</div>
                        <div class="form-group">
                            <label><input type="checkbox" id="showImages" ${proposal.show_images != 0 ? 'checked' : ''}> Включать фото продукции в КП</label>
                        </div>
                        <div class="form-group">
                            <label>Оговорка под фото</label>
                            <textarea id="imagesNote" rows="2">${this.esc(proposal.images_note || '')}</textarea>
                        </div>
                        <!-- Количество фото ограничивается в настройках — значит,
                             ограничить его должно быть можно и здесь (модуль 023) -->
                        <div class="form-group" style="max-width:260px">
                            <label>Фото на позицию в этом КП</label>
                            <input type="number" id="photosPerItem" min="0" max="12"
                                   placeholder="как в настройках"
                                   value="${proposal.photos_per_item ?? ''}">
                        </div>
                    </div>

                    <div class="card">
                        <div class="grid grid--3">
                            <div class="form-group">
                                <label>НДС %</label>
                                <select id="vatRate">${[5,0,20].map(v => `<option value="${v}" ${proposal.vat_rate == v ? 'selected' : ''}>${v}%</option>`).join('')}</select>
                            </div>
                            <div class="form-group">
                                <label>Срок исполнения</label>
                                <select id="execDays">${[10,30,60,90].map(v => `<option value="${v}" ${proposal.execution_days == v ? 'selected' : ''}>${v} дней</option>`).join('')}</select>
                            </div>
                            <div class="form-group">
                                <label>Показать НДС</label>
                                <select id="showVat"><option value="0">Нет</option><option value="1" ${proposal.show_vat_total == 1 ? 'selected' : ''}>Да</option></select>
                            </div>
                        </div>
                        <button class="btn btn--outline btn--block" onclick="App.saveProposal(${id})">Сохранить изменения</button>
                    </div>
                </div>
                <div>
                    <div class="card" style="padding:10px">
                        <iframe class="pdf-frame" id="pdfPreview" src="/api/proposals.php?action=preview&id=${id}"></iframe>
                        <a class="btn btn--sm btn--outline btn--block" style="margin-top:8px"
                           href="/api/proposals.php?action=docx&id=${id}">Скачать в Word (.docx)</a>
                    </div>
                </div>
            </div>
            <div class="card" style="margin-top:16px">
                <div class="card__title">Отправка</div>
                <div class="grid grid--2">
                    <div class="form-group">
                        <label>Email получателя</label>
                        <input type="email" id="sendTo" placeholder="client@company.ru" value="${this.esc(proposal.email_from || proposal.contact_email || '')}">
                    </div>
                    <div class="form-group">
                        <label>Тема письма</label>
                        <input type="text" id="sendSubject" value="Коммерческое предложение от Atlant Armour">
                    </div>
                </div>
                <div class="form-group" style="max-width:320px">
                    <label>Чем приложить КП</label>
                    <select id="sendFormat">
                        <option value="docx">Word (.docx) — редактируемый</option>
                        <option value="pdf">PDF</option>
                        <option value="both">И Word, и PDF</option>
                        <option value="text">Только текстом в письме — без файла</option>
                    </select>
                    <div class="muted" style="margin-top:4px">В текстовом варианте то же самое:
                        позиции, цены, условия и комментарии. Нет только QR-кода.</div>
                </div>
                <!-- Свои файлы к письму: менеджер мог переделать документ руками (модуль 023) -->
                <div class="form-group" style="max-width:420px">
                    <label>Свои файлы к письму</label>
                    <input type="file" multiple onchange="App.kpAttach(this)">
                    <div class="composer__files" id="kpFiles"></div>
                </div>
                <button class="btn btn--primary" onclick="App.sendProposal(${id})">Отправить КП</button>
            </div>
        `;
        // Thumbnails load per position, so a KP with many photos still opens fast
        items.forEach(it => { if (it.moysklad_product_id) this.loadItemPhotos(it.id); });
    },

    /**
     * What is wrong with this КП, said before «Подтвердить» is pressed (module 018).
     *
     * Three holes the manager used to find out about only from the client:
     * positions with no price, positions the catalog never answered, and a
     * buyer whose «название» is still the sender's e-mail address.
     */
    proposalWarnings(proposal) {
        const gaps = (proposal.price_gaps && proposal.price_gaps.items) || [];
        const empty = !!(proposal.price_gaps && proposal.price_gaps.empty);
        const unmatched = proposal.unmatched || [];
        const buyer = proposal.buyer || {};
        const blocks = [];

        if (empty) {
            blocks.push('<div><strong>В КП нет ни одной позиции.</strong> Отправка попросит подтверждение.</div>');
        } else if (gaps.length) {
            blocks.push(`<div><strong>Без цены: ${gaps.length} поз.</strong> —
                ${gaps.map(i => `№${i.position} ${this.esc(i.name)}`).join(', ')}.
                Отправка попросит подтверждение.</div>`);
        }
        // Свёрнутое руками и ненайденное каталогом — разные новости, хотя в
        // документ обе попадают одним блоком (модуль 020)
        const folded = unmatched.filter(u => u.excluded);
        const missing = unmatched.filter(u => !u.excluded);
        if (missing.length) {
            blocks.push(`<div><strong>Не нашлось в каталоге: ${missing.length} поз.</strong> —
                ${missing.map(u => this.esc(u.requested)).join('; ')}.
                КП и письмо назовут их отдельным блоком.</div>`);
        }
        if (folded.length) {
            blocks.push(`<div><strong>Свёрнуто как «нет в наличии»: ${folded.length} поз.</strong> —
                ${folded.map(u => this.esc(u.requested)).join('; ')}.
                В таблицу и «Итого» они не войдут; КП назовёт их блоком «нужно уточнение».</div>`);
        }
        if (buyer.name_is_email) {
            blocks.push(`<div><strong>Название покупателя не определено</strong> — в карточке стоит
                ${this.esc(buyer.name_source)}. В документе печатается «${this.esc(buyer.name)}»;
                впишите название организации в карточке контрагента.</div>`);
        }
        if (!blocks.length) return '';
        return `<div class="kp-warn">
                    <div class="card__title">Проверьте перед отправкой</div>${blocks.join('')}
                </div>`;
    },

    /**
     * Одна карточка товара в редакторе КП.
     *
     * Карточка сворачивается: КП на пятнадцать позиций — это пятнадцать таких
     * форм подряд, и найти в них нужную можно было только прокруткой. Заголовок
     * свёрнутой карточки — строка с названием, числом фото и переключателем
     * «нет в наличии» (модуль 020).
     *
     * «Нет в наличии» сворачивает позицию и из документа: её не будет ни в
     * таблице, ни в карточках, ни в «Итого». Но и молча она не исчезнет — КП
     * назовёт её словами клиента в блоке «нужно уточнение», потому что КП с
     * незаметной дырой хуже КП, в котором чего-то нет.
     */
    itemCardEditor(it) {
        const photos = (() => { try { return JSON.parse(it.images_json || '[]').length; } catch (e) { return 0; } })();
        const off = it.is_excluded == 1;
        return `
            <div class="item-card ${off ? 'item-card--excluded item-card--folded' : ''}" data-item-id="${it.id}">
                <div class="item-card__head" onclick="App.toggleItemCard(this)" title="Свернуть или развернуть карточку">
                    <span class="item-card__caret">▾</span>
                    <strong>${this.esc(it.product_name)}</strong>
                    <span class="note">${photos} фото</span>
                    <label class="item-card__off" onclick="event.stopPropagation()"
                           title="Позиции у нас нет: в таблицу, карточки и «Итого» она не войдёт, а КП назовёт её блоком «нужно уточнение»">
                        <input type="checkbox" data-field="is_excluded" ${off ? 'checked' : ''}
                               onchange="App.excludeItem(this)"> нет в наличии
                    </label>
                </div>
                <div class="item-card__fold">
                ${it.is_substitution ? `
                    <div class="note note--swap">
                        Аналог: просили «${this.esc(it.requested_name)}». Что напишем клиенту про замену —
                        попадёт в сопроводительное письмо и запомнится для следующих КП.
                        ${(it.alt_matched || []).length ? `<ul class="alt-fits">${
                            it.alt_matched.map(m => `<li>соответствует: ${this.esc(m.requirement)}${m.ours ? ' — ' + this.esc(m.ours) : ''}</li>`).join('')
                        }</ul>` : ''}
                        ${(it.alt_differs || []).length ? `<div class="muted">отличается: ${this.esc(it.alt_differs.join('; '))}</div>` : ''}
                    </div>
                    <div class="form-group">
                        <label>Пояснение к замене</label>
                        <input type="text" data-field="alt_reason" value="${this.esc(it.alt_reason || '')}"
                               placeholder="аналог по классу защиты, наш производитель, срок поставки короче">
                    </div>
                    <div class="form-group">
                        <label>Примечание в таблице</label>
                        <input type="text" data-field="notes" value="${this.esc(it.notes || '')}">
                    </div>` : ''}
                <div class="form-group">
                    <label>Описание</label>
                    <textarea rows="3" data-field="description_text">${this.esc(it.description_text || '')}</textarea>
                </div>
                <div class="form-group">
                    <label>Характеристики</label>
                    <textarea rows="4" data-field="specs_text">${this.esc(it.specs_text || '')}</textarea>
                </div>
                <div class="form-group">
                    <label>Комплектация</label>
                    <textarea rows="2" data-field="included_text">${this.esc(it.included_text || '')}</textarea>
                </div>
                <div class="form-group">
                    <label>Ссылка на товар на сайте</label>
                    <input type="url" data-field="site_url" value="${this.esc(it.site_url || '')}"
                           placeholder="подставляется сама из каталога сайта — «Настройки → Сайт (Битрикс)»">
                </div>
                <div class="flex">
                    <label><input type="checkbox" data-field="show_images" ${it.show_images != 0 ? 'checked' : ''}> Фото</label>
                    <label><input type="checkbox" data-field="price_from" ${it.price_from == 1 ? 'checked' : ''}> Цена «от»</label>
                    <label><input type="checkbox" data-field="qty_from" ${it.qty_from == 1 ? 'checked' : ''}> Кол-во «от»</label>
                </div>
                <div class="item-card__photos" data-photos>
                    ${it.moysklad_product_id ? '<div class="muted">Фотографии загружаются...</div>' : ''}
                </div>
                </div>
            </div>`;
    },

    /** Свернуть или развернуть карточку товара в редакторе (модуль 020). */
    toggleItemCard(head) {
        const card = head.closest('.item-card');
        if (card) card.classList.toggle('item-card--folded');
    },

    /**
     * «Нет в наличии»: позиция сворачивается и вместе с карточкой уходит из
     * документа. Сохранится при следующем «Сохранить изменения» — как и любая
     * правка карточки.
     */
    excludeItem(input) {
        const card = input.closest('.item-card');
        if (!card) return;
        card.classList.toggle('item-card--excluded', input.checked);
        if (input.checked) card.classList.add('item-card--folded');
    },

    /**
     * Photo picker of one KP position (FR-046). Photos come from both sources —
     * downloaded through the МойСклад API and the CDN links of the Excel export
     * — and the manager ticks the ones this particular KP should carry.
     */
    async loadItemPhotos(itemId) {
        const card = document.querySelector(`.item-card[data-item-id="${itemId}"]`);
        const box = card && card.querySelector('[data-photos]');
        if (!box) return;
        try {
            const d = await this.api(`proposals.php?action=item_images&item_id=${itemId}`);
            if (!d.available.length) {
                box.innerHTML = '<div class="muted">Фотографий у позиции нет. Их приносит синхронизация с МойСклад '
                              + 'или импорт каталога из Excel.</div>';
                return;
            }
            // No stored choice means «все, что нашлись» — the behaviour before the picker
            const chosen = d.selected === null ? d.available.map(a => a.key) : d.selected;
            box.innerHTML = `
                <div class="muted">Фото в этом КП (${chosen.length} из ${d.available.length}):</div>
                <div class="photos">
                    ${d.available.map(a => `
                        <label class="photo ${chosen.includes(a.key) ? 'photo--on' : ''}">
                            <input type="checkbox" data-photo-key="${this.esc(a.key)}"
                                   ${chosen.includes(a.key) ? 'checked' : ''}
                                   onchange="this.closest('.photo').classList.toggle('photo--on', this.checked)">
                            <img src="${this.esc(a.url)}" alt="" loading="lazy">
                        </label>`).join('')}
                </div>`;
        } catch (err) {
            box.innerHTML = `<div class="no">${this.esc(err.message)}</div>`;
        }
    },

    // One upsell row in the editor
    addonRow(a, i) {
        return `
            <div class="addon-row flex" data-addon style="gap:6px;margin-bottom:6px">
                <input type="checkbox" data-field="is_selected" ${a.is_selected != 0 ? 'checked' : ''} title="Включить в КП">
                <input type="text" data-field="product_name" value="${this.esc(a.product_name || '')}" placeholder="Название модуля" style="flex:3">
                <input type="text" data-field="unit" value="${this.esc(a.unit || 'шт.')}" style="flex:1">
                <input type="number" step="0.01" data-field="price" value="${a.price || 0}" placeholder="Цена" style="flex:1">
                <input type="hidden" data-field="moysklad_product_id" value="${this.esc(a.moysklad_product_id || '')}">
                <button class="btn btn--outline" onclick="this.closest('.addon-row').remove()">×</button>
            </div>`;
    },

    addAddonRow(a = {}) {
        const box = document.getElementById('addonRows');
        if (!box) return;
        box.insertAdjacentHTML('beforeend', this.addonRow({unit: 'шт.', price: 0, is_selected: 1, ...a}, box.children.length));
    },

    // Pull addon candidates from the MoySklad module folder
    async suggestAddons(id) {
        try {
            const res = await this.api(`proposals.php?action=addons_suggest&id=${id}`);
            const existing = new Set([...document.querySelectorAll('[data-addon] [data-field="moysklad_product_id"]')].map(i => i.value));
            let added = 0;
            (res.items || []).forEach(p => {
                if (existing.has(p.moysklad_id)) return;
                this.addAddonRow({
                    product_name: p.name, unit: p.unit || 'шт.', price: p.price,
                    moysklad_product_id: p.moysklad_id, is_selected: 1,
                });
                added++;
            });
            this.toast(added ? `Добавлено модулей: ${added}` : 'Новых модулей не найдено', added ? 'success' : 'info');
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Re-download product photos from MoySklad and rebuild the PDF
    async refreshImages(id) {
        try {
            this.toast('Загружаем фото из МойСклад...', 'info');
            const res = await this.api(`proposals.php?action=refresh_images&id=${id}`, {method: 'POST'});
            this.toast(`Позиций с фото: ${res.items_with_photos}`, 'success');
            this.pageProposal(id);
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Collect the editor state into an update payload
    collectProposal() {
        const items = [...document.querySelectorAll('.item-card')].map(card => {
            const out = {id: parseInt(card.dataset.itemId)};
            card.querySelectorAll('[data-field]').forEach(el => {
                out[el.dataset.field] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
            });
            // The photo picker is only sent once it has actually rendered —
            // otherwise a slow load would be saved as «фото не нужны»
            const boxes = card.querySelectorAll('[data-photo-key]');
            if (boxes.length) {
                out.selected_images = [...boxes].filter(b => b.checked).map(b => b.dataset.photoKey);
            }
            return out;
        });
        const addons = [...document.querySelectorAll('[data-addon]')].map(row => {
            const out = {};
            row.querySelectorAll('[data-field]').forEach(el => {
                out[el.dataset.field] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
            });
            out.price = parseFloat(out.price) || 0;
            return out;
        }).filter(a => (a.product_name || '').trim() !== '');

        return {
            cover_letter_final: document.getElementById('coverLetter').value,
            pre_table_text: document.getElementById('preTable').value,
            post_table_text: document.getElementById('postTable').value,
            warranty_text: document.getElementById('warrantyText').value,
            images_note: document.getElementById('imagesNote').value,
            upsell_intro: document.getElementById('upsellIntro').value,
            upsell_note: document.getElementById('upsellNote').value,
            show_images: document.getElementById('showImages').checked ? 1 : 0,
            show_upsell: document.getElementById('showUpsell').checked ? 1 : 0,
            // Явный выбор менеджера побеждает автоопределение формы запроса
            show_match_table: document.getElementById('showMatchTable').checked ? 1 : 0,
            match_table_note: document.getElementById('matchTableNote').value,
            vat_rate: parseInt(document.getElementById('vatRate').value),
            execution_days: parseInt(document.getElementById('execDays').value),
            show_vat_total: parseInt(document.getElementById('showVat').value),
            // Пусто — работает общая настройка «Фото на позицию, максимум»
            photos_per_item: (document.getElementById('photosPerItem') || {}).value || null,
            items, addons,
        };
    },

    // Save proposal edits
    async saveProposal(id) {
        try {
            await this.api(`proposals.php?action=update&id=${id}`, {method: 'PUT', body: this.collectProposal()});
            this.toast('Сохранено', 'success');
            this.refreshPreview(id);
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Refresh PDF preview
    refreshPreview(id) {
        const frame = document.getElementById('pdfPreview');
        if (frame) frame.src = `/api/proposals.php?action=preview&id=${id}&t=${Date.now()}`;
    },

    /**
     * A КП that prices nothing is refused once, by position, and goes through
     * only on a second, explicit answer (SC-005, module 018). The server decides
     * — this just asks the question it sent back and repeats the call.
     *
     * Returns true when the call went through, false when the manager said no.
     */
    async postWithNoPriceAck(url, body = {}) {
        try {
            await this.api(url, {method: 'POST', body});
            return true;
        } catch (err) {
            const gap = err.data && err.data.no_price;
            if (!gap) throw err;
            const lines = (gap.items || []).map(i => `№${i.position} — ${i.name} (${i.quantity} ${i.unit})`);
            const what = gap.empty
                ? 'В КП нет ни одной позиции.'
                : `Без цены ${lines.length} поз.:\n${lines.join('\n')}\n\nИтого по КП: ${this.fmtMoney(gap.total)}`;
            if (!confirm(`${what}\n\nОтправляем клиенту в таком виде?`)) {
                this.toast('Отменено — проставьте цены и повторите', 'info');
                return false;
            }
            await this.api(url, {method: 'POST', body: {...body, no_price_ack: true}});
            return true;
        }
    },

    // Confirm and prepare for sending
    async confirmAndSend(id) {
        try {
            if (!await this.postWithNoPriceAck(`proposals.php?action=confirm&id=${id}`)) return;
            this.toast('КП подтверждено', 'success');
            this.refreshPreview(id);
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Send proposal email
    async sendProposal(id) {
        const to = document.getElementById('sendTo').value.trim();
        if (!to) return this.toast('Укажите email получателя', 'error');
        try {
            const sent = await this.postWithNoPriceAck(`proposals.php?action=send&id=${id}`, {
                to,
                subject: document.getElementById('sendSubject').value,
                format: document.getElementById('sendFormat')?.value || undefined,
                files: [...document.querySelectorAll('#kpFiles [data-cmp-file]')].map(el => el.dataset.cmpFile),
            });
            if (sent) this.toast('КП отправлено!', 'success');
        } catch (err) { this.toast(err.message, 'error'); }
    },

    /** Свои файлы к письму с КП — тот же выгрузчик, что у ответа на письмо. */
    async kpAttach(input) {
        const list = document.getElementById('kpFiles');
        if (!list || !input.files || !input.files.length) return;
        for (const file of [...input.files]) {
            const fd = new FormData();
            fd.append('file', file);
            try {
                const res = await fetch('/api/mail.php?action=upload', {
                    method: 'POST', body: fd, credentials: 'same-origin',
                });
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Файл не загрузился');
                list.insertAdjacentHTML('beforeend', `
                    <span class="chip" data-cmp-file="${this.esc(data.file.name)}">📎 ${this.esc(data.file.filename)}
                        <a onclick="this.parentElement.remove()" title="Убрать">×</a></span>`);
            } catch (err) { this.toast(err.message, 'error'); }
        }
        input.value = '';
    },

    // Counterparties list — unanswered first (FR-038)
    async pageCounterparties() {
        document.getElementById('app').innerHTML = this.mailShellHtml('companies');
        document.getElementById('mailBody').innerHTML = `
            <div class="card">
                <div class="form-group">
                    <input type="text" id="cpSearch" placeholder="Поиск по названию, ИНН или домену..." oninput="App.searchCounterparties()">
                </div>
                <div id="cpList"><div class="loading">Загрузка...</div></div>
            </div>
        `;
        try {
            const data = await this.api('counterparties.php?action=recent');
            this.renderCounterpartyList(data.items);
        } catch (err) { this.toast(err.message, 'error'); }
    },

    renderCounterpartyList(items) {
        const el = document.getElementById('cpList');
        if (!el) return;
        el.innerHTML = items && items.length
            ? `<table class="table">
                <thead><tr><th>Компания</th><th>ИНН</th><th>Контакт</th><th>Последнее письмо</th></tr></thead>
                <tbody>${items.map(c => `
                    <tr class="${c.answer_state && c.answer_state.unanswered ? 'row--unanswered row--' + c.answer_state.level : ''}"
                        style="cursor:pointer" onclick="location.hash='mail/company/${c.id}'">
                        <td>${this.esc(c.name)} ${this.answerBadge(c.answer_state)}</td>
                        <td>${this.esc(c.inn) || '—'}</td>
                        <td>${this.esc(c.contact_person) || this.esc(c.contact_email) || '—'}</td>
                        <td>${this.fmtDate(c.last_inbound_at)}</td>
                    </tr>`).join('')}
                </tbody></table>`
            : '<p class="muted">Компаний пока нет</p>';
    },

    async searchCounterparties() {
        const q = document.getElementById('cpSearch').value.trim();
        if (q.length < 2) {
            try {
                const data = await this.api('counterparties.php?action=recent');
                this.renderCounterpartyList(data.items);
            } catch {}
            return;
        }
        try {
            const data = await this.api(`counterparties.php?action=search&q=${encodeURIComponent(q)}`);
            this.renderCounterpartyList(data.items);
        } catch {}
    },

    /**
     * The company card — the one place a company lives (module 011).
     *
     * It used to be three screens: a company card here, its letters in the mail
     * list, its requests in a third table. Now the card IS the correspondence:
     * every conversation the company ever sent, each one a request with the КП
     * that answered it, the реквизиты we know, and the счета МойСклад made from
     * them. Nothing about this company is anywhere else.
     */
    async pageCounterparty(id) {
        document.getElementById('app').innerHTML = `<div class="loading">Загрузка...</div>`;
        const cp = await this.api(`counterparties.php?action=get&id=${id}`);
        this.company = cp;
        document.getElementById('app').innerHTML = `
            <div class="flex flex--between flex--wrap" style="margin-bottom:12px;gap:10px">
                <div class="flex flex--wrap" style="gap:10px;align-items:baseline">
                    <a href="#mail" class="btn btn--outline btn--sm">← На доску</a>
                    <h2 style="margin:0">${this.esc(cp.name)}</h2>
                    ${this.answerBadge(cp.answer_state)}
                </div>
                <div class="flex flex--wrap">
                    <button class="btn btn--outline btn--sm" id="syncBtn" onclick="App.syncCompany(${cp.id})">Обновить из МойСклад</button>
                    ${cp.moysklad_id ? `<a class="btn btn--outline btn--sm" target="_blank"
                        href="https://online.moysklad.ru/app/#counterparty/edit?id=${cp.moysklad_id}">МойСклад ↗</a>` : ''}
                </div>
            </div>
            <div id="cardPlacement"></div>
            <div class="grid grid--chat">
                <div>
                    <div class="card card--flush">
                        <div class="card__title" style="padding:12px 16px 0">
                            Переписка
                            <button class="btn btn--outline btn--sm" style="float:right;margin-top:-4px"
                                    onclick="App.toggleCompanyArchive(${cp.id}, this)">Архив</button>
                        </div>
                        <div id="cpThreads"><div class="loading">Загрузка...</div></div>
                    </div>
                </div>
                <div id="companySide">${this.companySide(cp)}</div>
            </div>
        `;
        this.loadCompanyThreads(cp.id);
        this.loadCardPlacement(cp.id);
        this.loadChat(cp.id);
        this.loadCounterpartyPriceTypes(cp);

        // Returning from the MoySklad tab refreshes the card (FR-030)
        this.onTabVisible = () => {
            if (location.hash === `#mail/company/${cp.id}`) this.syncCompany(cp.id, true);
        };
        this.syncCompany(cp.id, true);
    },

    /**
     * Every conversation of the company, newest first. Unanswered rows are bold
     * — the client wrote last and nobody replied; answered rows go to normal
     * weight and dimmed text, which is the whole read/unread language of the
     * card. A row opens in place: the letters load under it, the card stays.
     */
    async loadCompanyThreads(id) {
        const box = document.getElementById('cpThreads');
        if (!box) return;
        const archived = this.companyArchive ? 1 : 0;
        try {
            const d = await this.api(`counterparties.php?action=threads&id=${id}&archived=${archived}`);
            this.companyThreads = d.items || [];
            this.companyMailboxes = d.mailboxes || this.companyMailboxes || [];
            box.innerHTML = this.companyThreads.length
                ? `<div class="mlist">${this.companyThreads.map(t => this.companyThreadRow(t)).join('')}</div>`
                : (archived
                    ? '<div class="mlist__empty">В архиве этой компании пусто</div>'
                    : this.newLetterHtml(this.company || {id}));
            // Открытая переписка — а не кнопка «написать»: письмо, позиции по
            // каталогу и поле ответа видны сразу, без единого нажатия (модуль 019).
            // Раскрывается ровно та, что ждёт ответа: отвеченные и отправленные
            // остаются свёрнутыми, как в почте (модуль 020). И раскрытие само по
            // себе письма не читает — отметку ставит нажатие менеджера.
            const waiting = archived ? null : this.companyThreads.find(t => t.unanswered);
            if (waiting) {
                this.toggleCompanyThread(waiting.thread_key, {markRead: false});
            } else if (!archived && this.companyThreads.length) {
                // Отвечать некому — но писать первым по-прежнему не через кнопку
                // поверх экрана: поле нового письма стоит под списком, открытое
                box.insertAdjacentHTML('beforeend', this.newLetterHtml(this.company || {id},
                    'Все переписки отвечены. Ниже — новое письмо, выше — история: строка разворачивается нажатием.'));
            }
        } catch (err) {
            box.innerHTML = `<div class="mlist__empty">Переписка не загрузилась: ${this.esc(err.message)}</div>`;
        }
    },

    /** «Архив» карточки: письма, убранные как «не наш профиль» или вместе с ящиком. */
    toggleCompanyArchive(id, btn) {
        this.companyArchive = !this.companyArchive;
        if (btn) {
            btn.classList.toggle('btn--primary', this.companyArchive);
            btn.classList.toggle('btn--outline', !this.companyArchive);
            btn.textContent = this.companyArchive ? 'В работе' : 'Архив';
        }
        this.loadCompanyThreads(id);
    },

    /**
     * Компании ещё не писали — поле ответа всё равно открыто. Раньше здесь была
     * кнопка «Написать» наверху карточки, которая открывала окно поверх экрана;
     * теперь письмо пишется там же, где читается переписка.
     */
    newLetterHtml(cp, note = 'Писем от этой компании ещё нет — напишите первым.') {
        const to = cp.suggested_email || cp.contact_email || '';
        return `<div style="padding:0 16px 16px">
            <p class="muted">${this.esc(note)}</p>
            ${this.threadComposer('', {to, subject: '', counterparty_id: cp.id}, this.companyMailboxes || [])}
        </div>`;
    },

    companyThreadRow(t) {
        const cls = ['mrow', 'mrow--thread', t.unanswered ? 'mrow--unanswered' : 'mrow--answered'];
        if (t.unread) cls.push('mrow--unread');
        const kp = t.proposal
            ? `<a class="chip chip--kp" href="#mail/proposal/${t.proposal.id}" onclick="event.stopPropagation()">КП ${this.esc(t.proposal.number || '#' + t.proposal.id)}</a>`
            : (t.request_id ? `<a class="chip" href="#mail/request/${t.request_id}" onclick="event.stopPropagation()">запрос #${t.request_id}</a>` : '');
        return `
            <div class="${cls.join(' ')}" data-thread="${this.esc(t.thread_key)}">
                <div class="mrow__main" onclick="App.toggleCompanyThread('${this.jsStr(t.thread_key)}')">
                    <div class="mrow__subject">
                        ${t.last_direction === 'in' ? '📥' : '📤'}
                        ${this.esc(t.subject) || '<em>без темы</em>'}
                        ${t.count > 1 ? `<span class="mrow__count" title="писем в переписке">${t.count}</span>` : ''}
                        ${t.unread ? `<span class="pill pill--danger">${t.unread}</span>` : ''}
                    </div>
                    <div class="mrow__meta">
                        ${t.category ? this.categoryBadge(t.category, App.categoryLabels[t.category]) : ''}
                        ${kp}
                        ${t.unanswered ? '<span class="badge badge--unanswered">ждёт ответа</span>' : ''}
                        ${(t.mailboxes || []).map(b => `<span class="chip chip--box">${this.esc(b.name)}</span>`).join('')}
                        <span class="muted">${this.fmtDate(t.last_at)}</span>
                    </div>
                    <div class="mrow__preview">${this.esc(t.preview)}</div>
                </div>
                <div class="mrow__meta" style="margin-top:4px">
                    ${t.archived_at
                        ? `<button class="btn btn--outline btn--sm" onclick="App.unarchiveThread('${this.jsStr(t.thread_key)}')">↩ Вернуть в работу</button>
                           <span class="muted">${t.archived_reason === 'mailbox_off' ? 'ящик отключён' : 'не наш профиль'}</span>`
                        : `<button class="btn btn--outline btn--sm" title="Убрать переписку с экрана: на сервере она уйдёт в «Архив»"
                                   onclick="App.archiveThread('${this.jsStr(t.thread_key)}', ${t.count})">🗄 В архив (не наш профиль)</button>`}
                </div>
                <div class="thread-inline" id="th_${this.esc(this.threadDomId(t.thread_key))}" hidden></div>
            </div>`;
    },

    // A thread key is base64-ish («s:9f8c…»), an element id may not be
    threadDomId(key) {
        return String(key).replace(/[^a-zA-Z0-9]/g, '_');
    },

    /**
     * Open a conversation right inside the company card — no page change.
     *
     * Everything the letter needs is under it: the letters themselves, the
     * catalog positions the request turned into (module 012 — the КП table used
     * to live on a separate request page nobody could find), and the reply box
     * at the very bottom. Reading and answering is one screen and no dialog.
     */
    async toggleCompanyThread(key, opts = {}) {
        // Раскрытие руками — это чтение; раскрытие, которое сделал за менеджера
        // экран, — нет. Отсюда и флаг: карточка компании открывает свежую
        // переписку сама и просит НЕ помечать её прочитанной (модуль 020).
        const markRead = opts.markRead !== false;
        const box = document.getElementById('th_' + this.threadDomId(key));
        if (!box) return;
        // Любое нажатие по строке — явное действие, и свернуть раскрытую
        // карточкой переписку тоже можно только посмотрев на неё
        if (markRead && box.dataset.loaded) this.markThreadRead(key, box);
        if (!box.hidden) { box.hidden = true; return; }
        box.hidden = false;
        if (box.dataset.loaded) return;
        box.innerHTML = '<div class="loading">Загрузка писем...</div>';
        try {
            const d = await this.api('mail.php?action=thread&key=' + encodeURIComponent(key)
                                     + (markRead ? '&read=1' : ''));
            const reply = d.reply || {};
            box.innerHTML = `
                <div class="thread">
                    ${d.messages.map((m, i) => this.threadMessage(m, i === d.messages.length - 1, key)).join('')}
                </div>
                <div data-thread-items></div>
                ${this.threadComposer(key, reply, d.mailboxes || [])}
            `;
            box.dataset.loaded = '1';
            this.restoreComposerDraft(key);
            if (reply.request_id) this.loadThreadItems(box, reply.request_id);
            else this.noThreadItems(box);
            // Сервер уже снял отметку вместе с загрузкой — строке остаётся
            // только перестать кричать
            if (markRead) this.markThreadRead(key, box, true);
        } catch (err) {
            box.innerHTML = `<p class="no">${this.esc(err.message)}</p>`;
        }
    },

    /**
     * «Прочитано» по переписке: на сервере и на строке карточки.
     * $onServer — отметку уже поставила загрузка писем (`&read=1`).
     */
    async markThreadRead(key, box, onServer = false) {
        if (box.dataset.read === '1') return;
        box.dataset.read = '1';
        const row = box.closest('.mrow');
        if (row) {
            row.classList.remove('mrow--unread');
            row.querySelectorAll('.pill--danger').forEach(p => p.remove());
        }
        if (!onServer) {
            try { await this.api('mail.php?action=thread_read', {method: 'POST', body: {key}}); } catch {}
        }
    },

    /**
     * The catalog positions of this letter, in the letter. The same table as on
     * the request card — it is the same `request_items` — so a correction here
     * is the one the КП is built from.
     */
    /**
     * Переписка, из которой не завели запрос: подбирать нечего, и блок говорит
     * это вслух. Молча пропущенная таблица читается как «подбор товаров пропал».
     */
    noThreadItems(box) {
        const host = box.querySelector('[data-thread-items]');
        if (!host) return;
        host.className = 'card card--items';
        host.innerHTML = `<div class="card__title">Подходящие позиции${this.hint('match')}</div>
            <p class="muted">По этой переписке запрос не заведён — подбирать по каталогу нечего.
               Позиции появляются, когда письмо разобрано как запрос КП или заказ.</p>`;
    },

    async loadThreadItems(box, requestId) {
        const host = box.querySelector('[data-thread-items]');
        if (!host) return;
        host.className = 'card card--items';
        host.innerHTML = '<div class="loading">Подбираем позиции по каталогу...</div>';
        try {
            const draw = req => {
                const kp = {proposal_id: (req.proposals && req.proposals[0]) ? req.proposals[0].id : null};
                host.dataset.kp = JSON.stringify(kp);
                this.renderMatchedItems(requestId, req.items || [], host, {kp});
            };
            // Сохранённый ответ рисуется сразу, свежий — когда придёт
            draw(await this.apiCached(`requests.php?action=get&id=${requestId}`, draw));
        } catch (err) {
            host.innerHTML = `<p class="no">Позиции не загрузились: ${this.esc(err.message)}</p>`;
        }
    },

    /**
     * The reply, at the bottom of the conversation where a mail program puts it.
     * It used to be a dialog behind a button that stood next to a second button
     * doing almost the same thing; now there is one box, already open, and the
     * model writes into it instead of into a window of its own.
     */
    threadComposer(key, reply, mailboxes) {
        const id = this.threadDomId(key);
        return `
            <div class="composer" data-composer="${this.esc(id)}">
                <div class="composer__head">
                    <span class="composer__title">Ответ${this.hint('composer')}${this.hint('category')}</span>
                    <span class="muted" data-cmp-target>кому: ${this.esc(reply.to || '')}</span>
                    <select data-cmp-box title="Из какого ящика отправить">
                        ${(mailboxes || []).map(b => `<option value="${b.id}" ${reply.mailbox_id === b.id ? 'selected' : ''}>${this.esc(b.name)}</option>`).join('')}
                    </select>
                </div>
                <input type="hidden" data-cmp-to value="${this.esc(reply.to || '')}">
                <input type="hidden" data-cmp-reply value="${reply.reply_to_id || ''}">
                <input type="hidden" data-cmp-cp value="${reply.counterparty_id || ''}">
                <input type="text" data-cmp-subject value="${this.esc(reply.subject || '')}" placeholder="Тема">
                <!-- Текст письма оформляется как текст, а не как разметка (модуль 023):
                     жирный, курсив, списки и ссылки — кнопками, без единого тега на экране -->
                <div class="composer__tools">
                    <button type="button" class="btn btn--outline btn--sm" title="Жирный" onclick="App.rte(this,'bold')"><b>Ж</b></button>
                    <button type="button" class="btn btn--outline btn--sm" title="Курсив" onclick="App.rte(this,'italic')"><i>К</i></button>
                    <button type="button" class="btn btn--outline btn--sm" title="Подчёркнутый" onclick="App.rte(this,'underline')"><u>Ч</u></button>
                    <button type="button" class="btn btn--outline btn--sm" title="Список" onclick="App.rte(this,'insertUnorderedList')">• список</button>
                    <button type="button" class="btn btn--outline btn--sm" title="Нумерованный список" onclick="App.rte(this,'insertOrderedList')">1. список</button>
                    <button type="button" class="btn btn--outline btn--sm" title="Ссылка" onclick="App.rteLink(this)">ссылка</button>
                    <button type="button" class="btn btn--outline btn--sm" title="Убрать оформление" onclick="App.rte(this,'removeFormat')">✕ формат</button>
                </div>
                <div class="composer__editor" data-cmp-rte contenteditable="true"
                     data-placeholder="Ответьте клиенту — или попросите черновик у нейросети"
                     oninput="App.composerChanged('${this.jsStr(key)}')"></div>
                <textarea data-cmp-text hidden></textarea>
                <div class="composer__files" data-cmp-files></div>
                <div class="composer__actions">
                    ${this.categorySelect(reply.category)}
                    <label class="btn btn--outline btn--sm" title="Приложить свой файл к письму">
                        📎 Файл<input type="file" multiple hidden onchange="App.composerAttach('${this.jsStr(key)}', this)">
                    </label>
                    <button class="btn btn--primary btn--sm" onclick="App.threadSend('${this.jsStr(key)}', this)">Отправить</button>
                    <button class="btn btn--outline btn--sm" data-cmp-draft
                            ${reply.reply_to_id ? '' : 'disabled title="Отвечать нечего: в переписке нет входящего письма"'}
                            onclick="App.threadDraft('${this.jsStr(key)}', this)">✨ Сгенерировать ответ</button>
                    <span class="muted" data-cmp-note></span>
                    <span class="muted" data-cmp-saved></span>
                </div>
            </div>`;
    },

    // ---- Оформление текста письма и черновик, который не теряется ----

    /** Кнопка панели оформления. Работает над полем своего же композера. */
    rte(btn, command) {
        const c = btn.closest('[data-composer]');
        const box = c && c.querySelector('[data-cmp-rte]');
        if (!box) return;
        box.focus();
        try { document.execCommand(command, false, null); } catch { /* браузер без execCommand — текст всё равно набирается */ }
        this.composerChanged(c.dataset.composer);
    },

    rteLink(btn) {
        const url = prompt('Адрес ссылки');
        if (!url) return;
        const c = btn.closest('[data-composer]');
        const box = c && c.querySelector('[data-cmp-rte]');
        if (!box) return;
        box.focus();
        try { document.execCommand('createLink', false, url); } catch {}
        this.composerChanged(c.dataset.composer);
    },

    /** Текст письма как его видит сервер: разметка и она же без тегов. */
    composerBody(c) {
        const box = c.querySelector('[data-cmp-rte]');
        if (!box) return {html: '', text: (c.querySelector('[data-cmp-text]') || {}).value || ''};
        const html = box.innerHTML.trim();
        // Текстовая версия — из того же поля, с переносами вместо блоков
        const tmp = document.createElement('div');
        tmp.innerHTML = html
            .replace(/<\/(p|div|li|h[1-6])>/gi, '\n')
            .replace(/<br\s*\/?>/gi, '\n')
            .replace(/<li[^>]*>/gi, '— ');
        const text = (tmp.textContent || '').replace(/\n{3,}/g, '\n\n').trim();
        return {html, text};
    },

    /**
     * Черновик сохраняется сам (модуль 023).
     *
     * «Ввёл текст, отвлёкся, закрыл вкладку — всё пропало» перестаёт быть
     * возможным: набранное уходит на сервер через полторы секунды тишины и
     * возвращается в поле, когда переписку откроют снова — с любого устройства,
     * потому что живёт оно у письма, а не в этом браузере.
     */
    composerChanged(key) {
        clearTimeout(this._draftTimer);
        this._draftTimer = setTimeout(() => this.saveComposerDraft(key), 1500);
    },

    async saveComposerDraft(key) {
        const c = this.composerOf(key);
        if (!c) return;
        const {html} = this.composerBody(c);
        const saved = c.querySelector('[data-cmp-saved]');
        try {
            await this.api('mail.php?action=draft_save', {method: 'POST', body: {
                id: Number(c.querySelector('[data-cmp-reply]').value) || 0,
                thread_key: key || '',
                subject: (c.querySelector('[data-cmp-subject]') || {}).value || '',
                body: html,
            }});
            if (saved) saved.textContent = 'черновик сохранён';
        } catch { if (saved) saved.textContent = 'черновик не сохранился'; }
    },

    /** Вернуть в поле то, что осталось с прошлого раза. */
    async restoreComposerDraft(key) {
        const c = this.composerOf(key);
        if (!c) return;
        const box = c.querySelector('[data-cmp-rte]');
        if (!box || box.innerHTML.trim() !== '') return;
        try {
            const id = Number(c.querySelector('[data-cmp-reply]').value) || 0;
            const d = await this.api('mail.php?action=draft_get&id=' + id
                                     + '&thread_key=' + encodeURIComponent(key || ''));
            if (!d.draft || !d.draft.body) return;
            box.innerHTML = d.draft.body;
            const note = c.querySelector('[data-cmp-saved]');
            if (note) note.textContent = 'восстановлен черновик от ' + this.fmtDate(d.draft.updated_at);
        } catch { /* черновика нет — поле и так пустое */ }
    },

    /** Свои файлы к письму: менеджер мог переделать документ руками. */
    async composerAttach(key, input) {
        const c = this.composerOf(key);
        if (!c || !input.files || !input.files.length) return;
        const list = c.querySelector('[data-cmp-files]');
        for (const file of [...input.files]) {
            const fd = new FormData();
            fd.append('file', file);
            try {
                const res = await fetch('/api/mail.php?action=upload', {
                    method: 'POST', body: fd, credentials: 'same-origin',
                });
                const data = await res.json();
                if (!res.ok || data.error) throw new Error(data.error || 'Файл не загрузился');
                const f = data.file;
                list.insertAdjacentHTML('beforeend', `
                    <span class="chip" data-cmp-file="${this.esc(f.name)}">📎 ${this.esc(f.filename)}
                        <a onclick="this.parentElement.remove()" title="Убрать">×</a></span>`);
            } catch (err) { this.toast(err.message, 'error'); }
        }
        input.value = '';
    },

    composerFiles(c) {
        return [...c.querySelectorAll('[data-cmp-file]')].map(el => el.dataset.cmpFile);
    },

    composerOf(key) {
        return document.querySelector(`[data-composer="${this.threadDomId(key)}"]`);
    },

    /**
     * Классификатор перед кнопкой «Сгенерировать ответ» (модуль 022).
     *
     * Категория письма решает, КАКИМ промптом сервис отвечает и из каких
     * источников берёт факты. До сих пор её выбирала только модель — и на
     * вопрос «а можно ли у спальника отстегнуть слой?» отвечала обещанием
     * «уточнить наличие, цену и сроки»: письмо прочли как запрос цены.
     *
     * Теперь видно, чем сервис собирается отвечать, и это можно поменять до
     * генерации. Изменённая категория — не только правка этого письма: она
     * уходит в примеры классификатору, и в следующем похожем письме он
     * повторит решение менеджера.
     */
    categorySelect(current) {
        const cats = this.categoryList || [];
        const value = current || 'other';
        if (!cats.length) {
            // Список ещё не загрузился — рисуем то, что знаем, и дозагружаем
            this.loadCategoryList();
        }
        const known = cats.length ? cats : [{key: value, label: this.categoryLabels[value] || value, answerable: true}];
        const answerable = known.filter(c => c.answerable);
        const rest = known.filter(c => !c.answerable);
        const opt = c => `<option value="${this.esc(c.key)}" ${c.key === value ? 'selected' : ''}>${this.esc(c.label)}</option>`;
        return `<select data-cmp-cat class="composer__cat"
                        title="Чем отвечаем: категория выбирает промпт и источники фактов. Поправьте — классификатор запомнит">
                    <optgroup label="Отвечаем">${answerable.map(opt).join('')}</optgroup>
                    ${rest.length ? `<optgroup label="Без ответа">${rest.map(opt).join('')}</optgroup>` : ''}
                </select>`;
    },

    /** Категории с признаком «есть чем отвечать» — грузятся один раз. */
    async loadCategoryList() {
        if (this.categoryListLoading) return this.categoryList || [];
        this.categoryListLoading = true;
        try {
            const d = await this.api('requests.php?action=categories');
            this.categoryList = d.categories || [];
            (this.categoryList).forEach(c => { this.categoryLabels[c.key] = c.label; });
            // Списки, нарисованные до загрузки, дозаполняются на месте
            document.querySelectorAll('select[data-cmp-cat]').forEach(sel => {
                const value = sel.value;
                sel.outerHTML = this.categorySelect(value);
            });
        } catch { /* подпись категории не критична — останутся ключи */ }
        finally { this.categoryListLoading = false; }
        return this.categoryList || [];
    },

    /** «Ответить на это письмо» — same box, just aimed at that letter. */
    replyToMessage(key, messageId, to) {
        const c = this.composerOf(key);
        if (!c) return;
        c.querySelector('[data-cmp-reply]').value = messageId;
        if (to) c.querySelector('[data-cmp-to]').value = to;
        const label = c.querySelector('[data-cmp-target]');
        if (label) label.textContent = 'кому: ' + (to || '');
        (c.querySelector('[data-cmp-rte]') || c.querySelector('[data-cmp-text]')).focus();
        c.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        // У каждого письма свой черновик: переключились — подставили его
        this.restoreComposerDraft(key);
    },

    async threadDraft(key, btn) {
        const c = this.composerOf(key);
        if (!c) return;
        const area = c.querySelector('[data-cmp-rte]') || c.querySelector('[data-cmp-text]');
        const id = Number(c.querySelector('[data-cmp-reply]').value);
        if (!id) { this.toast('Нечего отвечать — в переписке нет входящего письма', 'error'); return; }
        btn.disabled = true;
        const label = btn.textContent;
        btn.textContent = 'Генерация...';
        area.placeholder = 'Нейросеть готовит черновик ответа...';
        // Категория, которую менеджер видит перед собой, и есть та, которой
        // отвечаем. Поменял — уходит вместе с запросом и запоминается
        const catSel = c.querySelector('[data-cmp-cat]');
        const body = {id};
        if (catSel && catSel.value) body.category = catSel.value;
        try {
            const r = await this.api('mail.php?action=draft_reply', {method: 'POST', body});
            // Черновик приходит текстом — в редакторе он становится абзацами
            area.innerHTML = (r.text || '').split(/\n{2,}/)
                .map(p => '<p>' + this.esc(p).replace(/\n/g, '<br>') + '</p>').join('');
            this.composerChanged(key);
            const subj = c.querySelector('[data-cmp-subject]');
            if (subj && !subj.value.trim() && r.subject) subj.value = r.subject;
            if (catSel && r.category) catSel.value = r.category;
            const note = c.querySelector('[data-cmp-note]');
            if (note) note.textContent = 'черновик' + (r.category_label ? ` · ${r.category_label}` : '')
                                       + (r.model ? ` · ${r.model}` : '') + ' — проверьте перед отправкой';
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; btn.textContent = label; area.placeholder = ''; }
    },


    async threadSend(key, btn) {
        const c = this.composerOf(key);
        if (!c) return;
        const {text} = this.composerBody(c);
        if (!text.trim()) { this.toast('Письмо пустое', 'error'); return; }
        btn.disabled = true;
        try {
            const res = await this.api('mail.php?action=send', {method: 'POST', body: {
                to:          c.querySelector('[data-cmp-to]').value.trim(),
                subject:     c.querySelector('[data-cmp-subject]').value.trim(),
                text,
                mailbox_id:  (c.querySelector('[data-cmp-box]') || {}).value || null,
                reply_to_id: Number(c.querySelector('[data-cmp-reply]').value) || null,
                counterparty_id: Number(c.querySelector('[data-cmp-cp]').value) || null,
                thread_key:  key || null,
                files:       this.composerFiles(c),
            }});
            // «Отправлено» is only half the news when the copy never reached the
            // server's «Отправленные» — the manager hears it now, not in a month
            if (res.warning) this.toast(res.warning, 'error');
            else this.toast('Письмо отправлено' + (res.sent_folder ? ` · копия в «${res.sent_folder}»` : ''), 'success');
            // The answer belongs in the conversation it answers — reopen it
            const box = key ? document.getElementById('th_' + this.threadDomId(key)) : null;
            if (box) { box.dataset.loaded = ''; box.hidden = true; this.toggleCompanyThread(key); }
            if (this.company) { this.loadCompanyThreads(this.company.id); this.loadChat(this.company.id); }
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; }
    },

    /** Where this company sits on the board, and a one-click move to a column. */
    async loadCardPlacement(id) {
        const box = document.getElementById('cardPlacement');
        if (!box) return;
        try {
            const d = await this.api('boards.php?action=placement&counterparty_id=' + id);
            const t = await this.api('boards.php?action=targets');
            const columns = (t.items[0] || {}).columns || [];
            const here = (d.items || [])[0];
            box.innerHTML = `<div class="card card--inline">
                <span class="muted">Этап:</span>
                ${columns.map(c => `<button class="btn btn--sm ${here && here.column_id == c.id ? 'btn--primary' : 'btn--outline'}"
                    style="border-color:${this.esc(c.color || '#ccc')}"
                    onclick="App.moveCompanyCard(${id}, ${c.id})">${this.esc(c.title)}</button>`).join('')}
            </div>`;
        } catch { box.innerHTML = ''; }
    },

    async moveCompanyCard(counterpartyId, columnId) {
        try {
            await this.api('boards.php?action=card_add', {method: 'POST',
                body: {column_id: columnId, counterparty_id: counterpartyId}});
            this.toast('Карточка перемещена', 'success');
            this.loadCardPlacement(counterpartyId);
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Which price type this counterparty gets by default (module: price defaults)
    async loadCounterpartyPriceTypes(cp) {
        const sel = document.getElementById('cpPriceType');
        if (!sel) return;
        try {
            const d = await this.api('products.php?action=price_types');
            (d.items || []).forEach(name => {
                const opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                if (name === cp.default_price_type) opt.selected = true;
                sel.appendChild(opt);
            });
        } catch { /* каталог ещё не синхронизирован — список типов пуст, это не ошибка */ }
    },

    async setCounterpartyPriceType(id, value) {
        try {
            await this.api(`counterparties.php?action=update&id=${id}`, {method: 'POST', body: {default_price_type: value}});
            this.toast('Сохранено', 'success');
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Right column: info, contacts, orders, invoices
    companySide(cp) {
        const invoices = cp.invoices || [];
        const orders = cp.orders || [];
        const contacts = cp.contacts || [];
        return `
            <div class="card">
                <div class="card__title">Информация</div>
                <p><strong>ИНН:</strong> ${this.esc(cp.inn) || '—'}</p>
                <p><strong>Домен:</strong> ${this.esc(cp.email_domain) || '—'}</p>
                <p><strong>МойСклад:</strong> ${cp.moysklad_id ? 'привязан' : '<span class="muted">не привязан</span>'}</p>
                ${cp.merged_cards && cp.merged_cards.length
                    ? `<p class="muted">Объединено с: ${cp.merged_cards.map(m => this.esc(m.name)).join(', ')}</p>` : ''}
                <div class="form-group" style="margin-top:8px">
                    <label>Цена по умолчанию для этого контрагента</label>
                    <select id="cpPriceType" onchange="App.setCounterpartyPriceType(${cp.id}, this.value)">
                        <option value="">как в настройках каталога</option>
                    </select>
                </div>
            </div>

            <div class="card">
                <div class="card__title">Заметки и события</div>
                <div class="note" style="margin-bottom:8px">Заметки для коллег и вехи сделки.
                    Письма — слева, в «Переписке»: там на них можно ответить.</div>
                <div id="chatFeed" class="chat"><div class="loading">Загрузка...</div></div>
                <div class="chat__composer">
                    <textarea id="noteText" rows="2" placeholder="Заметка для коллег (клиенту не уходит)..."></textarea>
                    <button class="btn btn--outline" onclick="App.addNote(${cp.id})">Добавить заметку</button>
                </div>
            </div>

            <div class="card">
                <div class="card__title">Контакты (${contacts.length})</div>
                ${contacts.length ? contacts.map(c => `
                    <div class="flex flex--between" style="padding:6px 0;border-bottom:1px solid var(--border)">
                        <div>
                            <div>${this.esc(c.name) || this.esc(c.email)}</div>
                            <small class="muted">${this.esc(c.email)}${c.phone ? ' · ' + this.esc(c.phone) : ''} · писем: ${c.messages_count}</small>
                        </div>
                        <button class="btn btn--sm btn--outline" title="Отделить в свою карточку"
                            onclick="App.splitContact(${cp.id}, '${this.jsStr(c.email)}')">Отделить</button>
                    </div>`).join('') : '<p class="muted">Контактов пока нет</p>'}
            </div>

            <div class="card">
                <div class="card__title">Заказы (${orders.length})</div>
                ${orders.length ? orders.map(o => `
                    <div class="flex flex--between" style="padding:6px 0;border-bottom:1px solid var(--border)">
                        <a href="https://online.moysklad.ru/app/#customerorder/edit?id=${o.moysklad_id}" target="_blank">${this.esc(o.name)} ↗</a>
                        <span class="muted">${this.fmtMoney(o.sum)}${o.state_name ? ' · ' + this.esc(o.state_name) : ''}</span>
                    </div>`).join('') : '<p class="muted">Заказов нет</p>'}
            </div>

            <div class="card">
                <div class="card__title">Счета (${invoices.length})</div>
                ${invoices.length ? invoices.map(i => `
                    <div class="invoice">
                        <div class="flex flex--between">
                            <a href="https://online.moysklad.ru/app/#invoiceout/edit?id=${i.moysklad_id}" target="_blank">Счёт ${this.esc(i.name)} ↗</a>
                            <strong>${this.fmtMoney(i.sum)}</strong>
                        </div>
                        <small class="muted">
                            ${this.fmtDate(i.moment, false)}${i.state_name ? ' · ' + this.esc(i.state_name) : ''}
                            ${i.payed_sum > 0 ? ' · оплачено ' + this.fmtMoney(i.payed_sum) : ''}
                            ${i.sent_at ? ' · отправлен ' + this.fmtDate(i.sent_at) : ''}
                        </small>
                        <div class="flex" style="margin-top:6px">
                            <a class="btn btn--sm btn--outline" target="_blank" href="/api/invoices.php?action=pdf&id=${i.id}">PDF</a>
                            <button class="btn btn--sm btn--primary" onclick="App.sendInvoice(${i.id}, '${this.jsStr(cp.suggested_email || '')}')">
                                ${i.sent_at ? 'Отправить ещё раз' : 'Отправить счёт'}
                            </button>
                        </div>
                    </div>`).join('') : '<p class="muted">Счетов нет. Выставьте счёт в МойСклад — он появится здесь.</p>'}
            </div>
        `;
    },

    /**
     * Лента компании (FR-033): заметки коллегам и вехи сделки.
     *
     * Письма сюда больше не приходят — они читаются в «Переписке» слева, где
     * есть цепочка, вложения и поле ответа (модуль 020). Веха вроде «КП
     * отправлено» несёт с собой текст письма: он свёрнут в одну строку и
     * разворачивается нажатием, чтобы лента оставалась лентой.
     */
    async loadChat(id) {
        try {
            const data = await this.api(`counterparties.php?action=chat&id=${id}&limit=50`);
            const feed = document.getElementById('chatFeed');
            if (!feed) return;

            // Отправленные письма живут В ПЕРЕПИСКЕ слева, а не в ленте заметок
            // (модуль 023). Раньше каждое наше письмо было и там, и там: лента
            // событий из-за этого читалась как вторая, неполная почта.
            const MAIL_EVENTS = ['mail_sent', 'kp_sent', 'invoice_sent', 'followup_sent'];
            data.items = (data.items || []).filter(m => !MAIL_EVENTS.includes(m.event_type));

            feed.innerHTML = data.items.length ? data.items.map(m => {
                const isEvent = m.kind === 'event' || !!m.event_type;
                const cls = isEvent ? 'msg--event' : 'msg--note';
                const who = isEvent
                    ? (App.eventLabels[m.event_type] || 'событие')
                    : (this.esc(m.manager_name) || 'система');
                const files = (m.attachments || []).map(a => this.attachmentLink(a, 'requests.php')).join('');
                const body = this.esc(m.body || '');
                return `
                    <div class="msg ${cls}">
                        <div class="msg__head">
                            <span>${who}</span>
                            <span class="muted">${this.fmtDate(m.created_at)}</span>
                        </div>
                        ${m.subject ? `<div class="msg__subject">${this.esc(m.subject)}</div>` : ''}
                        ${m.email_to ? `<div class="muted">кому: ${this.esc(m.email_to)}</div>` : ''}
                        ${body ? `<div class="msg__body ${isEvent ? 'msg__body--fold' : ''}"
                                       ${isEvent ? `onclick="this.classList.toggle('msg__body--fold')" title="Показать текст целиком"` : ''}
                                  >${body}</div>` : ''}
                        ${files ? `<div class="msg__files">${files}</div>` : ''}
                        ${m.request_id ? `<a class="muted" href="#mail/request/${m.request_id}">→ запрос #${m.request_id}</a>` : ''}
                    </div>`;
            }).join('') : `<p class="muted">Заметок и событий пока нет.
                Отправленные письма — в переписке слева.</p>`;

            feed.scrollTop = feed.scrollHeight;
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Чем была веха сделки — читается в ленте без расшифровки в теле
    eventLabels: {
        mail_sent:     'письмо отправлено',
        kp_sent:       'КП отправлено',
        invoice_sent:  'счёт отправлен',
        followup_sent: 'напоминание отправлено',
        order_created: 'заказ создан',
        invoice_created: 'счёт выставлен',
    },

    // Internal note (FR-036)
    async addNote(id) {
        const el = document.getElementById('noteText');
        const text = el.value.trim();
        if (!text) return this.toast('Введите текст заметки', 'error');
        try {
            await this.api(`counterparties.php?action=note&id=${id}`, {method: 'POST', body: {text}});
            el.value = '';
            this.loadChat(id);
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Pull fresh orders and invoices, then repaint the right column (FR-030)
    async syncCompany(id, silent = false) {
        // Background refreshes fire on every focus — don't hammer the MoySklad API
        const now = Date.now();
        if (silent && this._lastSync && this._lastSync.id === id && now - this._lastSync.at < 15000) return;
        this._lastSync = {id, at: now};

        const btn = document.getElementById('syncBtn');
        if (btn && !silent) { btn.disabled = true; btn.textContent = 'Обновление...'; }
        try {
            await this.api(`invoices.php?action=sync&counterparty_id=${id}`, {method: 'POST'});
            const cp = await this.api(`counterparties.php?action=get&id=${id}`);
            const side = document.getElementById('companySide');
            // Фоновое обновление правой колонки не должно съесть недописанную
            // заметку — она теперь живёт именно там (модуль 019)
            const draft = (document.getElementById('noteText') || {}).value || '';
            if (side) side.innerHTML = this.companySide(cp);
            const note = document.getElementById('noteText');
            if (note && draft) note.value = draft;
            this.loadChat(id);
            if (!silent) this.toast('Данные из МойСклад обновлены', 'success');
        } catch (err) {
            if (!silent) this.toast(err.message, 'error');
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Обновить из МойСклад'; }
        }
    },

    // One-click invoice send (FR-032)
    sendInvoice(invoiceId, suggestedEmail) {
        this.modal('Отправка счёта', `
            <div class="form-group">
                <label>Email получателя</label>
                <input type="email" id="invTo" value="${this.esc(suggestedEmail)}" placeholder="client@company.ru">
            </div>
            <div class="form-group">
                <label>Тема письма</label>
                <input type="text" id="invSubject" placeholder="Оставьте пустым — подставим номер счёта">
            </div>
            <button class="btn btn--primary btn--block" onclick="App.doSendInvoice(${invoiceId})">Отправить</button>
        `);
    },

    async doSendInvoice(invoiceId) {
        const to = document.getElementById('invTo').value.trim();
        const subject = document.getElementById('invSubject').value.trim();
        try {
            const r = await this.api(`invoices.php?action=send&id=${invoiceId}`, {method: 'POST', body: {to, subject}});
            this.closeModal();
            this.toast('Счёт отправлен на ' + r.sent_to, 'success');
            const m = location.hash.match(/company\/(\d+)/);
            if (m) this.syncCompany(Number(m[1]), true);
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Split a contact into its own company card (FR-037)
    async splitContact(id, email) {
        if (!confirm(`Отделить ${email} в отдельную карточку компании?`)) return;
        try {
            const r = await this.api(`counterparties.php?action=split&id=${id}`, {method: 'POST', body: {email}});
            this.toast('Карточка разделена', 'success');
            location.hash = `mail/company/${r.id}`;
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Notifications
    //
    // Push lives here, not only under «Настройки → Это устройство»: the switch
    // belongs on the page the manager opens when уведомления не приходят.
    async pageNotifications() {
        const data = await this.api('notifications.php?action=poll');
        document.getElementById('app').innerHTML = `
            <h2 style="margin-bottom:16px">Уведомления</h2>
            <div class="card" id="pushCard"><div class="loading">Загрузка...</div></div>
            <div class="card">
                ${data.items.length ? data.items.map(n => `
                    <div class="flex flex--between" style="padding:10px 0;border-bottom:1px solid var(--border)">
                        <div>
                            <strong>${this.esc(n.title)}</strong>
                            ${n.body ? `<p style="color:var(--text-muted);font-size:13px">${this.esc(n.body)}</p>` : ''}
                            <small style="color:var(--text-muted)">${new Date(n.created_at).toLocaleString('ru-RU')}</small>
                        </div>
                        <div class="flex">
                            ${this.notifLink(n)}
                            <button class="btn btn--sm btn--outline" onclick="App.readNotif(${n.id}, this)">✓</button>
                        </div>
                    </div>
                `).join('') : '<p style="color:var(--text-muted);text-align:center;padding:20px">Нет новых уведомлений</p>'}
            </div>
        `;
        this.renderPushCard();
    },

    // Where a notification leads: the target the server recorded (the letter
    // itself for new mail), with the old ref_type guess for rows written before
    notifLink(n) {
        const url = n.url || (n.ref_type === 'request' && n.ref_id ? '/#mail/request/' + n.ref_id : '');
        const at = url.indexOf('#');
        if (at < 0) return '';   // «/» leads nowhere in particular — no button for it
        return `<a href="${this.esc(url.slice(at))}" class="btn btn--sm btn--outline">Открыть</a>`;
    },

    async readNotif(id, btn) {
        await this.api(`notifications.php?action=read&id=${id}`, {method:'POST'});
        btn.closest('.flex').parentElement.remove();
    },

    // ==== Ликбез, разобранный по местам (модуль 022) ====
    //
    // Инструкция была ОДНОЙ страницей во вкладке «Ликбез»: чтобы понять, что
    // делает кнопка, надо было уйти с экрана, найти абзац про неё и вернуться.
    // Так ею не пользуются — так её один раз пролистывают.
    //
    // Теперь объяснение стоит ТАМ, где вопрос возникает: значок «?» рядом с
    // блоком, по нажатию — короткий текст про этот самый блок. На телефоне
    // подсказка разворачивается во всю ширину внизу экрана: наведения там нет,
    // а `title` не показывается вовсе.
    //
    // Тексты живут в одном месте, и страница «Ликбез» собирается из них же —
    // это по-прежнему полная инструкция, просто больше не единственный способ
    // её прочитать.

    HINTS: {
        // Письма и ответ
        'board':        ['Доска «Письма»', 'Каждая карточка — КОМПАНИЯ, а не письмо: внутри вся её переписка, запросы и КП. Новые письма попадают сюда сами при открытии доски. Колонку карточке вы назначаете сами — сервис её никогда не двигает.'],
        'thread':       ['Переписка', 'Вся цепочка писем с этой компанией, из всех наших ящиков сразу, в одной ленте. Прочитанные и наши собственные письма свёрнуты в строку; чтобы прочитать письмо целиком — нажмите на его заголовок.'],
        'composer':     ['Ответ клиенту', 'Одно окно ответа на переписку. Письмо уходит с того ящика, который выбран справа вверху, и его копия ложится в «Отправленные» этого ящика.'],
        'category':     ['Классификатор', 'Категория решает, каким промптом сервис пишет ответ и откуда берёт факты — из каталога, из заказов или из вики. Если сервис прочитал письмо неправильно, поменяйте категорию ДО генерации: правка запомнится, и в следующем похожем письме он повторит ваше решение.'],
        'match':        ['Подходящие позиции', 'Что строки письма означают в нашем каталоге. Подбираются сами при открытии карточки — модель на это не тратится. Равнозначные варианты сервис не выбирает молча: он спрашивает.'],
        'match-scope':  ['«Не наша номенклатура»', 'Кнопка 🚫 убирает строку из КП и из ответа клиенту целиком: мы ей не занимаемся и ничего по ней не обещаем. Строка остаётся на экране, чтобы вы видели, что из просьбы клиента отброшено. Её слова пополняют список правил — в следующем письме такая же строка отсеется сама.'],
        'match-variant':['Модификации', 'Если в письме один товар просят в нескольких размерах или цветах («р.S-5шт, р.M-13шт»), сервис делает из этого отдельные строки с их количествами и подставляет каждой свою карточку из МойСклад — со своим артикулом, ценой и остатком.'],
        'kp-editor':    ['Редактор КП', 'Здесь правится всё, что попадёт в документ: цены, количества, тексты карточек товаров и блоки вокруг таблицы. Реквизиты и НДС правке не подлежат — они приходят из МойСклад и замораживаются на КП в момент создания.'],
        'kp-exclude':   ['Свернуть позицию', 'Позиции, которой нет в наличии, в таблице КП не будет — но в документе она останется: КП назовёт её словами клиента и скажет, что мы по ней уточняем. Молча выкинуть строку нельзя.'],
        // Настройки
        'kp-settings':  ['Оформление КП', 'Тексты и значения по умолчанию для каждого нового КП: условия поставки, гарантия, сроки, подписи под фотографиями. В самом КП их можно переписать — здесь стоит то, с чего КП начинается.'],
        'signature':    ['Моя подпись', 'КП подписывает тот, кто его отправляет. Загрузите картинку своей подписи и напишите расшифровку — они встанут под вашими КП. Пусто — печатается подписант организации.'],
        'knowledge':    ['База знаний', 'Вики компании из репозитория GitHub. В промпт она попадает не целиком, а теми разделами, которые относятся к тексту письма. Это ЗНАНИЯ О ТОВАРЕ — инструкции про кнопки сюда класть нельзя, они мешают модели отвечать.'],
        'knowledge-check': ['Проверка подбора', 'Вставьте текст письма — увидите, какие разделы вики попадут в промпт и что сервис на это ответит. Ответ можно тут же забраковать кнопкой 👎 и написать, как он должен был звучать: эта правка уйдёт в обучение.'],
        'prompts':      ['Промпты', 'Инструкции, по которым нейросеть пишет каждый ответ и каждое письмо. Правится текстом; у каждого промпта есть история и кнопка «Вернуть встроенный». Ко всем добавляется общий блок дисциплины — его отдельно дублировать не надо.'],
        'tov':          ['Tone of Voice', 'Как мы разговариваем с клиентом: обращение, длина фраз, что обещаем и чего не обещаем. Подмешивается в каждый ответ. Это НЕ база знаний: факты о товаре живут в вики, здесь — только манера речи.'],
        'stores':       ['Склады для остатков', 'Отметьте склады, с которых вы реально отгружаете. Остаток считается только по ним: остаток витрины или брака, попавший в КП, превращается в обещание, которого не выполнить. Ничего не отмечено — считаем по всем складам.'],
        'learning':     ['Правки и обучение', 'Всё, что человек поправил за машиной: категория письма, текст ответа, забракованный подбор. Свежие правки подмешиваются в промпты примерами, а накопленное выгружается архивом в вики компании.'],
        'learning-export': ['Выгрузка правок', 'Архив со всеми новыми правками уходит файлом в репозиторий вики. После удачной выгрузки они помечаются выгруженными, и следующий архив собирается только из новых — повторов не будет.'],
        'logo':         ['Логотипы', 'Три разных знака: в шапке КП, иконка приложения на телефоне и значок вкладки браузера. Файлы лежат вне репозитория, поэтому обновление кода их не стирает. Для КП лучше PNG без прозрачного фона — прозрачность на некоторых серверах не печатается.'],
        'catalog':      ['Каталог товаров', 'Копия номенклатуры МойСклад: названия, артикулы, цены, остатки и модификации. Из неё собираются КП — чтобы документ не зависел от того, отвечает ли сейчас МойСклад. Если API недоступен, каталог можно загрузить из Excel-выгрузки.'],
    },

    /** Значок «?» рядом с блоком. `text` перебивает текст из HINTS. */
    hint(key, text) {
        const known = this.HINTS[key] || [];
        const title = known[0] || '';
        const body = text || known[1] || '';
        if (!body) return '';
        return `<button type="button" class="hint" aria-label="Подсказка: ${this.esc(title || key)}"
                        data-hint-title="${this.esc(title)}" data-hint-body="${this.esc(body)}"
                        onclick="App.showHint(event, this)">?</button>`;
    },

    /** Показать подсказку. Второе нажатие по тому же значку её закрывает. */
    showHint(ev, btn) {
        ev.stopPropagation();
        const open = document.getElementById('hintBubble');
        if (open && open.dataset.owner === (btn.dataset.hintTitle || '') && open.dataset.shown === '1') {
            this.closeHint();
            return;
        }
        this.closeHint();

        const box = document.createElement('div');
        box.id = 'hintBubble';
        box.className = 'hint-bubble';
        box.dataset.owner = btn.dataset.hintTitle || '';
        box.dataset.shown = '1';
        box.innerHTML = `
            <button type="button" class="hint-bubble__close" aria-label="Закрыть" onclick="App.closeHint()">×</button>
            ${btn.dataset.hintTitle ? `<div class="hint-bubble__title">${this.esc(btn.dataset.hintTitle)}</div>` : ''}
            <div class="hint-bubble__text">${this.esc(btn.dataset.hintBody)}</div>`;
        document.body.appendChild(box);

        // Телефон: подсказка приклеена к низу экрана и занимает всю ширину —
        // всплывающее окошко у значка там всё равно не помещается
        if (window.innerWidth > 560) {
            const r = btn.getBoundingClientRect();
            const width = Math.min(340, window.innerWidth - 24);
            box.style.width = width + 'px';
            box.style.left = Math.max(12, Math.min(window.innerWidth - width - 12, r.left - 8)) + 'px';
            box.style.top = (window.scrollY + r.bottom + 8) + 'px';
        }
        this.hintCloser = () => this.closeHint();
        setTimeout(() => {
            document.addEventListener('click', this.hintCloser, {once: true});
            document.addEventListener('keydown', this.hintEsc = e => { if (e.key === 'Escape') this.closeHint(); });
        }, 0);
    },

    closeHint() {
        const box = document.getElementById('hintBubble');
        if (box) box.remove();
        if (this.hintCloser) document.removeEventListener('click', this.hintCloser);
        if (this.hintEsc) document.removeEventListener('keydown', this.hintEsc);
    },

    // ==== Гайд по интерфейсу: подсказки по очереди, а не полотном (модуль 023) ====
    //
    // Значки «?» стоят там, где непонятно, но человек, впервые открывший экран,
    // не знает, какой из них нажать первым, — и не нажимает ни одного. Поэтому
    // при первом заходе на страницу подсказки этой страницы показываются
    // ПОДРЯД, шагами, с подсветкой того места, о котором идёт речь: как это
    // делают все современные приложения. Пройденный гайд не повторяется —
    // отметка живёт в браузере, — а перезапустить его можно кнопкой в «Ликбезе».

    tourSeen(page) {
        try { return localStorage.getItem('tour:' + page) === '1'; } catch { return true; }
    },

    tourMarkSeen(page) {
        try { localStorage.setItem('tour:' + page, '1'); } catch { /* приватный режим — гайд просто покажется снова */ }
    },

    /** Запустить гайд по подсказкам, которые есть на текущем экране. */
    startTour(page, opts = {}) {
        if (!opts.force && this.tourSeen(page)) return;
        const steps = [...document.querySelectorAll('.hint')];
        if (!steps.length) return;
        this.tourMarkSeen(page);
        this.tourSteps = steps;
        this.tourAt = -1;
        this.tourNext();
    },

    tourNext() {
        this.closeHint();
        this.tourAt++;
        const steps = this.tourSteps || [];
        if (this.tourAt >= steps.length) { this.tourEnd(); return; }

        const btn = steps[this.tourAt];
        // Значок мог уехать вместе с перерисовкой — тогда просто идём дальше
        if (!btn || !btn.isConnected) { this.tourNext(); return; }
        btn.scrollIntoView({behavior: 'smooth', block: 'center'});
        btn.classList.add('hint--tour');
        this.showHint(new Event('tour'), btn);

        const box = document.getElementById('hintBubble');
        if (!box) { this.tourEnd(); return; }
        box.classList.add('hint-bubble--tour');
        box.insertAdjacentHTML('beforeend', `
            <div class="hint-bubble__nav">
                <span class="muted">${this.tourAt + 1} из ${steps.length}</span>
                <button type="button" class="btn btn--outline btn--sm" onclick="App.tourEnd()">Пропустить</button>
                <button type="button" class="btn btn--primary btn--sm" onclick="App.tourNext()">
                    ${this.tourAt + 1 === steps.length ? 'Готово' : 'Дальше'}</button>
            </div>`);
        // Клик «куда-нибудь» закрывает обычную подсказку — в гайде он не должен
        // обрывать его на первом же шаге
        if (this.hintCloser) document.removeEventListener('click', this.hintCloser);
    },

    tourEnd() {
        this.closeHint();
        document.querySelectorAll('.hint--tour').forEach(el => el.classList.remove('hint--tour'));
        this.tourSteps = null;
    },

    /** «Показать гайд заново» — из «Ликбеза». */
    restartTour() {
        try {
            Object.keys(localStorage).filter(k => k.startsWith('tour:')).forEach(k => localStorage.removeItem(k));
        } catch {}
        this.toast('Гайд покажется заново на каждом экране', 'success');
        this.startTour(location.hash.slice(1).split('/')[0] || 'mail', {force: true});
    },

    // ==== Settings: one menu item, tabs inside (modules 004 and 008) ====
    // There used to be two «Настройки» in the header — a personal one and an
    // admin one — and no way to tell from the name which held what. Everything
    // lives here now; the tabs an ordinary manager may not touch are hidden.

    settingsTabs() {
        const admin = !!(this.manager && this.manager.is_admin);
        // Тексты — общие. Оформление КП, база знаний, промпты и Tone of Voice
        // открыты менеджеру (модуль 022): он работает с ними каждый день и
        // замечает кривую формулировку раньше всех, а каждая его правка видна
        // админу в ленте на «Обзоре». Тумблеры, ключи, ящики и логи остаются
        // админскими — там ломается не текст, а сервис.
        return [
            // «Ликбез» стоит первым и открыт всем: это единственная страница,
            // которую человек ищет в первый рабочий день (модуль 021)
            ['guide',      'Ликбез',          false],
            ['overview',   'Обзор',           true],
            ['catalog',    'Каталог товаров', false],
            ['moysklad',   'МойСклад',        true],
            ['llm',        'Нейросети',       true],
            ['mail',       'Почта',           true],
            ['processing', 'Обработка писем', true],
            ['kp',         'Оформление КП',   false],
            ['branding',   'Логотипы',        true],
            ['knowledge',  'База знаний',     false],
            ['tov',        'Tone of Voice',   false],
            ['prompts',    'Промпты',         false],
            ['learning',   'Правки и обучение', true],
            ['managers',   'Менеджеры',       true],
            ['device',     'Это устройство',  false],
            ['all',        'Все параметры',   true],
            ['logs',       'Логи',            true],
        ].filter(([, , adminOnly]) => admin || !adminOnly);
    },

    pageSettings(tab) {
        const tabs = this.settingsTabs();
        // Без явной вкладки админ попадает в «Обзор» — он заходит сюда работать,
        // а менеджер в «Ликбез»: ему здесь нужно объяснение, а не тумблеры
        if (!tabs.some(([k]) => k === tab)) tab = (this.manager && this.manager.is_admin) ? 'overview' : 'guide';
        document.getElementById('app').innerHTML = `
            <h2 style="margin-bottom:12px">Настройки</h2>
            <div class="tabs">
                ${tabs.map(([k, l]) => `<a href="#settings/${k}" class="tab ${tab === k ? 'tab--active' : ''}">${this.esc(l)}</a>`).join('')}
            </div>
            <div id="adminBody"><div class="loading">Загрузка...</div></div>
        `;
        const render = {
            guide:      () => this.settingsGuide(),
            overview:   () => this.adminOverview(),
            catalog:    () => this.settingsCatalog(),
            moysklad:   () => this.settingsMoysklad(),
            llm:        () => this.adminLlm(),
            mail:       () => this.adminMail(),
            processing: () => this.settingsProcessing(),
            kp:         () => this.settingsKp(),
            branding:   () => this.settingsBranding(),
            knowledge:  () => this.adminKnowledge(),
            tov:        () => this.settingsTov(),
            learning:   () => this.adminLearning(),
            managers:   () => this.adminManagers(),
            prompts:    () => this.adminPrompts(),
            device:     () => this.settingsDevice(),
            all:        () => this.adminSettings(),
            logs:       () => this.adminLogs(),
        }[tab] || (() => this.settingsCatalog());
        render();
    },

    // ---- «Ликбез»: как этим пользоваться (модуль 021) ----
    //
    // Одна страница на две роли. Менеджеру — про экран, на котором он работает
    // каждый день; администратору — про то, чем этот экран кормится. Текст живёт
    // ЗДЕСЬ, а не в вики компании: вики — это знания о товаре, которые уходят в
    // промпты, а это инструкция к интерфейсу, и путать их нельзя.
    settingsGuide(role) {
        const admin = !!(this.manager && this.manager.is_admin);
        this.guideRole = role || this.guideRole || 'manager';
        if (!admin) this.guideRole = 'manager';

        const section = (title, body) => `
            <div class="card">
                <div class="card__title">${title}</div>
                ${body}
            </div>`;

        document.getElementById('adminBody').innerHTML = `
            ${admin ? `
            <div class="tabs" style="margin-bottom:12px">
                <a class="tab ${this.guideRole === 'manager' ? 'tab--active' : ''}"
                   onclick="App.settingsGuide('manager')">Для менеджера</a>
                <a class="tab ${this.guideRole === 'admin' ? 'tab--active' : ''}"
                   onclick="App.settingsGuide('admin')">Для администратора</a>
            </div>` : ''}
            ${section('Подсказки живут в интерфейсе', `
                <p>Рядом с каждым блоком экрана стоит значок <span class="hint" style="cursor:default">?</span> —
                   нажмите его, и вы прочитаете про ЭТОТ блок, не уходя со страницы. На телефоне подсказка
                   разворачивается внизу экрана.</p>
                <p class="muted">Эта страница — та же справка целиком, для чтения подряд: ниже собраны все
                   подсказки, которые встречаются в интерфейсе.</p>
                <p class="muted">При первом заходе на каждый экран они показываются по очереди,
                   с подсветкой того места, о котором речь.</p>
                <button class="btn btn--outline btn--sm" onclick="App.restartTour()">Показать гайд заново</button>
                ${this.hintIndexHtml()}`)}
            ${this.guideRole === 'admin' ? this.guideAdminHtml(section) : this.guideManagerHtml(section)}
        `;
    },

    /**
     * Все подсказки списком — «Ликбез» собирается из тех же текстов, что стоят
     * у кнопок (модуль 022). Один источник: правка подсказки меняет и то, что
     * видно у блока, и то, что написано в справке.
     */
    hintIndexHtml() {
        return `<div class="hint-index">
            ${Object.entries(this.HINTS).map(([key, [title, body]]) => `
                <div class="hint-index__item">
                    <div class="hint-index__title">${this.esc(title)}</div>
                    <div class="muted">${this.esc(body)}</div>
                </div>`).join('')}
        </div>`;
    },

    guideManagerHtml(section) {
        return `
            ${section('Что это за программа', `
                <p>Панель делает из входящего письма готовое коммерческое предложение. Письма из всех
                   почтовых ящиков компании стекаются сюда сами, раскладываются по компаниям и ждут ответа.
                   Отвечать, считать позиции, собирать КП и отправлять его клиенту — всё на одном экране.</p>
                <p class="muted">Почтовый клиент открывать не нужно: ответ уходит с того же адреса, что и раньше,
                   и его копия ложится в «Отправленные» вашего ящика.</p>`)}

            ${section('Главный экран — доска «Письма»', `
                <p>Карточка на доске — это <strong>компания</strong>, а не одно письмо. Внутри карточки
                   вся переписка с ней, её запросы и её КП.</p>
                <ul>
                    <li><strong>Жирная карточка</strong> — клиент написал последним, ответа он ещё не получил.
                        Такие карточки сами поднимаются вверх колонки.</li>
                    <li><strong>Бледная карточка</strong> — на последнее письмо мы уже ответили.</li>
                    <li>Карточку можно перетащить в другую колонку — порядок колонок программа никогда
                        не меняет сама, это ваша разметка работы.</li>
                    <li>Новые письма попадают во «Входящие» без всякой кнопки: доска обновляется на каждом открытии.</li>
                </ul>`)}

            ${section('Карточка компании: где что лежит', `
                <ul>
                    <li><strong>Переписка</strong> — цепочки писем. Самая свежая переписка, которая ждёт ответа,
                        раскрывается сразу, вместе с полем ответа и таблицей подходящих позиций.</li>
                    <li><strong>Заметки и события</strong> — то, что вы записали руками, и вехи сделки
                        (создано КП, выставлен счёт). Писем здесь нет намеренно: письма читаются в «Переписке».</li>
                    <li><strong>Реквизиты, контакты, ЭДО</strong> — справа. ИНН, адрес и идентификатор ЭДО
                        программа вытаскивает из писем сама, но вы можете поправить.</li>
                </ul>
                <p class="muted">Письмо считается прочитанным, когда его открыли вы, — карточка, раскрывшаяся сама,
                   счётчик непрочитанных не трогает.</p>`)}

            ${section('Как ответить на письмо', `
                <ol>
                    <li>Откройте переписку на карточке компании.</li>
                    <li>Нажмите <strong>«Сгенерировать ответ»</strong> — нейросеть напишет черновик по тексту письма,
                        каталогу и базе знаний компании.</li>
                    <li><strong>Прочитайте и поправьте.</strong> Черновик — это заготовка, а не готовое письмо:
                        ответственность за то, что уйдёт клиенту, остаётся на вас.</li>
                    <li>Прикрепите файлы, если нужно, и нажмите «Отправить».</li>
                </ol>
                <p class="muted">Ваши правки не пропадают впустую: программа запоминает разницу между черновиком
                   и тем, что вы отправили, и следующие черновики становятся ближе к вашему стилю.</p>`)}

            ${section('Как собрать КП', `
                <ol>
                    <li>В письме с запросом откройте <strong>«Подходящие позиции»</strong> — строки запроса уже
                        сопоставлены с каталогом.</li>
                    <li>Где стоит <span class="badge badge--warning">нужен выбор</span>, программа нашла несколько
                        одинаково подходящих товаров и не стала решать за вас — выберите модель сами.</li>
                    <li>Позиции, которых нет в наличии, получают аналог со склада; КП прямо называет,
                        каким требованиям клиента этот аналог отвечает.</li>
                    <li>Нажмите <strong>«Сгенерировать КП»</strong> и проверьте карточки товаров, цены и условия
                        в редакторе. Позицию, которой у нас нет, можно свернуть — из таблицы и «Итого» она уйдёт,
                        но в документе останется отдельной строкой «уточняем».</li>
                    <li><strong>«Отправить клиенту»</strong> — КП уходит письмом в Word (закупщику нужен
                        редактируемый файл); PDF можно приложить отдельно.</li>
                </ol>
                <p class="muted">Цены, наличие и реквизиты в документ ставит каталог и МойСклад, а не нейросеть:
                   выдумать цифру программа не может по устройству.</p>`)}

            ${section('Пометки, которые вы увидите', `
                <ul>
                    <li><span class="badge badge--kp">Запрос КП</span>, <span class="badge badge--new">Доставка</span>,
                        <span class="badge badge--draft">ЭДО</span> — о чём письмо. Категорию ставит разбор входящего;
                        если он ошибся, это видно сразу и правится руками.</li>
                    <li><span class="badge badge--warning">не доставлено</span> — наш ответ не дошёл до адресата.
                        Это не «клиент молчит», это надо перепроверить адрес.</li>
                    <li><strong>«Не наш профиль»</strong> — кнопка, которая убирает переписку <em>с экрана</em>
                        в архив. Из почтового ящика письмо никуда не исчезает, и вернуть его видно можно в любой момент.</li>
                    <li><strong>Спам</strong> — отправитель попадает в чёрный список, следующие его письма не тревожат.</li>
                </ul>`)}

            ${section('Частые вопросы', `
                <p><strong>Письмо пришло, а на доске компании нет.</strong> Значит, разбор счёл письмо служебным
                   (рассылка, уведомление сервиса). Ищите его в «Архиве писем» — ссылка есть на доске.</p>
                <p><strong>Клиент пишет с личной почты.</strong> Компания опознаётся по ИНН, домену и подписи
                   в тексте. С gmail или mail.ru письмо может не найти карточку — привяжите его к компании руками.</p>
                <p><strong>Одно и то же письмо два раза.</strong> Такого быть не должно: дубликаты отсекаются
                   по Message-ID и по содержимому. Если увидели — скажите администратору, это ошибка, а не норма.</p>
                <p><strong>Не нашёл письмо.</strong> Поиск по теме, адресу и тексту есть в «Архиве писем»
                   и на странице компании.</p>
                <p><strong>Панель на телефоне.</strong> Откройте «Настройки → Это устройство» и установите
                   приложение на домашний экран — уведомления о новых письмах будут приходить туда.</p>`)}
        `;
    },

    guideAdminHtml(section) {
        return `
            ${section('Что администратор держит в рабочем состоянии', `
                <p>Менеджер работает на доске. Всё, чем эта доска кормится, живёт в «Настройках»:
                   почтовые ящики, каталог, ключи нейросетей, реквизиты и промпты.</p>
                <p class="muted">Любую настройку можно поменять здесь, в интерфейсе — лазить в <code>config.php</code>
                   на сервере не нужно: значение из панели всегда важнее файла.</p>`)}

            ${section('Почтовые ящики — <a href="#settings/mail">«Почта»</a>', `
                <ul>
                    <li>Яндекс, Mail.ru и Gmail не пускают по обычному паролю — нужен
                        <strong>пароль приложения</strong>. Подсказка со ссылкой появляется прямо в форме ящика.</li>
                    <li><strong>«Забрать почту»</strong> — разовая синхронизация. Регулярно её делает
                        <code>cron/check_mail.php</code>.</li>
                    <li><strong>«Скачать весь архив»</strong> — забирает всю переписку ящика шагами. Старые письма
                        ложатся в архив и на карточки компаний, но запросов КП из них не создаётся.</li>
                    <li><strong>«Отправленные»</strong> — кнопка находит настоящее имя папки на сервере. Если копия
                        отправленного письма не появляется у клиента в ящике, начинать надо отсюда.</li>
                    <li>Ящик <strong>выключают</strong>, а не удаляют: настройки и пароли остаются, письма можно
                        временно убрать с экрана вместе с ним.</li>
                </ul>`)}

            ${section('Дедупликация и импорт переписки', `
                <p><strong>Дедупликация включена по умолчанию и действует на все ящики сразу.</strong>
                   Одно письмо не ляжет в архив дважды — ни из второго ящика, куда оно пришло копией,
                   ни из папки «Отправленные», ни из импортированного mbox.</p>
                <ul>
                    <li>Первый ключ — <strong>Message-ID</strong>, он ищется по всем ящикам.</li>
                    <li>Второй — <strong>отпечаток письма</strong>: отправитель, получатели, тема, текст и байты
                        вложений. Ловит копии, которым шлюз переписал Message-ID.</li>
                    <li>Совсем короткие письма («Спасибо!») по содержимому не сравниваются — два одинаковых
                        «спасибо» в разные дни это два письма, а не одно.</li>
                </ul>
                <p><strong>Импорт mbox</strong> («Настройки → Почта») переносит историю из Gmail или Thunderbird:
                   письма встают на карточки контрагентов в хронологическом порядке, со всеми файлами.
                   Файл любого размера кладётся через панель: браузер режет его на куски, поэтому «413 Request
                   Entity Too Large» от сервера больше не мешает, а оборванная загрузка продолжается с того же
                   места. Архив на гигабайты быстрее положить в <code>storage/mbox</code> по FTP: он появится
                   в списке сам. Импорт тоже идёт шагами и продолжается с того же места.</p>`)}

            ${section('Каталог — <a href="#settings/catalog">«Каталог товаров»</a>', `
                <ul>
                    <li>Два источника, и КП обязано работать на любом: <strong>API МойСклад</strong> и
                        <strong>выгрузка Excel</strong>. Умерший токен не должен останавливать работу.</li>
                    <li>Остатки приходят только по API — в файле выгрузки лежит неснижаемый остаток, а не наличие.</li>
                    <li><strong>Колонка цены в импорте = тип цены по умолчанию.</strong> Это одна настройка,
                        а не две: по ней считается КП, когда для контрагента не выбран свой тип.</li>
                    <li><strong>Векторный поиск</strong> — необязательная добавка к подбору по словам. Без ключа
                        Yandex подбор тихо остаётся словесным, а не ломается.</li>
                </ul>`)}

            ${section('Нейросети и промпты — <a href="#settings/llm">«Нейросети»</a>, <a href="#settings/prompts">«Промпты»</a>', `
                <ul>
                    <li>Два провайдера с запасной цепочкой: Yandex Foundation Models и OpenRouter.
                        Ключи вводятся здесь и показываются маской — первые и последние четыре символа.</li>
                    <li>Все системные промпты правятся в «Промптах», с историей версий и откатом.
                        В коде их нет.</li>
                    <li><a href="#settings/knowledge">«База знаний»</a> — вики компании из GitHub. В промпт
                        подмешиваются только те разделы, которые относятся к делу.</li>
                    <li>Кнопки «Проверить» в каждой карточке говорят, что именно ответил провайдер, — не гадайте.</li>
                </ul>`)}

            ${section('Документы и знаки — <a href="#settings/kp">«Оформление КП»</a>, <a href="#settings/branding">«Логотипы»</a>', `
                <ul>
                    <li>Реквизиты, НДС, банк и договор тянутся из МойСклад и <strong>замораживаются</strong>
                        в КП в момент создания: переиздание документа не меняет того, что уже подписано.</li>
                    <li>Формат для клиента по умолчанию — Word: закупщик переносит позиции в свою форму.</li>
                    <li>Логотипы — КП, знак приложения и значок вкладки — загружаются во вкладке «Логотипы».
                        Файлы лежат вне репозитория, поэтому обновление кода их не перезаписывает.</li>
                </ul>`)}

            ${section('Люди, логи и обновления', `
                <ul>
                    <li><a href="#settings/managers">«Менеджеры»</a> — учётные записи и права. Администратор видит
                        настройки, менеджер — только работу.</li>
                    <li><a href="#settings/logs">«Логи»</a> — всё, что сломалось, с текстом ответа сервера.
                        Красная цифра рядом с «Настройками» — ошибки за сутки.</li>
                    <li><a href="#settings/all">«Все параметры»</a> — полный список настроек с описанием каждой
                        и указанием, откуда взято текущее значение.</li>
                    <li>Автообновление кода включается в «Все параметры → Автообновление»: каждое открытие
                        страницы проверяет GitHub. Это режим активной разработки, на спокойном сервере его выключают.</li>
                </ul>`)}
        `;
    },

    // ---- Логотипы: КП, приложение, значок вкладки (модуль 021) ----

    async settingsBranding() {
        try {
            const d = await this.api('branding.php?action=list');
            document.getElementById('adminBody').innerHTML = `
                <div class="card">
                    <div class="card__title">Логотипы${this.hint('logo')}</div>
                    <p class="muted">Загруженные файлы лежат в <code>storage/logo</code> — вне репозитория,
                       поэтому обновление кода их не перезаписывает. Пока свой файл не загружен, печатается
                       и показывается встроенный знак.</p>
                    ${d.kp_warning ? `<p class="no">${this.esc(d.kp_warning)}</p>`
                        : '<p class="ok">Знак в КП печатается: прозрачность снята, документ его покажет.</p>'}
                </div>
                ${(d.items || []).map(i => `
                    <div class="card">
                        <div class="card__title">${this.esc(i.title)}</div>
                        <p class="muted">${this.esc(i.hint)}</p>
                        <div class="flex flex--wrap" style="gap:16px;align-items:center">
                            <img src="/${i.url}" alt="${this.esc(i.title)}"
                                 style="max-height:64px;max-width:220px;background:#fff;border:1px solid var(--border);
                                        border-radius:4px;padding:6px">
                            <div>
                                <p style="margin:0">${i.uploaded
                                    ? `<span class="badge badge--sent">загружен</span> ${this.esc(i.filename)}`
                                    : `<span class="badge badge--draft">встроенный</span> ${this.esc(i.filename)}`}
                                   <span class="muted">· ${Math.max(1, Math.round(i.size / 1024))} КБ</span></p>
                                <div class="flex flex--wrap" style="margin-top:8px;gap:8px">
                                    <input type="file" id="brand_${i.kind}" accept=".png,.jpg,.jpeg,.svg,.webp${i.kind === 'favicon' ? ',.ico' : ''}">
                                    <button class="btn btn--primary btn--sm" onclick="App.uploadBranding('${i.kind}')">Загрузить</button>
                                    ${i.uploaded ? `<button class="btn btn--outline btn--sm" onclick="App.resetBranding('${i.kind}')">Вернуть встроенный</button>` : ''}
                                </div>
                            </div>
                        </div>
                    </div>`).join('')}
                <div class="card">
                    <div class="card__title">Где знак появится</div>
                    <p class="muted">В коммерческом предложении — сразу, в PDF и в Word. Во вкладке браузера и на
                       иконке установленного приложения — после перезагрузки страницы; телефон может держать
                       старую иконку в кэше до переустановки приложения с домашнего экрана.</p>
                </div>
            `;
        } catch (err) { this.adminFail(err); }
    },

    async uploadBranding(kind) {
        const input = document.getElementById('brand_' + kind);
        if (!input || !input.files.length) { this.toast('Выберите файл', 'error'); return; }
        const fd = new FormData();
        fd.append('kind', kind);
        fd.append('file', input.files[0]);
        try {
            const res = await fetch('/api/branding.php?action=upload', {method: 'POST', body: fd, credentials: 'same-origin'});
            const raw = await res.text();
            let d;
            try { d = JSON.parse(raw); } catch { throw new Error(`Сервер вернул не JSON (HTTP ${res.status}). ${raw.slice(0, 200)}`); }
            if (!res.ok || d.error) throw new Error(d.error || `HTTP ${res.status}`);
            this.toast('Логотип загружен', 'success');
            this.settingsBranding();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async resetBranding(kind) {
        if (!confirm('Вернуть встроенный знак? Загруженный файл будет удалён.')) return;
        try {
            await this.api('branding.php?action=reset', {method: 'POST', body: {kind}});
            this.toast('Вернули встроенный знак', 'success');
            this.settingsBranding();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // ---- Catalog: the local product base the KP and the matcher read ----

    async settingsCatalog() {
        document.getElementById('adminBody').innerHTML = `
            <div class="card" id="catalogStats"><div class="loading">Считаем каталог...</div></div>
            <div class="card">
                <div class="card__title">Обновить из МойСклад${this.hint('catalog')}</div>
                <p class="muted">Тянет номенклатуру, цены, модификации и остатки через API. Нужен рабочий токен.
                   С каких складов считать остаток — «Настройки → МойСклад».</p>
                <button class="btn btn--outline" onclick="App.refreshProducts()">Обновить каталог</button>
            </div>
            <div class="card">
                <div class="card__title">Обновить из файла Excel</div>
                <p class="muted">Выгрузка МойСклад: «Товары → Экспорт → Excel». Из файла берутся названия,
                   артикулы, цены, описания, модификации и ссылки на фотографии.
                   <strong>Остатки не берутся</strong> — в выгрузке лежит неснижаемый остаток,
                   а не наличие; оно приходит синхронизацией по API.</p>
                <div class="grid grid--2">
                    <div class="form-group">
                        <label>Файл выгрузки (.xlsx или .csv)</label>
                        <input type="file" id="catalogFile" accept=".xlsx,.csv">
                    </div>
                    <div class="form-group">
                        <label>Колонка цены = тип цены по умолчанию</label>
                        <input type="text" id="catalogPriceCol" list="priceTypeList" placeholder="Опт безнал">
                        <datalist id="priceTypeList"></datalist>
                        <div class="muted">Колонка «Цена: …» выгрузки. Она же становится типом цены по умолчанию —
                            тем, по которому считается КП, когда для контрагента не выбран свой.
                            Пусто — «Опт безнал», затем «Опт», затем «Розница».</div>
                    </div>
                </div>
                <label style="display:block;margin-bottom:10px">
                    <input type="checkbox" id="catalogPrune">
                    Удалить из базы позиции, которых нет в файле (те, что уже попали в КП, останутся)
                </label>
                <button class="btn btn--primary" id="catalogImportBtn" onclick="App.importCatalog()">Загрузить файл</button>
                <div id="catalogImportResult" style="margin-top:12px"></div>
            </div>
            <div class="card" id="vectorCard"><div class="loading">Проверяем векторный индекс...</div></div>
            <div class="card">
                <div class="card__title">Проверить поиск</div>
                <p class="muted">То же, что подставляется в «Подходящие позиции» на карточке запроса.</p>
                <div class="flex">
                    <input type="text" id="catalogQuery" placeholder="бронежилет скрытого ношения"
                           onkeydown="if(event.key==='Enter')App.catalogSearch()">
                    <button class="btn btn--outline" onclick="App.catalogSearch()">Найти</button>
                </div>
                <div id="catalogSearchResult" style="margin-top:12px"></div>
            </div>
        `;
        this.loadCatalogStats();
        this.loadVectorStats();
        this.loadPriceTypeList();
        try {
            const s = await this.api('admin.php?action=settings');
            const el = document.getElementById('catalogPriceCol');
            // Колонка импорта и тип цены по умолчанию — одна настройка: показываем
            // ту, что реально применяется (модуль 019)
            const col = (s.items || []).find(i => i.key === 'CATALOG_PRICE_COLUMN');
            const def = (s.items || []).find(i => i.key === 'CATALOG_DEFAULT_PRICE_TYPE');
            if (el) el.value = (col && col.value) || (def && def.value) || '';
        } catch { /* a plain manager cannot read settings — the field just stays empty */ }
    },

    /** Типы цен, которые каталог реально знает — и после API, и после Excel. */
    async loadPriceTypeList() {
        const list = document.getElementById('priceTypeList');
        if (!list) return;
        try {
            const d = await this.api('products.php?action=price_types');
            list.innerHTML = (d.items || []).map(t => `<option value="${this.esc(t)}"></option>`).join('');
        } catch { /* каталог ещё не загружен — подсказывать нечем */ }
    },

    async loadCatalogStats() {
        const card = document.getElementById('catalogStats');
        if (!card) return;
        try {
            const d = await this.api('products.php?action=stats');
            card.innerHTML = `
                <div class="card__title">Что сейчас в базе</div>
                <p>Позиций: <strong>${d.total}</strong> · из них модификаций: ${d.variants}
                   · с ценой: ${d.with_price} · с фотографиями: ${d.with_photos}
                   ${d.archived ? ` · архивных: ${d.archived}` : ''}</p>
                <p class="muted">Последнее обновление: ${d.updated_at ? this.fmtDate(d.updated_at) : 'никогда'}
                   ${d.from_excel ? ` · из файла Excel: ${d.from_excel} позиций, ${d.imported_at ? this.fmtDate(d.imported_at) : ''}` : ''}</p>
                ${d.total === 0 ? '<p class="no">Каталог пуст — КП будет собираться без цен и наличия.</p>' : ''}
            `;
        } catch (err) {
            card.innerHTML = `<div class="card__title">Что сейчас в базе</div><p class="no">${this.esc(err.message)}</p>`;
        }
    },

    // ---- Catalog vectors: the «по смыслу» half of the match (module 009) ----

    async loadVectorStats() {
        const card = document.getElementById('vectorCard');
        if (!card) return;
        try {
            const d = await this.api('products.php?action=vector_stats');
            const done = d.total ? Math.round(100 * Math.min(d.indexed, d.total) / d.total) : 0;
            card.innerHTML = `
                <div class="card__title">Векторный поиск по каталогу</div>
                <p class="muted">Эмбеддинги Yandex Cloud позволяют подобрать позицию по смыслу, а не только
                   по совпадению слов: «броник скрытого ношения» находит «Бронежилет скрытого ношения».
                   Индекс строится шагами и продолжается с того места, где остановился.</p>
                ${!d.configured ? '<p class="no">Не заданы ключ и Folder ID Yandex — подбор работает только по словам. Задайте их в «Настройки → Нейросети».</p>'
                    : (!d.enabled ? '<p class="no">Векторный поиск выключен в «Настройки → Все параметры → Подбор позиций».</p>' : '')}
                <p>Векторизовано: <strong>${d.indexed}</strong> из ${d.total}
                   ${d.pending ? ` · ждёт обработки: ${d.pending}` : ' · всё актуально'}
                   ${d.dim ? ` · размерность ${d.dim}` : ''}</p>
                <div class="progress"><div class="progress__bar" style="width:${done}%"></div></div>
                <p class="muted">Модель: ${this.esc(d.model)}${d.updated_at ? ` · обновлён ${this.fmtDate(d.updated_at)}` : ''}</p>
                <div class="flex flex--wrap">
                    <button class="btn btn--primary btn--sm" id="vecBtn" onclick="App.vectorIndex()"
                            ${d.configured ? '' : 'disabled'}>Векторизовать каталог</button>
                    <button class="btn btn--outline btn--sm" onclick="App.vectorReset()">Очистить индекс</button>
                    <button class="btn btn--outline btn--sm" onclick="App.vectorDiagnose()"
                            ${d.configured ? '' : 'disabled'}>Проверить эмбеддинги</button>
                </div>
                <div id="vectorProgress" class="muted" style="margin-top:8px"></div>
                <div id="vectorDiag" style="margin-top:8px"></div>
            `;
        } catch (err) {
            card.innerHTML = `<div class="card__title">Векторный поиск по каталогу</div><p class="no">${this.esc(err.message)}</p>`;
        }
    },

    /**
     * The indexing loop lives in the browser: each request does one bounded step
     * on the server and says how much is left. That is what keeps a 1 200-position
     * catalog — or a wiki of two hundred sections — from ever needing a request
     * longer than the host allows. The same loop drives both indexes.
     */
    async vectorLoop({endpoint, btnId, outId, label, after}) {
        const btn = document.getElementById(btnId);
        const out = document.getElementById(outId);
        const idle = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = 'Векторизуем...'; }
        let indexed = 0, failed = 0, steps = 0;
        try {
            while (steps < 200) {
                const r = (await this.api(endpoint, {method: 'POST', body: {}})).report;
                indexed += r.indexed; failed += r.failed; steps++;
                if (out) out.textContent = `шаг ${steps}: обработано ${indexed}, осталось ${r.left}`
                    + (failed ? `, неудач ${failed}` : '');
                if (r.done) break;
                // A step that moved nothing will not move anything next time either
                if (r.indexed === 0) {
                    this.toast(r.error || 'Шаг без прогресса — проверьте ключ Yandex и логи', 'error');
                    break;
                }
                // Partial failure still has a reason, and the panel prints it
                if (r.error && out) out.textContent += ` · ${r.error}`;
            }
            this.toast(`${label}: ${indexed}` + (failed ? `, не удалось: ${failed}` : ''), failed ? 'info' : 'success');
        } catch (err) {
            this.toast(err.message, 'error');
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = idle; }
            if (after) after();
        }
    },

    vectorIndex() {
        return this.vectorLoop({
            endpoint: 'products.php?action=vector_index',
            btnId: 'vecBtn', outId: 'vectorProgress',
            label: 'Векторизовано позиций',
            after: () => this.loadVectorStats(),
        });
    },

    // One live request to Yandex — the answer to «почему не векторизуется»
    async vectorDiagnose() {
        const out = document.getElementById('vectorDiag');
        out.innerHTML = '<p class="muted">Спрашиваем Yandex Cloud...</p>';
        try {
            const d = await this.api('products.php?action=vector_diagnose');
            out.innerHTML = `
                <p class="${d.ok ? 'ok' : 'no'}">${d.ok
                    ? `Эмбеддинг получен: HTTP ${d.http}, размерность ${d.dim}, ${d.ms} мс`
                    : this.esc(d.error || `HTTP ${d.http}`)}</p>
                <p class="muted">Адрес: <code>${this.esc(d.endpoint)}</code> · модель <code>${this.esc(d.model)}</code>
                   · folder <code>${this.esc(d.folder || 'не задан')}</code> · ключ <code>${this.esc(d.key || 'не задан')}</code>
                   ${d.proxy ? ` · через прокси <code>${this.esc(d.proxy)}</code>` : ''}</p>`;
        } catch (err) { out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`; }
    },

    async vectorReset() {
        if (!confirm('Очистить векторный индекс? Его придётся построить заново.')) return;
        try {
            const r = await this.api('products.php?action=vector_reset', {method: 'POST', body: {}});
            this.toast(`Индекс очищен (${r.removed})`, 'success');
        } catch (err) { this.toast(err.message, 'error'); }
        this.loadVectorStats();
    },

    async importCatalog() {
        const input = document.getElementById('catalogFile');
        const out = document.getElementById('catalogImportResult');
        const btn = document.getElementById('catalogImportBtn');
        if (!input || !input.files.length) { this.toast('Выберите файл выгрузки', 'error'); return; }

        // Колонка цены — это и есть тип цены по умолчанию, поэтому выбор
        // сохраняется в обе настройки сразу; импорт запишет туда же ту колонку,
        // которую в файле действительно нашёл
        const col = (document.getElementById('catalogPriceCol') || {}).value;
        if (col !== undefined && this.manager.is_admin) {
            try {
                await this.api('admin.php?action=settings', {method: 'PUT', body: {values: {
                    CATALOG_PRICE_COLUMN: col,
                    ...(col.trim() ? {CATALOG_DEFAULT_PRICE_TYPE: col.trim()} : {}),
                }}});
            } catch { /* not fatal — the import falls back to the usual columns */ }
        }

        const fd = new FormData();
        fd.append('file', input.files[0]);
        fd.append('prune', document.getElementById('catalogPrune').checked ? '1' : '0');

        btn.disabled = true;
        btn.textContent = 'Читаем файл...';
        out.innerHTML = '<p class="muted">Файл разбирается на сервере, это может занять минуту.</p>';
        try {
            const res = await fetch('/api/products.php?action=import', {method: 'POST', body: fd, credentials: 'same-origin'});
            const raw = await res.text();
            let d;
            try { d = JSON.parse(raw); } catch { throw new Error(`Сервер вернул не JSON (HTTP ${res.status}). ${raw.slice(0, 200)}`); }
            if (!res.ok || d.error) throw new Error(d.error || `HTTP ${res.status}`);

            const r = d.report;
            out.innerHTML = `
                <p class="ok">Загружено позиций: <strong>${r.imported}</strong> из ${r.total}
                   · модификаций: ${r.variants} · с фотографиями: ${r.images}
                   ${r.skipped ? ` · пропущено: ${r.skipped}` : ''}${r.pruned ? ` · удалено: ${r.pruned}` : ''}</p>
                ${r.price_column ? `<p class="muted">Цены взяты из колонки «Цена: ${this.esc(r.price_column)}» —
                    она же теперь тип цены по умолчанию.
                    ${(r.price_types || []).length > 1 ? `Остальные типы цен файла сохранены и доступны в подборе позиций:
                        ${r.price_types.map(t => this.esc(t)).join(', ')}.` : ''}</p>` : ''}
                ${(r.warnings || []).map(w => `<p class="muted">${this.esc(w)}</p>`).join('')}
            `;
            this.loadCatalogStats();
        } catch (err) {
            out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`;
        } finally {
            btn.disabled = false;
            btn.textContent = 'Загрузить файл';
        }
    },

    async catalogSearch() {
        const out = document.getElementById('catalogSearchResult');
        const q = document.getElementById('catalogQuery').value.trim();
        if (!q) return;
        out.innerHTML = '<p class="muted">Ищем...</p>';
        try {
            // The same call the request card makes: words, meaning and the score
            const d = await this.api('products.php?action=match_preview&q=' + encodeURIComponent(q));
            out.innerHTML = d.items.length ? `
                <p class="muted">${d.vector ? 'Подбор идёт по словам и по смыслу.' : 'Векторный индекс выключен — подбор только по словам.'}</p>
                <table class="table">
                    <thead><tr><th>Наименование</th><th>Артикул</th><th class="price">Цена</th><th class="num">Остаток</th><th>Совпадение</th></tr></thead>
                    <tbody>${d.items.map(i => `<tr>
                        <td>${this.esc(i.name)}${i.characteristics ? `<div class="muted">${this.esc(i.characteristics)}</div>` : ''}</td>
                        <td class="muted">${this.esc(i.article || '')}</td>
                        <td class="price">${this.fmtMoney(i.price)}</td>
                        <td class="num">${i.stock ?? '—'}</td>
                        <td class="muted">${Math.round(i.score * 100)}% · ${this.matchSourceLabel(i.source)}
                            <div class="muted">слова ${Math.round(i.lexical * 100)}%${i.vector !== null ? ` · смысл ${Math.round(i.vector * 100)}%` : ''}</div></td>
                    </tr>`).join('')}</tbody>
                </table>` : '<p class="muted">Ничего не найдено — попробуйте другую формулировку или постройте векторный индекс.</p>';
        } catch (err) { out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`; }
    },

    async refreshProducts() {
        this.toast('Обновление каталога...', 'info');
        try {
            const r = await this.api('products.php?action=refresh_cache', {method:'POST'});
            this.toast(`Загружено ${r.count} товаров за ${r.elapsed_sec}с`, 'success');
            this.loadCatalogStats();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // ---- MoySklad: token, access and webhooks ----

    settingsMoysklad() {
        document.getElementById('adminBody').innerHTML = `
            ${this.manager.is_admin ? `
            <div class="card">
                <div class="card__title">Токен доступа</div>
                <p class="muted">МойСклад → профиль сотрудника → «Токен доступа». Токен отзывается при смене
                   пароля сотрудника: если доступ вдруг пропал, чаще всего дело именно в этом.</p>
                <div class="grid grid--2">
                    <div class="form-group"><label>Токен</label>
                        <input type="password" id="set_MOYSKLAD_TOKEN" placeholder="вставьте новый токен"></div>
                    <div class="form-group"><label>ID организации</label>
                        <input type="text" id="set_MOYSKLAD_ORG_ID" value=""></div>
                </div>
                <button class="btn btn--primary" onclick="App.saveMoyskladToken()">Сохранить и проверить</button>
            </div>` : ''}
            <div class="card" id="msCard"><div class="loading">Проверяем доступ...</div></div>
            <div class="card" id="msStores"><div class="loading">Читаем список складов...</div></div>
        `;
        this.loadMoyskladSettings();
        this.loadStores();
        if (this.manager.is_admin) {
            this.api('admin.php?action=settings').then(s => {
                this.settingsSpec = (s.items || []).filter(i => i.group === 'moysklad');
                const org = this.settingsSpec.find(i => i.key === 'MOYSKLAD_ORG_ID');
                const el = document.getElementById('set_MOYSKLAD_ORG_ID');
                if (el && org) el.value = org.value || '';
            }).catch(() => {});
        }
    },

    async saveMoyskladToken() {
        const values = {MOYSKLAD_ORG_ID: document.getElementById('set_MOYSKLAD_ORG_ID').value};
        const token = document.getElementById('set_MOYSKLAD_TOKEN').value;
        if (token !== '') values.MOYSKLAD_TOKEN = token;
        try {
            await this.api('admin.php?action=settings', {method: 'PUT', body: {values}});
            this.toast('Сохранено — проверяем доступ', 'success');
            document.getElementById('set_MOYSKLAD_TOKEN').value = '';
            this.loadMoyskladSettings();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // ---- Attachment parsing, OCR and highlighting thresholds ----

    settingsProcessing() {
        document.getElementById('adminBody').innerHTML = `
            <div class="card" id="processingCard"><div class="loading">Загрузка...</div></div>
            <div class="card">
                <div class="card__title">Кодировка архива писем</div>
                <p class="muted">Письма, забранные до исправления разбора заголовков, могли попасть в архив
                   с испорченной кодировкой — темы выглядят как ряд «?». Кнопка перечитывает такие записи.
                   Тему, от которой в базе не осталось байтов, вернёт только повторное скачивание ящика.</p>
                <button class="btn btn--outline" onclick="App.repairMailEncoding()">Перечитать кодировку</button>
                <div id="repairResult" style="margin-top:10px"></div>
            </div>
        `;
        this.loadProcessingSettings();
    },

    async repairMailEncoding() {
        const out = document.getElementById('repairResult');
        out.innerHTML = '<p class="muted">Перечитываем архив...</p>';
        try {
            const d = await this.api('admin.php?action=mail_repair_encoding', {method: 'POST', body: {}});
            out.innerHTML = `<p class="ok">Проверено писем: ${d.result.checked}, исправлено записей: ${d.result.fixed}</p>`;
        } catch (err) { out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`; }
    },

    // ---- KP look: defaults typed here, facts pulled from МойСклад ----
    // НДС, ИНН, адреса, банк and the договор are not settings — they are what
    // МойСклад holds, frozen onto the КП when it is generated (module 013).
    // The tab shows them so the manager sees the document's real numbers, and
    // the typed-in fields below only cover what МойСклад does not answer.

    async settingsKp() {
        try {
            const d = await this.api('settings.php?action=kp');
            this.kpState = d;
            const g = d.general || {};
            const s = d.seller || {};
            const cv = d.catalog_vat || {};
            const row = (label, value, extra = '') => value
                ? `<p><span class="muted">${label}:</span> ${this.esc(value)}${extra}</p>` : '';
            const vatHint = !s.pays_vat
                ? `<div class="muted">Организация в МойСклад — не плательщик НДС: в КП печатается «${this.esc(d.exempt_note)}», ставка не применяется.</div>`
                : (cv.rate === null || cv.rate === undefined
                    ? '<div class="muted">В каталоге нет ставок НДС — применяется значение из этого поля.</div>'
                    : `<div class="muted">В каталоге МойСклад преобладает ${cv.rate}% (${cv.share}% из ${cv.items} позиций).
                       Ставка позиции всегда важнее этого поля — оно применяется, когда в каталоге ставки нет.
                       ${String(g.default_vat_rate || '') !== String(cv.rate)
                           ? `<a href="#" onclick="App.kpUseCatalogVat(event)">подставить ${cv.rate}%</a>` : ''}</div>`);

            document.getElementById('adminBody').innerHTML = `
                <div class="card">
                    <div class="card__title">Реквизиты из МойСклад</div>
                    <p class="muted">Это подставляется в КП и замораживается в документе в момент создания.
                       Правится в МойСклад, не здесь.</p>
                    ${d.seller.synced_at
                        ? `<p class="muted">Обновлено из МойСклад: ${this.fmtDate(d.seller.synced_at)}</p>`
                        : '<p class="no">Реквизиты ещё ни разу не подтягивались — нажмите «Обновить из МойСклад».</p>'}
                    ${row('Организация', s.full_name)}
                    ${row('Краткое имя', s.short_name)}
                    ${row('ИНН', s.inn)}${row('КПП', s.kpp)}
                    ${row('ОГРН', s.ogrn)}${row('ОГРНИП', s.ogrnip)}${row('ОКПО', s.okpo)}
                    ${row('Юридический адрес', s.legal_address)}
                    ${row('Фактический адрес', s.address)}
                    ${row('Телефон', s.phone)}${row('E-mail', s.email)}
                    ${row('Подписант', s.signatory)}
                    ${row('Банк', (s.bank || {}).line)}
                    <p><span class="muted">НДС:</span> ${s.pays_vat
                        ? 'организация — плательщик НДС'
                        : `не плательщик — в КП «${this.esc(d.exempt_note)}»`}</p>
                    <p class="muted">ID организации в МойСклад: <code>${this.esc(d.org_id || s.moysklad_id || 'не задан')}</code>
                       ${d.token_set ? '' : ' · <span class="no">токен МойСклад не задан</span>'}</p>
                    ${d.autosync ? '' : '<p class="no">Автоподтяжка выключена (REQUISITES_AUTOSYNC) — КП возьмёт то, что лежит здесь.</p>'}
                    ${d.block_in_kp ? '' : '<p class="no">Блок реквизитов в КП выключен (KP_REQUISITES_BLOCK) — документ их не печатает.</p>'}
                    <div class="flex flex--wrap">
                        <button class="btn btn--primary" id="kpReqBtn" onclick="App.kpSyncRequisites()">Обновить из МойСклад</button>
                        <a class="btn btn--outline" href="#settings/moysklad">Токен и ID организации</a>
                    </div>
                    <div id="kpReqResult" style="margin-top:10px"></div>
                </div>

                <div class="card" id="kpSignatureCard"><div class="loading">Читаем подпись...</div></div>

                <div class="card">
                    <div class="card__title">Умолчания коммерческого предложения${this.hint('kp-settings')}</div>
                    <p class="muted">Применяются там, где МойСклад молчит: ставка позиции и реквизиты организации
                       всегда важнее этих полей.</p>
                    <div class="grid grid--3">
                        <div class="form-group"><label>НДС по умолчанию, %</label>
                            <input type="number" id="kpVat" value="${this.esc(g.default_vat_rate || 5)}"
                                   ${s.pays_vat ? '' : 'disabled'}>
                            ${vatHint}</div>
                        <div class="form-group"><label>Срок исполнения, дней</label>
                            <input type="number" id="kpExec" value="${this.esc(g.default_execution_days || 30)}"></div>
                        <div class="form-group"><label>Срок действия КП, дней</label>
                            <input type="number" id="kpValid" value="${this.esc(g.default_validity_days || 14)}"></div>
                        <div class="form-group"><label>Фото на позицию, максимум</label>
                            <input type="number" id="kpMaxImages" min="0" max="12" value="${this.esc(g.kp_max_images_per_item || 5)}"></div>
                        <div class="form-group"><label>Папка модулей в МойСклад</label>
                            <input type="text" id="kpAddonCategory" value="${this.esc(g.addon_category || '')}"></div>
                    </div>
                    <div class="form-group"><label>Условия поставки</label>
                        <textarea id="kpConditions" rows="2">${this.esc(g.default_conditions_text || '')}</textarea></div>
                    <div class="form-group"><label>Гарантия</label>
                        <textarea id="kpWarranty" rows="2">${this.esc(g.default_warranty_text || '')}</textarea></div>
                    <div class="form-group"><label>Оговорка под фотографиями</label>
                        <textarea id="kpImagesNote" rows="2">${this.esc(g.kp_images_note || '')}</textarea></div>
                    <button class="btn btn--primary" onclick="App.saveKpSettings()">Сохранить</button>
                </div>
            `;
            this.loadSignature();
        } catch (err) { this.adminFail(err); }
    },

    /**
     * «Моя подпись» (модуль 022).
     *
     * КП подписывает тот, кто его отправляет, а не компания вообще: раньше под
     * каждым документом стоял прочерк «_______________» и одна фамилия на всех.
     * Прочерк убран — пустое место под подпись в подписанном документе читается
     * как незаполненный бланк, — а фамилия и картинка теперь у каждого свои.
     */
    async loadSignature() {
        const card = document.getElementById('kpSignatureCard');
        if (!card) return;
        try {
            const d = await this.api('admin.php?action=signature');
            card.innerHTML = `
                <div class="card__title">Моя подпись${this.hint('signature')}</div>
                <p class="muted">Ставится под теми КП, которые отправляете вы. Пусто — печатается подписант
                   организации: <strong>${this.esc(d.default_name || '')}</strong>.</p>
                <div class="form-group"><label>Расшифровка подписи</label>
                    <input type="text" id="sigName" value="${this.esc(d.signatory_name || '')}"
                           placeholder="${this.esc(d.default_name || 'Фамилия Имя Отчество')}"></div>
                <p>Сейчас в КП печатается: <strong>${this.esc(d.effective_name || '')}</strong>
                   ${d.has_image ? '<span class="badge badge--sent">с картинкой подписи</span>'
                                 : '<span class="muted">· картинка подписи не загружена</span>'}</p>
                ${d.has_image ? `<p><img src="api/settings.php?action=signature_image&v=${Date.now()}"
                        alt="Подпись" style="max-height:70px;background:#fff;padding:4px;border:1px solid var(--border)"></p>` : ''}
                <div class="flex flex--wrap">
                    <button class="btn btn--primary" onclick="App.saveSignatoryName(this)">Сохранить расшифровку</button>
                    <label class="btn btn--outline" style="cursor:pointer">
                        Загрузить картинку подписи
                        <input type="file" accept="image/png,image/jpeg" hidden onchange="App.uploadSignature(this)">
                    </label>
                    ${d.has_image ? '<button class="btn btn--outline btn--danger" onclick="App.resetSignature()">Убрать картинку</button>' : ''}
                </div>
                <p class="muted" style="margin-top:6px">PNG или JPG, лучше на прозрачном или белом фоне, высотой около 200 px.</p>
                <div id="sigOut" style="margin-top:8px"></div>`;
        } catch (err) {
            card.innerHTML = `<div class="card__title">Моя подпись</div><p class="no">${this.esc(err.message)}</p>`;
        }
    },

    async saveSignatoryName(btn) {
        btn.disabled = true;
        try {
            await this.api('settings.php?action=signatory_name', {method: 'POST',
                body: {signatory_name: document.getElementById('sigName').value}});
            this.toast('Расшифровка сохранена', 'success');
            this.loadSignature();
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; }
    },

    async uploadSignature(input) {
        const file = input.files && input.files[0];
        if (!file) return;
        const out = document.getElementById('sigOut');
        out.innerHTML = '<p class="muted">Загружаем...</p>';
        const fd = new FormData();
        fd.append('file', file);
        try {
            const res = await fetch('/api/settings.php?action=upload_signature', {method: 'POST', body: fd, credentials: 'same-origin'});
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            out.innerHTML = '';
            this.toast('Подпись загружена', 'success');
            this.loadSignature();
        } catch (err) { out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`; }
    },

    async resetSignature() {
        if (!confirm('Убрать картинку подписи? Расшифровка останется.')) return;
        try {
            await this.api('admin.php?action=signature_reset', {method: 'POST', body: {}});
            this.toast('Картинка убрана', 'success');
            this.loadSignature();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    kpUseCatalogVat(ev) {
        if (ev) ev.preventDefault();
        const rate = ((this.kpState || {}).catalog_vat || {}).rate;
        if (rate === null || rate === undefined) return;
        document.getElementById('kpVat').value = rate;
    },

    async kpSyncRequisites() {
        const btn = document.getElementById('kpReqBtn');
        const out = document.getElementById('kpReqResult');
        if (btn) { btn.disabled = true; btn.textContent = 'Спрашиваем МойСклад...'; }
        out.innerHTML = '';
        try {
            await this.api('settings.php?action=kp_requisites_sync', {method: 'POST', body: {}});
            this.toast('Реквизиты обновлены', 'success');
            this.settingsKp();
        } catch (err) {
            out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`;
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Обновить из МойСклад'; }
        }
    },

    async saveKpSettings() {
        try {
            await this.api('settings.php?action=general', {method: 'PUT', body: {
                default_vat_rate: document.getElementById('kpVat').value,
                default_execution_days: document.getElementById('kpExec').value,
                default_validity_days: document.getElementById('kpValid').value,
                kp_max_images_per_item: document.getElementById('kpMaxImages').value,
                addon_category: document.getElementById('kpAddonCategory').value,
                default_conditions_text: document.getElementById('kpConditions').value,
                default_warranty_text: document.getElementById('kpWarranty').value,
                kp_images_note: document.getElementById('kpImagesNote').value,
            }});
            this.toast('Сохранено', 'success');
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // ---- This device: push and the installable app ----

    settingsDevice() {
        document.getElementById('adminBody').innerHTML = `
            <div class="card" id="pushCard"><div class="loading">Загрузка...</div></div>
            <div class="card" id="installCard"><div class="loading">Загрузка...</div></div>
        `;
        this.renderPushCard();
        this.renderInstallCard();
    },

    // MoySklad access + webhook status (FR-029, FR-039)
    async loadMoyskladSettings() {
        const card = document.getElementById('msCard');
        if (!card) return;
        try {
            const d = await this.api('settings.php?action=moysklad');
            const p = d.permissions || {};
            const yes = v => v ? '<span class="ok">есть</span>' : '<span class="no">нет</span>';
            const stale = (d.webhooks_all || []).filter(w => !w.current).length;
            const source = {db: 'задан в этом окне', config: 'из config.php на сервере', default: 'не задан'}[d.token_source] || d.token_source;
            card.innerHTML = `
                <div class="card__title">Доступ к API</div>
                ${d.ms_error ? `<p class="no">${this.esc(d.ms_error)}</p>` : ''}
                ${d.diag ? `<p class="muted">Токен: ${this.esc(source)}, длина ${d.diag.token_len},
                   <code>${this.esc(d.diag.token_mask || '')}</code>${Object.entries(d.diag.probes || {}).map(([k, v]) => ` · ${k}: HTTP ${v.code}`).join('')}</p>
                   <p class="muted">ID организации: <code>${this.esc(d.diag.org_id || 'не задан')}</code></p>` : ''}
                <p>Товары: ${yes(p.products)} · Контрагенты: ${yes(p.counterparties)} · Заказы: ${yes(p.orders_write)}
                   · Счета: ${yes(p.invoices)} · Вебхуки: ${yes(p.webhooks)}</p>
                <p class="muted">Адрес вебхука: <code>${this.esc(d.webhook_url)}</code></p>
                ${!d.app_url_ok ? '<p class="no">APP_URL в настройках должен быть публичным https-адресом — иначе вебхуки не придут, останется подтяжка при открытии карточки.</p>' : ''}
                <p>Зарегистрировано вебхуков: <strong>${(d.webhooks || []).length}</strong> из 4
                   ${stale ? `<span class="muted">· с устаревшим адресом: ${stale} (кнопка ниже их обновит)</span>` : ''}
                   ${d.last_webhook ? `<span class="muted">· последний: ${this.esc(d.last_webhook.entity_type)}/${this.esc(d.last_webhook.action)} — ${this.esc(d.last_webhook.result)}, ${this.fmtDate(d.last_webhook.created_at)}</span>` : ''}</p>
                <div class="flex">
                    <button class="btn btn--primary" onclick="App.registerWebhooks()">Зарегистрировать вебхуки</button>
                    <button class="btn btn--outline" onclick="App.removeWebhooks()">Удалить вебхуки</button>
                </div>
            `;
        } catch (err) {
            card.innerHTML = `<div class="card__title">Доступ к API</div><p class="no">${this.esc(err.message)}</p>`;
        }
    },

    /**
     * Склады, с которых берутся остатки (модуль 022).
     *
     * Остаток вообще не читался: `mapProduct()` писал в кэш ноль, и каждая
     * позиция КП уходила «под заказ», даже когда товар лежал на полке. Теперь
     * он читается отчётом МойСклад — и ровно по тем складам, которые здесь
     * отмечены: остаток витрины или брака, попавший в КП, превращается в
     * обещание, которого не выполнить.
     */
    async loadStores() {
        const card = document.getElementById('msStores');
        if (!card) return;
        try {
            const d = await this.api('admin.php?action=moysklad_stores');
            const selected = d.selected || [];
            const items = (d.items || []).filter(st => !st.archived);
            this.storesList = items;
            card.innerHTML = `
                <div class="card__title">Склады для остатков${this.hint('stores')}</div>
                ${items.length ? `
                <p class="muted">Отмечено ${selected.length || 'ничего — считаем по всем складам'}${selected.length ? ' из ' + items.length : ''}.</p>
                <div class="store-list">
                    ${items.map(st => `
                        <label class="store-list__item">
                            <input type="checkbox" value="${this.esc(st.id)}" ${selected.includes(st.id) ? 'checked' : ''}>
                            <span>${this.esc(st.name)}${st.address ? `<span class="muted"> · ${this.esc(st.address)}</span>` : ''}</span>
                        </label>`).join('')}
                </div>
                <div class="flex flex--wrap" style="margin-top:10px">
                    <button class="btn btn--primary" onclick="App.saveStores(this)">Сохранить выбор</button>
                    <button class="btn btn--outline" onclick="App.refreshStock(this)">Перечитать остатки сейчас</button>
                    <button class="btn btn--outline" onclick="App.refreshVariants(this)"
                            title="Размеры и цвета товаров из МойСклад — без них КП собирает три размера в одну строку">Загрузить модификации</button>
                </div>` : '<p class="muted">МойСклад не вернул ни одного склада — проверьте доступ выше.</p>'}
                <div id="msStockOut" style="margin-top:10px"></div>`;
        } catch (err) {
            card.innerHTML = `<div class="card__title">Склады для остатков${this.hint('stores')}</div>
                <p class="no">${this.esc(err.message)}</p>`;
        }
    },

    async saveStores(btn) {
        const ids = [...document.querySelectorAll('#msStores input[type="checkbox"]:checked')].map(c => c.value);
        btn.disabled = true;
        try {
            await this.api('admin.php?action=settings', {method: 'PUT',
                body: {values: {MOYSKLAD_STORES: ids.join(',')}}});
            this.toast(ids.length ? `Складов выбрано: ${ids.length}` : 'Остатки будут считаться по всем складам', 'success');
            this.loadStores();
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; }
    },

    async refreshStock(btn) {
        const out = document.getElementById('msStockOut');
        btn.disabled = true;
        out.innerHTML = '<p class="muted">Читаем отчёт «Остатки»...</p>';
        try {
            const r = (await this.api('admin.php?action=moysklad_stock_refresh', {method: 'POST', body: {}})).result;
            // Ноль обновлённых позиций — это поломка, а не пустой склад, и
            // говорить о ней надо здесь, а не оставлять нули в карточках (модуль 023)
            const bad = !r.updated;
            out.innerHTML = `<p class="${bad ? 'no' : 'ok'}">Строк в отчёте: ${r.rows}
                · обновлено позиций каталога: ${r.updated}
                ${r.stores ? `· складов: ${r.stores}` : '· по всем складам'}
                ${r.fallback ? '· отчёт по складам не ответил, остатки взяты из ассортимента' : ''}</p>
                ${r.error ? `<p class="no">${this.esc(r.error)}</p>` : ''}
                ${bad ? `<p class="muted">Ни одна позиция отчёта не совпала с каталогом.
                    Проверьте, что выбранные склады те самые, и что у токена есть право читать отчёт «Остатки».</p>` : ''}`;
        } catch (err) { out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`; }
        finally { btn.disabled = false; }
    },

    async refreshVariants(btn) {
        const out = document.getElementById('msStockOut');
        btn.disabled = true;
        out.innerHTML = '<p class="muted">Загружаем модификации...</p>';
        try {
            const r = await this.api('admin.php?action=moysklad_variants_refresh', {method: 'POST', body: {}});
            out.innerHTML = `<p class="ok">Модификаций в каталоге: ${r.variants}</p>`;
        } catch (err) { out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`; }
        finally { btn.disabled = false; }
    },

    async registerWebhooks() {
        try {
            const r = await this.api('settings.php?action=webhooks_register', {method: 'POST'});
            this.toast(`Вебхуки готовы: новых ${r.created}, обновлено ${r.updated}, уже было ${r.kept}`, 'success');
            this.loadMoyskladSettings();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async removeWebhooks() {
        try {
            const r = await this.api('settings.php?action=webhooks_remove', {method: 'POST'});
            this.toast(`Удалено вебхуков: ${r.removed}`, 'success');
            this.loadMoyskladSettings();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Attachment parsing, OCR and highlighting thresholds
    async loadProcessingSettings() {
        const card = document.getElementById('processingCard');
        try {
            const d = await this.api('settings.php?action=general');
            card.innerHTML = `
                <div class="card__title">Обработка писем</div>
                <div class="grid grid--3">
                    <div class="form-group">
                        <label>OCR сканов (Yandex Vision)</label>
                        <select id="setOcr">
                            <option value="1" ${d.ocr_enabled === '1' ? 'selected' : ''}>Включён</option>
                            <option value="0" ${d.ocr_enabled !== '1' ? 'selected' : ''}>Выключен</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Страниц OCR на файл</label>
                        <input type="number" id="setOcrPages" min="1" max="20" value="${this.esc(d.ocr_max_pages || 3)}">
                    </div>
                    <div class="form-group">
                        <label>Макс. размер вложения, МБ</label>
                        <input type="number" id="setMaxMb" min="1" max="50" value="${this.esc(d.attachment_max_mb || 10)}">
                    </div>
                    <div class="form-group">
                        <label>Порог «без ответа», часов</label>
                        <input type="number" id="setUnanswered" min="1" max="168" value="${this.esc(d.unanswered_critical_h || 24)}">
                    </div>
                    <div class="form-group" style="grid-column:span 2">
                        <label>Тема письма со счётом</label>
                        <input type="text" id="setInvoiceSubject" value="${this.esc(d.invoice_email_subject || '')}">
                    </div>
                </div>
                <button class="btn btn--outline" onclick="App.saveProcessingSettings()">Сохранить</button>
            `;
        } catch (err) {
            card.innerHTML = `<div class="card__title">Обработка писем</div><p class="no">${this.esc(err.message)}</p>`;
        }
    },

    async saveProcessingSettings() {
        try {
            await this.api('settings.php?action=general', {method: 'PUT', body: {
                ocr_enabled: document.getElementById('setOcr').value,
                ocr_max_pages: document.getElementById('setOcrPages').value,
                attachment_max_mb: document.getElementById('setMaxMb').value,
                unanswered_critical_h: document.getElementById('setUnanswered').value,
                invoice_email_subject: document.getElementById('setInvoiceSubject').value,
            }});
            this.toast('Сохранено', 'success');
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // ==== Mail: the archive of every incoming and outgoing letter (module 004) ====

    // ==== Mail: a mail client over the company mailboxes (modules 004, 010) ====
    // Letters are grouped into conversations by subject («Re:» and «Fwd:» stripped),
    // so an answer sent from Gmail sits in the same thread as the Yandex original.

    async pageMailInbox() {
        document.getElementById('app').innerHTML = this.mailShellHtml('inbox', `
            <button class="btn btn--outline" onclick="App.mailSync()">⟳ Синхронизировать</button>
            <button class="btn btn--primary" onclick="App.mailCompose()">✉ Написать</button>
        `);
        const state = this.mailState = this.mailState || {direction: '', mailbox_id: '', q: '', offset: 0};
        const qs = new URLSearchParams({
            limit: 50, offset: state.offset,
            ...(state.direction ? {direction: state.direction} : {}),
            ...(state.mailbox_id ? {mailbox_id: state.mailbox_id} : {}),
            ...(state.q ? {q: state.q} : {}),
            ...(state.unread ? {unread: 1} : {}),
            ...(state.archived ? {archived: 1} : {}),
        });
        const d = await this.api('mail.php?action=threads&' + qs);
        const tab = (key, label) => `<button class="btn btn--sm ${state.direction === key && !state.unread && !state.archived ? 'btn--primary' : 'btn--outline'}"
            onclick="App.mailFilter({direction:'${key}',unread:0,archived:0})">${label}</button>`;

        document.getElementById('mailBody').innerHTML = `
            ${(d.mailboxes || []).filter(b => b.last_error).map(b => `
                <div class="card card--alert"><strong>${this.esc(b.name)}</strong>: ${this.esc(b.last_error)}</div>
            `).join('')}
            ${!d.mailboxes || !d.mailboxes.length ? `<div class="card">Почтовые ящики ещё не настроены.
                ${this.manager.is_admin ? '<a href="#settings/mail">Добавить ящик</a>' : 'Обратитесь к администратору.'}</div>` : ''}
            <div class="card card--inline">
                ${tab('', 'Все')}${tab('in', 'Входящие')}${tab('out', 'Исходящие')}
                <button class="btn btn--sm ${state.unread ? 'btn--primary' : 'btn--outline'}" onclick="App.mailFilter({unread:1,direction:'',archived:0})">Непрочитанные</button>
                <button class="btn btn--sm ${state.archived ? 'btn--primary' : 'btn--outline'}"
                        title="Не наш профиль и письма отключённых ящиков"
                        onclick="App.mailFilter({archived:1,unread:0,direction:''})">🗄 Архив</button>
                <select id="mailBox" onchange="App.mailFilter({mailbox_id:this.value})">
                    <option value="">Все ящики</option>
                    ${(d.mailboxes || []).map(b => `<option value="${b.id}" ${String(state.mailbox_id) === String(b.id) ? 'selected' : ''}>${this.esc(b.name)}</option>`).join('')}
                </select>
                <input type="text" id="mailQ" placeholder="Поиск по теме, адресу и тексту" value="${this.esc(state.q)}"
                       style="max-width:320px" onkeydown="if(event.key==='Enter')App.mailFilter({q:this.value})">
                <span class="muted">Переписок: ${d.total}</span>
            </div>
            <div class="card card--flush">
                <div class="mlist">
                    ${d.items.map(t => this.threadRow(t)).join('')}
                    ${d.items.length === 0 ? '<div class="mlist__empty">Писем нет</div>' : ''}
                </div>
                <div class="flex flex--between" style="padding:12px 16px">
                    <button class="btn btn--sm btn--outline" ${state.offset === 0 ? 'disabled' : ''} onclick="App.mailPage(-1)">← Новее</button>
                    <button class="btn btn--sm btn--outline" ${state.offset + 50 >= d.total ? 'disabled' : ''} onclick="App.mailPage(1)">Старее →</button>
                </div>
            </div>
        `;
    },

    /**
     * One conversation in the list: subject in normal size, everything else in
     * the small grey type of a mail client, and the «Re:» count as a bubble —
     * «Тема · 4» is how you tell a live discussion from a one-off letter.
     */
    threadRow(t) {
        const who = [...new Set((t.participants || []).slice(0, 3))].join(', ');
        // Answered conversations step back — normal weight, dimmed; the ones
        // still waiting on us stay bold. Same language as the board (module 011).
        const state = t.last_direction === 'in' ? 'mrow--unanswered' : 'mrow--answered';
        return `
            <div class="mrow ${state} ${t.unread ? 'mrow--unread' : ''}" onclick="location.hash='mail/t/${encodeURIComponent(t.thread_key)}'">
                <div class="mrow__dir">${t.last_direction === 'in' ? '📥' : '📤'}${t.has_attachment ? '<span class="mrow__clip">📎</span>' : ''}</div>
                <div class="mrow__main">
                    <div class="mrow__subject">
                        ${this.esc(t.subject) || '<em>без темы</em>'}
                        ${t.count > 1 ? `<span class="mrow__count" title="писем в переписке">${t.count}</span>` : ''}
                        ${t.unread ? `<span class="pill pill--danger">${t.unread}</span>` : ''}
                    </div>
                    <div class="mrow__meta">
                        ${this.esc(who)}
                        ${t.counterparty_id ? ` · <a href="#mail/company/${t.counterparty_id}" onclick="event.stopPropagation()">${this.esc(t.counterparty_name)}</a>` : ''}
                        ${(t.mailboxes || []).map(b => `<span class="chip chip--box">${this.esc(b.name)}</span>`).join('')}
                    </div>
                    <div class="mrow__preview">${this.esc(t.preview)}</div>
                </div>
                <div class="mrow__date">${this.fmtDate(t.last_at)}
                    ${t.archived_at ? `<div><a onclick="event.stopPropagation();App.unarchiveThread('${this.jsStr(t.thread_key)}')">↩ в работу</a></div>` : ''}
                </div>
                <button class="mrow__del" title="Удалить переписку"
                        onclick="event.stopPropagation();App.deleteThread('${this.jsStr(t.thread_key)}', ${t.count})">🗑</button>
            </div>`;
    },

    mailFilter(patch) {
        this.mailState = Object.assign(this.mailState || {}, patch, {offset: 0});
        this.pageMailInbox();
    },

    mailPage(dir) {
        this.mailState.offset = Math.max(0, (this.mailState.offset || 0) + dir * 50);
        this.pageMailInbox();
    },

    async mailSync() {
        this.toast('Синхронизация почты...', 'info');
        try {
            const r = await this.api('mail.php?action=sync', {method: 'POST', body: {}});
            const total = (r.report || []).reduce((a, x) => a + x.in + x.out, 0);
            const errors = (r.report || []).filter(x => x.error);
            if (errors.length) this.toast(errors.map(e => `${e.name}: ${e.error}`).join('; '), 'error');
            else this.toast(`Загружено писем: ${total}`, 'success');
            this.pageMailInbox();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    /**
     * The conversation itself: every letter of the thread from every mailbox, in
     * order. The last one is open, the earlier ones are folded the way a mail
     * client folds them.
     */
    async pageMailThread(key) {
        // Открыть переписку отдельной страницей — само по себе явное действие:
        // здесь отметка «прочитано» ставится сразу (модуль 020)
        const d = await this.api('mail.php?action=thread&read=1&key=' + encodeURIComponent(key));
        const t = d.thread;
        const reply = d.reply || {};
        // Кто написал: последнее входящее письмо цепочки — то, на что отвечают
        const lastIn = [...(d.messages || [])].reverse().find(m => m.direction === 'in') || (d.messages || [])[0] || {};
        const sender = {name: lastIn.from_name || '', email: lastIn.from_email || ''};
        document.getElementById('app').innerHTML = `
            <div class="flex flex--between flex--wrap" style="margin-bottom:12px;gap:10px">
                <div>
                    <!-- В шапке карточки — компания, ОТПРАВИТЕЛЬ и тема, как они
                         есть: по одной теме понять, чьё это письмо, нельзя (модуль 023) -->
                    <h2 style="margin-bottom:2px">${this.esc(t.counterparty_name || sender.name || sender.email || 'Без компании')}</h2>
                    <div class="thread-head__who">
                        ${sender.name || sender.email
                            ? `<span>${this.esc(sender.name || '')}${sender.email ? ` &lt;${this.esc(sender.email)}&gt;` : ''}</span>` : ''}
                        <span class="thread-head__subject">${this.esc(t.subject || 'Без темы')}</span>
                    </div>
                    <div class="muted" style="font-size:12px">
                        писем: ${t.count} · входящих ${t.in_count} · исходящих ${t.out_count}
                        ${(t.mailboxes || []).map(b => `<span class="chip chip--box">${this.esc(b.name)}</span>`).join('')}
                        ${t.counterparty_id ? ` · <a href="#mail/company/${t.counterparty_id}">${this.esc(t.counterparty_name)}</a>` : ''}
                    </div>
                </div>
                <div class="flex flex--wrap">
                    <a href="#mail/inbox" class="btn btn--outline btn--sm">← К списку</a>
                    <button class="btn btn--outline btn--sm" onclick="App.boardPick('${this.jsStr(key)}')">▦ В доску</button>
                    ${t.archived_at
                        ? `<button class="btn btn--outline btn--sm" onclick="App.unarchiveThread('${this.jsStr(key)}')">↩ Вернуть в работу</button>`
                        : `<button class="btn btn--outline btn--sm" onclick="App.archiveThread('${this.jsStr(key)}', ${t.count})">🗄 В архив</button>`}
                    <button class="btn btn--outline btn--sm btn--danger" onclick="App.deleteThread('${this.jsStr(key)}', ${t.count})">🗑 Удалить переписку</button>
                </div>
            </div>
            <div id="threadPlacement"></div>
            <!-- Одна раскладка для всех писем: переписка слева, подбор справа на
                 десктопе и снизу на телефоне. Раньше «Подходящие позиции» стояли
                 то справа, то внизу — в зависимости от того, откуда письмо
                 открыли, и это читалось как два разных экрана (модуль 023). -->
            <div class="letter">
                <div class="letter__main">
                    <div class="thread">
                        ${d.messages.map((m, i) => this.threadMessage(m, i === d.messages.length - 1, key)).join('')}
                    </div>
                    ${this.threadComposer(key, reply, d.mailboxes || [])}
                </div>
                <aside class="letter__side">
                    <div data-thread-items></div>
                    <div id="threadFacts"></div>
                </aside>
            </div>
        `;
        this.loadThreadPlacement(key);
        this.restoreComposerDraft(key);
        this.loadThreadFacts(t, lastIn);
        // Панель подбора есть у КАЖДОГО письма: у письма без запроса она честно
        // говорит, почему пуста, а не исчезает вовсе
        const host = document.getElementById('app');
        if (reply.request_id) this.loadThreadItems(host, reply.request_id);
        else this.noThreadItems(host);
    },

    /**
     * Правая панель письма: что мы про него знаем (модуль 023).
     *
     * Раньше её не было у писем без запроса — «письмо с вопросом по заказу»
     * открывалось голым экраном, и непонятно было, от кого оно и куда пришло.
     * Панель одна на все письма; пустых полей в ней просто нет.
     */
    loadThreadFacts(t, lastIn) {
        const box = document.getElementById('threadFacts');
        if (!box) return;
        const rows = [];
        const add = (label, value) => { if (value) rows.push(`<div class="facts__row"><span>${label}</span><b>${value}</b></div>`); };
        add('Отправитель', this.esc([lastIn.from_name, lastIn.from_email].filter(Boolean).join(' · ')));
        add('Кому', this.esc(lastIn.to_emails || ''));
        add('Ящик', this.esc(lastIn.mailbox_name || ''));
        add('Получено', this.fmtDate(lastIn.date_at));
        add('Компания', t.counterparty_id
            ? `<a href="#mail/company/${t.counterparty_id}">${this.esc(t.counterparty_name || '')}</a>` : '');
        add('Категория', this.esc(this.categoryLabels[lastIn.category] || lastIn.category || ''));
        add('Вложений', (lastIn.attachments || []).length || '');
        box.className = 'card';
        box.innerHTML = `<div class="card__title">О письме</div>
            <div class="facts">${rows.join('') || '<p class="muted">Ничего, кроме самого письма.</p>'}</div>`;
    },

    /**
     * Одно письмо переписки. $isLast — последнее ли оно в цепочке.
     *
     * Развёрнуто то, что ещё не прочитано, и последнее письмо клиента: именно
     * его читают, открыв компанию. Наши ответы и уже прочитанные письма
     * свёрнуты в строку с началом текста и разворачиваются нажатием — так
     * переписка из пятнадцати писем читается сверху вниз, а не пролистывается
     * (модуль 020). $key — цепочка, в которой письмо показано, чтобы
     * «ответить» попало в единственное поле внизу, а не открыло своё окно.
     */
    threadMessage(m, isLast, key) {
        // A bounced answer is not a delivered one: the client is still waiting
        const sentBad = m.direction === 'out'
            && ['failed', 'bounced', 'bounce_soft'].includes(m.sent_state);
        const open = m.direction === 'in' && (isLast || Number(m.is_read) === 0);
        const preview = (m.body_text || '').replace(/\s+/g, ' ').trim().slice(0, 120);
        return `
            <div class="tmsg ${m.direction === 'in' ? 'tmsg--in' : 'tmsg--out'}" data-tmsg>
                <div class="tmsg__head" onclick="App.toggleTmsg(this)">
                    <span class="tmsg__who">${this.esc(m.from_name || m.from_email || '—')}</span>
                    <span class="muted">${m.direction === 'in' ? '→ нам' : '→ ' + this.esc(m.to_emails)}</span>
                    ${preview ? `<span class="tmsg__preview muted">${this.esc(preview)}</span>` : ''}
                    <span class="chip chip--box">${this.esc(m.mailbox_name || 'без ящика')}</span>
                    ${m.folder ? `<span class="muted tmsg__folder">${this.esc(m.folder)}</span>` : ''}
                    ${m.has_attachment ? '<span>📎</span>' : ''}
                    ${sentBad ? '<span class="badge badge--warning" title="Копия не попала в «Отправленные» на сервере">нет в «Отправленных»</span>' : ''}
                    <span class="tmsg__date muted">${this.fmtDate(m.date_at)}</span>
                </div>
                <div class="tmsg__body">
                    ${m.cc_emails ? `<div class="muted" style="margin-bottom:6px">Копия: ${this.esc(m.cc_emails)}</div>` : ''}
                    ${this.msgBodyHtml(m)}
                    ${(m.attachments || []).length ? `<div class="msg__files">
                        ${m.attachments.map(a => this.attachmentLink(a, 'mail.php')).join('')}
                    </div>` : ''}
                    <div class="flex flex--wrap" style="margin-top:8px">
                        <button class="btn btn--outline btn--sm"
                                onclick="App.replyToMessage('${this.jsStr(key || '')}', ${m.id}, '${this.jsStr(m.direction === 'in' ? (m.from_email || '') : (m.to_emails || ''))}')">
                            Ответить на это письмо</button>
                        ${m.direction === 'in' && !m.archived_at ? `<button class="btn btn--outline btn--sm" title="Не наш профиль: письмо уйдёт в «Архив» на сервере"
                            onclick="App.archiveMail(${m.id})">🗄 В архив</button>` : ''}
                        ${m.archived_at ? `<button class="btn btn--outline btn--sm" onclick="App.unarchiveMail(${m.id})">↩ Вернуть в работу</button>` : ''}
                        ${m.direction === 'in' ? `<button class="btn btn--outline btn--sm btn--danger" onclick="App.markSpam(${m.id})">🚫 Спам</button>` : ''}
                        <button class="btn btn--outline btn--sm btn--danger" onclick="App.deleteMail(${m.id})">🗑 Удалить</button>
                    </div>
                </div>
            </div>`.replace('class="tmsg ', open ? 'class="tmsg tmsg--open ' : 'class="tmsg ');
    },

    // Which board this conversation already sits on
    async loadThreadPlacement(key) {
        const box = document.getElementById('threadPlacement');
        if (!box) return;
        try {
            const d = await this.api('boards.php?action=placement&thread_key=' + encodeURIComponent(key));
            box.innerHTML = (d.items || []).length ? `<div class="card card--inline">
                <span class="muted">На доске:</span>
                ${d.items.map(p => `<a class="chip" href="#mail/board" style="border-color:${this.esc(p.color || '#ccc')}">
                    ${this.esc(p.column_title)}</a>`).join('')}
            </div>` : '';
        } catch { box.innerHTML = ''; }
    },

    // A single letter, for links that point at one message rather than a thread
    async pageMailMessage(id) {
        const m = await this.api(`mail.php?action=get&id=${id}`);
        // Уведомление ведёт сюда, а отвечать отсюда нельзя. Если у письма есть
        // переписка — а она есть почти всегда, — открываем карточку, из которой
        // видно всё и можно ответить (модуль 023).
        if (m.thread_key) {
            location.replace('#mail/t/' + encodeURIComponent(m.thread_key));
            return;
        }
        document.getElementById('app').innerHTML = `
            <div class="flex flex--between flex--wrap" style="margin-bottom:16px;gap:10px">
                <h2 style="margin:0">${this.esc(m.subject) || 'Без темы'}</h2>
                <div class="flex flex--wrap">
                    <a href="#mail/inbox" class="btn btn--outline btn--sm">← К списку</a>
                    ${m.thread_key
                        ? `<a href="#mail/t/${encodeURIComponent(m.thread_key)}" class="btn btn--primary btn--sm">Вся переписка и ответ →</a>`
                        : `<button class="btn btn--primary btn--sm" onclick="App.mailCompose(${m.id})">Ответить</button>`}
                    ${m.direction === 'in' && !m.archived_at ? `<button class="btn btn--outline btn--sm" onclick="App.archiveMail(${m.id})">🗄 В архив</button>` : ''}
                    ${m.archived_at ? `<button class="btn btn--outline btn--sm" onclick="App.unarchiveMail(${m.id})">↩ Вернуть в работу</button>` : ''}
                    ${m.direction === 'in' ? `<button class="btn btn--outline btn--sm btn--danger" onclick="App.markSpam(${m.id})">🚫 Спам</button>` : ''}
                    <button class="btn btn--outline btn--sm btn--danger" onclick="App.deleteMail(${m.id}, '${m.thread_key ? 'mail/t/' + encodeURIComponent(m.thread_key) : 'mail/inbox'}')">🗑 Удалить</button>
                </div>
            </div>
            <div class="card">
                <p><strong>${m.direction === 'in' ? 'От' : 'Кому'}:</strong>
                   ${this.esc(m.direction === 'in' ? (m.from_email || '') : (m.to_emails || ''))}
                   ${m.from_name ? '· ' + this.esc(m.from_name) : ''}</p>
                ${m.cc_emails ? `<p><strong>Копия:</strong> ${this.esc(m.cc_emails)}</p>` : ''}
                <p class="muted">${this.esc(m.mailbox_name) || ''} · ${this.esc(m.folder)} · ${this.fmtDate(m.date_at)}</p>
                <p><strong>Компания:</strong> ${m.counterparty_id
                    ? `<a href="#mail/company/${m.counterparty_id}">${this.esc(m.counterparty_name)}</a>`
                    : 'не определена'}
                   ${m.request_id ? ` · <a href="#mail/request/${m.request_id}">Запрос #${m.request_id}</a>` : ''}</p>
                ${m.error ? `<p class="no">Ошибка обработки: ${this.esc(m.error)}</p>` : ''}
                ${m.direction === 'out' && m.sent_state === 'failed'
                    ? '<p class="no">Копия письма не попала в «Отправленные» на почтовом сервере — смотрите «Настройки → Почта».</p>' : ''}
                ${m.direction === 'out' && ['bounced', 'bounce_soft'].includes(m.sent_state)
                    ? `<p class="no"><strong>Письмо не доставлено.</strong> ${this.esc(m.error || 'Почтовый сервер вернул отчёт о недоставке')} — клиент ответа не получил.</p>` : ''}
                ${m.needs_call
                    ? '<p class="no"><strong>Заявка с сайта: нужен звонок.</strong> Посетитель оставил телефон и не оставил адреса — ответить письмом некуда.</p>' : ''}
                ${m.source_channel === 'site_form'
                    ? '<p class="muted">Пришло с формы сайта — отправитель и тема восстановлены из полей формы.</p>' : ''}
                <hr style="margin:12px 0">
                ${this.msgBodyHtml(m, true)}
                ${(m.attachments || []).length ? `
                    <div class="msg__files">
                        ${m.attachments.map(a => this.attachmentLink(a, 'mail.php')).join('')}
                    </div>` : ''}
            </div>
        `;
    },


    // ==== The board (modules 010 + 011) ====
    // Trello, without Trello: columns you rename, and companies you drag by
    // hand. Exactly one board, and it is the whole section — a card is a
    // COMPANY with all of its correspondence on it, so there is nothing else to
    // switch to. Every company that writes to us is already in «Входящие» when
    // the page opens; the manager only decides which column it moves on to.

    async pageMailBoard() {
        document.getElementById('app').innerHTML = this.mailShellHtml('board', `
            <input type="text" id="boardFilter" style="flex:0 1 260px;min-width:160px"
                   placeholder="Поиск: тема, адрес, текст, файл…"
                   title="Ищет по всей почте: темам, телу писем, адресам, именам и вложениям — включая архив"
                   oninput="App.boardFilter(this.value)">
            <button class="btn btn--outline btn--sm" onclick="App.boardSync()">⟳ Забрать почту</button>
            <button class="btn btn--outline btn--sm" onclick="App.mailCompose()">✉ Написать</button>
            <button class="btn btn--outline btn--sm" onclick="App.boardAddColumn()">+ Колонка</button>
            <a href="#mail/inbox" class="btn btn--outline btn--sm" title="Плоский архив всех писем">Архив</a>
        `);
        // action=get syncs first: the intake is not a button somebody remembers
        // to press, it is what opening the board means
        const b = await this.api('boards.php?action=get');
        this.board = b;
        document.getElementById('mailBody').innerHTML = `
            <div id="boardSearchOut"></div>
            <div class="board" id="board">
                ${b.columns.map(c => this.boardColumn(c)).join('')}
            </div>
        `;
        this.boardBindDnd();
        const added = (b.sync && (b.sync.created || b.sync.upgraded)) || 0;
        if (added) this.toast(`Новых карточек на доске: ${added}`, 'success');
    },

    // Fetch mail from the servers, then pull whatever arrived onto the board
    async boardSync() {
        this.toast('Синхронизация почты...', 'info');
        try {
            const r = await this.api('mail.php?action=sync', {method: 'POST', body: {}});
            const errors = (r.report || []).filter(x => x.error);
            if (errors.length) this.toast(errors.map(e => `${e.name}: ${e.error}`).join('; '), 'error');
        } catch (err) { this.toast(err.message, 'error'); }
        this.pageMailBoard();
    },

    // Client-side filter over the cards already on the page — every company on
    // this board, not just the one column being looked at
    boardFilter(q) {
        q = q.trim();
        const low = q.toLowerCase();
        let shown = 0;
        document.querySelectorAll('.bcard').forEach(card => {
            const hit = !q || card.textContent.toLowerCase().includes(low);
            card.hidden = !hit;
            if (hit && q) shown++;
        });
        this.boardRecount();

        // Заголовок карточки — это тема и компания, и только. Письмо ищут по
        // адресу, по фамилии, по имени файла — по всему, что в нём есть, и
        // это умеет только сервер (модуль 023).
        clearTimeout(this._boardSearchTimer);
        const out = document.getElementById('boardSearchOut');
        if (out && q.length < 2) { out.innerHTML = ''; return; }
        this._boardSearchTimer = setTimeout(() => this.boardSearchServer(q, shown), 350);
    },

    /** Сквозной поиск по почте: тела, адреса, имена, вложения, архив. */
    async boardSearchServer(q, shownLocally) {
        const out = document.getElementById('boardSearchOut');
        if (!out) return;
        out.innerHTML = '<div class="loading">Ищем по всем письмам...</div>';
        try {
            const d = await this.api('mail.php?action=threads&archived=all&limit=30&q=' + encodeURIComponent(q));
            const items = d.items || [];
            if (!items.length) {
                out.innerHTML = shownLocally
                    ? ''
                    : `<div class="card"><p class="muted">По запросу «${this.esc(q)}» ничего не нашлось —
                       ни в темах, ни в тексте писем, ни в адресах, ни в именах вложений.</p></div>`;
                return;
            }
            out.innerHTML = `<div class="card">
                <div class="card__title">Найдено в почте: ${items.length}${d.total > items.length ? ' из ' + d.total : ''}</div>
                ${items.map(t => this.threadRow ? this.threadRow(t) : `
                    <div class="mrow" onclick="location.hash='mail/t/${encodeURIComponent(t.thread_key)}'">
                        <div class="mrow__main">
                            <div class="mrow__subject">${this.esc(t.subject || 'без темы')}</div>
                            <div class="mrow__meta">${this.esc((t.participants || []).join(', '))}
                                ${t.archived_at ? '<span class="chip">архив</span>' : ''}</div>
                            <div class="mrow__preview">${this.esc(t.preview || '')}</div>
                        </div>
                        <div class="mrow__date">${this.fmtDate(t.last_at)}</div>
                    </div>`).join('')}
            </div>`;
        } catch (err) { out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`; }
    },

    boardColumn(c) {
        return `
            <div class="bcol" data-col="${c.id}" style="--col:${this.esc(c.color || '#8a8f98')}">
                <div class="bcol__head">
                    <span class="bcol__title" onclick="App.boardRenameColumn(${c.id}, '${this.jsStr(c.title)}')">${this.esc(c.title)}</span>
                    ${c.kind === 'inbox' ? '<span class="bcol__kind" title="Сюда сами падают новые письма">авто</span>' : ''}
                    <span class="bcol__count">${c.cards.length}</span>
                    <button class="bcol__x" title="Удалить колонку" onclick="App.boardDeleteColumn(${c.id})">×</button>
                </div>
                <div class="bcol__cards" data-drop="${c.id}">
                    ${c.cards.map(card => this.boardCard(card)).join('')}
                </div>
                <button class="bcol__add" onclick="App.boardAddCard(${c.id})">+ карточка</button>
            </div>`;
    },

    /**
     * A card is a company: its name, what it last wrote about, and whether the
     * ball is on our side. Unanswered is loud — bold, coloured edge, top of the
     * column; answered goes quiet — normal weight and dimmed — so a column of
     * forty cards still says at a glance which three need a person.
     */
    boardCard(card) {
        const c = card.company || {};
        const t = card.thread || {};
        const cls = ['bcard'];
        if (card.hot) cls.push('bcard--hot');
        if (card.unanswered) cls.push('bcard--unanswered'); else cls.push('bcard--answered');
        const href = card.counterparty_id
            ? `#mail/company/${card.counterparty_id}`
            : (card.thread_key ? `#mail/t/${encodeURIComponent(card.thread_key)}` : '');
        const letters = c.letters || t.count || 0;
        const subject = c.subject || t.subject || '';
        const kp = c.proposal_status ? this.proposalBadge(c.proposal_status) : '';
        return `
            <div class="${cls.join(' ')}" draggable="true" data-card="${card.id}">
                <div class="bcard__title">
                    ${href ? `<a href="${href}">${this.esc(card.title)}</a>` : this.esc(card.title)}
                    ${card.unread ? `<span class="pill pill--danger" title="непрочитанных писем">${card.unread}</span>` : ''}
                </div>
                ${subject ? `<div class="bcard__subject">${this.esc(subject)}</div>` : ''}
                ${card.note ? `<div class="bcard__note">${this.esc(card.note)}</div>` : ''}
                <div class="bcard__meta">
                    ${letters ? `<span class="chip">писем ${letters}</span>` : ''}
                    ${c.requests_open ? `<span class="chip chip--work">запросов ${c.requests_open}</span>` : ''}
                    ${kp}
                    ${card.kind === 'thread' ? '<span class="chip chip--new" title="Отправитель ещё не привязан к компании">новый адрес</span>' : ''}
                    ${card.last_at ? `<span class="muted">${this.fmtDate(card.last_at, false)}</span>` : ''}
                </div>
                <div class="bcard__actions">
                    ${href ? `<a href="${href}">открыть</a>` : ''}
                    <a onclick="App.boardCardNote(${card.id})">заметка</a>
                    <a onclick="App.boardCardDelete(${card.id})">убрать</a>
                </div>
            </div>`;
    },

    proposalBadge(status) {
        const map = {draft: ['КП черновик', 'badge--draft'], confirmed: ['КП готово', 'badge--draft'],
                     sent: ['КП отправлено', 'badge--sent'], accepted: ['КП принято', 'badge--confirmed']};
        const [label, cls] = map[status] || [status, 'badge--new'];
        return `<span class="badge ${cls}">${this.esc(label)}</span>`;
    },

    /**
     * Drag and drop with the browser's own HTML5 API — no library on a page that
     * has none. The card being dragged is shown where it would land, so a drop
     * is never a surprise.
     */
    boardBindDnd() {
        const board = document.getElementById('board');
        if (!board) return;
        let dragged = null;

        board.addEventListener('dragstart', e => {
            const card = e.target.closest('.bcard');
            if (!card) return;
            dragged = card;
            card.classList.add('bcard--dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.dataset.card);
        });

        board.addEventListener('dragend', () => {
            if (dragged) dragged.classList.remove('bcard--dragging');
            board.querySelectorAll('.bcol__cards--over').forEach(el => el.classList.remove('bcol__cards--over'));
            dragged = null;
        });

        board.addEventListener('dragover', e => {
            const drop = e.target.closest('[data-drop]');
            if (!drop || !dragged) return;
            e.preventDefault();
            drop.classList.add('bcol__cards--over');

            // Insert before the first card whose middle is below the cursor
            const after = [...drop.querySelectorAll('.bcard:not(.bcard--dragging)')]
                .find(el => e.clientY < el.getBoundingClientRect().top + el.offsetHeight / 2);
            if (after) drop.insertBefore(dragged, after);
            else drop.appendChild(dragged);
        });

        board.addEventListener('dragleave', e => {
            const drop = e.target.closest('[data-drop]');
            if (drop && !drop.contains(e.relatedTarget)) drop.classList.remove('bcol__cards--over');
        });

        board.addEventListener('drop', async e => {
            const drop = e.target.closest('[data-drop]');
            if (!drop || !dragged) return;
            e.preventDefault();
            drop.classList.remove('bcol__cards--over');

            const cardId = Number(dragged.dataset.card);
            const columnId = Number(drop.dataset.drop);
            const position = [...drop.querySelectorAll('.bcard')].indexOf(dragged);
            this.boardRecount();
            try {
                await this.api('boards.php?action=card_move', {method: 'POST', body: {id: cardId, column_id: columnId, position}});
            } catch (err) {
                this.toast(err.message, 'error');
                this.pageMailBoard();   // the server said no — show what it really holds
            }
        });
    },

    boardRecount() {
        document.querySelectorAll('.bcol').forEach(col => {
            const n = col.querySelectorAll('.bcard').length;
            const badge = col.querySelector('.bcol__count');
            if (badge) badge.textContent = n;
        });
    },

    async boardAddColumn() {
        const title = prompt('Название колонки', 'Новая колонка');
        if (title === null) return;
        await this.api('boards.php?action=column_save', {method: 'POST', body: {board_id: this.board.id, title}});
        this.pageMailBoard();
    },

    async boardRenameColumn(id, current) {
        const title = prompt('Название колонки', current);
        if (title === null) return;
        await this.api('boards.php?action=column_save', {method: 'POST', body: {board_id: this.board.id, id, title}});
        this.pageMailBoard();
    },

    async boardDeleteColumn(id) {
        if (!confirm('Удалить колонку вместе с карточками?')) return;
        await this.api('boards.php?action=column_delete', {method: 'POST', body: {id}});
        this.pageMailBoard();
    },

    async boardAddCard(columnId) {
        const title = prompt('Название карточки', '');
        if (title === null || !title.trim()) return;
        await this.api('boards.php?action=card_add', {method: 'POST', body: {column_id: columnId, title}});
        this.pageMailBoard();
    },

    async boardCardNote(id) {
        const note = prompt('Заметка на карточке', '');
        if (note === null) return;
        await this.api('boards.php?action=card_save', {method: 'POST', body: {id, note}});
        this.pageMailBoard();
    },

    async boardCardDelete(id) {
        if (!confirm('Убрать карточку с доски? Письма останутся в почте.')) return;
        await this.api('boards.php?action=card_delete', {method: 'POST', body: {id}});
        this.pageMailBoard();
    },

    // «В доску» from a conversation: only one board, so this just picks the
    // column — no board-list to choose from any more (item 2)
    async boardPick(threadKey) {
        const d = await this.api('boards.php?action=targets');
        const b = d.items[0];
        if (!b) { this.toast('Доска пока недоступна', 'error'); return; }
        this.modal('Положить переписку на доску', `
            <div class="board-pick">
                <div class="flex flex--wrap">
                    ${b.columns.map(c => `<button class="btn btn--outline btn--sm"
                        style="border-color:${this.esc(c.color || '#ccc')}"
                        onclick="App.boardPut(${c.id}, '${this.jsStr(threadKey)}')">${this.esc(c.title)}</button>`).join('')}
                </div>
            </div>
        `);
    },

    async boardPut(columnId, threadKey) {
        try {
            await this.api('boards.php?action=card_add', {method: 'POST', body: {column_id: columnId, thread_key: threadKey}});
            this.closeModal();
            this.toast('Переписка на доске', 'success');
            this.loadThreadPlacement(threadKey);
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Model list for the reply window, loaded once per session
    async loadLlmModels() {
        if (this.llmModels) return this.llmModels;
        try {
            this.llmModels = await this.api('settings.php?action=llm_models');
        } catch {
            this.llmModels = {providers: [], picker: false};
        }
        return this.llmModels;
    },

    /**
     * «Нейросеть» dropdown of the reply window. The default option keeps the
     * configured fallback chain; picking a model sends that one model and only
     * it. Hidden entirely when «Настройки → Нейросети → Выбор модели» is off.
     */
    replyModelSelect(d) {
        if (!d || !d.picker) return '';
        const current = d.current || {};
        return `<select id="cmpModel" style="width:auto;font-size:13px;flex:1 1 200px"
                        title="Какой нейросетью писать черновик">
            <option value="">По умолчанию (${this.esc(current.model || 'цепочка провайдеров')})</option>
            ${(d.providers || []).map(p => {
                const groups = {};
                (p.models || []).forEach(m => { (groups[m.group || p.label] = groups[m.group || p.label] || []).push(m); });
                return Object.entries(groups).map(([g, list]) => `<optgroup label="${this.esc(p.label)} · ${this.esc(g)}">
                    ${list.map(m => `<option value="${this.esc(p.provider)}:${this.esc(m.id)}"
                        ${p.ready ? '' : 'disabled'}>${this.esc(m.label)}${p.ready ? '' : ' — нет ключа'}</option>`).join('')}
                </optgroup>`).join('');
            }).join('')}
        </select>`;
    },

    // $generate=true — the manager pressed «Создать ответ»: draft the text right away.
    // «Спам»: files the letter as spam, moves it into the mailbox's own Spam/Junk
    // folder on the server, and remembers the sender for next time.
    async markSpam(id) {
        if (!confirm('Отметить письмо как спам? Оно уйдёт в папку «Спам» на почтовом сервере, а письма с этого адреса больше не станут запросами.')) return;
        try {
            await this.api('mail.php?action=mark_spam', {method: 'POST', body: {id}});
            this.toast('Отмечено как спам', 'success');
            this.route();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // «Удалить»: out of the archive here and into «Корзина» on the mail server,
    // so the letter cannot come back with the next sync. $back is where to go
    // when the page we are on is the letter that just disappeared.
    async deleteMail(id, back) {
        if (!confirm('Удалить письмо? Оно уйдёт в «Корзину» на почтовом сервере и исчезнет из панели.')) return;
        try {
            const r = await this.api('mail.php?action=delete', {method: 'POST', body: {id}});
            // «Удалено» has to mean the same thing on both sides — say which one happened
            const ok = r.server_state === 'trashed' ? 'Письмо удалено и перенесено в «Корзину»'
                     : r.server_state === 'expunged' ? 'Письмо удалено с почтового сервера'
                     : 'Письмо удалено из панели';
            this.toast(r.warning || ok, r.warning ? 'error' : 'success');
            this.goAfterDelete(r.thread_empty ? 'mail/inbox' : back);
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async deleteThread(key, count) {
        const what = count > 1 ? `Удалить всю переписку (${count} писем)?` : 'Удалить переписку?';
        if (!confirm(what + ' Письма уйдут в «Корзину» на почтовом сервере и исчезнут из панели.')) return;
        try {
            const r = await this.api('mail.php?action=delete_thread', {method: 'POST', body: {thread_key: key}});
            this.toast(r.warning || `Удалено писем: ${r.deleted}`, r.warning ? 'error' : 'success');
            this.goAfterDelete('mail/inbox');
        } catch (err) { this.toast(err.message, 'error'); }
    },

    /**
     * «В архив (не наш профиль)» — половина входящих про то, чем мы не торгуем.
     * Спамом это не назвать (писал живой человек и по делу), удалять нельзя
     * (вернётся с другим запросом — надо найти), поэтому письмо уходит в
     * «Архив» на самом сервере и перестаёт числиться в работе: ни в списках,
     * ни на доске, ни в счётчике неотвеченных.
     */
    async archiveMail(id) {
        if (!confirm('Убрать письмо в архив? На почтовом сервере оно уйдёт в «Архив», из панели пропадёт, но останется в архиве компании.')) return;
        try {
            const r = await this.api('mail.php?action=archive', {method: 'POST', body: {id}});
            this.toast(r.warning || ('Письмо в архиве' + (r.folder ? ` · «${r.folder}» на сервере` : '')),
                       r.warning ? 'error' : 'success');
            this.route();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async archiveThread(key, count) {
        const what = count > 1 ? `Убрать в архив всю переписку (${count} писем)?` : 'Убрать переписку в архив?';
        if (!confirm(what + ' Письма уйдут в «Архив» на почтовом сервере и пропадут из работы.')) return;
        try {
            const r = await this.api('mail.php?action=archive_thread', {method: 'POST', body: {thread_key: key}});
            this.toast(r.warning || `В архив убрано писем: ${r.archived}`, r.warning ? 'error' : 'success');
            if (this.company) this.loadCompanyThreads(this.company.id);
            else this.goAfterDelete('mail/inbox');
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async unarchiveThread(key) {
        try {
            await this.api('mail.php?action=unarchive', {method: 'POST', body: {thread_key: key}});
            this.toast('Переписка вернулась в работу', 'success');
            if (this.company) this.loadCompanyThreads(this.company.id);
            else this.route();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async unarchiveMail(id) {
        try {
            await this.api('mail.php?action=unarchive', {method: 'POST', body: {id}});
            this.toast('Письмо вернулось в работу', 'success');
            this.route();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    goAfterDelete(hash) {
        if (!hash || location.hash.slice(1) === hash) this.route();
        else location.hash = hash;
    },

    // ==== Rendering an email body: HTML sanitized server-side, shown inside a
    // sandboxed iframe with no allow-scripts so a sanitizer gap still can't run
    // anything; plain text keeps the old escaped/pre-wrapped rendering (item 4) ====
    msgBodyHtml(m, tall) {
        if (m.body_html && m.body_html.trim() !== '') {
            return this.htmlPreviewFrame(m.body_html, {maxHeight: tall ? 2000 : 1200});
        }
        return `<div class="msg__body"${tall ? ' style="max-height:none"' : ''}>${this.esc(m.body_text)}</div>`;
    },

    // Auto-sized iframe for HTML we do not fully trust: an email body (item 4)
    // or a client-side DOCX/XLSX-to-HTML conversion (item 5). No allow-scripts
    // in the sandbox — even a gap in sanitizeHtml() or a bug in the docx/xlsx
    // converter still cannot execute anything here. allow-same-origin is safe
    // to add precisely because scripts never run, and is only there so this
    // page may read the frame's scrollHeight to size it.
    htmlPreviewFrame(innerHtml, {bg = '#fff', maxHeight = 2000} = {}) {
        const doc = `<!doctype html><html><head><meta charset="utf-8"><style>
            html,body{margin:0;padding:10px;background:${bg};color:#1a1a1a;
                font:14px/1.55 -apple-system,'Segoe UI',Roboto,sans-serif;
                word-wrap:break-word;overflow-wrap:break-word;}
            img{max-width:100%;height:auto}
            table{border-collapse:collapse;max-width:100%}
            td,th{border:1px solid #ddd;padding:4px 6px;font-size:12px;text-align:left}
            a{color:#8a2a24}
            </style></head><body>${innerHtml}</body></html>`;
        return `<iframe class="html-frame" data-maxh="${maxHeight}" sandbox="allow-same-origin allow-popups"
                    onload="App.sizeFrame(this)" srcdoc="${this.esc(doc)}"></iframe>`;
    },

    /**
     * Свернуть/развернуть письмо — и ПЕРЕМЕРИТЬ его тело.
     *
     * Письмо, свёрнутое при загрузке, рисует своё тело в скрытом блоке, а у
     * скрытого элемента `scrollHeight` равен нулю: рамка оставалась 80 пикселей
     * высотой, и письмо приходилось читать через щель со своей полосой
     * прокрутки. Ровно это и было видно на первом письме переписки, пока
     * последнее — раскрытое с самого начала — показывалось нормально
     * (модуль 022).
     */
    toggleTmsg(head) {
        const box = head.parentElement;
        box.classList.toggle('tmsg--open');
        if (box.classList.contains('tmsg--open')) {
            box.querySelectorAll('iframe.html-frame').forEach(f => this.sizeFrame(f));
        }
    },

    /**
     * Подогнать рамку под содержимое.
     *
     * Меряем дважды: сразу и после `load` картинок — письмо с логотипом в шапке
     * до их загрузки короче настоящего. Ноль означает «блок сейчас скрыт»:
     * тогда высоту не трогаем вовсе, чтобы не запомнить 80 пикселей навсегда, —
     * перемеряем при раскрытии.
     */
    sizeFrame(iframe) {
        let h = 0;
        try {
            const doc = iframe.contentWindow.document;
            h = Math.max(doc.documentElement.scrollHeight, doc.body ? doc.body.scrollHeight : 0);
        } catch { iframe.style.height = '400px'; return; }

        if (!h) return;   // блок скрыт — мерить нечего, померим при раскрытии
        const max = Number(iframe.dataset.maxh) || 2000;
        iframe.style.height = Math.min(max, Math.max(160, h + 24)) + 'px';

        // Картинки догружаются после onload документа и делают письмо выше
        if (!iframe.dataset.resized) {
            iframe.dataset.resized = '1';
            setTimeout(() => this.sizeFrame(iframe), 350);
        }
    },

    // ==== Attachment preview: images/PDF natively, DOCX/XLSX via a small
    // client-side library, everything else falls back to opening the file
    // (item 5). Read-only — nothing here can save changes back to the file. ====

    // One clickable attachment: preview inline for the types we can render,
    // otherwise straight to the file (still not a separate "download" step —
    // the browser handles anything it doesn't recognize with its own Save As).
    attachmentLink(a, endpoint) {
        const url = `/api/${endpoint}?action=attachment&id=${a.id}`;
        const ext = (a.filename.split('.').pop() || '').toLowerCase();
        const previewable = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx'].includes(ext);
        return previewable
            ? `<a class="chip" href="${url}&inline=1" onclick="event.preventDefault();App.previewAttachment(${a.id},'${this.jsStr(a.filename)}','${endpoint}')">📎 ${this.esc(a.filename)}</a>`
            : `<a class="chip" href="${url}" target="_blank">📎 ${this.esc(a.filename)}</a>`;
    },

    // One <script> tag loaded once per session, however many previews ask for it
    loadLib(url) {
        this._libs = this._libs || {};
        if (!this._libs[url]) {
            this._libs[url] = new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = url;
                s.onload = () => resolve();
                s.onerror = () => reject(new Error('Не удалось загрузить библиотеку предпросмотра'));
                document.head.appendChild(s);
            });
        }
        return this._libs[url];
    },

    async previewAttachment(id, filename, endpoint) {
        const url = `/api/${endpoint}?action=attachment&id=${id}&inline=1`;
        const ext = (filename.split('.').pop() || '').toLowerCase();
        this.modal(filename, `
            <div id="previewBody" class="preview-body"><div class="loading">Загрузка предпросмотра...</div></div>
            <div class="flex" style="margin-top:10px">
                <a class="btn btn--outline btn--sm" href="${url}" target="_blank" rel="noopener">Открыть в новой вкладке</a>
            </div>
        `);
        const box = document.getElementById('modal');
        if (box) box.querySelector('.modal__box').classList.add('modal__box--wide');
        const body = document.getElementById('previewBody');
        try {
            if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                body.innerHTML = `<img src="${url}" alt="${this.esc(filename)}" style="max-width:100%;display:block;margin:0 auto">`;
            } else if (ext === 'pdf') {
                body.innerHTML = `<iframe class="pdf-frame preview-frame" src="${url}"></iframe>`;
            } else if (ext === 'docx') {
                // mammoth.js — pinned version, loaded from CDN (no build step / no npm in this repo)
                await this.loadLib('https://cdn.jsdelivr.net/npm/mammoth@1.8.0/mammoth.browser.min.js');
                const res = await fetch(`/api/${endpoint}?action=attachment&id=${id}`, {credentials: 'same-origin'});
                if (!res.ok) throw new Error('Не удалось загрузить файл');
                const buf = await res.arrayBuffer();
                const out = await window.mammoth.convertToHtml({arrayBuffer: buf});
                body.innerHTML = this.htmlPreviewFrame(out.value, {maxHeight: 1800});
            } else if (ext === 'xlsx' || ext === 'xls') {
                // SheetJS community edition — pinned version, loaded from CDN
                await this.loadLib('https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js');
                const res = await fetch(`/api/${endpoint}?action=attachment&id=${id}`, {credentials: 'same-origin'});
                if (!res.ok) throw new Error('Не удалось загрузить файл');
                const buf = await res.arrayBuffer();
                const wb = window.XLSX.read(buf, {type: 'array'});
                const html = wb.SheetNames.map(name => `
                    <h4 style="margin:0 0 6px">${this.esc(name)}</h4>
                    ${window.XLSX.utils.sheet_to_html(wb.Sheets[name], {editable: false})}
                `).join('<hr style="margin:14px 0">');
                body.innerHTML = this.htmlPreviewFrame(html, {maxHeight: 1800});
            } else if (ext === 'doc') {
                body.innerHTML = `<p class="muted">Предпросмотр старого формата .doc не поддерживается —
                    откройте файл в новой вкладке или скачайте его.</p>`;
            } else {
                body.innerHTML = `<p class="muted">Предпросмотр для этого типа файла недоступен — откройте файл в новой вкладке.</p>`;
            }
        } catch (err) {
            body.innerHTML = `<p class="no">Не удалось построить предпросмотр: ${this.esc(err.message)}</p>`;
        }
    },

    // $threadKey keeps the answer in the conversation it belongs to, whichever
    // mailbox it is sent from.
    // $toOverride — writing to a company from its card, with nothing to reply to
    async mailCompose(replyToId, generate, threadKey, toOverride) {
        const d = await this.api('mail.php?action=list&limit=1');
        let src = null;
        if (replyToId) src = await this.api(`mail.php?action=get&id=${replyToId}`);
        this.composeThread = threadKey || (src && src.thread_key) || null;
        await this.loadCategories();
        const llm = await this.loadLlmModels();
        const subject = src ? (src.subject || '').replace(/^(Re:\s*)?/i, 'Re: ') : '';
        const to = src ? (src.direction === 'in' ? src.from_email : src.to_emails) : (toOverride || '');
        this.modal(replyToId ? 'Ответ' : 'Новое письмо', `
            <div class="form-group">
                <label>Отправить из ящика</label>
                <select id="cmpBox">
                    ${(d.mailboxes || []).map(b => `<option value="${b.id}" ${src && src.mailbox_id === b.id ? 'selected' : ''}>${this.esc(b.name)}${b.email ? ' — ' + this.esc(b.email) : ''}</option>`).join('')}
                </select>
                <div class="muted">Ответить можно из любого ящика — письмо всё равно останется в этой же переписке.</div>
            </div>
            <div class="form-group"><label>Кому</label><input type="text" id="cmpTo" value="${this.esc(to)}"></div>
            <div class="form-group"><label>Копия (через запятую)</label><input type="text" id="cmpCc"></div>
            <div class="form-group"><label>Тема</label><input type="text" id="cmpSubject" value="${this.esc(subject)}"></div>
            <div class="form-group">
                <label>Текст</label>
                ${replyToId && src && src.direction === 'in' ? `
                    <div class="flex flex--wrap" style="gap:6px;align-items:center;margin-bottom:8px">
                        <select id="cmpCategory" style="width:auto;font-size:13px;flex:1 1 160px"
                                title="Тип запроса — от него зависят промпт и источники фактов">
                            ${Object.entries(this.categoryLabels).map(([k, l]) =>
                                `<option value="${k}" ${src.category === k ? 'selected' : ''}>${this.esc(l)}</option>`).join('')}
                        </select>
                        ${this.replyModelSelect(llm)}
                        <button class="btn btn--sm btn--outline" id="genReplyBtn" onclick="App.mailGenerateReply(${replyToId})">Создать ответ</button>
                    </div>` : ''}
                <textarea id="cmpText" rows="9"></textarea>
            </div>
            <button class="btn btn--primary btn--block" onclick="App.mailSend(${replyToId || 'null'})">Отправить</button>
        `);
        if (generate && src && src.direction === 'in') this.mailGenerateReply(replyToId);
    },

    // Draft the reply text via LLM — never on new mail, only on this button
    async mailGenerateReply(id) {
        const btn = document.getElementById('genReplyBtn');
        const area = document.getElementById('cmpText');
        if (btn) { btn.disabled = true; btn.textContent = 'Генерация...'; }
        if (area) area.placeholder = 'Нейросеть готовит черновик ответа...';
        try {
            const sel = document.getElementById('cmpCategory');
            const model = document.getElementById('cmpModel');
            const r = await this.api('mail.php?action=draft_reply', {method: 'POST', body: {
                id, category: sel ? sel.value : null, model: model ? model.value : '',
            }});
            if (area) area.value = r.text || '';
            const subj = document.getElementById('cmpSubject');
            if (subj && !subj.value.trim() && r.subject) subj.value = r.subject;
            const to = document.getElementById('cmpTo');
            if (to && !to.value.trim() && r.to) to.value = r.to;
            this.toast('Черновик готов' + (r.category_label ? ` (${r.category_label}` : '')
                + (r.model ? `, ${r.model}` : '') + (r.category_label ? ')' : '')
                + ' — проверьте перед отправкой', 'success');
        } catch (err) {
            this.toast(err.message, 'error');
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Создать ответ'; }
            if (area) area.placeholder = '';
        }
    },

    async mailSend(replyToId) {
        const body = {
            to: document.getElementById('cmpTo').value.trim(),
            cc: document.getElementById('cmpCc').value.trim(),
            subject: document.getElementById('cmpSubject').value.trim(),
            text: document.getElementById('cmpText').value,
            mailbox_id: document.getElementById('cmpBox').value || null,
            reply_to_id: replyToId || null,
            thread_key: this.composeThread || null,
        };
        try {
            const res = await this.api('mail.php?action=send', {method: 'POST', body});
            this.closeModal();
            // «Отправлено» is only half the news when the copy never reached the
            // server's «Отправленные» — the manager hears it now, not in a month
            if (res.warning) this.toast(res.warning, 'error');
            else this.toast('Письмо отправлено' + (res.sent_folder ? ` · копия в «${res.sent_folder}»` : ''), 'success');
            const key = this.composeThread;
            this.composeThread = null;
            if (key) location.hash = 'mail/t/' + encodeURIComponent(key); else this.pageMailInbox();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // ==== Settings tabs that only an administrator sees (module 004) ====

    adminFail(err) {
        document.getElementById('adminBody').innerHTML = `<div class="card"><p class="no">${this.esc(err.message)}</p></div>`;
    },

    // llm_route is per provider («openrouter» through a proxy, «yandex» direct),
    // so the overview names both instead of printing the object itself
    routeLabel(route) {
        if (!route) return 'напрямую';
        if (typeof route === 'string') return route;
        const names = {openrouter: 'OpenRouter', yandex: 'Yandex'};
        const parts = Object.entries(route).map(([k, v]) => `${names[k] || k} — ${v}`);
        return parts.length ? parts.join(' · ') : 'напрямую';
    },

    async adminOverview() {
        try {
            const d = await this.api('admin.php?action=overview');
            document.getElementById('adminBody').innerHTML = `
                <div class="grid grid--2">
                    <div class="card">
                        <div class="card__title">Состояние</div>
                        <p>Ошибок за сутки: <strong class="${d.log.errors_24h ? 'no' : 'ok'}">${d.log.errors_24h}</strong>
                           · предупреждений: ${d.log.warnings_24h} · записей всего: ${d.log.total}</p>
                        <p>Почтовых ящиков: <strong>${d.mailboxes}</strong> · менеджеров: <strong>${d.managers}</strong></p>
                        <p>Расширение IMAP: ${d.imap ? '<span class="ok">есть</span>' : '<span class="no">нет — почта не будет читаться</span>'}</p>
                        <p>config.php на сервере: ${d.config_file
                            ? 'есть <span class="muted">— значения из него используются по умолчанию</span>'
                            : 'нет <span class="muted">— работают значения из интерфейса</span>'}</p>
                        <p class="muted">Версия схемы БД: ${this.esc(d.schema)}</p>
                    </div>
                    <div class="card">
                        <div class="card__title">Нейросети</div>
                        ${d.llm.map(p => `<p>${this.esc(p.label)}: ${p.enabled ? `<span class="ok">в цепочке #${p.order}</span>` : '<span class="muted">выключен</span>'}
                            · ключ ${p.key_set ? '<span class="ok">задан</span>' : '<span class="no">не задан</span>'}
                            · модель <code>${this.esc(p.model)}</code></p>`).join('')}
                        <p class="muted">Запросы идут ${this.esc(this.routeLabel(d.llm_route))}${d.llm_catalog && d.llm_catalog.count
                            ? ` · каталог OpenRouter: ${d.llm_catalog.count} моделей, ${this.fmtDate(d.llm_catalog.synced_at)}` : ''}</p>
                        <a href="#settings/llm" class="btn btn--outline btn--sm">Настроить</a>
                    </div>
                </div>
                ${this.changesCard(d)}
                <div class="card">
                    <div class="card__title">База знаний (вики)${this.hint('knowledge')}</div>
                    ${d.knowledge.enabled ? `<p>Репозиторий <code>${this.esc(d.knowledge.repo)}</code> · ветка <code>${this.esc(d.knowledge.branch)}</code>
                        · токен ${d.knowledge.token_set ? '<span class="ok">задан</span>' : '<span class="muted">не задан (только публичный репозиторий)</span>'}</p>
                       <p>Коммит <code>${this.esc((d.knowledge.commit || '—').slice(0, 8))}</code>
                        · проверено ${d.knowledge.checked_at ? this.fmtDate(d.knowledge.checked_at) : 'ни разу'}</p>
                       ${d.knowledge.last_error ? `<p class="no">${this.esc(d.knowledge.last_error)}</p>` : ''}`
                     : '<p class="muted">Выключена — вики не подмешивается в промпты</p>'}
                    <a href="#settings/knowledge" class="btn btn--outline btn--sm">Открыть</a>
                </div>
                ${d.mail_errors.length ? `<div class="card card--alert">
                    <div class="card__title">Ошибки почты</div>
                    ${d.mail_errors.map(e => `<p><strong>${this.esc(e.name)}</strong>: ${this.esc(e.error)} <span class="muted">${this.fmtDate(e.at)}</span></p>`).join('')}
                </div>` : ''}
                ${(d.logo || {}).warning ? `<div class="card card--alert">
                    <div class="card__title">Логотип в КП${this.hint('logo')}</div>
                    <p class="no">${this.esc(d.logo.warning)}</p>
                    <a href="#settings/branding" class="btn btn--outline btn--sm">Открыть «Логотипы»</a>
                </div>` : ''}
                <div class="card">
                    <div class="card__title">Проверка подключений</div>
                    <div class="flex flex--wrap">
                        <button class="btn btn--outline" onclick="App.testMoysklad()">Проверить МойСклад</button>
                        <button class="btn btn--outline" onclick="App.testLlm('yandex')">Проверить Yandex</button>
                        <button class="btn btn--outline" onclick="App.testLlm('openrouter')">Проверить OpenRouter</button>
                        <button class="btn btn--outline" onclick="App.diagnoseLlm('openrouter')">Куда уходит запрос</button>
                        <a href="#settings/mail" class="btn btn--outline">Проверить почту</a>
                    </div>
                    <div id="testResult" style="margin-top:12px"></div>
                </div>
            `;
        } catch (err) { this.adminFail(err); }
    },

    /**
     * Что изменили менеджеры и чему сервис научился — первое, что админ видит,
     * заходя в панель (модуль 022).
     *
     * Промпты, база знаний и Tone of Voice перестали быть его личной вкладкой:
     * их правит тот, кто с ними работает. Но текст, который правят вдвоём и
     * молча, однажды уезжает не туда — поэтому у каждой правки есть след, и
     * след этот лежит на первой странице, а не в логах.
     */
    changesCard(d) {
        const rows = d.changes || [];
        const learning = d.learning || {};
        return `
            <div class="card">
                <div class="card__title">Что изменили менеджеры${this.hint('learning')}</div>
                <p class="muted">Правок текстов за сутки: <strong>${d.changes_24h || 0}</strong>
                   · правок на обучении: <strong>${learning.total || 0}</strong>
                   ${learning.pending ? `· не выгружено: <strong class="ok">${learning.pending}</strong>` : ''}</p>
                ${rows.length ? `<div class="changes">
                    ${rows.map(c => `
                        <div class="changes__row">
                            <span class="badge badge--kp">${this.esc(c.area_label)}</span>
                            <span>${this.esc(c.title || c.item_key || '')}</span>
                            <span class="muted">${this.esc(c.manager_name || 'кто-то')}
                                ${c.by_admin ? '' : '<span title="Правил менеджер, не администратор">·&nbsp;менеджер</span>'}
                                · ${this.fmtDate(c.created_at)}
                                ${c.delta ? ` · ${c.delta > 0 ? '+' : ''}${c.delta} симв.` : ''}</span>
                        </div>`).join('')}
                </div>` : '<p class="muted">Пока никто ничего не правил.</p>'}
                <div class="flex flex--wrap" style="margin-top:10px">
                    <a href="#settings/learning" class="btn btn--outline btn--sm">Правки и обучение${learning.pending ? ` (${learning.pending})` : ''}</a>
                    <a href="#settings/prompts" class="btn btn--outline btn--sm">Промпты</a>
                    <a href="#settings/tov" class="btn btn--outline btn--sm">Tone of Voice</a>
                </div>
                ${learning.last ? `<p class="muted" style="margin-top:6px">Последняя выгрузка правок: ${this.esc(learning.last)}</p>` : ''}
            </div>`;
    },

    testOut(html, cls = 'ok') {
        const el = document.getElementById('testResult');
        if (el) el.innerHTML = `<p class="${cls}">${html}</p>`;
    },

    async testMoysklad() {
        this.testOut('Проверяем...', 'muted');
        try {
            const r = await this.api('admin.php?action=test_moysklad', {method: 'POST', body: {}});
            const p = r.permissions || {};
            const yes = v => v ? '<span class="ok">есть</span>' : '<span class="no">нет</span>';
            this.testOut(`Товары: ${yes(p.products)} · Контрагенты: ${yes(p.counterparties)} · Заказы: ${yes(p.orders_write)}
                          · Счета: ${yes(p.invoices)} · Вебхуки: ${yes(p.webhooks)}`, '');
        } catch (err) { this.testOut(this.esc(err.message), 'no'); }
    },

    async testLlm(provider) {
        this.testOut('Спрашиваем модель...', 'muted');
        try {
            const r = await this.api('admin.php?action=test_llm', {method: 'POST', body: {provider}});
            this.testOut(`${this.esc(provider)} — ответ за ${r.result.ms} мс, модель ${this.esc(r.result.model)}
                          (${this.esc(r.result.route)}): «${this.esc(r.result.answer)}»`);
        } catch (err) { this.testOut(this.esc(err.message), 'no'); }
    },

    // Separates «ключ не тот» from «запрос не дошёл»: a 403 written by a filter
    // on the way looks nothing like a provider's own refusal
    async diagnoseLlm(provider) {
        this.testOut('Проверяем маршрут до API...', 'muted');
        try {
            const r = (await this.api('admin.php?action=llm_diagnose', {method: 'POST', body: {provider}})).result;
            this.testOut(`
                <strong>${this.esc(r.url)}</strong> — ${this.esc(r.route)}, HTTP ${r.http_code}, ${r.ms} мс<br>
                ${r.curl_error ? `Ошибка соединения: ${this.esc(r.curl_error)}<br>` : ''}
                ${r.body_head ? `Ответ: <code>${this.esc(r.body_head)}</code><br>` : ''}
                <span class="${r.intercepted ? 'no' : 'ok'}">${r.intercepted
                    ? 'Отвечает не провайдер, а фильтр на пути.'
                    : 'Отвечает сам провайдер.'}</span><br>${this.esc(r.hint)}`, '');
        } catch (err) { this.testOut(this.esc(err.message), 'no'); }
    },

    // ---- Knowledge base: the company wiki pulled from GitHub ----

    async adminKnowledge() {
        try {
            const d = await this.api('admin.php?action=knowledge');
            this.knowledgeState = d;
            const kb = n => (n / 1024).toFixed(1) + ' КБ';
            document.getElementById('adminBody').innerHTML = `
                <div class="card">
                    <div class="card__title">Состояние копии${this.hint('knowledge')}</div>
                    ${d.enabled ? '' : '<p class="no">База знаний выключена — включите KNOWLEDGE_ENABLED в «Настройках».</p>'}
                    <p>Источник: <code>${this.esc(d.repo)}</code> · ветка <code>${this.esc(d.branch)}</code>
                       · папка <code>${this.esc(d.path)}</code></p>
                    <p>Токен GitHub: ${d.token_set ? '<span class="ok">задан</span>' : '<span class="muted">не задан — доступен только публичный репозиторий</span>'}
                       <span class="muted">(«Настройки → База знаний» или config.php)</span></p>
                    <p>Версия: коммит <code>${this.esc((d.commit || '—').slice(0, 8))}</code>
                       ${d.commit_at ? '· ' + this.fmtDate(d.commit_at) : ''}</p>
                    <p>Проверено: ${d.checked_at ? this.fmtDate(d.checked_at) : 'ни разу'}
                       · скачано: ${d.synced_at ? this.fmtDate(d.synced_at) : 'ни разу'}
                       · документов: <strong>${d.docs_count}</strong> (${kb(d.total_size)})</p>
                    <p class="muted">Версия репозитория проверяется перед генерацией, но не чаще чем раз в
                       ${d.ttl_sec} сек (KNOWLEDGE_SYNC_TTL_SEC; 0 — каждый раз).</p>
                    ${d.last_error ? `<p class="no">Последняя ошибка: ${this.esc(d.last_error)}</p>` : ''}
                    <div class="flex flex--wrap">
                        <button class="btn btn--primary" onclick="App.knowledgeSync(false)">Проверить и обновить</button>
                        <button class="btn btn--outline" onclick="App.knowledgeSync(true)">Перечитать всё заново</button>
                    </div>
                    <div id="knowledgeResult" style="margin-top:12px"></div>
                </div>

                <div class="card">
                    <div class="card__title">Где используется</div>
                    <p class="muted">Вики подмешивается только в эти генерации и только теми разделами,
                       которые относятся к тексту. Список задач — настройка KNOWLEDGE_TASKS.</p>
                    ${d.tasks.map(t => `<p>${t.enabled ? '<span class="ok">вкл</span>' : '<span class="muted">выкл</span>'}
                        · ${this.esc(t.label)} <code>${t.key}</code> · бюджет ${t.budget} символов</p>`).join('')}
                </div>

                <div class="card" id="kbVectorCard"><div class="loading">Проверяем векторный индекс...</div></div>

                <div class="card">
                    <div class="card__title">Проверка подбора${this.hint('knowledge-check')}</div>
                    <p class="muted">Вставьте текст письма или список позиций — увидите, какие разделы вики попадут в промпт
                       и что сервис на это ответит. «по смыслу» — раздел, который добавили векторы, а не совпавшие слова.</p>
                    <textarea id="kbQuery" rows="4" placeholder="Например: подскажите, у спального мешка Envelope двойного можно отстегнуть слой?"></textarea>
                    <div class="flex flex--wrap" style="margin-top:8px">
                        <select id="kbTask">${d.tasks.map(t => `<option value="${t.key}">${this.esc(t.label)}</option>`).join('')}</select>
                        <button class="btn btn--outline" onclick="App.knowledgePreview(false)">Показать разделы</button>
                        <button class="btn btn--primary" onclick="App.knowledgePreview(true)"
                                title="Разделы вики и сам ответ — один запрос к модели">Разделы и ответ</button>
                    </div>
                    <div id="kbPreview" style="margin-top:12px"></div>
                </div>

                <div class="card">
                    <div class="card__title">Документы (${d.docs_count})</div>
                    <table class="table"><thead><tr><th>Страница</th><th>Файл</th><th>Размер</th><th>Обновлён</th></tr></thead>
                    <tbody>${d.docs.map(doc => `<tr>
                        <td>${this.esc(doc.title)}</td>
                        <td class="muted"><code>${this.esc(doc.path)}</code></td>
                        <td>${kb(doc.size)}</td>
                        <td class="muted">${this.fmtDate(doc.updated_at)}</td>
                    </tr>`).join('') || '<tr><td colspan="4" class="muted">Пока пусто — нажмите «Проверить и обновить»</td></tr>'}
                    </tbody></table>
                </div>
            `;
            this.loadKnowledgeVectorStats();
        } catch (err) { this.adminFail(err); }
    },

    // ---- Wiki vectors: the «по смыслу» half of the knowledge base (modules 005, 009) ----

    async loadKnowledgeVectorStats() {
        const card = document.getElementById('kbVectorCard');
        if (!card) return;
        const d = ((this.knowledgeState || {}).vectors) || {};
        try {
            const fresh = await this.api('admin.php?action=knowledge_vector_stats');
            Object.assign(d, fresh);
        } catch { /* the status call already gave us a snapshot */ }
        const done = d.total ? Math.round(100 * Math.min(d.indexed, d.total) / d.total) : 0;
        card.innerHTML = `
            <div class="card__title">Векторный поиск по базе знаний</div>
            <p class="muted">Те же эмбеддинги, что и у каталога: к разделам, найденным по словам, добавляются
               подходящие по смыслу — «сколько ждать заказ» находит «Сроки поставки» без общих слов.
               Индекс строится шагами и переживает перечитывание вики: раздел, текст которого не изменился,
               заново не векторизуется.</p>
            ${!d.configured ? '<p class="no">Не заданы ключ и Folder ID Yandex — подбор по вики работает только по словам. Задайте их в «Настройки → Нейросети».</p>'
                : (!d.enabled ? '<p class="no">Векторы базы знаний выключены («Настройки → Все параметры → База знаний»).</p>' : '')}
            <p>Векторизовано разделов: <strong>${d.indexed || 0}</strong> из ${d.total || 0}
               ${d.pending ? ` · ждёт обработки: ${d.pending}` : ' · всё актуально'}
               ${d.dim ? ` · размерность ${d.dim}` : ''}</p>
            <div class="progress"><div class="progress__bar" style="width:${done}%"></div></div>
            <p class="muted">Модель: ${this.esc(d.model || '')}${d.updated_at ? ` · обновлён ${this.fmtDate(d.updated_at)}` : ''}</p>
            ${this.manager && this.manager.is_admin ? `
            <div class="flex flex--wrap">
                <button class="btn btn--primary btn--sm" id="kbVecBtn" onclick="App.knowledgeVectorIndex()"
                        ${d.configured ? '' : 'disabled'}>Векторизовать базу знаний</button>
                <button class="btn btn--outline btn--sm" onclick="App.knowledgeVectorReset()">Очистить индекс</button>
            </div>` : '<p class="muted">Перестроить индекс может администратор.</p>'}
            <div id="kbVectorProgress" class="muted" style="margin-top:8px"></div>
        `;
    },

    knowledgeVectorIndex() {
        return this.vectorLoop({
            endpoint: 'admin.php?action=knowledge_vector_index',
            btnId: 'kbVecBtn', outId: 'kbVectorProgress',
            label: 'Векторизовано разделов',
            after: () => this.loadKnowledgeVectorStats(),
        });
    },

    async knowledgeVectorReset() {
        if (!confirm('Очистить векторы базы знаний? Их придётся построить заново.')) return;
        try {
            const r = await this.api('admin.php?action=knowledge_vector_reset', {method: 'POST', body: {}});
            this.toast(`Индекс очищен (${r.removed})`, 'success');
        } catch (err) { this.toast(err.message, 'error'); }
        this.loadKnowledgeVectorStats();
    },

    async knowledgeSync(force) {
        const out = document.getElementById('knowledgeResult');
        out.innerHTML = '<p class="muted">Спрашиваем GitHub...</p>';
        try {
            const r = await this.api('admin.php?action=knowledge_sync', {method: 'POST', body: {force}});
            const rep = r.report;
            out.innerHTML = `<p class="ok">${rep.status === 'updated'
                ? `Обновлено файлов: ${rep.updated}, удалено: ${rep.deleted}`
                : 'Актуально — новых изменений нет'} · коммит <code>${this.esc((rep.commit || '').slice(0, 8))}</code></p>`;
            this.adminKnowledge();
        } catch (err) { out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`; }
    },

    /**
     * «Проверка подбора» (модуль 022).
     *
     * Она отвечала, КАКИЕ разделы вики попадут в промпт, и на этом
     * останавливалась — а вопрос у человека всегда был другой: «что сервис на
     * это ответит?». Теперь она отвечает и им, и этот ответ можно тут же
     * забраковать: 👎 открывает поле «как должен звучать правильный ответ?»,
     * и написанное уходит в обучение вместе с вопросом.
     */
    async knowledgePreview(withAnswer) {
        const out = document.getElementById('kbPreview');
        const query = document.getElementById('kbQuery').value;
        const task = document.getElementById('kbTask').value;
        if (!query.trim()) { this.toast('Вставьте текст письма', 'error'); return; }
        this.kbLast = {query, task};
        out.innerHTML = `<p class="muted">${withAnswer ? 'Подбираем разделы и пишем ответ...' : 'Ищем...'}</p>`;
        try {
            const d = await this.api('admin.php?action=knowledge_preview',
                {method: 'POST', body: {query, task, draft: withAnswer ? 1 : 0}});
            const sections = (d.items || []).length
                ? d.items.map(i => `<p>${this.esc(i.title)}
                    <span class="muted">· ${i.chars} симв. · ${i.source === 'vector'
                        ? `по смыслу, близость ${i.score}`
                        : `совпало терминов: ${i.hits} · вес ${i.score}`}</span></p>`).join('')
                : '<p class="muted">Ничего подходящего — вики в этот промпт не попадёт.</p>';

            this.kbLast.answer = d.answer || '';
            out.innerHTML = (d.enabled ? '' : '<p class="no">Для этой задачи база знаний выключена — показан «сухой» подбор.</p>')
                + `<div class="card__title" style="font-size:13px">Разделы вики</div>${sections}`
                + (d.error ? `<p class="no">Ответ не сгенерирован: ${this.esc(d.error)}</p>` : '')
                + (d.answer ? `
                    <div class="card__title" style="font-size:13px;margin-top:12px">Предполагаемый ответ</div>
                    <div class="kb-answer">${this.esc(d.answer)}</div>
                    <div class="flex flex--wrap" style="margin-top:8px">
                        <button class="btn btn--outline btn--sm" onclick="App.kbThumbDown(this)">👎 Ответ неправильный</button>
                        <span class="muted">Скажите, как он должен звучать — сервис на этом учится</span>
                    </div>
                    <div id="kbFix"></div>` : '');
        } catch (err) { out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`; }
    },

    /** 👎 — поле «как должен звучать правильный ответ?» прямо под ответом. */
    kbThumbDown(btn) {
        const box = document.getElementById('kbFix');
        if (!box) return;
        btn.disabled = true;
        box.innerHTML = `
            <div class="note note--choice" style="margin-top:10px">
                <div class="card__title" style="font-size:13px">Как должен звучать правильный ответ?</div>
                <textarea id="kbFixText" rows="5" placeholder="Напишите ответ так, как его должен был дать сервис"></textarea>
                <input type="text" id="kbFixNote" placeholder="Почему так — одной строкой (необязательно)" style="margin-top:6px">
                <div class="flex" style="margin-top:8px">
                    <button class="btn btn--primary btn--sm" onclick="App.kbSaveFix(this)">Сохранить правку</button>
                    <button class="btn btn--outline btn--sm" onclick="document.getElementById('kbFix').innerHTML=''">Отмена</button>
                </div>
            </div>`;
        document.getElementById('kbFixText').focus();
    },

    async kbSaveFix(btn) {
        const correct = document.getElementById('kbFixText').value.trim();
        if (!correct) { this.toast('Напишите, как должно было быть', 'error'); return; }
        btn.disabled = true;
        try {
            await this.api('admin.php?action=learning_add', {method: 'POST', body: {
                kind: 'answer',
                subject: 'Проверка подбора',
                question: (this.kbLast || {}).query || '',
                auto_answer: (this.kbLast || {}).answer || '',
                correct_answer: correct,
                comment: document.getElementById('kbFixNote').value,
                context: {task: (this.kbLast || {}).task || ''},
            }});
            document.getElementById('kbFix').innerHTML =
                '<p class="ok" style="margin-top:10px">Правка сохранена — она попадёт в обучение и в выгрузку.</p>';
            this.toast('Спасибо, запомнили', 'success');
        } catch (err) { this.toast(err.message, 'error'); btn.disabled = false; }
    },

    // ---- Settings: every key, with its source and an override ----

    async adminSettings() {
        try {
            const d = await this.api('admin.php?action=settings');
            // Model keys get the same grouped picker as the «Нейросети» tab
            let catalogs = {};
            try {
                const m = await this.api('settings.php?action=llm_models');
                (m.providers || []).forEach(p => { catalogs[p.provider] = p.models; });
            } catch { /* the plain text field is a fine fallback */ }
            this.settingsSpec = d.items;
            const badge = it => it.source === 'db'
                ? '<span class="badge badge--confirmed">из интерфейса</span>'
                : (it.source === 'config' ? '<span class="badge badge--new">из config.php</span>' : '<span class="badge badge--draft">по умолчанию</span>');
            const field = it => {
                const id = 'set_' + it.key;
                if (it.type === 'bool') return `<select id="${id}">
                    <option value="1" ${String(it.value) === '1' ? 'selected' : ''}>Да</option>
                    <option value="0" ${String(it.value) !== '1' ? 'selected' : ''}>Нет</option></select>`;
                if (it.type.startsWith('select:')) return `<select id="${id}">
                    ${it.type.slice(7).split(',').map(o => `<option value="${o}" ${String(it.value) === o ? 'selected' : ''}>${o || '(без шифрования)'}</option>`).join('')}</select>`;
                if (it.type.startsWith('model:')) return this.modelSelect(id, catalogs[it.type.slice(6)] || [], String(it.value));
                if (it.secret) return `<input type="password" id="${id}" placeholder="${it.filled ? 'задан ' + this.esc(it.tail) + ' — оставьте пустым, чтобы не менять' : 'не задан'}">`;
                if (it.type === 'int') return `<input type="number" id="${id}" value="${this.esc(it.value)}">`;
                // Звук уведомления выбирается из того, что лежит в sounds/,
                // и тут же слушается — иначе выбирать приходится по имени файла
                if (it.type === 'sound') {
                    this.loadSoundOptions(id, String(it.value));
                    return `<span class="flex">
                        <select id="${id}"><option value="${this.esc(it.value)}">${this.esc(it.value) || '(без звука)'}</option></select>
                        <button type="button" class="btn btn--outline btn--sm" onclick="App.playSoundPreview('${id}')">▶ Послушать</button>
                    </span>`;
                }
                // Списки синонимов и текст блока дисциплины — многострочные
                if (it.type === 'textarea') return `<textarea id="${id}" rows="6">${this.esc(it.value)}</textarea>`;
                return `<input type="text" id="${id}" value="${this.esc(it.value)}">`;
            };
            // Автообновление кода: состояние последней проверки и кнопка разовой
            // проверки живут прямо в карточке этой группы (модуль 014).
            const ap = d.autopull || {};
            const apCard = () => {
                const lines = [];
                lines.push(ap.configured
                    ? `Отслеживается: <b>${this.esc(ap.repo)}</b> · ${this.esc(ap.ref)}. Креды — из <code>pull-config.php</code> в корне сайта.`
                    : 'Рядом с <code>pull.php</code> нет <code>pull-config.php</code> — включать нечего.');
                if (ap.checked_at) lines.push(`Последняя проверка: ${this.esc(ap.checked_at)}${ap.note ? ' — ' + this.esc(ap.note) : ''}.`);
                if (ap.error) lines.push(`<b>Ошибка: ${this.esc(ap.error)}</b>`);
                return `<div class="muted" style="margin:8px 0">${lines.join('<br>')}</div>
                    <div class="flex flex--end"><button class="btn btn--sm btn--outline" onclick="App.autopullCheck()">Проверить и обновить сейчас</button></div>`;
            };
            const groups = Object.entries(d.groups).map(([key, title]) => {
                const items = d.items.filter(i => i.group === key);
                if (!items.length) return '';
                return `<div class="card">
                    <div class="card__title">${this.esc(title)}</div>
                    ${key === 'deploy' ? apCard() : ''}
                    ${items.map(it => `
                        <div class="setting">
                            <div class="setting__label">
                                <label for="set_${it.key}">${this.esc(it.label)}</label>
                                <div class="muted"><code>${it.key}</code> ${badge(it)}
                                    ${it.hint ? '· ' + this.esc(it.hint) : ''}</div>
                            </div>
                            <div class="setting__field">${field(it)}</div>
                            <div class="setting__actions">
                                ${it.has_override ? `<button class="btn btn--sm btn--outline" onclick="App.resetSetting('${it.key}')" title="Вернуться к значению из config.php или встроенному">Сбросить</button>` : ''}
                            </div>
                        </div>`).join('')}
                </div>`;
            }).join('');

            document.getElementById('adminBody').innerHTML = `
                <div class="card">
                    <p>Значения из <code>config.php</code> — это умолчания. Всё, что изменено здесь, хранится в базе и имеет приоритет:
                       если <code>config.php</code> удалить с сервера, сервис продолжит работать на этих значениях.</p>
                    <p class="muted">config.php на сервере: ${d.config_file ? 'есть' : 'нет'} ·
                       секреты в базе ${d.encrypted ? 'шифруются' : 'хранятся как есть (нет ext-openssl)'} и никогда не отдаются в браузер.</p>
                </div>
                ${groups}
                <div class="flex flex--end"><button class="btn btn--primary" onclick="App.saveSettings()">Сохранить настройки</button></div>
            `;
        } catch (err) { this.adminFail(err); }
    },

    /** Наполнить выбор звуков тем, что реально лежит в папке sounds/. */
    async loadSoundOptions(selectId, current) {
        try {
            const d = await this.api('admin.php?action=sounds');
            const sel = document.getElementById(selectId);
            if (!sel) return;
            sel.innerHTML = `<option value="">(без звука)</option>`
                + (d.items || []).map(f =>
                    `<option value="${this.esc(f.file)}" ${f.file === current ? 'selected' : ''}>${this.esc(f.name)}</option>`).join('');
        } catch { /* останется то, что уже выбрано */ }
    },

    playSoundPreview(selectId) {
        const sel = document.getElementById(selectId);
        if (!sel || !sel.value) { this.toast('Звук не выбран', 'info'); return; }
        try {
            const a = new Audio('/sounds/' + encodeURIComponent(sel.value));
            const vol = document.getElementById('set_MAIL_SOUND_VOLUME');
            a.volume = Math.min(1, Math.max(0, Number(vol ? vol.value : 60) / 100));
            a.play().catch(() => this.toast('Браузер не дал проиграть звук', 'error'));
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async saveSettings() {
        const values = {};
        (this.settingsSpec || []).forEach(it => {
            const el = document.getElementById('set_' + it.key);
            if (!el) return;
            if (it.secret && el.value === '') return;   // empty means "keep the stored secret"
            values[it.key] = el.value;
        });
        try {
            await this.api('admin.php?action=settings', {method: 'PUT', body: {values}});
            this.toast('Настройки сохранены', 'success');
            this.adminSettings();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Разовая проверка обновления: спрашивает head у GitHub и, если коммит новее
    // выложенного, запускает pull.php — независимо от галочки.
    async autopullCheck() {
        try {
            const res = await this.api('admin.php?action=autopull_check', {method: 'POST', body: {}});
            const r = res.report || {};
            this.toast(r.ok ? `Автообновление: ${r.note}${r.head ? ' (head ' + r.head + ')' : ''}` : `Автообновление: ${r.error}`,
                r.ok ? 'success' : 'error');
            this.adminSettings();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async resetSetting(key) {
        try {
            await this.api('admin.php?action=settings', {method: 'PUT', body: {reset: [key]}});
            this.toast('Значение сброшено', 'success');
            this.adminSettings();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // ---- LLM: providers, order, models and the road to the API ----

    /**
     * A grouped <select> of every model of a provider, plus «своя модель» that
     * reveals a text field: the catalog is a convenience, not a fence — any slug
     * can still be typed. `models` come from the server: the built-in list plus
     * whatever «Обновить каталог OpenRouter» downloaded.
     */
    modelSelect(id, models, value, extra = '') {
        const groups = {};
        (models || []).forEach(m => { (groups[m.group || 'Модели'] = groups[m.group || 'Модели'] || []).push(m); });
        const known = (models || []).some(m => m.id === value);
        return `
            <select id="${id}_pick" onchange="App.modelPicked('${id}')" ${extra}>
                ${Object.entries(groups).map(([g, list]) => `<optgroup label="${this.esc(g)}">
                    ${list.map(m => `<option value="${this.esc(m.id)}" ${m.id === value ? 'selected' : ''}>${this.esc(m.label)}</option>`).join('')}
                </optgroup>`).join('')}
                <option value="" ${known ? '' : 'selected'}>— своя модель —</option>
            </select>
            <input type="text" id="${id}" value="${this.esc(value || '')}" placeholder="vendor/model"
                   style="margin-top:6px" ${known ? 'hidden' : ''}>
        `;
    },

    // «своя модель» reveals the field; a catalog row fills it and hides it again
    modelPicked(id) {
        const pick = document.getElementById(id + '_pick');
        const field = document.getElementById(id);
        if (!pick || !field) return;
        if (pick.value === '') {
            field.hidden = false;
            field.focus();
        } else {
            field.value = pick.value;
            field.hidden = true;
        }
    },

    async adminLlm() {
        try {
            const d = await this.api('admin.php?action=overview');
            const s = await this.api('admin.php?action=settings');
            const val = k => (s.items.find(i => i.key === k) || {}).value || '';
            const secret = k => s.items.find(i => i.key === k) || {};
            const cat = d.llm_catalog || {};

            document.getElementById('adminBody').innerHTML = `
                <div class="card">
                    <div class="card__title">Порядок провайдеров</div>
                    <p class="muted">Первый отвечает, следующий подхватывает, если первый недоступен.</p>
                    <div class="form-group">
                        <input type="text" id="set_LLM_PROVIDER_PRIORITY" value="${this.esc(val('LLM_PROVIDER_PRIORITY'))}"
                               placeholder="yandex, openrouter">
                    </div>
                    <div class="grid grid--3">
                        <div class="form-group"><label>Таймаут запроса, сек</label>
                            <input type="number" id="set_LLM_TIMEOUT_SEC" value="${this.esc(val('LLM_TIMEOUT_SEC'))}"></div>
                        <div class="form-group"><label>Температура по умолчанию</label>
                            <input type="text" id="set_LLM_TEMPERATURE" value="${this.esc(val('LLM_TEMPERATURE'))}"></div>
                        <div class="form-group"><label>Выбор модели в окне ответа</label>
                            <select id="set_LLM_MODEL_PICKER">
                                <option value="1" ${val('LLM_MODEL_PICKER') === '1' ? 'selected' : ''}>Показывать</option>
                                <option value="0" ${val('LLM_MODEL_PICKER') !== '1' ? 'selected' : ''}>Скрыть</option>
                            </select></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card__title">Доступ к API</div>
                    <p class="muted">Сейчас запросы к OpenRouter идут <strong>${this.esc((d.llm_route && d.llm_route.openrouter) || 'напрямую')}</strong>,
                       к Yandex — <strong>${this.esc((d.llm_route && d.llm_route.yandex) || 'напрямую')}</strong>.
                       Если провайдер отвечает «Access denied by security policy» или подобным, при живом ключе —
                       значит, до него запрос не доходит: его завернул фильтр по дороге. Прокси теперь включается
                       отдельно для каждого провайдера — адрес общий, переключатель свой.</p>
                    <div class="grid grid--2">
                        <div class="form-group"><label>Адрес прокси</label>
                            <input type="text" id="set_LLM_PROXY" value="${this.esc(val('LLM_PROXY'))}"
                                   placeholder="http://host:port или socks5h://host:port"></div>
                        <div class="form-group"><label>Логин:пароль прокси</label>
                            <input type="password" id="set_LLM_PROXY_AUTH"
                                   placeholder="${secret('LLM_PROXY_AUTH').filled ? 'задан — оставьте пустым' : 'если прокси без авторизации — пусто'}"></div>
                        <div class="form-group"><label>Прокси для OpenRouter</label>
                            <select id="set_LLM_PROXY_OPENROUTER" title="OpenRouter обычно недоступен напрямую с российского хостинга">
                                <option value="1" ${val('LLM_PROXY_OPENROUTER') !== '0' ? 'selected' : ''}>Включён</option>
                                <option value="0" ${val('LLM_PROXY_OPENROUTER') === '0' ? 'selected' : ''}>Выключен — идти напрямую</option>
                            </select></div>
                        <div class="form-group"><label>Прокси для Yandex Foundation Models</label>
                            <select id="set_LLM_PROXY_YANDEX" title="Yandex Cloud обычно доступен напрямую с российского хостинга">
                                <option value="1" ${val('LLM_PROXY_YANDEX') === '1' ? 'selected' : ''}>Включён</option>
                                <option value="0" ${val('LLM_PROXY_YANDEX') !== '1' ? 'selected' : ''}>Выключен — идти напрямую</option>
                            </select></div>
                        <div class="form-group" style="grid-column:span 2"><label>Адрес API OpenRouter</label>
                            <input type="text" id="set_OPENROUTER_BASE_URL" value="${this.esc(val('OPENROUTER_BASE_URL'))}"
                                   placeholder="https://openrouter.ai/api/v1"></div>
                    </div>
                    <div class="flex flex--wrap">
                        <button class="btn btn--outline" onclick="App.diagnoseLlm('openrouter')">Куда уходит запрос — OpenRouter</button>
                        <button class="btn btn--outline" onclick="App.diagnoseLlm('yandex')">…и Yandex</button>
                    </div>
                    <div id="testResult" style="margin-top:12px"></div>
                </div>

                <div class="card">
                    <div class="card__title">Каталог моделей OpenRouter</div>
                    <p class="muted">${cat.count
                        ? `Загружено моделей: <strong>${cat.count}</strong>, ${this.fmtDate(cat.synced_at)}. Они добавлены в списки ниже.`
                        : 'Пока используется встроенный список. Обновите каталог — и в выпадающих списках появятся все модели, доступные вашему ключу, а бесплатные соберутся в отдельную группу.'}</p>
                    <div class="flex flex--wrap">
                        <button class="btn btn--outline" onclick="App.refreshOpenRouterModels()">Обновить каталог OpenRouter</button>
                        ${cat.count ? '<button class="btn btn--outline" onclick="App.forgetOpenRouterModels()">Убрать из списков</button>' : ''}
                    </div>
                    <div id="orCatalogResult" style="margin-top:10px"></div>
                </div>

                ${d.llm.map(p => {
                    const keyName = p.provider === 'yandex' ? 'YANDEX_API_KEY' : 'OPENROUTER_API_KEY';
                    const modelName = p.provider === 'yandex' ? 'YANDEX_MODEL' : 'OPENROUTER_MODEL';
                    const k = secret(keyName);
                    return `<div class="card">
                        <div class="card__title">${this.esc(p.label)} ${p.enabled ? `<span class="badge badge--confirmed">#${p.order}</span>` : '<span class="badge badge--draft">выключен</span>'}</div>
                        <div class="grid grid--2">
                            <div class="form-group">
                                <label>Модель</label>
                                ${this.modelSelect('set_' + modelName, p.models, p.model)}
                            </div>
                            <div class="form-group">
                                <label>API-ключ</label>
                                <input type="password" id="set_${keyName}" placeholder="${k.filled ? 'задан ' + this.esc(k.tail) + ' — оставьте пустым' : 'не задан'}">
                            </div>
                            ${p.provider === 'yandex' ? `<div class="form-group"><label>Folder ID</label>
                                <input type="text" id="set_YANDEX_FOLDER_ID" value="${this.esc(val('YANDEX_FOLDER_ID'))}"></div>` : ''}
                        </div>
                        <button class="btn btn--outline btn--sm" onclick="App.testLlm('${p.provider}')">Проверить подключение</button>
                    </div>`;
                }).join('')}
                <div class="flex flex--end"><button class="btn btn--primary" onclick="App.saveSettings()">Сохранить</button></div>
            `;
            this.settingsSpec = s.items.filter(i => document.getElementById('set_' + i.key));
        } catch (err) { this.adminFail(err); }
    },

    async refreshOpenRouterModels() {
        const out = document.getElementById('orCatalogResult');
        out.innerHTML = '<p class="muted">Спрашиваем OpenRouter...</p>';
        try {
            const r = await this.api('admin.php?action=openrouter_models_refresh', {method: 'POST', body: {}});
            out.innerHTML = `<p class="ok">Загружено моделей: ${r.count}</p>`;
            this.adminLlm();
        } catch (err) { out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`; }
    },

    async forgetOpenRouterModels() {
        try {
            await this.api('admin.php?action=openrouter_models_forget', {method: 'POST', body: {}});
            this.toast('Списки вернулись к встроенному каталогу');
            this.adminLlm();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // ---- Mailboxes ----

    async adminMail() {
        try {
            const d = await this.api('admin.php?action=mailboxes');
            this.mailboxManagers = d.managers;
            this.mailProviders = d.providers || [];   // the table names the service, so load it first
            document.getElementById('adminBody').innerHTML = `
                ${!d.imap_available ? '<div class="card card--alert">На сервере нет расширения PHP <code>imap</code> — входящая почта читаться не будет. Отправка через SMTP работает.</div>' : ''}
                <div class="card">
                    <div class="flex flex--between" style="margin-bottom:10px">
                        <div class="card__title" style="margin:0">Почтовые ящики</div>
                        <div class="flex">
                            <button class="btn btn--outline btn--sm" onclick="App.syncAllMailboxes()">⟳ Синхронизировать все</button>
                            <button class="btn btn--primary btn--sm" onclick="App.editMailbox(null)">+ Добавить ящик</button>
                        </div>
                    </div>
                    <table class="table">
                        <thead><tr><th>Ящик</th><th>Менеджер</th><th>Писем</th><th>Весь архив</th><th>Проверка</th><th></th></tr></thead>
                        <tbody>
                            ${d.items.map(b => `
                                <tr>
                                    <td><strong>${this.esc(b.name)}</strong> ${b.is_default ? '<span class="badge badge--sent">основной</span>' : ''}
                                        ${b.is_active ? '' : '<span class="badge badge--draft">выключен</span>'}
                                        ${b.hidden_messages ? `<span class="badge badge--warning">письма скрыты: ${b.hidden_messages}</span>` : ''}
                                        <div class="muted">${this.esc(b.email)} · ${this.esc(this.providerTitle(b.provider))} · IMAP ${this.esc(b.imap_host)}</div></td>
                                    <td>${this.esc(b.manager_name) || '<em>общий</em>'}</td>
                                    <td class="num">${b.messages}${b.oldest_at ? `<div class="muted">с ${this.fmtDate(b.oldest_at)}</div>` : ''}</td>
                                    <td id="bf_${b.id}">${this.backfillLabel(b.backfill)}</td>
                                    <td class="muted">${b.last_error ? `<span class="no">${this.esc(b.last_error)}</span>` : this.fmtDate(b.last_check_at)}</td>
                                    <td>
                                        <button class="btn btn--sm btn--outline" onclick="App.editMailbox(${b.id})">Изменить</button>
                                        <button class="btn btn--sm btn--outline" onclick="App.toggleMailbox(${b.id}, ${b.is_active ? 0 : 1})"
                                                title="${b.is_active ? 'Перестать опрашивать ящик, не удаляя его' : 'Снова опрашивать ящик'}">
                                            ${b.is_active ? '⏸ Отключить' : '▶ Включить'}</button>
                                        <button class="btn btn--sm btn--outline" onclick="App.syncMailbox(${b.id})">Забрать почту</button>
                                        <button class="btn btn--sm btn--outline" onclick="App.backfillMailbox(${b.id})">Скачать весь архив</button>
                                        <button class="btn btn--sm btn--outline" onclick="App.checkSentFolder(${b.id})"
                                                title="Найти на сервере настоящую папку «Отправленные»">Отправленные</button>
                                        <a class="btn btn--sm btn--outline" href="api/admin.php?action=mailbox_export&id=${b.id}">Выгрузить .mbox</a>
                                    </td>
                                </tr>`).join('')}
                            ${d.items.length === 0 ? '<tr><td colspan="6" style="text-align:center;color:var(--text-muted)">Ящиков пока нет</td></tr>' : ''}
                        </tbody>
                    </table>
                    <p class="muted">«Скачать весь архив» забирает ВСЮ переписку ящика, а не только новое: письма идут шагами,
                        кнопку можно нажать повторно — загрузка продолжится с того же места. Старые письма попадают в архив,
                        но запросы КП из них не создаются.</p>
                    <p class="muted">«Отключить» оставляет ящик со всеми настройками и паролями — его просто перестают
                        опрашивать, и он исчезает из выбора отправителя. Письма отключённого ящика можно убрать с экрана
                        вместе с ним и вернуть, когда ящик включат обратно.</p>
                </div>
                <div class="card">
                    <div class="card__title">Цепочки писем</div>
                    <p class="muted">Письма собираются в переписки по теме без «Re:» и «Fwd:» — так ответ,
                       отправленный с другого ящика, виден в той же цепочке. Пересоберите группировку, если
                       темы писем чинились после загрузки архива.</p>
                    <button class="btn btn--outline btn--sm" onclick="App.rethreadMail()">Пересобрать цепочки</button>
                </div>
                <div class="card" id="dedupCard"><div class="loading">Загрузка...</div></div>
                <div class="card" id="mboxCard"><div class="loading">Загрузка...</div></div>
                <div id="mailboxForm"></div>
            `;
            this.mailboxes = d.items;
            this.mailboxBlank = d.blank;
            this.loadMboxCard();
        } catch (err) { this.adminFail(err); }
    },

    // ---- Дедупликация и импорт переписки из mbox (модуль 021) ----

    async loadMboxCard() {
        const dedupCard = document.getElementById('dedupCard');
        const mboxCard = document.getElementById('mboxCard');
        if (!dedupCard || !mboxCard) return;
        try {
            const d = await this.api('admin.php?action=mbox_files');
            this.mboxState = d;
            const dd = d.dedup || {};
            const hashed = Math.max(0, (dd.total || 0) - (dd.unhashed || 0));

            dedupCard.innerHTML = `
                <div class="card__title">Дедупликация писем</div>
                <p class="muted">Одно и то же письмо не ложится в архив дважды — ни из второго ящика, куда оно
                   пришло копией, ни из папки «Отправленные», ни из импортированного mbox. Проверка идёт
                   по всем ящикам сразу.</p>
                <label style="display:block;margin-bottom:8px">
                    <input type="checkbox" id="dedupOn" ${dd.enabled ? 'checked' : ''}
                           onchange="App.saveDedup()"> Отсекать дубликаты (по умолчанию включено)
                </label>
                <label style="display:block;margin-bottom:8px">
                    <input type="checkbox" id="dedupContent" ${dd.content ? 'checked' : ''}
                           ${dd.enabled ? '' : 'disabled'} onchange="App.saveDedup()">
                    Сравнивать ещё и по содержимому: отправитель, получатели, тема, текст и байты вложений
                </label>
                <p class="muted">Без второй галочки дубликат узнаётся только по Message-ID. Совсем короткие письма
                   («Спасибо!») по содержимому не сравниваются никогда — два одинаковых «спасибо» в разные дни
                   это два письма.</p>
                <p>Отпечатки посчитаны у <strong>${hashed}</strong> писем из ${dd.total || 0}.
                   ${dd.unhashed ? `<span class="muted">Пока не у всех — старые письма сравниваются
                      по Message-ID. Досчитывается шагами, в том числе при импорте.</span>` : ''}</p>
                ${dd.unhashed ? `<button class="btn btn--outline btn--sm" id="hashBtn" onclick="App.hashOldMail()">
                    Досчитать отпечатки (${dd.unhashed})</button>` : ''}
            `;

            const files = d.files || [];
            mboxCard.innerHTML = `
                <div class="card__title">Импорт переписки из mbox</div>
                <p class="muted">Формат выгрузки Gmail («Скачать данные»), Thunderbird и любого почтового клиента.
                   Письма встают на карточки контрагентов в хронологическом порядке, со всеми вложениями,
                   и не создают запросов КП — это история, а не новая работа.</p>
                <div class="grid grid--2">
                    <div class="form-group">
                        <label>Файл .mbox</label>
                        <input type="file" id="mboxFile" accept=".mbox,.mbx,.txt,.eml">
                        <div class="muted">Файл уходит кусками, поэтому ограничение сервера на один запрос
                            (${this.esc(d.upload_max || '')}) размеру архива не мешает — резать выгрузку Gmail
                            вручную не нужно. Оборвалась связь — выберите тот же файл снова, загрузка продолжится
                            с того же места. Многогигабайтный архив быстрее положить по FTP
                            в <code>${this.esc(d.dir)}</code> — он появится в списке сам.</div>
                    </div>
                    <div class="form-group">
                        <label>Ящик, в который лягут письма</label>
                        <select id="mboxMailbox">
                            ${(d.mailboxes || []).map(b => `<option value="${b.id}">${this.esc(b.name)} — ${this.esc(b.email)}</option>`).join('')}
                        </select>
                        <div class="muted">Направление письма («от нас» или «нам») определяется по отправителю
                            и меткам Gmail, а не по ящику.</div>
                    </div>
                </div>
                <label style="display:block;margin-bottom:10px">
                    <input type="checkbox" id="mboxCreateCompanies">
                    Заводить карточки компаний для незнакомых отправителей
                    <span class="muted">— иначе старое письмо привязывается только к уже существующей карточке,
                    а рассылки и случайные адреса новых компаний не плодят</span>
                </label>
                <!-- Архив потеряли вместе с ящиком, а файл тот же самый — и импорт
                     честно считает каждое письмо дубликатом того, чего уже нет (модуль 023) -->
                <label style="display:block;margin-bottom:10px">
                    <input type="checkbox" id="mboxForce">
                    Загрузить заново, не считаясь с дубликатами
                    <span class="muted">— если письма из прошлого импорта потеряны (например, вместе с удалённым
                    ящиком) и «всё дубликаты» означает «ничего не загрузилось». Письма, которые ещё есть,
                    при этом задвоятся</span>
                </label>
                <div class="flex flex--wrap" style="margin-bottom:10px">
                    <button class="btn btn--primary" id="mboxUploadBtn" onclick="App.uploadMbox()">Загрузить файл</button>
                    <button class="btn btn--outline" onclick="App.restoreOrphanedMail(this)"
                            title="Письма ящиков, которых больше нет, вернуть на экран">↩ Вернуть письма удалённых ящиков</button>
                </div>
                <div id="mboxProgress" style="margin-top:12px"></div>

                <div class="card__title" style="margin-top:16px">Файлы в ${this.esc(d.dir)}</div>
                ${files.length ? `
                <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>Файл</th><th class="num">Размер</th><th>Состояние</th><th></th></tr></thead>
                    <tbody>
                        ${files.map(f => this.mboxFileRow(f)).join('')}
                    </tbody>
                </table>
                </div>` : '<p class="muted">Пока пусто — загрузите файл выше или положите его по FTP.</p>'}
                ${this.mboxHistoryHtml(d.imports || [], files)}
            `;
        } catch (err) {
            dedupCard.innerHTML = `<div class="card__title">Дедупликация писем</div><p class="no">${this.esc(err.message)}</p>`;
            mboxCard.innerHTML = '';
        }
    },

    /**
     * Что уже импортировано. Файл после импорта обычно убирают — а ответ на
     * вопрос «эту переписку мы уже переносили?» нужен и после этого.
     */
    mboxHistoryHtml(imports, files) {
        const onDisk = new Set(files.map(f => f.filename));
        const past = imports.filter(i => !onDisk.has(i.filename));
        if (!past.length) return '';
        return `
            <div class="card__title" style="margin-top:16px">Импортированные раньше</div>
            <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Файл</th><th>Когда</th><th>Итог</th><th></th></tr></thead>
                <tbody>
                    ${past.map(i => `<tr>
                        <td>${this.esc(i.filename)}<div class="muted">ящик: ${this.esc(i.mailbox_name) || '—'}</div></td>
                        <td class="muted">${this.fmtDate(i.finished_at || i.started_at)}</td>
                        <td>${i.done ? '<span class="ok">импортирован</span>' : `<span class="badge badge--draft">остановлен на ${i.percent}%</span>`}
                            · добавлено ${i.imported} · дубликатов ${i.duplicates}${i.failed ? ` · с ошибкой ${i.failed}` : ''}
                            ${i.exists ? '' : '<div class="muted">файла в storage/mbox больше нет</div>'}</td>
                        <td><button class="btn btn--sm btn--outline" onclick="App.deleteMboxImport(${i.id})">Убрать из списка</button></td>
                    </tr>`).join('')}
                </tbody>
            </table>
            </div>`;
    },

    mboxFileRow(f) {
        const im = f.import;
        const mb = (n) => (n / 1024 / 1024).toFixed(1) + ' МБ';
        const state = !im
            ? '<span class="muted">не импортировался</span>'
            : (im.done
                ? `<span class="ok">импортирован</span> · добавлено ${im.imported} · дубликатов ${im.duplicates}${im.failed ? ` · с ошибкой ${im.failed}` : ''}`
                : `<span class="badge badge--draft">${im.percent}%</span> добавлено ${im.imported} · дубликатов ${im.duplicates}`);
        return `
            <tr>
                <td><strong>${this.esc(f.filename)}</strong><div class="muted">${this.esc(f.mtime)}</div></td>
                <td class="num">${mb(f.size)}</td>
                <td>${state}${im && im.error ? `<div class="no">${this.esc(im.error)}</div>` : ''}</td>
                <td>
                    ${!im || !im.done
                        ? `<button class="btn btn--sm btn--primary" onclick="App.startMboxImport('${this.jsStr(f.filename)}')">${im ? 'Продолжить' : 'Импортировать'}</button>`
                        : `<button class="btn btn--sm btn--outline" onclick="App.startMboxImport('${this.jsStr(f.filename)}', true)">Импортировать заново</button>`}
                    ${im ? `<button class="btn btn--sm btn--outline" onclick="App.deleteMboxImport(${im.id})">Убрать файл</button>` : ''}
                </td>
            </tr>`;
    },

    async saveDedup() {
        const on = document.getElementById('dedupOn').checked;
        const content = document.getElementById('dedupContent').checked;
        try {
            await this.api('admin.php?action=settings', {method: 'PUT', body: {values: {
                MAIL_DEDUP: on ? '1' : '0',
                MAIL_DEDUP_CONTENT: content ? '1' : '0',
            }}});
            this.toast(on ? 'Дедупликация включена' : 'Дедупликация выключена', 'success');
            this.loadMboxCard();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async hashOldMail() {
        const btn = document.getElementById('hashBtn');
        if (btn) { btn.disabled = true; btn.textContent = 'Считаем...'; }
        try {
            let left = 1;
            // Шагами, пока есть что считать: одним запросом архив в тысячи писем
            // на дешёвом хостинге не пережёвывается
            while (left > 0) {
                const r = (await this.api('admin.php?action=mail_fingerprints', {method: 'POST', body: {}})).result;
                left = r.left;
                if (btn) btn.textContent = `Осталось ${left}...`;
                if (!r.done) break;
            }
            this.toast('Отпечатки посчитаны', 'success');
        } catch (err) { this.toast(err.message, 'error'); }
        this.loadMboxCard();
    },

    async uploadMbox() {
        const input = document.getElementById('mboxFile');
        const btn = document.getElementById('mboxUploadBtn');
        if (!input || !input.files.length) { this.toast('Выберите файл .mbox', 'error'); return; }
        btn.disabled = true;
        btn.textContent = 'Загружаем...';
        try {
            const filename = await this.sendMboxFile(input.files[0]);
            this.toast('Файл загружен, начинаем импорт', 'success');
            await this.startMboxImport(filename);
        } catch (err) {
            this.toast(err.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Загрузить файл';
        }
    },

    /**
     * Файл уходит кусками. Выгрузка Gmail — сотни мегабайт (меньше гигабайта
     * Google её и не режет), а одним запросом такое не принимает никто: nginx
     * отвечает «413 Request Entity Too Large» HTML-страницей ещё до PHP, у PHP
     * есть свой upload_max_filesize. Режет файл сам браузер, сервер дописывает
     * куски в один файл и собирает его на последнем байте — руками делить
     * архив не нужно.
     *
     * Размер, который берёт прокси, из браузера не узнать, поэтому кусок,
     * который не прошёл, уменьшается вдвое. Оборванная связь не теряет
     * загруженное: сервер помнит файл по имени и размеру и говорит, с какого
     * байта продолжать.
     */
    async sendMboxFile(file) {
        const out = document.getElementById('mboxProgress');
        const MIN = 256 * 1024;
        const mb = (n) => (n / 1024 / 1024).toFixed(1);
        const init = await this.api('admin.php?action=mbox_upload_init', {
            method: 'POST', body: {name: file.name, size: file.size},
        });
        let sent = Math.min(Number(init.received) || 0, file.size);
        let chunk = Math.max(MIN, Math.min(Number(init.chunk_max) || MIN, 8 * 1024 * 1024));
        let fails = 0;
        this.mboxUploadStop = false;

        const draw = (note = '') => {
            if (!out) return;
            const percent = Math.floor(100 * sent / Math.max(1, file.size));
            out.innerHTML = `
                <div class="progress"><div class="progress__bar" style="width:${percent}%"></div></div>
                <p>Загружено ${mb(sent)} из ${mb(file.size)} МБ (${percent}%)
                   ${note ? `· <span class="muted">${this.esc(note)}</span>` : ''}</p>
                <button class="btn btn--outline btn--sm" onclick="App.mboxUploadStop = true">Остановить</button>`;
        };
        draw(sent ? 'продолжаем прерванную загрузку' : '');

        while (sent < file.size) {
            if (this.mboxUploadStop) {
                throw new Error('Загрузка остановлена. Выберите тот же файл снова — она продолжится с этого места');
            }
            const fd = new FormData();
            fd.append('upload_id', init.upload_id);
            fd.append('offset', String(sent));
            fd.append('chunk', file.slice(sent, Math.min(sent + chunk, file.size)), 'chunk');

            let status = 0, data = null;
            try {
                const res = await fetch('/api/admin.php?action=mbox_upload_chunk',
                    {method: 'POST', body: fd, credentials: 'same-origin'});
                status = res.status;
                const raw = await res.text();
                try { data = JSON.parse(raw); } catch { data = null; }   // 413 прокси отдаёт HTML
            } catch (e) {
                status = 0;                                              // связь оборвалась
            }

            if (status === 200 && data && typeof data.received === 'number') {
                // Счётчик, не сдвинувшийся с места, — это вечный цикл, а не загрузка
                if (data.received <= sent) throw new Error('Сервер принял кусок, но файл не вырос — попробуйте заново');
                sent = Math.min(data.received, file.size);
                fails = 0;
                draw();
                continue;
            }
            if (status === 401) throw new Error('Сессия кончилась — войдите заново и повторите загрузку');
            // Кусок не пролез через прокси или PHP: половиним и пробуем снова
            if ((status === 413 || status === 0) && chunk > MIN) {
                chunk = Math.max(MIN, Math.floor(chunk / 2));
                draw(`кусок уменьшен до ${mb(chunk)} МБ`);
                continue;
            }
            // Сервер принял другое число байт, чем мы думаем: спрашиваем его
            if (status === 400 && data && data.error && fails < 3) {
                fails++;
                const again = await this.api('admin.php?action=mbox_upload_init', {
                    method: 'POST', body: {name: file.name, size: file.size},
                });
                sent = Math.min(Number(again.received) || 0, file.size);
                draw('сверяемся с сервером');
                continue;
            }
            if (++fails > 4) {
                if (status === 413) {
                    throw new Error(`Сервер не принимает даже кусок в ${mb(chunk)} МБ — поднимите`
                        + ' client_max_body_size у прокси или положите файл по FTP в storage/mbox');
                }
                if (status === 0) throw new Error('Связь оборвалась. Выберите тот же файл снова — загрузка продолжится');
                throw new Error((data && data.error) || `Кусок не загрузился (HTTP ${status})`);
            }
            draw(`повтор ${fails}`);
            await new Promise(r => setTimeout(r, 1000 * fails));
        }

        draw('собираем файл');
        const fin = await this.api('admin.php?action=mbox_upload_finish',
            {method: 'POST', body: {upload_id: init.upload_id}});
        return fin.filename;
    },

    async startMboxImport(filename, restart = false) {
        const box = document.getElementById('mboxMailbox');
        const create = document.getElementById('mboxCreateCompanies');
        try {
            const force = document.getElementById('mboxForce');
            const forceOn = !!(force && force.checked);
            const r = await this.api('admin.php?action=mbox_start', {method: 'POST', body: {
                filename,
                mailbox_id: box ? Number(box.value) : 0,
                create_companies: create ? create.checked : false,
                force: forceOn,
            }});
            // Перезапуск с начала файла — и с тем же ответом про дубликаты
            if (restart || forceOn) {
                await this.api('admin.php?action=mbox_reset', {method: 'POST', body: {id: r.import.id, force: forceOn}});
            }
            await this.runMboxImport(Number(r.import.id));
        } catch (err) { this.toast(err.message, 'error'); }
    },

    /** Письма ящиков, которых больше нет, — обратно на экран (модуль 023). */
    async restoreOrphanedMail(btn) {
        btn.disabled = true;
        try {
            const r = await this.api('admin.php?action=mail_restore_orphaned', {method: 'POST', body: {}});
            this.toast(r.restored
                ? `Вернулось писем: ${r.restored}`
                : 'Писем удалённых ящиков в архиве нет', r.restored ? 'success' : 'info');
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; }
    },

    /**
     * Шагами до конца файла. Каждый шаг сам сохраняет позицию, поэтому «Стоп» —
     * это пауза, а не потеря: продолжить можно кнопкой в списке файлов.
     */
    async runMboxImport(id) {
        const out = document.getElementById('mboxProgress');
        this.mboxStop = false;
        let total = {imported: 0, duplicates: 0, failed: 0, scanned: 0};
        for (let step = 0; step < 100000; step++) {
            let r;
            try {
                r = (await this.api('admin.php?action=mbox_step', {method: 'POST', body: {id}})).result;
            } catch (err) {
                if (out) out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`;
                break;
            }
            ['imported', 'duplicates', 'failed', 'scanned'].forEach(k => { total[k] += r[k] || 0; });
            const percent = (r.import || {}).percent || 0;
            if (out) out.innerHTML = `
                <div class="progress"><div class="progress__bar" style="width:${percent}%"></div></div>
                <p>Прочитано ${percent}% файла · добавлено <strong>${total.imported}</strong>
                   · дубликатов ${total.duplicates}${total.failed ? ` · с ошибкой ${total.failed}` : ''}</p>
                ${r.done ? '<p class="ok">Импорт закончен.</p>'
                    : '<button class="btn btn--outline btn--sm" onclick="App.mboxStop = true">Остановить</button>'}
            `;
            if (r.done || this.mboxStop) break;
        }
        this.loadMboxCard();
    },

    async deleteMboxImport(id) {
        if (!confirm('Убрать файл и запись об импорте? Уже импортированные письма останутся в архиве.')) return;
        try {
            await this.api('admin.php?action=mbox_delete', {method: 'POST', body: {id, with_file: true}});
            this.toast('Файл убран', 'success');
            this.loadMboxCard();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async rethreadMail() {
        this.toast('Пересобираем цепочки...', 'info');
        try {
            const r = await this.api('admin.php?action=mail_rethread', {method: 'POST', body: {}});
            this.toast(`Писем сгруппировано: ${r.threaded}`, 'success');
        } catch (err) { this.toast(err.message, 'error'); }
    },

    /**
     * «Отправленные» of a mailbox. The copy of an outgoing letter used to vanish
     * because the folder in the settings did not exist on the server — this
     * prints the server's own folder list and fixes the name in place.
     */
    async checkSentFolder(id) {
        this.toast('Смотрим папки на сервере...', 'info');
        try {
            const r = (await this.api('admin.php?action=mailbox_sent_check', {method: 'POST', body: {id}})).result;
            this.modal('Папка «Отправленные»', `
                <p>Используется: <strong>${this.esc(r.resolved || 'не найдена')}</strong>
                   ${r.fixed ? `<span class="badge badge--sent">исправлено, было «${this.esc(r.configured)}»</span>` : ''}</p>
                <p class="muted">Папки на сервере: ${r.folders.map(f => `<span class="chip">${this.esc(f)}</span>`).join(' ')}</p>
                <div class="card__title" style="margin-top:12px">Последние отправленные</div>
                <table class="table">
                    <thead><tr><th>Тема</th><th>Кому</th><th>Копия на сервере</th><th>Дата</th></tr></thead>
                    <tbody>${r.recent.map(m => `<tr>
                        <td>${this.esc(m.subject) || '<em>без темы</em>'}</td>
                        <td class="muted">${this.esc(m.to_emails)}</td>
                        <td>${m.sent_state === 'appended'
                            ? `<span class="ok">в «${this.esc(m.folder)}»</span>`
                            : (m.sent_state === 'off' ? '<span class="muted">копирование выключено</span>'
                               : `<span class="no">${this.esc(m.sent_state || 'неизвестно')}</span>`)}</td>
                        <td class="muted">${this.fmtDate(m.date_at)}</td>
                    </tr>`).join('')}
                    ${r.recent.length ? '' : '<tr><td colspan="4" class="muted">Из этого ящика ещё ничего не отправляли</td></tr>'}
                    </tbody>
                </table>
            `);
            this.adminMail();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    editMailbox(id) {
        const b = id ? this.mailboxes.find(x => x.id === id) : Object.assign({id: '', name: '', imap_password_set: false, smtp_password_set: false}, this.mailboxBlank);
        const sel = (name, value, options) => `<select id="mb_${name}">
            ${options.map(o => `<option value="${o[0]}" ${String(value) === String(o[0]) ? 'selected' : ''}>${o[1]}</option>`).join('')}</select>`;
        document.getElementById('mailboxForm').innerHTML = `
            <div class="card">
                <div class="card__title">${id ? 'Ящик: ' + this.esc(b.name) : 'Новый почтовый ящик'}</div>
                <div class="grid grid--3">
                    <div class="form-group"><label>Название</label><input type="text" id="mb_name" value="${this.esc(b.name)}"></div>
                    <div class="form-group"><label>Адрес</label>
                        <input type="text" id="mb_email" value="${this.esc(b.email)}" oninput="App.mailboxEmailChanged()"></div>
                    <div class="form-group"><label>Почтовый сервис</label>
                        <select id="mb_provider" onchange="App.providerChanged()">
                            ${(this.mailProviders || []).map(p => `<option value="${p.key}" ${String(b.provider || 'custom') === p.key ? 'selected' : ''}>${this.esc(p.title)}</option>`).join('')}
                        </select></div>
                    <div class="form-group"><label>Менеджер</label>
                        ${sel('manager_id', b.manager_id || '', [['', 'Общий ящик']].concat((this.mailboxManagers || []).map(m => [m.id, m.name])))}</div>
                </div>
                <div id="mbProviderHint" class="card card--alert" style="margin-bottom:12px"></div>
                <div class="grid grid--3">
                    <div class="form-group"><label>IMAP сервер</label><input type="text" id="mb_imap_host" value="${this.esc(b.imap_host)}"></div>
                    <div class="form-group"><label>Порт</label><input type="number" id="mb_imap_port" value="${this.esc(b.imap_port)}"></div>
                    <div class="form-group"><label>Шифрование</label>
                        ${sel('imap_encryption', b.imap_encryption, [['ssl', 'SSL'], ['tls', 'TLS'], ['notls', 'без шифрования']])}</div>
                    <div class="form-group"><label>Логин</label><input type="text" id="mb_imap_user" value="${this.esc(b.imap_user)}"></div>
                    <div class="form-group"><label>Пароль <span class="muted" id="mbImapPwdNote"></span></label>
                        <input type="password" id="mb_imap_password" placeholder="${b.imap_password_set ? 'сохранён — оставьте пустым' : 'не задан'}"
                               oninput="App.mirrorSmtpPassword()"></div>
                    <div class="form-group"><label>Папка входящих</label><input type="text" id="mb_imap_folder_in" value="${this.esc(b.imap_folder_in || 'INBOX')}"></div>
                    <div class="form-group"><label>Папка отправленных</label><input type="text" id="mb_imap_folder_sent" value="${this.esc(b.imap_folder_sent || '')}"></div>
                </div>
                <div class="grid grid--3">
                    <div class="form-group"><label>SMTP сервер</label><input type="text" id="mb_smtp_host" value="${this.esc(b.smtp_host)}"></div>
                    <div class="form-group"><label>Порт</label><input type="number" id="mb_smtp_port" value="${this.esc(b.smtp_port)}"></div>
                    <div class="form-group"><label>Шифрование</label>
                        ${sel('smtp_encryption', b.smtp_encryption, [['ssl', 'SSL'], ['tls', 'TLS'], ['', 'без шифрования']])}</div>
                    <div class="form-group"><label>Логин</label><input type="text" id="mb_smtp_user" value="${this.esc(b.smtp_user)}"></div>
                    <div class="form-group"><label>Пароль <span class="muted" id="mbSmtpPwdNote"></span></label>
                        <input type="password" id="mb_smtp_password" placeholder="${b.smtp_password_set ? 'сохранён — оставьте пустым' : 'если пусто — как у IMAP'}"
                               oninput="App.mirrorImapPassword()"></div>
                    <div class="form-group"><label>Имя отправителя</label><input type="text" id="mb_from_name" value="${this.esc(b.from_name)}"></div>
                    <div class="form-group"><label>Адрес отправителя</label><input type="text" id="mb_from_email" value="${this.esc(b.from_email)}"></div>
                </div>
                <div class="grid grid--3">
                    <div class="form-group"><label>Активен</label>${sel('is_active', b.is_active ? 1 : 0, [[1, 'Да'], [0, 'Нет']])}</div>
                    <div class="form-group"><label>Основной для отправки</label>${sel('is_default', b.is_default ? 1 : 0, [[1, 'Да'], [0, 'Нет']])}</div>
                    <div class="form-group"><label>Создавать запросы из писем</label>${sel('create_requests', b.create_requests ? 1 : 0, [[1, 'Да'], [0, 'Нет']])}</div>
                    <div class="form-group"><label>Забирать «Отправленные»</label>${sel('sync_sent', b.sync_sent ? 1 : 0, [[1, 'Да'], [0, 'Нет']])}</div>
                </div>
                <div class="flex flex--wrap">
                    <button class="btn btn--primary" onclick="App.saveMailbox(${id || 'null'})">Сохранить</button>
                    <button class="btn btn--outline" onclick="App.testMailbox('imap', ${id || 'null'})">Проверить IMAP</button>
                    <button class="btn btn--outline" onclick="App.testMailbox('smtp', ${id || 'null'})">Проверить SMTP</button>
                    <button class="btn btn--outline" onclick="App.testMailbox('smtp', ${id || 'null'}, true)">Отправить тестовое письмо</button>
                    ${id ? `<button class="btn btn--outline" onclick="App.restartBackfill(${id})">Скачать архив заново</button>` : ''}
                    ${id ? `<button class="btn btn--danger" onclick="App.deleteMailbox(${id})">Удалить</button>` : ''}
                </div>
                <div id="mbTest" style="margin-top:10px"></div>
            </div>
        `;
        this.applyProvider(false);
    },

    /** An explicit pick wins over the domain guess for the rest of the session. */
    providerChanged() {
        const sel = document.getElementById('mb_provider');
        if (sel) sel.dataset.touched = '1';
        this.applyProvider(true);
    },

    providerTitle(key) {
        const p = (this.mailProviders || []).find(x => x.key === (key || 'custom'));
        return p ? p.title : (key || '');
    },

    /** Provider picked — fill the servers and show what kind of password is needed. */
    applyProvider(overwrite) {
        const key = (document.getElementById('mb_provider') || {}).value || 'custom';
        const p = (this.mailProviders || []).find(x => x.key === key);
        if (!p) return;
        const email = (document.getElementById('mb_email') || {}).value || '';
        const set = (name, value) => {
            const el = document.getElementById('mb_' + name);
            if (el && value !== undefined && value !== '' && (overwrite || !el.value)) el.value = value;
        };
        Object.keys(p.defaults || {}).forEach(f => set(f, p.defaults[f]));
        if (p.app_password && email) { set('imap_user', email); set('smtp_user', email); set('from_email', email); }

        const hint = document.getElementById('mbProviderHint');
        if (hint) {
            hint.style.display = p.hint ? '' : 'none';
            hint.innerHTML = `${this.esc(p.hint)}${p.help_url ? ` <a href="${p.help_url}" target="_blank" rel="noopener">Создать пароль приложения →</a>` : ''}`;
        }
        const note = p.app_password ? 'пароль приложения' : '';
        ['mbImapPwdNote', 'mbSmtpPwdNote'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = note;
        });
    },

    /** Typing the address picks the service by its domain, while it is still untouched. */
    mailboxEmailChanged() {
        const sel = document.getElementById('mb_provider');
        if (!sel || sel.dataset.touched === '1') return;
        const email = (document.getElementById('mb_email') || {}).value || '';
        const domain = (email.split('@')[1] || '').toLowerCase();
        const guess = {'yandex.ru': 'yandex', 'yandex.com': 'yandex', 'ya.ru': 'yandex', 'narod.ru': 'yandex',
                       'mail.ru': 'mailru', 'bk.ru': 'mailru', 'inbox.ru': 'mailru', 'list.ru': 'mailru',
                       'gmail.com': 'gmail'}[domain];
        if (guess && sel.value !== guess) { sel.value = guess; this.applyProvider(true); }
        else this.applyProvider(false);
    },

    /**
     * One application password serves both IMAP and SMTP, so whichever field is
     * filled in copies itself into the other — until that other one is typed in
     * by hand, and then it is left alone.
     */
    mirrorPassword(fromId, toId) {
        const from = document.getElementById(fromId);
        const to = document.getElementById(toId);
        if (!from || !to) return;
        from.dataset.typed = '1';
        if (to.dataset.typed === '1' && to.value) return;
        to.value = from.value;
        to.dataset.typed = '';
    },

    mirrorImapPassword() { this.mirrorPassword('mb_smtp_password', 'mb_imap_password'); },
    mirrorSmtpPassword() { this.mirrorPassword('mb_imap_password', 'mb_smtp_password'); },

    mailboxForm(id) {
        const val = n => (document.getElementById('mb_' + n) || {}).value ?? '';
        return {
            id: id || null,
            name: val('name'), email: val('email'), provider: val('provider'), manager_id: val('manager_id'),
            imap_host: val('imap_host'), imap_port: val('imap_port'), imap_encryption: val('imap_encryption'),
            imap_user: val('imap_user'), imap_password: val('imap_password'),
            imap_folder_in: val('imap_folder_in'), imap_folder_sent: val('imap_folder_sent'),
            smtp_host: val('smtp_host'), smtp_port: val('smtp_port'), smtp_encryption: val('smtp_encryption'),
            smtp_user: val('smtp_user'), smtp_password: val('smtp_password'),
            from_name: val('from_name'), from_email: val('from_email'),
            is_active: val('is_active') === '1', is_default: val('is_default') === '1',
            create_requests: val('create_requests') === '1', sync_sent: val('sync_sent') === '1',
        };
    },

    async saveMailbox(id) {
        try {
            await this.api('admin.php?action=mailbox_save', {method: 'POST', body: this.mailboxForm(id)});
            this.toast('Ящик сохранён', 'success');
            this.adminMail();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    /**
     * Выключить ящик вместо удаления: настройки и пароли на месте, опрос
     * прекращается. Письма по желанию уходят с экрана вместе с ящиком —
     * выключенный ящик, чьи письма продолжают висеть в списках, ничего не решает.
     */
    toggleMailbox(id, on) {
        const box = (this.mailboxes || []).find(b => b.id === id) || {};
        if (on) return this.doToggleMailbox(id, 1, 'keep');
        this.modal(`Отключить ящик «${box.name || id}»`, `
            <p class="muted">Почта из него забираться не будет, из выбора отправителя он исчезнет.
               Настройки и пароли останутся — включить обратно можно одной кнопкой.</p>
            <div class="form-group">
                <label>Письма этого ящика (${box.messages || 0})</label>
                <select id="mbOffLetters">
                    <option value="hide">Скрыть из панели — вернутся при включении</option>
                    <option value="keep">Оставить на экране</option>
                    <option value="delete">Удалить из базы вместе с вложениями</option>
                </select>
            </div>
            <div class="flex flex--end">
                <button class="btn btn--outline" onclick="App.closeModal()">Отмена</button>
                <button class="btn btn--primary" onclick="App.doToggleMailbox(${id}, 0)">Отключить</button>
            </div>
        `);
    },

    async doToggleMailbox(id, on, letters) {
        letters = letters || (document.getElementById('mbOffLetters') || {}).value || 'keep';
        if (letters === 'delete' && !confirm('Письма этого ящика будут удалены из базы вместе с вложениями. Продолжить?')) return;
        try {
            const r = await this.api('admin.php?action=mailbox_toggle', {method: 'POST',
                body: {id, is_active: !!on, letters}});
            const n = (r.result || {}).messages || 0;
            this.closeModal();
            const what = on ? 'вернулось' : (letters === 'delete' ? 'удалено' : 'скрыто');
            this.toast((on ? 'Ящик включён' : 'Ящик отключён') + (n ? ` · писем ${what}: ${n}` : ''), 'success');
            this.adminMail();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    /**
     * Удаление ящика с архивом падало на «FOREIGN KEY constraint failed»: на
     * ящик ссылается каждое его письмо. Теперь удаление спрашивает, что делать
     * с этими письмами, и делает это одной транзакцией.
     */
    deleteMailbox(id) {
        const box = (this.mailboxes || []).find(b => b.id === id) || {};
        this.modal(`Удалить ящик «${box.name || id}»`, `
            <p>В базе писем этого ящика: <strong>${box.messages || 0}</strong>.</p>
            <p class="muted">Ящик можно не удалять, а отключить — тогда настройки и пароли останутся,
               а почта перестанет забираться.</p>
            <div class="form-group">
                <label>Что сделать с письмами</label>
                <select id="mbLetters">
                    <option value="keep">Оставить в базе (уйдут в архив панели)</option>
                    <option value="delete">Удалить вместе с ящиком, с вложениями</option>
                </select>
            </div>
            <p class="muted">На почтовом сервере письма не трогаем: доступа к нему после удаления ящика уже нет.</p>
            <div class="flex flex--end">
                <button class="btn btn--outline" onclick="App.closeModal()">Отмена</button>
                <button class="btn btn--danger" onclick="App.doDeleteMailbox(${id})">Удалить ящик</button>
            </div>
        `);
    },

    async doDeleteMailbox(id) {
        const letters = (document.getElementById('mbLetters') || {}).value || 'keep';
        if (letters === 'delete' && !confirm('Письма этого ящика будут удалены из базы вместе с вложениями. Продолжить?')) return;
        try {
            const r = await this.api('admin.php?action=mailbox_delete', {method: 'POST', body: {id, letters}});
            const n = (r.result || {}).messages || 0;
            this.closeModal();
            this.toast(`Ящик удалён${n ? ` · писем ${letters === 'delete' ? 'удалено' : 'сохранено'}: ${n}` : ''}`, 'success');
            this.adminMail();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Tests run on the values in the form, so a new mailbox can be checked before saving
    async testMailbox(kind, id, send) {
        const out = document.getElementById('mbTest');
        out.innerHTML = '<p class="muted">Проверяем...</p>';
        const body = this.mailboxForm(id);
        if (send) {
            const to = prompt('Кому отправить тестовое письмо?', body.email || '');
            if (!to) { out.innerHTML = ''; return; }
            body.send_to = to;
        }
        try {
            const r = await this.api(`admin.php?action=test_${kind}`, {method: 'POST', body});
            out.innerHTML = kind === 'imap'
                ? `<p class="ok">IMAP работает: папка ${this.esc(r.result.folder)}, писем ${r.result.count}.
                   <span class="muted">Папки: ${(r.result.folders || []).map(f => this.esc(f)).join(', ')}</span></p>`
                : `<p class="ok">SMTP работает: ${this.esc(r.result.host)}:${r.result.port} · логин ${this.esc(r.result.user)}${r.result.sent_to ? ' · письмо отправлено на ' + this.esc(r.result.sent_to) : ''}</p>`;
        } catch (err) {
            out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`;
        }
    },

    async syncMailbox(id) {
        this.toast('Забираем почту...', 'info');
        try {
            const r = await this.api('admin.php?action=mailbox_sync', {method: 'POST', body: {id}});
            const rep = (r.report || [])[0] || {};
            if (rep.error) this.toast(rep.error, 'error');
            else this.toast(`Входящих: ${rep.in}, исходящих: ${rep.out}, новых запросов: ${rep.requests}`, 'success');
            this.adminMail();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    /** Human-readable state of the full archive download. */
    backfillLabel(bf) {
        if (!bf) return '<span class="muted">—</span>';
        if (bf.done) return `<span class="ok">скачан</span>${bf.finished_at ? `<div class="muted">${this.fmtDate(bf.finished_at)}</div>` : ''}`;
        if (!bf.started_at) return '<span class="muted">не скачан</span>';
        return `<span class="muted">${bf.percent}%</span>`;
    },

    /**
     * Walks the whole mailbox in steps: the server hands back a cursor, we keep
     * asking until it says done. Stopping the page just pauses it — the next run
     * continues from the same letter.
     */
    async backfillMailbox(id, restart) {
        if (this.backfillRunning) { this.backfillRunning = false; this.toast('Скачивание остановлено', 'info'); return; }
        const cell = document.getElementById('bf_' + id);
        this.backfillRunning = true;
        let total = 0;
        try {
            while (this.backfillRunning) {
                const r = await this.api('admin.php?action=mailbox_backfill', {
                    method: 'POST', body: {id, restart: restart ? 1 : 0},
                });
                restart = false;
                const res = r.result || {};
                total += res.stored || 0;
                if (cell) cell.innerHTML = `<span class="muted">${res.percent || 0}% · +${total}</span>
                    <div><button class="btn btn--sm btn--outline" onclick="App.backfillMailbox(${id})">стоп</button></div>`;
                if (res.error) { this.toast(res.error, 'error'); break; }
                if (res.done) { this.toast(`Архив скачан полностью: новых писем ${total}`, 'success'); break; }
                if (!res.scanned) { this.toast(`Загружено писем: ${total}`, 'info'); break; }
            }
        } catch (err) {
            this.toast(err.message, 'error');
        } finally {
            this.backfillRunning = false;
            this.adminMail();
        }
    },

    async restartBackfill(id) {
        if (!confirm('Пройти ящик с самого первого письма заново? Уже скачанные письма не задвоятся.')) return;
        this.backfillMailbox(id, true);
    },

    async syncAllMailboxes() {
        this.toast('Синхронизация...', 'info');
        try {
            const r = await this.api('admin.php?action=mailbox_sync', {method: 'POST', body: {}});
            const errors = (r.report || []).filter(x => x.error);
            if (errors.length) this.toast(errors.map(e => `${e.name}: ${e.error}`).join('; '), 'error');
            else this.toast('Готово', 'success');
            this.adminMail();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // ---- Managers ----

    async adminManagers() {
        try {
            const d = await this.api('admin.php?action=managers');
            this.managersList = d.items;
            document.getElementById('adminBody').innerHTML = `
                <div class="card">
                    <div class="flex flex--between" style="margin-bottom:10px">
                        <div class="card__title" style="margin:0">Менеджеры</div>
                        <button class="btn btn--primary btn--sm" onclick="App.editManager(null)">+ Добавить менеджера</button>
                    </div>
                    <table class="table">
                        <thead><tr><th>Имя</th><th>Логин</th><th>Email</th><th>Роль</th><th>Ящиков</th><th>Запросов</th><th></th></tr></thead>
                        <tbody>
                            ${d.items.map(m => `
                                <tr class="${m.is_active ? '' : 'row--off'}">
                                    <td>${this.esc(m.name)} ${m.is_active ? '' : '<span class="badge badge--draft">отключён</span>'}</td>
                                    <td><code>${this.esc(m.login)}</code></td>
                                    <td>${this.esc(m.email) || '—'}</td>
                                    <td>${m.is_admin ? '<span class="badge badge--confirmed">админ</span>' : 'менеджер'}</td>
                                    <td class="num">${m.mailboxes}</td>
                                    <td class="num">${m.requests}</td>
                                    <td><button class="btn btn--sm btn--outline" onclick="App.editManager(${m.id})">Изменить</button></td>
                                </tr>`).join('')}
                        </tbody>
                    </table>
                    <p class="muted" style="margin-top:8px">Менеджеры из <code>config.php</code> создаются при первом запуске.
                       После правки здесь конфиг их больше не перезаписывает.</p>
                </div>
                <div id="managerForm"></div>
            `;
        } catch (err) { this.adminFail(err); }
    },

    editManager(id) {
        const m = id ? this.managersList.find(x => x.id === id) : {id: '', name: '', login: '', email: '', phone: '', is_admin: 0, is_active: 1, moysklad_uid: ''};
        document.getElementById('managerForm').innerHTML = `
            <div class="card">
                <div class="card__title">${id ? 'Менеджер: ' + this.esc(m.name) : 'Новый менеджер'}</div>
                <div class="grid grid--3">
                    <div class="form-group"><label>Имя</label><input type="text" id="mg_name" value="${this.esc(m.name)}"></div>
                    <div class="form-group"><label>Логин</label><input type="text" id="mg_login" value="${this.esc(m.login)}"></div>
                    <div class="form-group"><label>Пароль</label><input type="password" id="mg_password" placeholder="${id ? 'оставьте пустым — не менять' : ''}"></div>
                    <div class="form-group"><label>Email</label><input type="text" id="mg_email" value="${this.esc(m.email)}"></div>
                    <div class="form-group"><label>Телефон</label><input type="text" id="mg_phone" value="${this.esc(m.phone)}"></div>
                    <div class="form-group"><label>UID в МойСклад</label><input type="text" id="mg_uid" value="${this.esc(m.moysklad_uid)}"></div>
                    <div class="form-group"><label>Права</label>
                        <select id="mg_admin"><option value="0" ${m.is_admin ? '' : 'selected'}>Менеджер</option>
                        <option value="1" ${m.is_admin ? 'selected' : ''}>Администратор</option></select></div>
                    <div class="form-group"><label>Активен</label>
                        <select id="mg_active"><option value="1" ${m.is_active ? 'selected' : ''}>Да</option>
                        <option value="0" ${m.is_active ? '' : 'selected'}>Нет</option></select></div>
                </div>
                <div class="flex">
                    <button class="btn btn--primary" onclick="App.saveManager(${id || 'null'})">Сохранить</button>
                    ${id ? `<button class="btn btn--danger" onclick="App.deleteManager(${id})">Удалить</button>` : ''}
                </div>
            </div>
        `;
    },

    async saveManager(id) {
        const v = n => document.getElementById('mg_' + n).value;
        try {
            await this.api('admin.php?action=manager_save', {method: 'POST', body: {
                id, name: v('name'), login: v('login'), password: v('password'), email: v('email'),
                phone: v('phone'), moysklad_uid: v('uid'), is_admin: v('admin') === '1', is_active: v('active') === '1',
            }});
            this.toast('Менеджер сохранён', 'success');
            this.adminManagers();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async deleteManager(id) {
        if (!confirm('Удалить менеджера? Если за ним закреплены запросы, он будет отключён, а не удалён.')) return;
        try {
            const r = await this.api('admin.php?action=manager_delete', {method: 'POST', body: {id}});
            this.toast(r.result === 'deleted' ? 'Менеджер удалён' : 'Менеджер отключён', 'success');
            this.adminManagers();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // ---- Technical prompts ----

    // ---- «Tone of Voice»: как компания разговаривает с клиентом (модуль 022) ----
    //
    // Текст лежал файлом в репозитории, и деплой стирал каждую правку. Теперь
    // он в storage/, правит его и менеджер, и админ видит правку в ленте.

    async settingsTov() {
        const body = document.getElementById('adminBody');
        try {
            const d = await this.api('admin.php?action=tov');
            body.innerHTML = `
                <div class="card">
                    <div class="card__title">Tone of Voice
                        ${this.hint('tov', 'Как мы разговариваем с клиентом: обращение, длина фраз, что говорим про сроки и чего не обещаем. Текст подмешивается в каждый ответ и в сопроводительное письмо КП. Это НЕ база знаний: факты о товаре живут в вики, здесь — только манера речи.')}
                    </div>
                    <p class="muted">${d.is_custom
                        ? 'Свой текст' + (d.updated_at ? ', изменён ' + this.fmtDate(d.updated_at) : '')
                        : 'Пока используется встроенный текст из репозитория — сохраните, и он станет вашим.'}</p>
                    <textarea id="tovText" rows="20" style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px">${this.esc(d.content || '')}</textarea>
                    <div class="flex flex--wrap" style="margin-top:8px">
                        <button class="btn btn--primary" onclick="App.saveTov()">Сохранить</button>
                        ${d.is_custom ? '<button class="btn btn--outline" onclick="App.resetTov()">Вернуть встроенный</button>' : ''}
                    </div>
                </div>`;
        } catch (err) { this.adminFail(err); }
    },

    async saveTov() {
        try {
            await this.api('admin.php?action=tov_save', {method: 'POST',
                body: {content: document.getElementById('tovText').value}});
            this.toast('Tone of Voice сохранён', 'success');
            this.settingsTov();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async resetTov() {
        if (!confirm('Вернуть встроенный текст? Ваш вариант будет удалён.')) return;
        try {
            await this.api('admin.php?action=tov_reset', {method: 'POST', body: {}});
            this.toast('Вернули встроенный текст', 'success');
            this.settingsTov();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // ---- «Правки и обучение»: на чём сервис учится (модуль 022) ----
    //
    // Сюда стекается всё, что человек поправил за машиной: категория письма,
    // текст ответа, забракованный кнопкой 👎 подбор. Админ их правит и
    // выгружает архивом в вики компании — после удачной выгрузки следующий
    // архив собирается только из новых.

    async adminLearning(filter = {}) {
        const body = document.getElementById('adminBody');
        this.learningFilter = Object.assign({kind: '', only_new: false, page: 1}, this.learningFilter || {}, filter);
        const f = this.learningFilter;
        const qs = `kind=${encodeURIComponent(f.kind)}&only_new=${f.only_new ? 1 : 0}&page=${f.page}`;
        try {
            const d = await this.api('admin.php?action=learning&' + qs);
            const ex = d.export || {};
            body.innerHTML = `
                <div class="card">
                    <div class="card__title">Выгрузка в репозиторий
                        ${this.hint('learning-export', 'Архив со всеми новыми правками уходит файлом в вики компании. После удачной выгрузки эти правки помечаются выгруженными, и следующий архив собирается только из новых — повторов не будет.')}
                    </div>
                    <p>Накоплено новых правок: <strong class="${d.pending ? 'ok' : 'muted'}">${d.pending}</strong>
                       · всего в базе: ${d.total}</p>
                    <p class="muted">Куда: <code>${this.esc(ex.repo || '')}</code> · ветка <code>${this.esc(ex.branch || '')}</code>
                       · папка <code>${this.esc(ex.path || '')}</code></p>
                    ${ex.token_set ? '' : '<p class="no">Нужен токен GitHub с правом Contents: Write — «Настройки → Все параметры → GITHUB_TOKEN».</p>'}
                    ${ex.last ? `<p class="muted">Последняя выгрузка: ${this.esc(ex.last)}</p>` : ''}
                    <button class="btn btn--primary" ${d.pending ? '' : 'disabled'} onclick="App.learningExport(this)">
                        Выгрузить архивом (${d.pending})</button>
                    <div id="learningExportOut" style="margin-top:10px"></div>
                </div>

                <div class="card">
                    <div class="card__title">Правки (${d.total})</div>
                    <div class="flex flex--wrap" style="margin-bottom:10px">
                        <select onchange="App.adminLearning({kind: this.value, page: 1})">
                            <option value="">Все виды</option>
                            ${(d.kinds || []).map(k => `<option value="${k.key}" ${f.kind === k.key ? 'selected' : ''}>${this.esc(k.label)}</option>`).join('')}
                        </select>
                        <label class="flex" style="gap:6px">
                            <input type="checkbox" ${f.only_new ? 'checked' : ''}
                                   onchange="App.adminLearning({only_new: this.checked, page: 1})"> только не выгруженные
                        </label>
                    </div>
                    ${(d.items || []).length ? (d.items || []).map(it => this.learningRow(it, d.can_edit)).join('')
                        : '<p class="muted">Пока пусто. Правки появляются, когда менеджер меняет категорию письма перед ответом или бракует подбор кнопкой 👎.</p>'}
                    ${d.total > 25 ? `<div class="flex" style="margin-top:10px">
                        <button class="btn btn--outline btn--sm" ${f.page > 1 ? '' : 'disabled'}
                                onclick="App.adminLearning({page: ${f.page - 1}})">← Назад</button>
                        <span class="muted">стр. ${f.page}</span>
                        <button class="btn btn--outline btn--sm" ${f.page * 25 < d.total ? '' : 'disabled'}
                                onclick="App.adminLearning({page: ${f.page + 1}})">Вперёд →</button>
                    </div>` : ''}
                </div>`;
        } catch (err) { this.adminFail(err); }
    },

    learningRow(it, canEdit) {
        return `
            <div class="card card--inline" style="display:block" data-learn="${it.id}">
                <div class="flex flex--wrap" style="gap:8px">
                    <span class="badge badge--kp">${this.esc(it.kind_label)}</span>
                    ${it.exported_at ? `<span class="badge badge--muted" title="Уже в репозитории">выгружено</span>`
                                     : '<span class="badge badge--unanswered">новая</span>'}
                    <span class="muted">${this.esc(it.manager_name || '')} · ${this.fmtDate(it.created_at)}</span>
                </div>
                ${it.subject ? `<div style="margin-top:6px"><strong>${this.esc(it.subject)}</strong></div>` : ''}
                <div class="muted" style="margin-top:4px;white-space:pre-wrap">${this.esc((it.question || '').slice(0, 400))}</div>
                <div class="grid grid--2" style="margin-top:8px">
                    <div>
                        <label class="muted">Было (машина)</label>
                        <textarea rows="3" data-learn-field="auto_answer" ${canEdit ? '' : 'readonly'}>${this.esc(it.auto_answer || '')}</textarea>
                    </div>
                    <div>
                        <label class="muted">Должно быть</label>
                        <textarea rows="3" data-learn-field="correct_answer" ${canEdit ? '' : 'readonly'}>${this.esc(it.correct_answer || '')}</textarea>
                    </div>
                </div>
                <label class="muted">Пояснение</label>
                <input type="text" data-learn-field="comment" value="${this.esc(it.comment || '')}" ${canEdit ? '' : 'readonly'}>
                ${canEdit ? `<div class="flex" style="margin-top:8px">
                    <button class="btn btn--outline btn--sm" onclick="App.learningSave(${it.id}, this)">Сохранить</button>
                    <button class="btn btn--outline btn--sm btn--danger" onclick="App.learningDelete(${it.id})">Удалить</button>
                </div>` : ''}
            </div>`;
    },

    async learningSave(id, btn) {
        const box = btn.closest('[data-learn]');
        const body = {id};
        box.querySelectorAll('[data-learn-field]').forEach(el => { body[el.dataset.learnField] = el.value; });
        try {
            await this.api('admin.php?action=learning_save', {method: 'POST', body});
            this.toast('Правка сохранена', 'success');
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async learningDelete(id) {
        if (!confirm('Удалить правку? Она перестанет попадать и в промпты, и в выгрузку.')) return;
        try {
            await this.api('admin.php?action=learning_delete', {method: 'POST', body: {id}});
            this.adminLearning();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async learningExport(btn) {
        const out = document.getElementById('learningExportOut');
        btn.disabled = true;
        out.innerHTML = '<p class="muted">Собираем архив и кладём его в репозиторий...</p>';
        try {
            const r = (await this.api('admin.php?action=learning_export', {method: 'POST', body: {}})).result;
            out.innerHTML = `<p class="ok">Выгружено правок: ${r.count} · файл <code>${this.esc(r.file)}</code>
                ${r.url ? `· <a href="${this.esc(r.url)}" target="_blank" rel="noopener">открыть на GitHub</a>` : ''}</p>
                <p class="muted">Следующий архив соберётся только из новых правок.</p>`;
            this.toast('Архив в репозитории', 'success');
            setTimeout(() => this.adminLearning(), 1200);
        } catch (err) {
            out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`;
            btn.disabled = false;
        }
    },

    async adminPrompts() {
        try {
            const d = await this.api('admin.php?action=prompts');
            document.getElementById('adminBody').innerHTML = `
                <div class="card"><div class="card__title">Промпты${this.hint('prompts')}</div>
                   <p>Системные промпты, с которыми сервис обращается к нейросети.
                   Пустое поле вернёт встроенный текст. Плейсхолдеры <code>{{...}}</code> подставляются кодом — не удаляйте их.</p></div>
                ${d.items.map(p => `
                    <div class="card">
                        <div class="flex flex--between">
                            <div class="card__title">${this.esc(p.title)}
                                ${p.is_custom ? '<span class="badge badge--confirmed">изменён</span>' : '<span class="badge badge--draft">встроенный</span>'}</div>
                            <span class="muted">${p.updated_at ? this.fmtDate(p.updated_at) + (p.updated_by ? ' · ' + this.esc(p.updated_by) : '') : ''}</span>
                        </div>
                        <p class="muted">${this.esc(p.description)}
                           ${p.placeholders.length ? '· плейсхолдеры: ' + p.placeholders.map(v => `<code>{{${v}}}</code>`).join(' ') : ''}</p>
                        <textarea id="pr_${p.key}" rows="12">${this.esc(p.content)}</textarea>
                        <div class="flex" style="margin-top:8px">
                            <button class="btn btn--primary btn--sm" onclick="App.savePrompt('${p.key}')">Сохранить</button>
                            ${p.is_custom ? `<button class="btn btn--outline btn--sm" onclick="App.resetPrompt('${p.key}')">Вернуть встроенный</button>` : ''}
                            ${p.history ? `<button class="btn btn--outline btn--sm" onclick="App.promptHistory('${p.key}')">История (${p.history})</button>` : ''}
                        </div>
                    </div>`).join('')}
            `;
        } catch (err) { this.adminFail(err); }
    },

    async savePrompt(key) {
        try {
            await this.api('admin.php?action=prompt_save', {method: 'POST', body: {key, content: document.getElementById('pr_' + key).value}});
            this.toast('Промпт сохранён', 'success');
            this.adminPrompts();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async resetPrompt(key) {
        if (!confirm('Вернуть встроенный текст промпта?')) return;
        try {
            await this.api('admin.php?action=prompt_reset', {method: 'POST', body: {key}});
            this.toast('Промпт возвращён к встроенному', 'success');
            this.adminPrompts();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async promptHistory(key) {
        const d = await this.api(`admin.php?action=prompt_history&key=${encodeURIComponent(key)}`);
        this.modal('История промпта', d.items.map(h => `
            <div class="card" style="margin-bottom:8px">
                <div class="muted">${this.fmtDate(h.created_at)} ${h.manager_name ? '· ' + this.esc(h.manager_name) : ''}</div>
                <pre style="white-space:pre-wrap;font-size:12px;max-height:200px;overflow:auto">${this.esc(h.content)}</pre>
            </div>`).join('') || '<p class="muted">Пока пусто</p>');
    },

    // ---- Error log ----

    async adminLogs() {
        const st = this.logState = this.logState || {level: 'warning', channel: '', q: '', offset: 0};
        try {
            const qs = new URLSearchParams({level: st.level, channel: st.channel, q: st.q, limit: 100, offset: st.offset});
            const d = await this.api('admin.php?action=logs&' + qs);
            const levelBadge = l => `<span class="badge badge--${l === 'error' ? 'critical' : (l === 'warning' ? 'warning' : 'new')}">${l}</span>`;
            document.getElementById('adminBody').innerHTML = `
                <div class="card card--inline">
                    <select id="logLevel" onchange="App.logFilter({level:this.value})">
                        <option value="" ${st.level === '' ? 'selected' : ''}>Все записи</option>
                        <option value="info" ${st.level === 'info' ? 'selected' : ''}>info и выше</option>
                        <option value="warning" ${st.level === 'warning' ? 'selected' : ''}>Предупреждения и ошибки</option>
                        <option value="error" ${st.level === 'error' ? 'selected' : ''}>Только ошибки</option>
                    </select>
                    <select id="logChannel" onchange="App.logFilter({channel:this.value})">
                        <option value="">Все источники</option>
                        ${(d.channels || []).map(c => `<option value="${this.esc(c)}" ${st.channel === c ? 'selected' : ''}>${this.esc(c)}</option>`).join('')}
                    </select>
                    <input type="text" id="logQ" placeholder="Поиск по тексту" value="${this.esc(st.q)}" style="max-width:280px"
                           onkeydown="if(event.key==='Enter')App.logFilter({q:this.value})">
                    <span class="muted">Записей: ${d.total} · за сутки ошибок ${d.counts.errors_24h}, предупреждений ${d.counts.warnings_24h}
                          <br>Повторы одного и того же сообщения собираются в одну строку со счётчиком</span>
                    <button class="btn btn--sm btn--outline" onclick="App.adminLogs()">⟳ Обновить</button>
                    <button class="btn btn--sm btn--danger" onclick="App.clearLogs()">Очистить</button>
                </div>
                <div class="card">
                    <table class="table table--logs">
                        <colgroup><col class="col-when"><col class="col-level"><col class="col-source"><col></colgroup>
                        <thead><tr><th>Когда</th><th>Уровень</th><th>Источник</th><th>Сообщение</th></tr></thead>
                        <tbody>
                            ${d.items.map(r => `
                                <tr onclick="App.logDetails(${r.id})" style="cursor:pointer">
                                    <td class="muted log__when">${this.fmtDate(r.last_at || r.created_at)}
                                        ${Number(r.repeat_count) > 1 ? `<div class="muted">с ${this.fmtDate(r.created_at)}</div>` : ''}</td>
                                    <td>${levelBadge(r.level)}
                                        ${Number(r.repeat_count) > 1 ? `<div class="badge badge--new">×${r.repeat_count}</div>` : ''}</td>
                                    <td class="log__source">${this.esc(r.channel)}<div class="muted">${this.esc(r.source || '')}</div></td>
                                    <td class="log__msg">${this.esc(r.message)}
                                        ${r.request_uri ? `<div class="muted">${this.esc(r.request_uri)}</div>` : ''}</td>
                                </tr>`).join('')}
                            ${d.items.length === 0 ? '<tr><td colspan="4" style="text-align:center;color:var(--text-muted)">Записей нет</td></tr>' : ''}
                        </tbody>
                    </table>
                    <div class="flex flex--between" style="margin-top:12px">
                        <button class="btn btn--sm btn--outline" ${st.offset === 0 ? 'disabled' : ''} onclick="App.logPage(-1)">← Новее</button>
                        <button class="btn btn--sm btn--outline" ${st.offset + 100 >= d.total ? 'disabled' : ''} onclick="App.logPage(1)">Старее →</button>
                    </div>
                </div>
            `;
            this.logRows = d.items;
        } catch (err) { this.adminFail(err); }
    },

    logFilter(patch) {
        this.logState = Object.assign(this.logState || {}, patch, {offset: 0});
        this.adminLogs();
    },

    logPage(dir) {
        this.logState.offset = Math.max(0, (this.logState.offset || 0) + dir * 100);
        this.adminLogs();
    },

    logDetails(id) {
        const r = (this.logRows || []).find(x => x.id === id);
        if (!r) return;
        let ctx = r.context || '';
        try { ctx = JSON.stringify(JSON.parse(ctx), null, 2); } catch (e) {}
        this.modal('Запись лога', `
            <p><strong>${this.esc(r.level)}</strong> · ${this.esc(r.channel)} · ${this.fmtDate(r.created_at)}
               ${Number(r.repeat_count) > 1 ? `· повторилось ${r.repeat_count} раз, последний ${this.fmtDate(r.last_at)}` : ''}</p>
            <p>${this.esc(r.message)}</p>
            ${r.source ? `<p class="muted">${this.esc(r.source)}</p>` : ''}
            ${r.request_uri ? `<p class="muted">${this.esc(r.request_uri)}</p>` : ''}
            ${ctx ? `<pre style="white-space:pre-wrap;font-size:12px;max-height:320px;overflow:auto">${this.esc(ctx)}</pre>` : ''}
        `);
    },

    async clearLogs() {
        if (!confirm('Очистить журнал полностью?')) return;
        try {
            const r = await this.api('admin.php?action=logs_clear', {method: 'POST', body: {}});
            this.toast(`Удалено записей: ${r.deleted}`, 'success');
            this.adminLogs();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // First run: no accounts yet (config.php is optional since module 004)
    renderSetup() {
        document.getElementById('nav').innerHTML = '';
        document.getElementById('userBlock').innerHTML = '';
        document.getElementById('app').innerHTML = `
            <div class="login-wrap">
                <div class="login-box card">
                    <div class="card__title" style="text-align:center">Первый запуск</div>
                    <p class="muted" style="margin-bottom:16px">Создайте администратора — дальше все настройки,
                       почтовые ящики и менеджеры заводятся в интерфейсе.</p>
                    <form id="setupForm">
                        <div class="form-group"><label>Имя</label><input type="text" id="setupName" required></div>
                        <div class="form-group"><label>Логин</label><input type="text" id="setupLogin" required></div>
                        <div class="form-group"><label>Email</label><input type="text" id="setupEmail"></div>
                        <div class="form-group"><label>Пароль</label><input type="password" id="setupPass" required minlength="8"></div>
                        <button type="submit" class="btn btn--primary btn--block">Создать администратора</button>
                    </form>
                </div>
            </div>
        `;
        document.getElementById('setupForm').onsubmit = async (e) => {
            e.preventDefault();
            try {
                await App.api('auth.php?action=setup', {method: 'POST', body: {
                    name: document.getElementById('setupName').value,
                    login: document.getElementById('setupLogin').value,
                    email: document.getElementById('setupEmail').value,
                    password: document.getElementById('setupPass').value,
                }});
                if (!await App.sessionAlive()) return;
                location.reload();
            } catch (err) { App.toast(err.message, 'error'); }
        };
    },

    // The reload after a login must land on a live session. If the browser
    // refuses our cookie the login screen would just come back with no reason
    // shown, so say what happened instead of looping.
    async sessionAlive() {
        try {
            await this.api('auth.php?action=me');
            return true;
        } catch {
            this.toast('Браузер не сохранил cookie сессии. Очистите cookie этого сайта '
                + 'и отключите блокировку сторонних данных, затем повторите вход.', 'error');
            return false;
        }
    },

    // Login
    renderLogin() {
        document.getElementById('nav').innerHTML = '';
        document.getElementById('userBlock').innerHTML = '';
        document.getElementById('app').innerHTML = `
            <div class="login-wrap">
                <div class="login-box card">
                    <div class="card__title" style="text-align:center;margin-bottom:20px">Вход в систему</div>
                    <form id="loginForm">
                        <div class="form-group">
                            <label>Логин</label>
                            <input type="text" id="loginUser" required>
                        </div>
                        <div class="form-group">
                            <label>Пароль</label>
                            <input type="password" id="loginPass" required>
                        </div>
                        <button type="submit" class="btn btn--primary btn--block">Войти</button>
                    </form>
                </div>
            </div>
        `;
        document.getElementById('loginForm').onsubmit = async (e) => {
            e.preventDefault();
            try {
                await App.api('auth.php?action=login', {method:'POST', body: {
                    login: document.getElementById('loginUser').value,
                    password: document.getElementById('loginPass').value,
                }});
                if (!await App.sessionAlive()) return;
                location.reload();
            } catch (err) { App.toast(err.message, 'error'); }
        };
    },

    // Logout
    async logout() {
        await this.api('auth.php?action=logout', {method:'POST'});
        clearInterval(this.pollTimer);
        location.reload();
    },
};

// Boot
// ==== PWA and web push (module 007) ====
// Ported from kraskiweb: same enable/mute/test flow and the same copyable
// diagnostics, because «уведомления не приходят» is otherwise unanswerable.
Object.assign(App, {
    pushReg: null,
    swError: null,
    lastPushError: null,
    deferredInstall: null,

    registerServiceWorker() {
        if (!('serviceWorker' in navigator)) return;
        navigator.serviceWorker.register('/sw.js').catch(e => { this.swError = e; });
        // A tapped notification steers the open tab. The worker navigates the
        // window itself when it may; when it may not, it asks us here instead.
        navigator.serviceWorker.addEventListener('message', (e) => {
            const d = e.data || {};
            if (d.type !== 'navigate' || !d.url) return;
            const at = String(d.url).indexOf('#');
            if (at < 0) return;
            const hash = String(d.url).slice(at);
            if (location.hash === hash) this.route(); else location.hash = hash;
        });
    },

    // Chrome fires this instead of installing on its own; keep it for the button
    watchInstallPrompt() {
        window.addEventListener('beforeinstallprompt', e => {
            e.preventDefault();
            this.deferredInstall = e;
            const card = document.getElementById('installCard');
            if (card) this.renderInstallCard();
        });
        window.addEventListener('appinstalled', () => { this.deferredInstall = null; });
    },

    pushSupported() {
        return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    },

    isStandalone() {
        return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
            || window.navigator.standalone === true;
    },

    isIos() {
        return /iphone|ipad|ipod/i.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    },

    // The endpoint the browser hands out rotates — refresh the server's copy on load
    async initPush() {
        if (!this.pushSupported()) return;
        try {
            this.pushReg = await navigator.serviceWorker.ready;
            if (Notification.permission === 'granted') {
                const sub = await this.pushReg.pushManager.getSubscription();
                if (sub) await this.api('push.php?action=subscribe', {method: 'POST', body: {subscription: sub.toJSON()}});
            }
        } catch { /* push is a bonus, never a blocker */ }
    },

    // Plain-Russian reason plus the exact API state — enough to answer a support call
    pushDiagnostics() {
        const L = [
            'Адрес: ' + location.href,
            'Протокол: ' + location.protocol + (window.isSecureContext ? ' (secure context: да)' : ' (secure context: НЕТ — это и есть причина)'),
            'serviceWorker: ' + ('serviceWorker' in navigator ? 'есть' : 'НЕТ'),
            'PushManager: ' + ('PushManager' in window ? 'есть' : 'НЕТ'),
            'Notification: ' + ('Notification' in window ? 'есть' : 'НЕТ'),
        ];
        if ('Notification' in window) L.push('Разрешение: ' + Notification.permission);
        L.push('Установлено как приложение: ' + (this.isStandalone() ? 'да' : 'нет'));
        if (this.swError) L.push('Ошибка регистрации Service Worker: ' + (this.swError.message || this.swError));
        if (this.lastPushError) L.push('Последняя ошибка push: ' + (this.lastPushError.message || this.lastPushError));
        L.push('User-Agent: ' + navigator.userAgent);
        return L.join('\n');
    },

    pushUnsupportedHint() {
        if (!window.isSecureContext) {
            return 'Сайт открыт без HTTPS (' + location.protocol + '). Браузер включает Service Worker, '
                 + 'установку приложения и push только по https:// — настройте сертификат на хостинге.';
        }
        if (this.swError) return 'Service Worker не зарегистрировался — подробности в диагностике ниже.';
        return 'Этот браузер не даёт Service Worker или Push API.';
    },

    diagBlock(title, text) {
        return `<details style="margin-top:10px">
            <summary style="cursor:pointer;color:var(--text-muted);font-size:13px">${this.esc(title)}</summary>
            <pre style="white-space:pre-wrap;font-size:12px;background:var(--bg);padding:8px;border-radius:6px;margin-top:6px">${this.esc(text)}</pre>
        </details>`;
    },

    async renderPushCard() {
        const card = document.getElementById('pushCard');
        if (!card) return;
        if (!this.pushSupported()) {
            card.innerHTML = `<div class="card__title">Уведомления на телефон</div>
                <p class="muted">${this.esc(this.pushUnsupportedHint())}</p>
                <p class="muted" style="font-size:12px">На iPhone сначала установите приложение на экран «Домой» (Поделиться → «На экран „Домой“»), затем включите уведомления отсюда.</p>
                ${this.diagBlock('Диагностика', this.pushDiagnostics())}`;
            return;
        }
        let subscribed = false;
        try {
            this.pushReg = this.pushReg || await navigator.serviceWorker.ready;
            subscribed = !!(await this.pushReg.pushManager.getSubscription());
        } catch { /* */ }

        let prefs = {kinds: {}, muted: [], devices: 0, available: false};
        try { prefs = await this.api('push.php?action=prefs'); } catch (e) { this.lastPushError = e; }

        const rows = Object.entries(prefs.kinds || {}).map(([k, label]) => `
            <label class="flex" style="gap:8px;align-items:center;padding:4px 0">
                <input type="checkbox" data-push-kind="${this.esc(k)}" ${(prefs.muted || []).includes(k) ? '' : 'checked'} ${subscribed ? '' : 'disabled'}>
                ${this.esc(label)}
            </label>`).join('');

        card.innerHTML = `
            <div class="card__title">Уведомления на телефон</div>
            <p class="muted" style="font-size:13px">Приходят о новых запросах, заказах и напоминаниях, даже когда браузер закрыт.
               Подписка действует на этом устройстве — включите её на каждом телефоне и компьютере.</p>
            ${prefs.available === false ? '<p class="no">Сервер не может отправлять push: проверьте «Настройки → Все параметры → Push-уведомления».</p>' : ''}
            <div class="flex" style="gap:8px;margin:10px 0">
                ${subscribed
                    ? '<button class="btn btn--outline btn--sm" onclick="App.disablePush()">Отключить на этом устройстве</button>'
                    : '<button class="btn btn--sm" onclick="App.enablePush()">🔔 Включить уведомления</button>'}
                <button class="btn btn--outline btn--sm" onclick="App.testPush(this)" ${subscribed ? '' : 'disabled'}>📨 Проверить</button>
            </div>
            <p class="muted" style="font-size:13px">${subscribed ? '✅ Подписка активна. Устройств у вас: ' + (prefs.devices || 0) : 'На этом устройстве подписка не включена.'}</p>
            <div style="margin-top:10px"><strong style="font-size:13px">Что присылать</strong>${rows}</div>
            ${this.lastPushError ? this.diagBlock('Ошибка push', String(this.lastPushError.message || this.lastPushError) + '\n\n' + this.pushDiagnostics()) : this.diagBlock('Диагностика', this.pushDiagnostics())}`;

        card.querySelectorAll('[data-push-kind]').forEach(cb => cb.onchange = async () => {
            try {
                await this.api('push.php?action=mute', {method: 'POST', body: {kind: cb.dataset.pushKind, muted: !cb.checked}});
            } catch (e) { this.toast(e.message, 'error'); cb.checked = !cb.checked; }
        });
    },

    async enablePush() {
        if (!this.pushSupported()) { this.toast('Браузер не поддерживает уведомления', 'error'); return; }
        try {
            const perm = await Notification.requestPermission();
            if (perm !== 'granted') {
                this.lastPushError = new Error('Разрешение не выдано (Notification.permission = ' + perm + ')');
                this.toast('Уведомления не разрешены', 'error');
                return this.renderPushCard();
            }
            this.pushReg = await navigator.serviceWorker.ready;
            let sub = await this.pushReg.pushManager.getSubscription();
            if (!sub) {
                const {key} = await this.api('push.php?action=key');
                sub = await this.pushReg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: this.urlB64ToBytes(key),
                });
            }
            await this.api('push.php?action=subscribe', {method: 'POST', body: {subscription: sub.toJSON()}});
            this.lastPushError = null;
            this.toast('Уведомления включены', 'success');
        } catch (e) {
            this.lastPushError = e;
            this.toast(e.message || 'Не удалось включить уведомления', 'error');
        }
        this.renderPushCard();
    },

    async disablePush() {
        try {
            this.pushReg = this.pushReg || await navigator.serviceWorker.ready;
            const sub = await this.pushReg.pushManager.getSubscription();
            if (sub) {
                await this.api('push.php?action=unsubscribe', {method: 'POST', body: {endpoint: sub.endpoint}}).catch(() => {});
                await sub.unsubscribe();
            }
            this.lastPushError = null;
            this.toast('Уведомления отключены на этом устройстве');
        } catch (e) { this.lastPushError = e; this.toast(e.message, 'error'); }
        this.renderPushCard();
    },

    async testPush(btn) {
        btn.disabled = true;
        try {
            const r = await this.api('push.php?action=test', {method: 'POST', body: {}});
            this.lastPushError = null;
            this.toast(r.sent > 0 ? 'Отправлено — уведомление должно появиться' : 'Нет активных подписок', r.sent > 0 ? 'success' : 'error');
        } catch (e) { this.lastPushError = e; this.toast(e.message, 'error'); this.renderPushCard(); }
        finally { btn.disabled = false; }
    },

    urlB64ToBytes(base64) {
        const pad = '='.repeat((4 - (base64.length % 4)) % 4);
        const raw = atob((base64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
        const out = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    },

    renderInstallCard() {
        const card = document.getElementById('installCard');
        if (!card) return;
        const standalone = this.isStandalone();
        let verdict;
        if (standalone) verdict = '✅ Приложение уже установлено — вы работаете в нём.';
        else if (!window.isSecureContext) verdict = '❌ Установка возможна только по https:// — настройте сертификат на хостинге.';
        else if (this.deferredInstall) verdict = '✅ Можно установить прямо сейчас.';
        else if (this.isIos()) verdict = 'ℹ️ На iPhone/iPad: «Поделиться» → «На экран „Домой“».';
        else verdict = 'ℹ️ Системное предложение ещё не появилось. Меню браузера → «Установить приложение».';

        card.innerHTML = `
            <div class="card__title">Приложение на телефоне</div>
            <p class="muted" style="font-size:13px">Панель ставится на экран «Домой» и открывается как обычное приложение — без адресной строки и с уведомлениями.</p>
            <p>${this.esc(verdict)}</p>
            ${standalone ? '' : `<div class="flex" style="gap:8px;margin-top:8px">
                <button class="btn btn--sm" onclick="App.triggerInstall()" ${this.deferredInstall ? '' : 'disabled'}>📲 Установить</button>
                <button class="btn btn--outline btn--sm" onclick="App.renderInstallCard()">Обновить</button>
            </div>`}
            ${this.diagBlock('Диагностика установки', this.pushDiagnostics())}`;
    },

    async triggerInstall() {
        if (!this.deferredInstall) return;
        this.deferredInstall.prompt();
        try { await this.deferredInstall.userChoice; } catch { /* */ }
        this.deferredInstall = null;
        this.renderInstallCard();
    },
});

document.addEventListener('DOMContentLoaded', () => App.init());
