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

    /**
     * Всплывающее сообщение в правом верхнем углу (модуль 029).
     *
     * Ошибку нельзя показать на четыре секунды и убрать: пока её прочтут,
     * её уже нет, а переписать текст в письмо разработчику не с чего.
     * Поэтому ошибка ЖДЁТ: она висит, пока на неё не наведут курсор, —
     * наведение и есть «я вижу». Нажатие копирует текст целиком в буфер и
     * только после этого её закрывает. Успех и подсказки ведут себя как
     * раньше и гаснут сами, но наведение курсора останавливает и их таймер:
     * сообщение, которое исчезает из-под читающего, — то же самое зло.
     */
    toast(msg, type = 'info') {
        const text = String(msg ?? '');
        const el = document.createElement('div');
        el.className = `toast toast--${type}`;
        const sticky = type === 'error';
        el.innerHTML = `<span class="toast__text"></span>`;
        el.querySelector('.toast__text').textContent = text;

        let timer = null;
        const close = () => { clearTimeout(timer); el.remove(); };
        const arm = ms => { clearTimeout(timer); timer = setTimeout(close, ms); };

        // Наведение держит сообщение на экране; убрали курсор — оно уходит
        // (issue #60). У ошибки таймера на появление нет вовсе, пока её не
        // навели курсором хотя бы раз — не пропустить.
        el.addEventListener('mouseenter', () => { clearTimeout(timer); el.dataset.seen = '1'; });
        el.addEventListener('mouseleave', () => arm(sticky ? 300 : 2000));

        document.getElementById('toasts').appendChild(el);
        if (!sticky) arm(4000);
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

    /** Дата строки списка, как в Gmail: сегодня — время, этот год — «12 сен», раньше — 12.09.24. */
    fmtShort(v) {
        if (!v) return '';
        const d = new Date(String(v).replace(' ', 'T'));
        if (isNaN(d)) return this.esc(v);
        const now = new Date();
        if (d.toDateString() === now.toDateString()) {
            return d.toLocaleTimeString('ru-RU', {hour: '2-digit', minute: '2-digit'});
        }
        if (d.getFullYear() === now.getFullYear()) {
            return d.toLocaleDateString('ru-RU', {day: 'numeric', month: 'short'}).replace('.', '');
        }
        return d.toLocaleDateString('ru-RU', {day: '2-digit', month: '2-digit', year: '2-digit'});
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
        this.watchBlocks();
        this.bindA11y();
        this.bindMenus();
        this.watchInstallPrompt();
        try {
            this.manager = await this.api('auth.php?action=me');
            this.renderNav();
            this.initPush();
            this.loadCategories();
            this.loadUiPrefs();
            this.startPolling();
            this.startMailPolling();
            // Первый заход администратора на ненастроенный сервис — в мастер,
            // а не на пустую доску (модуль 038). Открытую закладку не трогаем.
            if (this.manager.setup_pending && !location.hash) location.hash = 'settings/setup';
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
            <!-- Всегда на виду, справа вверху шапки (issue #60) -->
            <button class="btn btn--outline btn--sm header__support" onclick="App.supportModal()"
                    title="Написать в поддержку: что сломалось или чего не хватает на этом экране"
                    >✉<span class="header__support-text"> Написать в поддержку</span></button>
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
            const r = await this.api('mail.php?action=sync', {method: 'POST', body: {}});
            const list = await this.api('mail.php?action=list&limit=1');
            const now = Number(list.unread || 0);
            this.unreadMail = now;
            const badge = document.getElementById('mailBadge');
            if (badge) badge.innerHTML = now > 0 ? ` <span class="pill">${now}</span>` : '';
            if (!first && before !== null && now > before) {
                this.playMailSound();
                this.toast(`Новых писем: ${now - before}`, 'info');
            }
            // Доска нарисовалась ДО того, как синхронизация закончилась, и о
            // забранном не знает — в том числе о письме, отправленном с телефона:
            // непрочитанных оно не добавляет, а карточка после него уже другая.
            // Открытую переписку не трогаем: там может быть начатый ответ.
            const pulled = (r.report || []).some(x => Number(x.in || 0) + Number(x.out || 0) > 0);
            if (pulled && /^mail(\/(board|list)(\/\d+)?)?$/.test(location.hash.slice(1) || 'mail')) this.route();
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
        // Запрос открытого письма — свойство экрана, а не сессии: с ним уезжали
        // бы чужие счета под полем ответа и чужая серость в ленте (модуль 029)
        this.companyRequestId = '';
        this.closeModal();
        // Гайд и подсказка прошлого экрана не висят над новым (модуль 050)
        if (this.tourSteps) this.tourEnd(); else this.closeHint();
        // Доска канбан идёт во всю ширину экрана: пять колонок в 1280px не
        // помещались и уезжали в горизонтальную прокрутку (модуль 023)
        const wide = page === 'mail' && (!params[0] || params[0] === 'board' || params[0] === 'list');
        const main = app.closest('.main') || document.querySelector('.main');
        if (main) main.classList.toggle('main--wide', wide);

        const open = () => {
            switch (page) {
                case 'mail': {
                    const seg = params[0] || '';
                    // Архив — тот же экран «Письма», переключённый ссылкой «Архив»
                    if ((!seg || seg === 'board' || seg === 'list') && (this.boardFilters || {}).archived) {
                        return this.pageBoardArchive();
                    }
                    // Список как в Gmail или доска (модуль 050); голый #mail — тот
                    // вид, которым пользовались на этом устройстве последним
                    if (seg === 'list' || (!seg && this.mailView() === 'list')) return this.pageMailList(params[1] || '');
                    if (!seg || seg === 'board') return this.pageMailBoard();
                    // Отдельной страницы «Архив писем» больше нет: она была
                    // вторым почтовым клиентом рядом с доской — со своим
                    // поиском, своими вкладками и всеми письмами подряд.
                    // Старая закладка открывает доску с включённым архивом.
                    // Старая закладка открывает доску В РАБОТЕ, а не архив
                    // (модуль 040): архив включался здесь сам, и после удаления
                    // или архивации письма экран уезжал в «Письма · архив» —
                    // человек нажимал «в архив», а попадал в чужую комнату.
                    if (seg === 'inbox') { location.replace('#mail'); return; }
                    // Корзина писем (модуль 040): удалённое письмо возвращается
                    if (seg === 'trash') return this.pageMailTrash();
                    if (seg === 't') return this.pageMailThread(decodeURIComponent(params[1] || ''));
                    if (seg === 'msg') return this.pageMailMessage(params[1]);
                    // Отдельного списка запросов больше нет: всё открывается с
                    // доски, чтобы один и тот же запрос не жил на двух экранах
                    // с разной вёрсткой (модуль 023)
                    if (seg === 'requests') { location.replace('#mail'); return; }
                    // «#mail/new/trial» — проверочный запрос мастера настройки (модуль 038)
                    if (seg === 'new') return this.pageNewRequest(params[1] === 'trial'
                        ? {trial: true, text: this.trialText || ''} : {});
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
                case 'settings': return this.pageSettings(params[0], params[1]);
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
                default: return (this.boardFilters || {}).archived
                    ? this.pageBoardArchive() : this.pageMailBoard();
            }
        };
        // Whatever the page throws, the user sees the reason and a retry button —
        // never a spinner that spins forever
        Promise.resolve().then(open)
            // Карточка проверочного запроса: полоска с оценкой качества (модуль 038)
            .then(() => this.trialStrip(hash))
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

    // ==== Одна шапка экрана и меню «⋯» (модуль 050) ====

    /**
     * Шапка внутреннего экрана: ссылка назад над заголовком, справа действия
     * (главное — последним и залитым), редкие и опасные — в меню «⋯».
     */
    pageHead({back = '#mail', backLabel = '← Письма', title = '', after = '', meta = '', actions = '', menu = []} = {}) {
        return `
            <div class="pagehead">
                <div class="pagehead__main">
                    ${back ? `<a class="pagehead__back" href="${this.esc(back)}">${this.esc(backLabel)}</a>` : ''}
                    <div class="pagehead__line">
                        <h2 class="pagehead__title">${title}</h2>${after}
                    </div>
                    ${meta ? `<div class="pagehead__meta">${meta}</div>` : ''}
                </div>
                <div class="pagehead__actions">${actions}${this.menuHtml(menu)}</div>
            </div>`;
    },

    /**
     * Меню «⋯»: `<details>`, так что открывается с клавиатуры само. Пункты
     * `{label, href|onclick, title, danger}`; опасные — в конце, после черты.
     */
    menuHtml(items, {label = '⋯', aria = 'Ещё действия'} = {}) {
        items = (items || []).filter(Boolean);
        if (!items.length) return '';
        const safe = items.filter(i => !i.danger), danger = items.filter(i => i.danger);
        const item = i => {
            const cls = 'menu__item' + (i.danger ? ' menu__item--danger' : '');
            const title = i.title ? ` title="${this.esc(i.title)}"` : '';
            return i.href
                ? `<a role="menuitem" class="${cls}" href="${this.esc(i.href)}"${i.blank ? ' target="_blank" rel="noopener"' : ''}${title}>${this.esc(i.label)}</a>`
                : `<button type="button" role="menuitem" class="${cls}"${title}
                           onclick="this.closest('details').open=false;${i.onclick}">${this.esc(i.label)}</button>`;
        };
        return `
            <details class="menu">
                <summary class="btn btn--outline btn--sm menu__btn" aria-label="${this.esc(aria)}" title="${this.esc(aria)}">${this.esc(label)}</summary>
                <div class="menu__list" role="menu">
                    ${safe.map(item).join('')}
                    ${safe.length && danger.length ? '<div class="menu__sep" role="separator"></div>' : ''}
                    ${danger.map(item).join('')}
                </div>
            </details>`;
    },

    /** Открытое меню закрывается щелчком мимо и Escape. */
    bindMenus() {
        document.addEventListener('click', e => {
            document.querySelectorAll('details.menu[open]').forEach(m => {
                if (!m.contains(e.target)) m.open = false;
            });
        });
        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            document.querySelectorAll('details.menu[open]').forEach(m => {
                m.open = false;
                m.querySelector('summary')?.focus();
            });
        });
    },

    /**
     * Текстовые действия `<a onclick>` без `href` не получали фокус с
     * клавиатуры вовсе. Один наблюдатель делает их кнопками: Tab, Enter, пробел.
     */
    bindA11y() {
        const fix = root => root.querySelectorAll?.('a[onclick]:not([href]):not([tabindex])').forEach(a => {
            a.tabIndex = 0;
            a.setAttribute('role', 'button');
        });
        fix(document);
        new MutationObserver(list => list.forEach(m => m.addedNodes.forEach(n => {
            if (n.nodeType === 1) { fix(n); if (n.matches?.('a[onclick]:not([href]):not([tabindex])')) fix(n.parentNode); }
        }))).observe(document.body, {childList: true, subtree: true});
        document.addEventListener('keydown', e => {
            const a = e.target;
            if ((e.key === 'Enter' || e.key === ' ') && a.matches?.('a[role="button"]:not([href])')) {
                e.preventDefault();
                a.click();
            }
        });
    },

    // ==== «Письма»: one board, and nothing else to switch between ====
    // Companies, requests and letters used to be three lists that had to be
    // cross-checked by hand (module 011). They are one object now — a company
    // card on the board — so the section has no tabs at all: the board IS the
    // page. The old lists stay reachable by URL for a link somebody saved, and
    // each of them opens with a way back to the board.
    mailShellHtml(active, extra = '') {
        // Архив — это та же доска, открытая пунктом «Архив», отсюда и заголовок
        const archived = !!(this.boardFilters || {}).archived;
        const body = '<div id="mailBody"><div class="loading">Загрузка...</div></div>';
        if (active === 'board' || active === 'list') {
            return `
                <div class="pagehead pagehead--mail">
                    <div class="pagehead__main">
                        ${archived ? `<a class="pagehead__back" href="#mail"
                            onclick="App.setBoardFilter('archived', 0); return false;">← Письма</a>` : ''}
                        <div class="pagehead__line">
                            <h2 class="pagehead__title">${archived ? 'Архив' : 'Письма'}${this.hint('board')}</h2>
                            ${archived ? '' : this.mailViewTabs(active)}
                        </div>
                    </div>
                    <div class="pagehead__actions">${extra}</div>
                </div>
                ${body}`;
        }
        const titles = {trash: 'Корзина', requests: 'Запросы', companies: 'Компании'};
        return this.pageHead({title: titles[active] || 'Письма', actions: extra}) + body;
    },

    /** «Список | Доска» — два вида одних и тех же карточек (модуль 050). */
    mailViewTabs(active) {
        const tab = (v, label) => `<a href="#mail/${v}" class="vtab ${active === v ? 'vtab--active' : ''}"
            ${active === v ? 'aria-current="page"' : ''}>${label}</a>`;
        return `<nav class="vtabs" aria-label="Вид писем">${tab('list', '☰ Список')}${tab('board', '▦ Доска')}</nav>`;
    },

    mailView() {
        try { return localStorage.getItem('mailView') === 'list' ? 'list' : 'board'; } catch { return 'board'; }
    },

    setMailView(v) {
        try { localStorage.setItem('mailView', v); } catch { /* приватный режим — вид просто не запомнится */ }
    },

    /** Поиск, «Забрать почту», «Написать» и меню «Ещё» — одни на оба вида. */
    mailToolbarHtml(view) {
        const archived = !!(this.boardFilters || {}).archived;
        return `
            <span class="searchbox" role="search">
                <input type="text" id="boardFilter" value="${this.esc(this.boardQuery || '')}"
                       placeholder="${archived ? 'Поиск по архиву' : 'Поиск в почте'}: тема, адрес, текст, файл…"
                       aria-label="Поиск по письмам"
                       title="Ищет по всей почте: темам, телу писем, адресам, именам и вложениям — включая архив"
                       oninput="App.${archived ? 'archiveSearch' : 'boardFilter'}(this.value)">
                <button type="button" class="searchbox__x" title="Очистить поиск" aria-label="Очистить поиск"
                        onclick="App.${archived ? 'archiveSearchClear' : 'boardFilterClear'}()">×</button>
            </span>
            <button class="btn btn--outline btn--sm btn--icon" onclick="App.boardSync()"
                    aria-label="Забрать почту" title="Забрать почту">⟳</button>
            <button class="btn btn--primary btn--sm" onclick="App.mailCompose()">✉ Написать</button>
            ${this.menuHtml([
                {href: '#mail/new', label: '+ Запрос не из почты',
                 title: 'Запрос пришёл в мессенджер или по телефону — вставьте текст и файлы'},
                view === 'board' && !archived ? {onclick: 'App.boardAddColumn()', label: '+ Колонка'} : null,
                archived ? {onclick: "App.setBoardFilter('archived', 0)", label: '← К письмам'}
                         : {onclick: "App.setBoardFilter('archived', 1)", label: '🗄 Архив',
                            title: 'Переписки, убранные как «не наш профиль»'},
                {href: '#mail/trash', label: '🗑 Корзина', title: 'Удалённые письма — их можно вернуть'},
            ], {label: 'Ещё ▾'})}`;
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

    /**
     * Запрос, который пришёл мимо почты (модуль 038).
     *
     * Ссылка на этот экран стоит НАД «Входящими» на доске: запрос из мессенджера,
     * с телефона или файлом на почту коллеге приходит чаще, чем кажется, и до
     * сих пор его перепечатывали руками, теряя вложение.
     *
     * Поле принимает и текст, и файлы — спецификацию, фотографию переписки,
     * скан. Созданный запрос кладётся карточкой в «В работе» и открывается
     * сразу: разбирать его всё равно сейчас.
     */
    pageNewRequest(opts = {}) {
        this.newReqFiles = [];
        document.getElementById('app').innerHTML = `
            ${this.pageHead({title: 'Новый запрос',
                meta: 'Запрос пришёл не почтой — из мессенджера, по телефону, файлом. Карточка встанет в «В работе».'})}
            ${opts.trial ? `
            <div class="card card--alert">
                <div class="card__title">Проверочный запрос</div>
                <p>Текст ниже — пример. Создайте по нему КП и письмо и вернитесь
                   в <a href="#settings/setup">мастер настройки</a>, чтобы оценить качество.</p>
            </div>` : ''}
            <div class="card">
                <form id="newRequestForm">
                    <div class="form-group">
                        <label for="reqText">Текст запроса</label>
                        <textarea id="reqText" rows="8" placeholder="Вставьте текст запроса из мессенджера, email или заметок…"
                                  aria-describedby="reqTextErr" oninput="App.fieldError('reqText', '')"
                                  onpaste="App.newRequestPaste(event)">${this.esc(opts.text || '')}</textarea>
                        <div class="field-error" id="reqTextErr" role="alert"></div>
                        <div class="muted">Скриншот можно вставить прямо сюда — Ctrl+V.</div>
                    </div>
                    <div class="form-group">
                        <label for="reqFiles">Файлы <span class="optional">необязательно</span></label>
                        <input type="file" id="reqFiles" multiple onchange="App.newRequestAttach(this)">
                        <div class="flex flex--wrap" id="reqFileList" style="gap:6px;margin-top:6px"></div>
                        <div class="muted">Спецификация, фотография переписки, скан — текст из них уходит в подбор позиций.</div>
                    </div>
                    <div class="form-group">
                        <label for="reqCounterparty">Контрагент (название организации) <span class="optional">необязательно</span></label>
                        <input type="text" id="reqCounterparty" placeholder="ООО Ромашка — пусто: сервис определит из текста">
                    </div>
                    <input type="hidden" id="reqTrial" value="${opts.trial ? '1' : ''}">
                    <button type="submit" class="btn btn--primary btn--block">Создать карточку в «В работе»</button>
                </form>
            </div>
        `;
        document.getElementById('newRequestForm').onsubmit = (e) => { e.preventDefault(); this.submitNewRequest(e.target); };
        // Проверочный текст открыли по закладке, мимо мастера — спросим его у сервера
        if (opts.trial && !(opts.text || '').trim()) this.fillTrialText();
    },

    /** Ошибка поля — под самим полем, а не всплывающим сообщением в углу (модуль 050). */
    fieldError(id, text) {
        const field = document.getElementById(id);
        const out = document.getElementById(id + 'Err');
        if (out) out.textContent = text;
        if (field) {
            field.toggleAttribute('aria-invalid', !!text);
            if (text) field.focus();
        }
    },

    async fillTrialText() {
        try {
            const d = await this.api('setup.php?action=state');
            const box = document.getElementById('reqText');
            if (box && !box.value.trim()) box.value = d.trial_text || '';
        } catch { /* мастер админский — менеджер напишет свой текст */ }
    },

    async submitNewRequest(form) {
        const text = document.getElementById('reqText').value.trim();
        const files = this.newReqFiles || [];
        if (!text && !files.length) {
            return this.fieldError('reqText', 'Вставьте текст запроса или приложите файл ниже — без них подбирать нечего.');
        }
        const trial = document.getElementById('reqTrial').value === '1';
        const btn = form.querySelector('button[type=submit]');
        btn.disabled = true; btn.textContent = 'Обработка…';
        try {
            const r = await this.api('requests.php?action=create', {method: 'POST', body: {
                text,
                counterparty_name: document.getElementById('reqCounterparty').value.trim(),
                files: files.map(f => f.name),
                trial,
            }});
            this.toast('Карточка в «В работе» создана', 'success');
            // Проверочный запрос ведёт назад в мастер — за оценкой
            if (trial) this.trialWatch(r.hash);
            location.hash = r.hash || ('mail/request/' + r.id);
        } catch (err) {
            this.toast(err.message, 'error');
            btn.disabled = false; btn.textContent = 'Создать карточку в «В работе»';
        }
    },

    /** Файлы формы живут там же, где вложения письма, — в папке менеджера. */
    async newRequestAttach(input) {
        for (const file of [...(input.files || [])]) await this.newRequestUpload(file);
        input.value = '';
    },

    /** Скриншот из буфера — обычный файл, просто без имени. */
    newRequestPaste(ev) {
        const items = [...((ev.clipboardData || {}).items || [])].filter(i => i.kind === 'file');
        if (!items.length) return;
        ev.preventDefault();
        items.forEach(i => {
            const file = i.getAsFile();
            if (file) this.newRequestUpload(file);
        });
    },

    async newRequestUpload(file) {
        const fd = new FormData();
        fd.append('file', file, file.name || ('снимок-' + Date.now() + '.png'));
        try {
            const res = await fetch('/api/mail.php?action=upload', {method: 'POST', body: fd, credentials: 'same-origin'});
            const d = await res.json();
            if (!res.ok || d.error) throw new Error(d.error || 'Файл не загрузился');
            (this.newReqFiles = this.newReqFiles || []).push(d.file);
            this.drawNewRequestFiles();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    drawNewRequestFiles() {
        const box = document.getElementById('reqFileList');
        if (!box) return;
        box.innerHTML = (this.newReqFiles || []).map((f, i) => `
            <span class="chip">📎 ${this.esc(f.filename)}
                <a onclick="App.newRequestDrop(${i})" title="Убрать">×</a></span>`).join('');
    },

    newRequestDrop(i) {
        (this.newReqFiles || []).splice(i, 1);
        this.drawNewRequestFiles();
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
            ${this.pageHead({
                title: `${isOrder ? 'Заказ' : 'Запрос'} #${req.id}`,
                after: this.typeBadge(req.type),
                actions: `
                    ${!req.manager_id ? `<button class="btn btn--outline" onclick="App.assignRequest(${req.id})">Взять в работу</button>` : ''}
                    ${req.mail_message_id ? `<button class="btn btn--outline" onclick="App.mailCompose(${req.mail_message_id}, true)">Создать ответ</button>` : ''}
                    ${actions}`,
            })}

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
        this.renderMatchedItems(req.id, req.items || [], null,
                                {delivery: req.delivery, conditions: req.conditions, price_types: req.price_types});
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
        host.dataset.block = 'items';
        host.dataset.requestId = requestId;
        // Доставка живёт на блоке подбора: перерисовка строк её не теряет
        if (opts.delivery) host.dataset.delivery = JSON.stringify(opts.delivery);
        else if (host.dataset.delivery) opts = {...opts, delivery: JSON.parse(host.dataset.delivery)};
        // Общие условия КП — тоже на блоке: они переживают перерисовку строк и
        // приезжают с сервера уже теми, какими менеджер закрыл прошлое КП
        if (opts.conditions) host.dataset.conditions = JSON.stringify(opts.conditions);
        if (opts.price_types) host.dataset.priceTypes = JSON.stringify(opts.price_types);
        // КП запроса — на блоке: кнопки и счета под таблицей читают его отсюда
        if (opts.kp) host.dataset.kp = JSON.stringify(opts.kp);
        const open = items.filter(i => i.needs_choice).length;
        host.classList.toggle('card--folded', host.dataset.folded === '1');
        host.innerHTML = `
            <div class="card__title">Подходящие позиции ${opts.kp ? `<span class="muted">запрос #${requestId}</span>` : ''}
                ${this.hint('match')}
                <button class="btn btn--outline btn--sm card__fold" onclick="App.toggleMatchFold(this)"
                        title="Свернуть или развернуть подбор">${host.dataset.folded === '1'
                            ? `▸ Развернуть (позиций: ${items.length})` : '▾ Свернуть'}</button></div>
            <p class="muted">Подбираются сами при открытии карточки. Начните печатать название —
               подскажет локальная база товаров.</p>
            ${open ? `<div class="note note--choice">Равнозначных вариантов: <strong>${open}</strong> —
                выберите нужный, автоподбор сам не решает.</div>` : ''}
            <!-- Кнопки подбора стоят НАД общими условиями (issue #60): сначала
                 собирают позиции, потом назначают на них цены и сроки -->
            <div class="flex flex--wrap" style="margin-bottom:8px">
                <button class="btn btn--outline btn--sm" onclick="App.addMatchRow(this)">+ Позиция</button>
                <button class="btn btn--outline btn--sm" onclick="App.rematchItems(this, false)">Подобрать по каталогу</button>
                <button class="btn btn--outline btn--sm" onclick="App.rematchItems(this, true)"
                        title="Нейросеть сначала приведёт формулировки клиента к нашим названиям — это один запрос к модели">Подобрать нейросетью</button>
            </div>
            <div data-conditions>${this.conditionsPanel(host)}</div>
            <div data-match-rows>${items.map((i, n) => this.matchRow(i, n)).join('')}</div>
            ${items.length ? '' : '<p class="muted" data-match-empty>Пока пусто — добавьте позицию или подберите по каталогу.</p>'}
            <div data-delivery>${this.deliveryRow(opts.delivery)}</div>
            <div class="flex flex--wrap" style="margin-top:10px">
                <!-- «Сохранить» больше нет: правки сохраняются сами (issue #60) -->
                <span class="muted" data-match-saved></span>
                <span data-kp-buttons class="flex flex--wrap">${this.matchKpButton(requestId, opts.kp || {})}</span>
            </div>
            <div data-kp-invoices class="muted" style="margin-top:6px"></div>
            <div data-match-total class="muted" style="margin-top:8px"></div>
            <div data-kp-slot></div>
        `;
        this.updateMatchTotal(host);
        this.bindMatchDnd(host);
        this.bindMatchAutosave(host);
        this.watchMatchPhotos(host);
        this.loadKpSummary(requestId, host);
    },

    /**
     * ==== Блоки письма (issue #67) ====
     *
     * Переписка, подбор, информация, заметки и счета МойСклад сворачиваются
     * кнопкой в заголовке. Положение запоминается у КАЖДОГО письма
     * (`localStorage['kp.fold.<ключ переписки>']`); «Информация» на телефоне
     * свёрнута всегда. У каждого блока свой оттенок — `[data-block]` в CSS.
     * Подбор сворачивается своим механизмом (`toggleMatchFold`), здесь только
     * запоминается.
     */
    FOLDABLE: ['thread', 'info', 'events', 'ms'],
    /** Блоки, которые на десктопе живут вкладками у правого края (модуль 049). */
    RAIL: ['info', 'events'],

    isPhone() {
        return window.matchMedia && window.matchMedia('(max-width: 640px)').matches;
    },

    foldStore() {
        try { return JSON.parse(localStorage.getItem('kp.fold.' + (this.foldKey || '')) || '{}') || {}; }
        catch { return {}; }
    },

    /** true — свёрнут, false — раскрыт, null — у этого письма не трогали. */
    foldGet(name) {
        if (name === 'info' && this.isPhone()) return true;
        // Десктоп: вкладки справа всегда свёрнуты (модуль 049)
        if (this.RAIL.includes(name) && !this.isPhone()) return true;
        const st = this.foldStore();
        return name in st ? !!st[name] : null;
    },

    foldSet(name, folded) {
        if (!this.foldKey || (this.RAIL.includes(name) && !this.isPhone())) return;
        const st = this.foldStore();
        st[name] = folded ? 1 : 0;
        try { localStorage.setItem('kp.fold.' + this.foldKey, JSON.stringify(st)); } catch { /* не запомним */ }
    },

    /** Письмо, чьё положение блоков сейчас на экране; сменилось — блоки перечитываются. */
    setFoldKey(key) {
        this.foldKey = key || '';
        this.bindBlocks(document, true);
    },

    /** Кнопка свернуть в заголовке каждого блока; $reapply — выставить сохранённое заново. */
    bindBlocks(root = document, reapply = false) {
        root.querySelectorAll('[data-block]').forEach(el => {
            const name = el.dataset.block;
            if (!this.FOLDABLE.includes(name)) return;
            const head = el.querySelector(':scope > [data-block-head], :scope > .card__title');
            if (!head) return;
            if (!head.querySelector(':scope > .block-fold')) {
                head.classList.add('block-head');
                head.insertAdjacentHTML('beforeend',
                    '<button type="button" class="block-fold" onclick="App.toggleBlock(this)"></button>');
            } else if (!reapply && el.dataset.foldBound === '1') {
                return;
            }
            el.dataset.foldBound = '1';
            this.applyBlockFold(el, !!this.foldGet(name));
        });
    },

    applyBlockFold(el, folded) {
        el.classList.toggle('is-folded', folded);
        const btn = el.querySelector(':scope > .block-head > .block-fold');
        if (btn) {
            btn.textContent = folded ? '▸' : '▾';
            btn.title = folded ? 'Развернуть' : 'Свернуть';
            btn.setAttribute('aria-expanded', folded ? 'false' : 'true');
        }
    },

    toggleBlock(btn) {
        const el = btn.closest('[data-block]');
        if (!el) return;
        const folded = !el.classList.contains('is-folded');
        // Шторка одна: открыли вкладку — соседняя закрывается
        if (!folded && this.RAIL.includes(el.dataset.block) && !this.isPhone()) {
            this.railClose(el);
            this.bindRailClose();
        }
        this.applyBlockFold(el, folded);
        this.foldSet(el.dataset.block, folded);
    },

    /** Нажатие по свёрнутой вкладке справа открывает её целиком. */
    railOpen(ev, el) {
        if (this.isPhone() || !el.classList.contains('is-folded')) return;
        if (ev.target.closest('.block-fold, .hint')) return;
        const btn = el.querySelector(':scope > .block-head > .block-fold');
        if (btn) this.toggleBlock(btn);
    },

    /** Свернуть открытые вкладки справа, кроме $keep. */
    railClose(keep = null) {
        document.querySelectorAll('#companySide > [data-block]:not(.is-folded)').forEach(el => {
            if (el !== keep) this.applyBlockFold(el, true);
        });
    },

    /** Esc и нажатие мимо шторки закрывают её; окна и подсказки не в счёт. */
    bindRailClose() {
        if (this._railBound) return;
        this._railBound = true;
        document.addEventListener('keydown', e => { if (e.key === 'Escape') this.railClose(); });
        document.addEventListener('mousedown', e => {
            if (this.isPhone() || !e.target.isConnected) return;
            if (e.target.closest('#companySide, .modal, .hint-bubble, .toast-container')) return;
            this.railClose();
        });
    },

    /** Блоки появляются в разметке в разное время — привязываем их по мере появления. */
    watchBlocks() {
        if (this._blockObserver || !('MutationObserver' in window)) return;
        let queued = false;
        this._blockObserver = new MutationObserver(() => {
            if (queued) return;
            queued = true;
            requestAnimationFrame(() => { queued = false; this.bindBlocks(); });
        });
        this._blockObserver.observe(document.body, {childList: true, subtree: true});
    },

    /** Свернуть/развернуть подбор — строки остаются в DOM, автосохранение живо. */
    toggleMatchFold(btn) {
        const host = this.matchHost(btn);
        if (!host) return;
        const folded = host.dataset.folded !== '1';
        host.dataset.folded = folded ? '1' : '0';
        host.classList.toggle('card--folded', folded);
        this.foldSet('items', folded);
        const rows = host.querySelectorAll('[data-match-row]').length;
        btn.textContent = folded ? `▸ Развернуть (позиций: ${rows})` : '▾ Свернуть';
    },

    /**
     * ==== Цены и условия на всё КП (модуль 036) ====
     *
     * «Розница и минус десять на то, что под заказ» — это решение на всё
     * предложение, а не на строку. Выставлялось оно в каждой строке по
     * отдельности, и следующее КП начиналось с той же работы заново. Здесь это
     * один выбор, и стоит он НАД таблицей — там, где принимают решение, а не
     * там, где его сорок раз повторяют.
     *
     * Выбор запоминается за менеджером: следующее КП открывается тем, чем
     * закрылось предыдущее. Поменял скидку — с этого места запомнена новая.
     */
    conditionsPanel(host) {
        const c = host && host.dataset.conditions ? JSON.parse(host.dataset.conditions) : null;
        if (!c) return '';
        const types = host.dataset.priceTypes ? JSON.parse(host.dataset.priceTypes) : [];
        return `
            <div class="conditions">
                <div class="conditions__title">Цены и условия — на все позиции${this.hint('kp-conditions')}</div>
                <div class="conditions__row">
                    <label>тип цены
                        <select data-cond="price_type">
                            <option value="">как в настройках</option>
                            ${types.map(t => `<option value="${this.esc(t)}" ${t === c.price_type ? 'selected' : ''}>${this.esc(t)}</option>`).join('')}
                        </select>
                    </label>
                    <label title="Скидка на каждую позицию подбора">скидка
                        <input type="number" step="0.01" min="0" max="100" data-cond="discount" value="${Number(c.discount) || 0}">%
                    </label>
                    <label title="Условия ниже получат только позиции, которых нет на складе">
                        <input type="checkbox" data-cond="wait_on" ${Number(c.wait_on) === 1 ? 'checked' : ''}
                               onchange="App.toggleWaitFields(this)"> под заказ
                    </label>
                    <span class="match-extra__wait" ${Number(c.wait_on) === 1 ? '' : 'hidden'}>
                        <label>ждать
                            <input type="number" min="0" max="120" data-cond="wait_months" value="${Number(c.wait_months) || 0}"> мес.
                        </label>
                        <label>за ожидание −
                            <input type="number" step="0.01" min="0" max="100" data-cond="wait_discount" value="${Number(c.wait_discount) || 0}">%
                        </label>
                        <label>предоплата
                            <input type="number" min="0" max="100" data-cond="wait_prepay" value="${Number(c.wait_prepay) || 0}">%
                        </label>
                    </span>
                    <label title="Сколько фотографий печатать у каждой позиции. Пусто — сколько разрешают настройки КП">фото
                        <input type="number" min="0" max="12" data-cond="photos" style="width:4.5em"
                               placeholder="как в настройках" value="${c.photos === null || c.photos === undefined ? '' : Number(c.photos)}">
                    </label>
                    <button class="btn btn--outline btn--sm" onclick="App.applyConditions(this)"
                            title="Проставить выбранное всем позициям и запомнить для следующих КП">Применить ко всем</button>
                </div>
            </div>`;
    },

    /** Что выбрано в панели условий. */
    collectConditions(host) {
        const box = host && host.querySelector('[data-conditions]');
        if (!box) return null;
        const out = {};
        box.querySelectorAll('[data-cond]').forEach(el => {
            out[el.dataset.cond] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
        });
        return Object.keys(out).length ? out : null;
    },

    /**
     * Проставить общие условия всем строкам — и запомнить их за менеджером.
     *
     * Цены пересчитывает СЕРВЕР: только он знает, что модификация без цены
     * берёт цену товара, а товар без цены — вилку по модификациям. Строку с
     * ценой, вписанной руками, он не трогает.
     */
    async applyConditions(btn) {
        const host = this.matchHost(btn);
        const conditions = this.collectConditions(host);
        if (!host || !conditions) return;
        const requestId = Number(host.dataset.requestId);
        btn.disabled = true;
        try {
            // Та же защита, что и у смены «не наша номенклатура» — «применить ко
            // всем» тоже перерисовывает таблицу целиком (issue #60)
            await this.api(`requests.php?action=items_save&id=${requestId}`, {
                method: 'POST', body: {items: this.collectMatchedItems(host), delivery: this.collectDelivery(host)},
            });
            const r = await this.api(`requests.php?action=items_conditions&id=${requestId}`,
                                     {method: 'POST', body: {conditions, apply: 1}});
            host.dataset.conditions = JSON.stringify(r.conditions || conditions);
            this.renderMatchedItems(requestId, r.items || [], host, this.matchOpts(host));
            this.toast('Условия проставлены и запомнены для следующих КП', 'success');
        } catch (err) {
            this.toast(err.message, 'error');
        } finally {
            btn.disabled = false;
        }
    },

    /**
     * ==== Доставка строкой подбора (модуль 034) ====
     *
     * Доставка правилась полем в «Настройках КП» — за двумя переходами от
     * таблицы подбора, — и про неё забывали: КП уходило с оговоркой «доставка
     * считается отдельно» и без единой цифры. Здесь это обычная строка под
     * позициями. Она стоит там с самого начала и убирается крестиком, как
     * любая другая; убранная — не печатается ни строкой, ни рублём в «Итого».
     */
    deliveryRow(delivery) {
        const d = delivery || {on: 1, name: 'Доставка', price: 0};
        if (Number(d.on) !== 1) {
            return `<div class="match-row match-row--delivery" data-delivery-row data-off="1">
                <div class="match-row__name muted">Доставка из КП убрана — в документ не печатается</div>
                <button class="btn btn--outline btn--sm" onclick="App.deliveryToggle(this, 1)">+ Вернуть доставку</button>
            </div>`;
        }
        // Доставка — одна сумма на весь заказ: без единиц и количества (модуль 049)
        const mode = d.mode || (this.ui || {}).delivery_mode || 'included';
        return `<div class="match-row match-row--delivery" data-delivery-row>
            <div class="match-row__name">
                <input type="text" data-delivery-name value="${this.esc(d.name || 'Доставка')}"
                       placeholder="Доставка">
                <div class="muted" data-delivery-note>${this.deliveryModeNote(mode)}</div>
            </div>
            ${this.deliveryModeSelect(mode, 'data-delivery-mode onchange="App.deliveryModeChanged(this)"')}
            <input type="number" step="0.01" min="0" data-delivery-price value="${Number(d.price) || 0}"
                   placeholder="Цена" title="Стоимость доставки на весь заказ" oninput="App.updateMatchTotal(this)">
            <div class="match-row__tools">
                <button class="btn btn--outline btn--sm" title="Убрать доставку из КП"
                        onclick="App.deliveryToggle(this, 0)">×</button>
            </div>
        </div>`;
    },

    DELIVERY_MODES: {
        included: ['в стоимость товаров', 'Распределится по ценам позиций КП и счёта'],
        line:     ['отдельной строкой', 'Своя строка в таблице КП и в итоге'],
        separate: ['оплачивается отдельно', 'В КП не входит; стоимость допишется в текст письма'],
    },

    /** Выбор режима доставки этого КП. $attrs — атрибуты select. */
    deliveryModeSelect(mode, attrs) {
        return `<select ${attrs} title="Как учитывать доставку в этом КП">
            ${Object.entries(this.DELIVERY_MODES).map(([k, v]) =>
                `<option value="${k}" ${k === mode ? 'selected' : ''}>${v[0]}</option>`).join('')}
        </select>`;
    },

    deliveryModeNote(mode) {
        return (this.DELIVERY_MODES[mode] || this.DELIVERY_MODES.included)[1];
    },

    deliveryModeChanged(sel) {
        const row = sel.closest('[data-delivery-row]');
        const note = row && row.querySelector('[data-delivery-note]');
        if (note) note.textContent = this.deliveryModeNote(sel.value);
        this.updateMatchTotal(sel);
    },

    /** Убрать доставку из КП или вернуть её обратно. */
    deliveryToggle(from, on) {
        const host = this.matchHost(from);
        const box = host && host.querySelector('[data-delivery]');
        if (!box) return;
        // Убранная строка помнит свою цену: вернуть её и увидеть ноль вместо
        // посчитанной доставки — значит считать её второй раз
        const current = this.collectDelivery(host);
        if (current) box.dataset.was = JSON.stringify(current);
        const was = box.dataset.was ? JSON.parse(box.dataset.was) : {name: 'Доставка', price: 0};
        box.innerHTML = this.deliveryRow({on, name: was.name, price: was.price, mode: was.mode});
        this.updateMatchTotal(host);
    },

    /** Что стоит в строке доставки сейчас; null — её убрали. */
    collectDelivery(host) {
        const row = host && host.querySelector('[data-delivery-row]');
        if (!row || row.dataset.off === '1') return null;
        const name = row.querySelector('[data-delivery-name]');
        const price = row.querySelector('[data-delivery-price]');
        const mode = row.querySelector('[data-delivery-mode]');
        return {
            name: (name && name.value.trim()) || 'Доставка',
            price: parseFloat(price && price.value) || 0,
            mode: mode ? mode.value : '',
        };
    },

    /**
     * Кнопки КП под таблицей подбора (модуль 048: одно КП на запрос).
     *
     * КП ещё нет — одна кнопка сборки. Есть — всё, что с ним делают:
     * 🔄 Пересобрать · Открыть · ⬇ Word · ⬇ PDF · 🧾 Счёт · Убрать. Счета и
     * «Убрать» зависят от сервера (выставлен ли счёт, ушло ли КП) — их
     * дорисовывает `loadKpSummary()`.
     */
    matchKpButton(requestId, kp) {
        if (!kp.proposal_id) {
            return `<button class="btn btn--primary btn--sm" onclick="App.generateKP(${requestId}, this)">Сформировать КП</button>`;
        }
        const id = kp.proposal_id;
        return `<button class="btn btn--outline btn--sm" onclick="App.kpRebuild(${requestId}, ${id}, this)"
                        title="Собрать КП заново из этих позиций">🔄 Пересобрать</button>
                <button class="btn btn--primary btn--sm" onclick="App.openKp(${id}, this)">Открыть</button>
                <button class="btn btn--outline btn--sm" onclick="App.buildKpFile(${requestId}, this, 'docx')"
                        title="Скачать КП файлом Word">⬇ Word</button>
                <button class="btn btn--outline btn--sm" onclick="App.buildKpFile(${requestId}, this, 'pdf')"
                        title="Скачать КП файлом PDF">⬇ PDF</button>
                <button class="btn btn--outline btn--sm" onclick="App.kpInvoice(${id}, this)"
                        title="Выставить счёт в МойСклад по этому КП">🧾 Счёт</button>
                <span data-kp-delete></span>`;
    },

    /** Счета КП и «Убрать» — то, что знает только сервер. */
    async loadKpSummary(requestId, host) {
        host = host || this.kpHost(requestId);
        const kp = host && host.dataset.kp ? JSON.parse(host.dataset.kp) : {};
        const box = host && host.querySelector('[data-kp-invoices]');
        if (!box) return;
        box.innerHTML = '';
        if (!kp.proposal_id) return;
        try {
            const s = await this.api(`proposals.php?action=summary&id=${kp.proposal_id}`);
            host.dataset.kpEdited = s.edited ? '1' : '';
            const del = host.querySelector('[data-kp-delete]');
            if (del) del.innerHTML = s.can_delete
                ? `<button class="btn btn--outline btn--sm btn--danger"
                           onclick="App.kpDeleteProposal(${requestId}, ${s.id})">Убрать</button>` : '';
            box.innerHTML = (s.invoices || []).map(i => `
                <div class="flex flex--wrap" style="gap:6px;align-items:center">
                    <a href="${this.esc(i.url)}" target="_blank" rel="noopener">Счёт ${this.esc(i.name)} ↗</a>
                    <span class="muted">${this.fmtMoney(i.sum)}${i.payed_sum > 0 ? ' · оплачено ' + this.fmtMoney(i.payed_sum) : ''}</span>
                    <a onclick="App.saveAs('${i.pdf_url}', 'Счёт ${this.jsStr(i.name)}.pdf')">PDF</a>
                </div>`).join('');
        } catch (err) {
            box.innerHTML = `<p class="no">${this.esc(err.message)}</p>`;
        }
    },

    kpHost(requestId) {
        return document.querySelector(`[data-match-host][data-request-id="${requestId}"]`);
    },

    /** Поставить на место кнопок КП состояние `kp` и дорисовать счета. */
    setKpButtons(requestId, host, kp) {
        if (!host) return;
        host.dataset.kp = JSON.stringify(kp);
        const bar = host.querySelector('[data-kp-buttons]');
        if (bar) bar.innerHTML = this.matchKpButton(requestId, kp);
        this.loadKpSummary(requestId, host);
    },

    /**
     * «🔄 Пересобрать»: таблица сохраняется, позиции КП собираются из неё
     * заново. Отправленное клиенту КП сервер не переписывает — собирает новое,
     * и дальше кнопки работают с ним.
     */
    async kpRebuild(requestId, proposalId, btn) {
        const host = this.kpHost(requestId);
        if (host && host.dataset.kpEdited === '1'
            && !confirm('КП правили руками на листе A4 — эти правки пропадут. Пересобрать?')) return;
        btn.disabled = true;
        const say = this.kpProgress(btn, 'Пересобираем КП по подбору...');
        try {
            if (host && host.querySelector('[data-match-row]')) await this.saveMatchedItems(host, true);
            const r = await this.api(`proposals.php?action=rebuild&id=${proposalId}`, {method: 'POST', body: {}});
            const id = this.proposalId(r);
            say('');
            this.toast(r.created ? 'КП уже ушло клиенту — по подбору собрано новое' : 'КП пересобрано', 'success');
            this.setKpButtons(requestId, host, {proposal_id: id});
            // Открытый документ показывает вчерашнюю сборку — открываем заново
            const slot = this.kpSlot() || (host && host.querySelector('[data-kp-slot]'));
            if (slot && slot.dataset.open) { slot.innerHTML = ''; slot.dataset.open = ''; }
            this.openKp(id, host && host.querySelector('[data-kp-buttons] button'));
        } catch (err) { say(err.message, true); this.toast(err.message, 'error'); }
        finally { btn.disabled = false; }
    },

    async kpDeleteProposal(requestId, proposalId) {
        if (!confirm('Убрать КП? Позиции останутся в таблице подбора — КП можно собрать заново.')) return;
        try {
            await this.api(`proposals.php?action=delete&id=${proposalId}`, {method: 'POST', body: {}});
            const host = this.kpHost(requestId);
            // Открытый документ убранного КП закрывается вместе с ним
            const slot = this.kpSlot() || (host && host.querySelector('[data-kp-slot]'));
            if (slot && slot.dataset.open === String(proposalId)) { slot.innerHTML = ''; slot.dataset.open = ''; }
            this.setKpButtons(requestId, host, {proposal_id: null});
            this.toast('КП убрано', 'success');
        } catch (err) { this.toast(err.message, 'error'); }
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
        const say = this.kpProgress(btn, '');
        say('Собираем КП: каталог, реквизиты, фотографии, документ...');
        try {
            if (host) await this.saveMatchedItems(host, true);
            let proposalId = host && host.dataset.kp ? (JSON.parse(host.dataset.kp).proposal_id || 0) : 0;
            if (!proposalId) {
                proposalId = this.proposalId(await this.api(
                    `proposals.php?action=generate&request_id=${requestId}`, {method: 'POST', body: {}}));
                if (host) host.dataset.kp = JSON.stringify({proposal_id: proposalId});
            } else {
                // Доставку из таблицы подбора несём в уже собранное КП: новое
                // забирает её при создании, старое — здесь (модуль 034)
                const d = host ? this.collectDelivery(host) : null;
                await this.api(`proposals.php?action=update&id=${proposalId}`, {method: 'POST', body: {
                    delivery_on: d ? 1 : 0,
                    delivery_name: d ? d.name : '',
                    delivery_price: d ? d.price : 0,
                }});
            }
            const url = format === 'pdf'
                ? `/api/proposals.php?action=preview&id=${proposalId}`
                : `/api/proposals.php?action=docx&id=${proposalId}`;
            // Документ раскрывается ПОД письмом и одновременно скачивается:
            // «собрать в файл» и «посмотреть, что собралось» — одно движение
            // менеджера, а не два (модуль 034)
            this.openKpUnderLetter(proposalId, btn);
            await this.download(url);
            say('');
            this.toast('КП собрано — файл скачивается, предпросмотр под письмом', 'success');
        } catch (err) { say(err.message, true); this.toast(err.message, 'error'); }
        finally { btn.disabled = false; btn.textContent = label; }
    },

    /**
     * Скачать файл, не открывая вкладку.
     *
     * `window.open` на КП оставлял за собой пустое окно с PDF, из которого
     * менеджер возвращался кнопкой «назад» — а всё управление КП должно
     * оставаться в одной карточке.
     *
     * Файл забирается запросом, а не ссылкой `<a download>` (модуль 034):
     * ссылка молча складывала на диск ОШИБКУ СЕРВЕРА под именем `KP-29.docx` —
     * нажатие выглядело как «ничего не происходит», а в папке лежал JSON с
     * «Not found». Теперь ошибка видна тостом и попадает в журнал, а на диск
     * уходит только настоящий документ.
     */
    async download(url, filename) {
        let res;
        try {
            res = await fetch(url, {credentials: 'same-origin'});
        } catch (err) {
            throw new Error('Файл не скачался: ' + err.message);
        }
        if (!res.ok) throw new Error(await this.errorTextOf(res));
        const blob = await res.blob();
        const href = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = href;
        // Имя даёт СЕРВЕР: «КП_Атлант_Армор_для_ООО_Воевода_от_17.09.2026.docx».
        // Запасное `KP-32.docx` — то, что уходило клиенту вместо него, пока имя
        // прописывали здесь, в кнопке (модуль 035).
        a.download = this.filenameOf(res) || filename || '';
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
        // Освобождать сразу нельзя: Safari не успевает начать скачивание
        setTimeout(() => URL.revokeObjectURL(href), 20000);
    },

    /** Имя файла из Content-Disposition: сначала filename*=UTF-8'', потом filename. */
    filenameOf(res) {
        const cd = res.headers.get('Content-Disposition') || '';
        const star = /filename\*=UTF-8''([^;]+)/i.exec(cd);
        if (star) { try { return decodeURIComponent(star[1].trim()); } catch { /* битая кодировка */ } }
        const plain = /filename="([^"]+)"/i.exec(cd) || /filename=([^;]+)/i.exec(cd);
        return plain ? plain[1].trim() : '';
    },

    /** Скачать и сказать вслух, если не вышло — для кнопок прямо в разметке. */
    saveAs(url, filename) {
        this.download(url, filename).catch(err => this.toast(err.message, 'error'));
    },

    /** Что на самом деле ответил сервер: JSON с полем error, иначе текст. */
    async errorTextOf(res) {
        const body = await res.text().catch(() => '');
        try {
            const data = JSON.parse(body);
            if (data && data.error) return data.error;
        } catch { /* не JSON — значит страница или пусто */ }
        return `Сервер ответил ${res.status}${body ? ': ' + body.replace(/<[^>]*>/g, ' ').trim().slice(0, 200) : ''}`;
    },

    /**
     * Номер КП из ответа сервера — или внятная ошибка.
     *
     * Отсюда росло «КП #undefined не найдено»: `location.hash =
     * 'mail/proposal/' + p.id` с `undefined` в `p.id` открывал страницу
     * редактора по несуществующему адресу, и она честно докладывала, что
     * такого КП нет. Теперь неответ сервера виден как неответ сервера.
     */
    proposalId(resp) {
        const id = Number((resp && (resp.id ?? resp.proposal_id)) || 0);
        if (!Number.isInteger(id) || id <= 0) {
            throw new Error('Сервер не вернул номер КП — оно не собралось. Загляните в «Настройки → Журнал».');
        }
        return id;
    },

    /**
     * «Открыть КП» — в ту же карточку, а не отдельной страницей (модуль 023).
     *
     * Раньше это была ссылка на #mail/proposal/N: менеджер уходил с письма, и
     * назад к переписке приходилось возвращаться через доску. А по битой
     * ссылке экран честно писал «КП #undefined не найдено» — потому что id
     * брался из ответа, которого не было.
     */
    /**
     * Раскрыть КП под письмом и НЕ закрыть его, если оно уже открыто.
     *
     * «Собрать КП в файл» зовёт это после сборки: нажатие на кнопку не должно
     * захлопывать документ, который только что собрали (модуль 034).
     */
    openKpUnderLetter(proposalId, btn) {
        this.openKp(proposalId, btn, true);
    },

    async openKp(proposalId, btn, keepOpen = false) {
        const id = Number(proposalId) || 0;
        if (!id) { this.toast('КП ещё не собрано — нажмите «Сформировать КП»', 'error'); return; }
        // Документ раскрывается В ЛЕВОЙ КОЛОНКЕ, над полем письма (модули 029, 049):
        // читают КП и пишут про него в одном столбце, а не в разных концах
        // экрана. Блок `#kpWide` под всей карточкой оставался за пределами
        // видимого экрана — до него надо было домотать, и то, что КП вообще
        // открылось, было не видно.
        const host = this.matchHost(btn);
        // На телефоне КП раскрывается прямо под блоком, где нажали «Открыть»
        // (issue #67): до поля письма внизу страницы пришлось бы листать
        const slot = (this.isPhone() && host && host.querySelector('[data-kp-slot]'))
            || this.kpSlot()
            || (host && (host.querySelector('[data-kp-slot]')
                || host.appendChild(this.dataDiv('kpSlot'))));
        // Карточки под рукой нет (страница редактора КП) — только тогда переход
        if (!slot) { location.hash = '#mail/proposal/' + id; return; }
        if (slot.dataset.open === String(id)) {
            if (keepOpen) { slot.scrollIntoView({behavior: 'smooth', block: 'start'}); return; }
            slot.innerHTML = '';
            slot.dataset.open = '';
            return;
        }
        slot.dataset.open = String(id);
        const height = Number(localStorage.getItem('kpHeight')) || 60;
        slot.innerHTML = `
            <div class="card kp-open" data-block="kp">
                <div class="flex flex--between flex--wrap">
                    <strong>КП #${id}</strong>
                    <span class="flex flex--wrap">
                        <!-- Высота окна — ползунком и за нижний край рамки;
                             выбранная запоминается на этом устройстве (модуль 029) -->
                        <label class="kp-size" title="Высота окна КП — или тяните за полосу под листом">
                            <input type="range" min="25" max="160" value="${height}"
                                   oninput="App.kpSetHeight(this.closest('.kp-open'), this.value)"><span data-kp-size>${height}vh</span>
                        </label>
                        <button class="btn btn--outline btn--sm"
                                onclick="App.kpDownload(${id}, 'docx', this)">⬇ Word</button>
                        <button class="btn btn--outline btn--sm"
                                onclick="App.kpDownload(${id}, 'pdf', this)">⬇ PDF</button>
                        <button class="btn btn--outline btn--sm" onclick="App.kpInvoice(${id}, this)"
                                title="Выставить счёт в МойСклад теми же позициями и приложить его к письму">🧾 Счёт в МойСклад</button>
                        <button class="btn btn--primary btn--sm" onclick="App.confirmAndSend(${id})">Подтвердить и отправить</button>
                        <a class="btn btn--outline btn--sm" href="#mail/proposal/${id}"
                           title="Полный редактор: карточки товаров, фото, блоки вокруг таблицы">Все настройки КП →</a>
                        <button class="btn btn--outline btn--sm" onclick="App.openKp(${id}, this)">Свернуть</button>
                    </span>
                </div>
                <!-- Документ открывается листом A4 в редакторе (модуль 051);
                     PDF — второй вид того же документа -->
                <div class="kp-modes">
                    <button class="btn btn--sm btn--primary" data-kp-mode="page"
                            onclick="App.kpMode(this, 'page', ${id})">✎ Редактировать вручную</button>
                    <button class="btn btn--sm btn--outline" data-kp-mode="pdf"
                            onclick="App.kpMode(this, 'pdf', ${id})">PDF</button>
                    <!-- Масштаб листа: пальцами, Ctrl + колесо / щипок тачпада, кнопками -->
                    <span class="kp-zoom" data-kp-zoom title="Масштаб: Ctrl + колесо мыши, щипок пальцами или тачпадом">
                        <button class="btn btn--sm btn--outline" onclick="App.kpZoomStep(this, -1)" aria-label="Уменьшить">−</button>
                        <button class="btn btn--sm btn--outline" data-kp-zoom-label onclick="App.kpZoomStep(this, 0)"
                                title="По ширине окна / 100%">100%</button>
                        <button class="btn btn--sm btn--outline" onclick="App.kpZoomStep(this, 1)" aria-label="Увеличить">+</button>
                    </span>
                </div>
                <div data-kp-pagewrap>
                    <div data-kp-page-bar></div>
                    <div data-kp-tb></div>
                    <div data-kp-page-state class="loading">Собираем документ...</div>
                    <iframe class="kp-page" style="height:${height}vh;display:none" sandbox="allow-same-origin"
                            title="КП #${id} — лист A4"></iframe>
                </div>
                <div data-kp-pdf hidden>
                    <!-- Пока документ собирается, в рамке был белый прямоугольник, и
                         было не отличить «ещё считает» от «не открылось» (модуль 035) -->
                    <div data-kp-state class="loading">Собираем документ...</div>
                    <iframe class="kp-preview" style="height:${height}vh;display:none"
                            title="Предпросмотр КП #${id}"></iframe>
                </div>
                <!-- Высота окна — за эту полосу (модуль 051): угол рамки у iframe
                     не тянется, мышь уходит внутрь документа -->
                <div class="kp-grip" role="separator" aria-orientation="horizontal" tabindex="0"
                     aria-label="Высота окна КП: тяните мышью или стрелками вверх и вниз"
                     title="Тяните, чтобы изменить высоту окна"
                     onpointerdown="App.kpGripStart(event, this)" onkeydown="App.kpGripKey(event, this)"></div>
            </div>`;
        slot.scrollIntoView({behavior: 'smooth', block: 'start'});
        this.fillKpPage(slot, id);
    },

    /** Страница A4 или PDF — два вида одного документа. PDF грузится при первом показе. */
    kpMode(btn, mode, id) {
        const card = btn.closest('.kp-open');
        if (!card) return;
        card.querySelectorAll('[data-kp-mode]').forEach(b => {
            b.classList.toggle('btn--primary', b.dataset.kpMode === mode);
            b.classList.toggle('btn--outline', b.dataset.kpMode !== mode);
        });
        card.querySelector('[data-kp-pagewrap]').hidden = mode !== 'page';
        const zoom = card.querySelector('[data-kp-zoom]');
        if (zoom) zoom.hidden = mode !== 'page';
        const pdf = card.querySelector('[data-kp-pdf]');
        pdf.hidden = mode !== 'pdf';
        if (mode === 'pdf') {
            const run = () => {
                const frame = card.querySelector('.kp-preview');
                if (frame && !frame.getAttribute('src')) this.fillKpPreview(card.parentElement, id);
                else if (frame) frame.src = this.kpPdfUrl(card, id, true);
            };
            // Несохранённая правка страницы сначала сохраняется — PDF собирается из неё
            if (card.dataset.dirty === '1') this.kpPageSave(id, card).then(ok => ok && run());
            else run();
        }
    },

    /**
     * PDF в масштабе листа (модуль 051): просмотрщик PDF в браузере понимает
     * `#zoom=`, и страница PDF выходит той же ширины, что лист в редакторе.
     */
    kpPdfUrl(card, id, fresh = false) {
        const z = Math.round(((card && card._kpZoom) || 1) * 100);
        return `/api/proposals.php?action=preview&id=${id}${fresh ? '&t=' + Date.now() : ''}#zoom=${z}`;
    },

    /**
     * ==== КП страницей A4 (модуль 045, issue #60) ====
     *
     * Документ — тот же HTML, из которого собираются PDF и Word, в рамке без
     * скриптов (`sandbox`), с `designMode`: текст правится прямо на листе.
     * «Сохранить правки» кладёт страницу в КП — из неё соберутся PDF и Word.
     */
    async fillKpPage(slot, id) {
        const state = slot.querySelector('[data-kp-page-state]');
        const frame = slot.querySelector('.kp-page');
        const bar = slot.querySelector('[data-kp-page-bar]');
        const tb = slot.querySelector('[data-kp-tb]');
        const card = slot.querySelector('.kp-open');
        if (!state || !frame || !card) return;
        state.hidden = false;
        state.className = 'loading';
        state.textContent = 'Собираем документ...';
        try {
            const d = await this.api(`proposals.php?action=html&id=${id}`);
            if (!slot.isConnected || slot.dataset.open !== String(id)) return;
            card.dataset.dirty = '';
            bar.innerHTML = this.kpPageBar(id, d);
            if (tb) tb.innerHTML = d.editable ? this.kpToolbar() : '';
            const html = String(d.html || '');
            const sheet = this.kpSheetStyle(d.editable);
            frame.onload = () => {
                const doc = frame.contentDocument;
                if (!doc) return;
                if (d.editable) this.kpEditorBind(frame, card, id);
                this.kpBindZoom(frame, card);
                // Разбивка на страницы — когда встанут шрифт и фотографии
                this.kpRepaginate(card);
                if (doc.fonts && doc.fonts.ready) doc.fonts.ready.then(() => this.kpRepaginate(card));
                doc.querySelectorAll('img').forEach(img => {
                    if (!img.complete) img.addEventListener('load', () => this.kpRepaginateSoon(card), {once: true});
                });
            };
            frame.srcdoc = /<\/head>/i.test(html) ? html.replace(/<\/head>/i, sheet + '</head>') : sheet + html;
            frame.style.display = '';
            state.hidden = true;
        } catch (err) {
            state.className = 'no';
            state.innerHTML = `<strong>КП не открылось.</strong> ${this.esc(err.message)}
                <button class="btn btn--outline btn--sm" style="margin-left:8px"
                        onclick="App.fillKpPage(this.closest('.kp-open').parentElement, ${id})">Повторить</button>`;
        }
    },

    /**
     * Стиль листа — только в редакторе; перед сохранением он убирается (и
     * сервер его вырезает тоже). Шрифт тот же, что у mPDF, — строки
     * переносятся как в PDF (модуль 051).
     */
    kpSheetStyle(editable) {
        const font = (f, w, st) => `@font-face{font-family:"DejaVu Sans";font-weight:${w};font-style:${st};
            src:url(/api/proposals.php?action=font&f=${f}) format("truetype")}`;
        return `<style data-kp-editor>
            ${font('r', 'normal', 'normal')}${font('b', 'bold', 'normal')}
            ${font('i', 'normal', 'italic')}${font('bi', 'bold', 'italic')}
            html{background:#e9e9e9}
            body{width:210mm;min-height:var(--kp-min,297mm);margin:12px auto!important;padding:10mm 15mm 15mm!important;
                box-sizing:border-box;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.25)}
            .kp-pgap{position:relative;height:0;margin:0;padding:0;border:0;user-select:none}
            .kp-pgap__band{position:absolute;bottom:10mm;height:14px;background:#e9e9e9;
                box-shadow:inset 0 4px 4px -3px rgba(0,0,0,.3),inset 0 -4px 4px -3px rgba(0,0,0,.3);
                font:10px/14px sans-serif;color:#777;text-align:center}
            tr.kp-pgap-row td{padding:0!important;border:0!important;background:transparent!important}
            .kp-page-break{height:0;border-top:1px dashed #b0b0b0;margin:0}
            ${editable ? `
            body:focus,body *:focus{outline:1px dashed #b0b0b0}
            [data-kp-field]{min-height:1.4em;border-radius:2px}
            [data-kp-field]:hover{box-shadow:0 0 0 1px #d6e2ff}
            [data-kp-field]:empty::before{content:attr(data-kp-hint);color:#a0a0a0;font-style:italic}
            [data-kp-var]{background:#eef3ff;border-radius:2px}` : ''}
        </style>`;
    },

    /** Панель редактора: действия над выделением на листе (модуль 051). */
    kpToolbar() {
        const b = (cmd, label, title, arg = '') => `<button type="button" class="kp-tb__btn" data-cmd="${cmd}"
            ${arg ? `data-arg="${arg}"` : ''} title="${title}" aria-label="${title}" aria-pressed="false">${label}</button>`;
        const sep = '<span class="kp-tb__sep" aria-hidden="true"></span>';
        return `<div class="kp-tb" role="toolbar" aria-label="Оформление текста КП">
            ${b('undo', '↶', 'Отменить (Ctrl+Z)')}${b('redo', '↷', 'Повторить (Ctrl+Y)')}${sep}
            <select class="kp-tb__select" data-cmd="formatBlock" title="Стиль абзаца" aria-label="Стиль абзаца">
                <option value="p">Обычный текст</option><option value="h1">Заголовок 1</option>
                <option value="h2">Заголовок 2</option><option value="h3">Заголовок 3</option>
            </select>${sep}
            ${b('bold', '<b>Ж</b>', 'Жирный (Ctrl+B)')}${b('italic', '<i>К</i>', 'Курсив (Ctrl+I)')}
            ${b('underline', '<u>Ч</u>', 'Подчёркнутый (Ctrl+U)')}${b('strikeThrough', '<s>З</s>', 'Зачёркнутый')}${sep}
            ${b('foreColor', '<span class="kp-tb__sw" style="background:#222"></span>', 'Цвет текста: чёрный', '#222222')}
            ${b('foreColor', '<span class="kp-tb__sw" style="background:#c00000"></span>', 'Цвет текста: красный', '#c00000')}
            ${b('foreColor', '<span class="kp-tb__sw" style="background:#777"></span>', 'Цвет текста: серый', '#777777')}${sep}
            ${b('justifyLeft', '⯇≡', 'По левому краю')}${b('justifyCenter', '≡', 'По центру')}
            ${b('justifyRight', '≡⯈', 'По правому краю')}${b('justifyFull', '☰', 'По ширине')}${sep}
            ${b('insertUnorderedList', '• ≡', 'Маркированный список')}${b('insertOrderedList', '1. ≡', 'Нумерованный список')}${sep}
            ${b('createLink', '🔗', 'Ссылка')}${b('unlink', '⛓̸', 'Убрать ссылку')}
            ${b('pageBreak', '⤓', 'Разрыв страницы: дальше — с новой страницы')}${sep}
            ${b('removeFormat', '⌫', 'Очистить оформление')}
        </div>`;
    },

    /** Лист становится редактором: панель, вставка без мусора, Ctrl+S, страницы. */
    kpEditorBind(frame, card, id) {
        const doc = frame.contentDocument;
        doc.designMode = 'on';
        try { doc.execCommand('styleWithCSS', false, false); } catch { /* старый браузер */ }
        doc.querySelectorAll('.kp-page-break').forEach(el => el.setAttribute('contenteditable', 'false'));
        const dirty = () => { card.dataset.dirty = '1'; this.kpPageDirty(card, true); this.kpRepaginateSoon(card); };
        doc.addEventListener('input', dirty);
        doc.addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'ы')) {
                e.preventDefault();
                this.kpPageSave(id, card);
            }
        });
        doc.addEventListener('paste', e => {
            const cd = e.clipboardData;
            if (!cd) return;
            e.preventDefault();
            const html = cd.getData('text/html');
            if (html) doc.execCommand('insertHTML', false, this.kpCleanPaste(html));
            else doc.execCommand('insertText', false, cd.getData('text/plain'));
        });
        doc.addEventListener('selectionchange', () => this.kpToolbarState(card));

        const tb = card.querySelector('.kp-tb');
        if (!tb) return;
        // Кнопка не забирает выделение у листа
        tb.addEventListener('mousedown', e => { if (e.target.closest('button')) e.preventDefault(); });
        tb.addEventListener('click', e => {
            const btn = e.target.closest('button[data-cmd]');
            if (btn) this.kpExec(card, btn.dataset.cmd, btn.dataset.arg || null);
        });
        const sel = tb.querySelector('select[data-cmd]');
        if (sel) sel.addEventListener('change', () => this.kpExec(card, 'formatBlock', '<' + sel.value + '>'));
        this.kpToolbarState(card);
    },

    kpExec(card, cmd, arg) {
        const frame = card.querySelector('.kp-page');
        const doc = frame && frame.contentDocument;
        if (!doc) return;
        frame.contentWindow.focus();
        if (cmd === 'createLink') {
            arg = prompt('Адрес ссылки', 'https://');
            if (!arg || !/^(https?:\/\/|mailto:)/i.test(arg.trim())) return;
            arg = arg.trim();
        }
        if (cmd === 'pageBreak') {
            doc.execCommand('insertHTML', false,
                '<div class="kp-page-break" style="page-break-before:always" contenteditable="false"></div><p><br></p>');
        } else {
            doc.execCommand(cmd, false, arg);
        }
        card.dataset.dirty = '1';
        this.kpPageDirty(card, true);
        this.kpToolbarState(card);
        this.kpRepaginateSoon(card);
    },

    /** Нажатые кнопки и стиль абзаца — по выделению на листе. */
    kpToolbarState(card) {
        const doc = card.querySelector('.kp-page')?.contentDocument;
        const tb = card.querySelector('.kp-tb');
        if (!doc || !tb) return;
        tb.querySelectorAll('button[data-cmd]').forEach(b => {
            if (b.dataset.arg || ['undo', 'redo', 'createLink', 'unlink', 'pageBreak', 'removeFormat'].includes(b.dataset.cmd)) return;
            let on = false;
            try { on = doc.queryCommandState(b.dataset.cmd); } catch { /* команда не поддерживается */ }
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        const sel = tb.querySelector('select[data-cmd]');
        if (sel) {
            let v = '';
            try { v = String(doc.queryCommandValue('formatBlock') || '').toLowerCase().replace(/[<>]/g, ''); } catch { /* нет */ }
            sel.value = ['h1', 'h2', 'h3'].includes(v) ? v : 'p';
        }
    },

    /**
     * Вставка из Word и с сайтов: остаются абзацы, списки, заголовки, жирный,
     * курсив, подчёркивание и ссылки — без классов, стилей и шрифтов.
     */
    kpCleanPaste(html) {
        const src = new DOMParser().parseFromString(html, 'text/html');
        const keep = {P: 'p', BR: 'br', B: 'b', STRONG: 'b', I: 'i', EM: 'i', U: 'u', S: 's', STRIKE: 's',
                      UL: 'ul', OL: 'ol', LI: 'li', H1: 'h1', H2: 'h2', H3: 'h3', H4: 'h3', A: 'a',
                      DIV: 'p', TR: 'p'};
        const out = document.createElement('div');
        const walk = (from, to) => {
            for (const n of from.childNodes) {
                if (n.nodeType === 3) { to.appendChild(document.createTextNode(n.nodeValue.replace(/\s+/g, ' '))); continue; }
                if (n.nodeType !== 1 || /^(SCRIPT|STYLE|META|LINK|TITLE|XML|O:P)$/i.test(n.nodeName)) continue;
                const tag = keep[n.nodeName];
                if (!tag) {
                    walk(n, to);
                    if (/^(TD|TH)$/.test(n.nodeName)) to.appendChild(document.createTextNode(' '));
                    continue;
                }
                const el = document.createElement(tag);
                if (tag === 'a') {
                    const href = n.getAttribute('href') || '';
                    if (/^(https?:|mailto:)/i.test(href)) el.setAttribute('href', href);
                }
                if (tag !== 'br') walk(n, el);
                to.appendChild(el);
            }
        };
        walk(src.body, out);
        return out.innerHTML;
    },

    /** Пересчитать страницы, когда правка затихла. */
    kpRepaginateSoon(card) {
        clearTimeout(card._kpPagTimer);
        card._kpPagTimer = setTimeout(() => this.kpRepaginate(card), 500);
    },

    /**
     * ==== Страницы на листе (модуль 051) ====
     *
     * Лист делится там же, где делит mPDF: A4, поля 10 / 15 мм, строка таблицы,
     * абзац и пункт списка переходят на новую страницу целиком; `.appendix`,
     * `.card--break` и `.kp-page-break` всегда начинают страницу. Между
     * страницами встаёт разделитель `[data-kp-editor-ui]` — в сохранение он не
     * попадает. Координаты — в пикселях самого листа, масштаб их не меняет.
     */
    kpRepaginate(card) {
        const frame = card && card.querySelector('.kp-page');
        const doc = frame && frame.contentDocument;
        const body = doc && doc.body;
        if (!body || !doc.defaultView) return;
        const win = doc.defaultView;
        doc.querySelectorAll('[data-kp-editor-ui]').forEach(el => el.remove());

        const MM = 96 / 25.4;
        const H = 272 * MM, TOP = 10 * MM, BOTTOM = 15 * MM, GAP = 14;
        const bodyRect = body.getBoundingClientRect();
        const scale = bodyRect.width / (210 * MM) || 1;
        const origin = bodyRect.top / scale + TOP;

        const inlineish = d => /^(inline|inline-block|inline-flex|contents|none)$/.test(d);
        const blockKids = el => [...el.children].some(c => {
            const cs = win.getComputedStyle(c);
            return !inlineish(cs.display) && cs.float === 'none' && cs.position !== 'absolute';
        });
        const forcedBy = (el, cs) => el.matches('.appendix, .card--break, .kp-page-break')
            || /always|page/.test(cs.pageBreakBefore || '') || cs.breakBefore === 'page';

        const units = [];
        const walk = (el) => {
            for (const c of el.children) {
                const cs = win.getComputedStyle(c);
                if (cs.display === 'none' || cs.float !== 'none' || /absolute|fixed/.test(cs.position)) continue;
                if (inlineish(cs.display) && c.tagName !== 'IMG') continue;
                const force = forcedBy(c, cs);
                const first = units.length;
                if (c.tagName === 'TABLE') {
                    const cols = Math.max(1, ...[...c.rows].map(r => r.cells.length));
                    [...c.rows].forEach(r => units.push({el: r, row: true, cols}));
                } else if (c.tagName !== 'IMG' && blockKids(c)) {
                    walk(c);
                } else {
                    units.push({el: c});
                }
                if (force) {
                    if (units.length > first) units[first].force = true;
                    else units.push({el: c, force: true});
                }
            }
        };
        walk(body);
        units.forEach(u => {
            const r = u.el.getBoundingClientRect();
            u.top = r.top / scale - origin;
            u.bottom = r.bottom / scale - origin;
        });

        let pageTop = 0, shift = 0;
        const gaps = [];
        for (const u of units) {
            const top = u.top + shift;
            const bottom = u.bottom + shift;
            while (top >= pageTop + H) pageTop += H;       // абзац выше страницы — PDF режет его строками
            const over = bottom > pageTop + H + 0.5 && (u.bottom - u.top) <= H / 3;
            if (!(u.force || over) || top <= pageTop + 1) continue;
            const h = pageTop + H - top + BOTTOM + GAP + TOP;
            gaps.push(this.kpInsertGap(doc, u, h, scale, bodyRect));
            shift += h;
            pageTop = top + h;
        }
        const last = units.length ? units[units.length - 1].bottom + shift : 0;
        while (last > pageTop + H) pageTop += H;
        const pages = gaps.length + 1;
        gaps.forEach((g, i) => { g.textContent = `стр. ${i + 2} из ${pages}`; });
        doc.documentElement.style.setProperty('--kp-min', (pageTop + H + TOP + BOTTOM) + 'px');
    },

    /** Разделитель страниц перед единицей потока; в таблице — строкой. Возвращает подпись. */
    kpInsertGap(doc, u, h, scale, bodyRect) {
        const gap = doc.createElement('div');
        gap.className = 'kp-pgap';
        gap.setAttribute('contenteditable', 'false');
        gap.style.height = h + 'px';
        const band = doc.createElement('span');
        band.className = 'kp-pgap__band';
        gap.appendChild(band);
        if (u.row) {
            const tr = doc.createElement('tr');
            tr.className = 'kp-pgap-row';
            tr.setAttribute('data-kp-editor-ui', '1');
            tr.setAttribute('contenteditable', 'false');
            const td = doc.createElement('td');
            td.colSpan = u.cols;
            td.appendChild(gap);
            tr.appendChild(td);
            u.el.parentNode.insertBefore(tr, u.el);
        } else {
            gap.setAttribute('data-kp-editor-ui', '1');
            u.el.parentNode.insertBefore(gap, u.el);
        }
        // Серая полоса — на всю ширину листа, где бы ни стоял разделитель
        const dx = (gap.getBoundingClientRect().left - bodyRect.left) / scale;
        band.style.left = (-dx) + 'px';
        band.style.width = (210 * 96 / 25.4) + 'px';
        return band;
    },

    /**
     * ==== Масштаб листа A4 ====
     *
     * Масштаб — CSS `zoom` корня документа в рамке: сам документ не меняется,
     * и в сохранённую правку он не попадает (`style[data-kp-editor]` вырезается,
     * а zoom стоит на элементе и снимается перед сохранением). 30–300 %.
     * Колесо без Ctrl остаётся прокруткой листа — иначе документ не прочитать.
     */
    KP_ZOOM_MIN: 0.3,
    KP_ZOOM_MAX: 3,

    /** Масштаб «по ширине»: лист A4 целиком в ширину рамки, но не крупнее 100 %. */
    kpFitZoom(frame) {
        const w = frame.clientWidth || 800;
        return Math.min(1, Math.max(this.KP_ZOOM_MIN, (w - 16) / 818));   // 210 мм ≈ 794 px + поля
    },

    kpZoomSet(card, z) {
        const frame = card && card.querySelector('.kp-page');
        const doc = frame && frame.contentDocument;
        if (!doc || !doc.documentElement) return;
        z = Math.min(this.KP_ZOOM_MAX, Math.max(this.KP_ZOOM_MIN, Math.round(z * 100) / 100));
        card._kpZoom = z;
        doc.documentElement.style.zoom = String(z);
        const label = card.querySelector('[data-kp-zoom-label]');
        if (label) label.textContent = Math.round(z * 100) + '%';
        try { localStorage.setItem('kpZoom', String(z)); } catch { /* не запомним */ }
    },

    /** −/+ — шаг 10 %; средняя кнопка — «по ширине» ⇄ 100 %. */
    kpZoomStep(btn, dir) {
        const card = btn.closest('.kp-open');
        const frame = card && card.querySelector('.kp-page');
        if (!frame) return;
        const z = card._kpZoom || 1;
        if (dir === 0) {
            const fit = this.kpFitZoom(frame);
            this.kpZoomSet(card, Math.abs(z - 1) < 0.01 ? fit : 1);
        } else {
            this.kpZoomSet(card, z + dir * 0.1);
        }
    },

    /** Колесо с Ctrl (и щипок тачпада — это то же событие), щипок двумя пальцами, жест Safari. */
    kpBindZoom(frame, card) {
        const doc = frame.contentDocument;
        if (!doc) return;
        let saved = NaN;
        try { saved = parseFloat(localStorage.getItem('kpZoom')); } catch { /* нет хранилища */ }
        this.kpZoomSet(card, Number.isFinite(saved) ? saved : this.kpFitZoom(frame));

        doc.addEventListener('wheel', e => {
            if (!e.ctrlKey && !e.metaKey) return;
            e.preventDefault();
            const z = card._kpZoom || 1;
            this.kpZoomSet(card, z * Math.exp(-e.deltaY * 0.0025));
        }, {passive: false});

        let pinch = null;
        const dist = t => Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY);
        doc.addEventListener('touchstart', e => {
            if (e.touches.length === 2) pinch = {d: dist(e.touches), z: card._kpZoom || 1};
        }, {passive: true});
        doc.addEventListener('touchmove', e => {
            if (!pinch || e.touches.length !== 2) return;
            e.preventDefault();
            this.kpZoomSet(card, pinch.z * dist(e.touches) / (pinch.d || 1));
        }, {passive: false});
        doc.addEventListener('touchend', e => { if (e.touches.length < 2) pinch = null; });

        // Safari на Mac шлёт щипок тачпада жестом, а не колесом
        let gz = 1;
        doc.addEventListener('gesturestart', e => { e.preventDefault(); gz = card._kpZoom || 1; });
        doc.addEventListener('gesturechange', e => { e.preventDefault(); this.kpZoomSet(card, gz * e.scale); });
    },

    kpPageBar(id, d) {
        if (!d.editable) {
            return '<div class="muted kp-page-note">КП отправлено клиенту — документ показан как отправлен, править его нельзя.</div>';
        }
        const oos = d.out_of_scope || {};
        const asks = d.requirements || [];
        return `
            ${asks.length ? `<div class="note kp-asks">Клиент просил указать в КП: ${asks.map(a => this.esc(a)).join('; ')}.
                Абзац на это собран под таблицей — проверьте его на листе.</div>` : ''}
            <div class="kp-page-note flex flex--wrap">
                ${oos.count ? `<label class="kp-oos" title="Строки «не наша номенклатура» — на своих местах, серым курсивом, с прочерками">
                    <input type="checkbox" ${oos.shown ? 'checked' : ''}
                           onchange="App.kpToggleOutOfScope(${id}, this)"> Показать в КП отсутствующую номенклатуру (${oos.count})</label>` : ''}
                <span class="muted" data-kp-page-status>${d.override_at
                    ? `Документ поправлен руками ${this.esc(d.override_at)}. Изменения подбора в него не попадут, пока не вернёте автоматическую сборку.`
                    : 'Щёлкните по тексту на листе и правьте. Сохранённая правка уйдёт в PDF и Word; вступление, условия и тексты вокруг таблицы станут заготовкой для следующих КП.'}</span>
                <button class="btn btn--primary btn--sm" data-kp-page-save
                        onclick="App.kpPageSave(${id}, this.closest('.kp-open'))">💾 Сохранить правки</button>
                ${d.override_at ? `<button class="btn btn--outline btn--sm"
                        title="Собрать документ из подбора и настроек заново — ручные правки листа пропадут"
                        onclick="App.kpPageReset(${id}, this)">↺ Вернуть автоматическую сборку</button>` : ''}
            </div>`;
    },

    /** Галочка КП «Показать отсутствующую номенклатуру»: сохранить и пересобрать лист. */
    async kpToggleOutOfScope(id, input) {
        const card = input.closest('.kp-open');
        if (card && card.dataset.dirty === '1'
            && !confirm('На листе есть несохранённые правки — документ соберётся заново и они пропадут. Продолжить?')) {
            input.checked = !input.checked;
            return;
        }
        input.disabled = true;
        try {
            await this.api(`proposals.php?action=update&id=${id}`, {method: 'POST',
                body: {show_out_of_scope: input.checked ? 1 : 0}});
            if (card) this.fillKpPage(card.parentElement, id);
        } catch (err) {
            input.checked = !input.checked;
            this.toast(err.message, 'error');
        } finally { input.disabled = false; }
    },

    kpPageDirty(card, dirty) {
        const status = card.querySelector('[data-kp-page-status]');
        if (status && dirty) status.textContent = 'Есть несохранённые правки.';
    },

    /** Сохранить лист. Возвращает true, когда сервер принял правку и собрал PDF. */
    async kpPageSave(id, card) {
        const frame = card && card.querySelector('.kp-page');
        const doc = frame && frame.contentDocument;
        if (!doc || !doc.documentElement) return false;
        const btn = card.querySelector('[data-kp-page-save]');
        if (btn) { btn.disabled = true; btn.textContent = 'Сохраняем...'; }
        try {
            const root = doc.documentElement.cloneNode(true);
            root.querySelectorAll('style[data-kp-editor], [data-kp-editor-ui]').forEach(el => el.remove());
            root.querySelectorAll('[contenteditable]').forEach(el => el.removeAttribute('contenteditable'));
            root.style.removeProperty('zoom');
            root.style.removeProperty('--kp-min');
            if (!root.getAttribute('style')) root.removeAttribute('style');
            const html = '<!DOCTYPE html>\n' + root.outerHTML;
            const r = await this.api(`proposals.php?action=html_save&id=${id}`, {method: 'POST', body: {html}});
            card.dataset.dirty = '';
            const bar = card.querySelector('[data-kp-page-bar]');
            if (bar) bar.innerHTML = this.kpPageBar(id, {editable: true, override_at: r.override_at});
            // PDF, если его уже смотрели, — свежий
            const pdf = card.querySelector('.kp-preview');
            if (pdf && pdf.getAttribute('src')) pdf.src = this.kpPdfUrl(card, id, true);
            const learned = r.learned || [];
            this.toast('Правки сохранены — PDF и Word соберутся из этой страницы'
                + (learned.length ? `. Заготовка для следующих КП: ${learned.join(', ')}` : ''), 'success');
            return true;
        } catch (err) {
            this.toast(err.message, 'error');
            return false;
        } finally {
            const b = card.querySelector('[data-kp-page-save]');
            if (b) { b.disabled = false; b.textContent = '💾 Сохранить правки'; }
        }
    },

    async kpPageReset(id, btn) {
        if (!confirm('Вернуть автоматическую сборку? Ручные правки листа пропадут.')) return;
        const card = btn.closest('.kp-open');
        btn.disabled = true;
        try {
            await this.api(`proposals.php?action=html_reset&id=${id}`, {method: 'POST', body: {}});
            this.toast('Документ собран заново из подбора', 'success');
            const pdf = card.querySelector('.kp-preview');
            if (pdf && pdf.getAttribute('src')) pdf.src = this.kpPdfUrl(card, id, true);
            this.fillKpPage(card.parentElement, id);
        } catch (err) { this.toast(err.message, 'error'); btn.disabled = false; }
    },

    /** ⬇ Word / ⬇ PDF: несохранённая правка листа сначала сохраняется. */
    async kpDownload(id, kind, btn) {
        const card = btn.closest('.kp-open');
        if (card && card.dataset.dirty === '1' && !(await this.kpPageSave(id, card))) return;
        this.saveAs(kind === 'docx' ? `/api/proposals.php?action=docx&id=${id}`
                                    : `/api/proposals.php?action=preview&id=${id}`);
    },

    /**
     * Наполнить рамку предпросмотра — и сказать вслух, если не вышло.
     *
     * Документ сначала запрашивается, и только ответивший сервер попадает в
     * рамку: иначе ошибка сборки показывалась как пустое окно, а сборка на
     * полминуты — как «ничего не происходит».
     */
    async fillKpPreview(slot, id) {
        const state = slot.querySelector('[data-kp-state]');
        const frame = slot.querySelector('.kp-preview');
        if (!state || !frame) return;
        state.className = 'loading';
        state.textContent = 'Собираем документ...';
        const url = `/api/proposals.php?action=preview&id=${id}`;
        const card = slot.querySelector('.kp-open');
        // Сборка PDF на живом каталоге идёт секундами — говорим об этом вслух
        const slow = setTimeout(() => {
            if (state.isConnected) state.textContent = 'Собираем документ — фотографии товаров считаются дольше всего...';
        }, 4000);
        try {
            const res = await fetch(url, {credentials: 'same-origin'});
            if (!res.ok) throw new Error(await this.errorTextOf(res));
            // Сервер ответил документом — можно показывать
            if (!slot.isConnected || slot.dataset.open !== String(id)) return;
            frame.src = card ? this.kpPdfUrl(card, id) : url;
            frame.style.display = '';
            state.remove();
        } catch (err) {
            state.className = 'no';
            state.innerHTML = `<strong>КП не открылось.</strong> ${this.esc(err.message)}
                <button class="btn btn--outline btn--sm" style="margin-left:8px"
                        onclick="App.fillKpPreview(this.closest('.kp-open').parentElement, ${id})">Повторить</button>`;
        } finally {
            clearTimeout(slow);
        }
    },

    /**
     * Куда раскрывать КП: под поле ответа в левой колонке, если оно на экране
     * есть, и в старый блок под карточкой, если нет (страница КП, экран запроса).
     */
    /** Пустой <div data-…="1">. `Object.assign(el, {dataset})` падал: dataset только для чтения. */
    dataDiv(key) {
        const el = document.createElement('div');
        el.dataset[key] = '1';
        return el;
    },

    /** Место КП — НАД полем письма (модуль 049): документ читают, затем пишут. */
    kpSlot() {
        const composer = document.querySelector('[data-composer]');
        if (composer && composer.parentElement) {
            return composer.parentElement.querySelector(':scope > [data-kp-under-letter]')
                || composer.insertAdjacentElement('beforebegin',
                       this.dataDiv('kpUnderLetter'));
        }
        return document.getElementById('kpWide');
    },

    /** Высота окна КП (лист и PDF вместе), vh. Значение живёт в этом браузере. */
    kpSetHeight(card, vh) {
        if (!card) return;
        vh = Math.round(Math.min(160, Math.max(25, Number(vh) || 60)));
        card.querySelectorAll('.kp-preview, .kp-page').forEach(f => { f.style.height = vh + 'vh'; });
        const range = card.querySelector('.kp-size input');
        if (range && Number(range.value) !== vh) range.value = vh;
        const label = card.querySelector('[data-kp-size]');
        if (label) label.textContent = vh + 'vh';
        try { localStorage.setItem('kpHeight', String(vh)); } catch { /* приватный режим — просто не запомним */ }
    },

    /**
     * Полоса под листом тянет высоту окна (модуль 051). Пока тянут, рамки не
     * ловят мышь — иначе iframe забирает движение и тянуть «перестаёт».
     */
    kpGripStart(e, grip) {
        const card = grip.closest('.kp-open');
        const frame = card && [...card.querySelectorAll('.kp-page, .kp-preview')].find(f => f.offsetParent);
        if (!frame || e.button > 0) return;
        e.preventDefault();
        const startY = e.clientY;
        const startH = frame.getBoundingClientRect().height;
        card.classList.add('kp-open--drag');
        grip.setPointerCapture(e.pointerId);
        const move = ev => this.kpSetHeight(card, (Math.max(200, startH + ev.clientY - startY) / window.innerHeight) * 100);
        const up = () => {
            card.classList.remove('kp-open--drag');
            grip.removeEventListener('pointermove', move);
            grip.removeEventListener('pointerup', up);
            grip.removeEventListener('pointercancel', up);
        };
        grip.addEventListener('pointermove', move);
        grip.addEventListener('pointerup', up);
        grip.addEventListener('pointercancel', up);
    },

    kpGripKey(e, grip) {
        if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
        e.preventDefault();
        const card = grip.closest('.kp-open');
        const now = Number((card.querySelector('.kp-size input') || {}).value) || 60;
        this.kpSetHeight(card, now + (e.key === 'ArrowDown' ? 5 : -5));
    },

    /**
     * Счёт по КП — из карточки (issue #38).
     *
     * Счёт создаётся в МойСклад теми же позициями, сразу скачивается его
     * печатная форма и появляется строкой в карточке: приложить к письму
     * одной кнопкой или забрать отдельным файлом — как и просили.
     */
    async kpInvoice(id, btn) {
        // Организаций в карточке может быть несколько — на какую счёт, решает
        // менеджер, а не карточка (модуль 029). Одна организация — вопрос не
        // задаётся вовсе.
        const orgId = await this.pickInvoiceOrg();
        if (orgId === null) return;

        // Кнопка живёт в двух местах: в развёрнутой карточке КП и под таблицей
        // подбора. Во втором случае счёт встаёт строкой под кнопками КП.
        const card = btn.closest('.kp-open');
        btn.disabled = true;
        const label = btn.textContent;
        btn.textContent = 'Выставляем...';
        try {
            const r = await this.api(
                `invoices.php?action=create_from_proposal&proposal_id=${id}&org_id=${orgId}`,
                {method: 'POST', body: {}});
            this.toast(`Счёт ${r.name} выставлен` + (r.order ? `, заказ ${r.order.name} в резерве` : ''),
                       'success');
            if (card) {
                const out = card.querySelector('[data-kp-invoice]')
                    || card.insertBefore(this.dataDiv('kpInvoice'),
                                         card.querySelector('.kp-preview'));
                out.innerHTML = this.kpInvoiceNote(r);
            } else if (r.order_error || (r.order_missing || []).length || (r.skipped || []).length || r.delivery_missing) {
                // Кнопке в колонке некуда положить оговорки — говорим их вслух
                this.toast([
                    r.order_error ? 'Заказ не создан: ' + r.order_error : '',
                    (r.order_missing || []).length ? 'В МойСклад не нашлось: ' + r.order_missing.join('; ') : '',
                    (r.skipped || []).length ? 'В счёт не вошли: ' + r.skipped.join('; ') : '',
                    r.delivery_missing ? 'Доставка в счёт не вошла: укажите услугу доставки в настройках МойСклад' : '',
                ].filter(Boolean).join('. '), 'error');
            }
            // Счёт встаёт под кнопками КП (модуль 048) и строкой под полем
            // письма, откуда его прикладывают (модуль 029)
            const host = document.querySelector('[data-match-host]');
            if (host && host.dataset.requestId) this.loadKpSummary(Number(host.dataset.requestId), host);
            this.refreshInvoiceDock();
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; btn.textContent = label; }
    },

    /**
     * На какую организацию счёт (модуль 029).
     *
     * Возвращает id организации, 0 — сама карточка, `null` — менеджер передумал.
     * Организация одна — спрашивать нечего: лишнее окно на каждом счёте хуже,
     * чем отсутствие выбора там, где выбора нет.
     */
    pickInvoiceOrg() {
        const orgs = (this.company && this.company.orgs) || [];
        if (orgs.length < 2) return Promise.resolve(0);
        return new Promise(resolve => {
            this._orgPick = resolve;
            this.modal('На какую организацию счёт?', `
                <p class="muted">В карточке несколько организаций — счёт выставляется на выбранную.</p>
                ${orgs.map(o => `
                    <button class="btn btn--block ${o.primary ? 'btn--primary' : 'btn--outline'}"
                            style="margin-bottom:6px;text-align:left"
                            ${o.moysklad_id ? '' : 'disabled title="Не связана с МойСклад — счёт выставить не на что"'}
                            onclick="App.finishOrgPick(${o.id})">
                        ${this.esc(o.name)}${o.inn ? ` <small class="muted">ИНН ${this.esc(o.inn)}</small>` : ''}
                        ${o.moysklad_id ? '' : ' <small class="no">нет в МойСклад</small>'}
                    </button>`).join('')}
                <button class="btn btn--outline btn--block" onclick="App.finishOrgPick(null)">Отмена</button>
            `);
        });
    },

    finishOrgPick(orgId) {
        const resolve = this._orgPick;
        this._orgPick = null;
        this.closeModal();
        if (resolve) resolve(orgId);
    },

    /** Что показать о только что выставленном счёте в развёрнутой карточке КП. */
    kpInvoiceNote(r) {
        return `
            <div class="note note--ok" style="margin:10px 0">
                <strong>Счёт ${this.esc(r.name)}</strong> на ${this.fmtMoney(r.sum)}
                ${r.order
                    ? `<div>Заказ <a href="${this.esc(r.order.url)}" target="_blank" rel="noopener">${this.esc(r.order.name)} ↗</a>
                         в резерве — счёт привязан к нему.</div>`
                    : `<div class="muted">Заказ не создан${r.order_error ? ': ' + this.esc(r.order_error) : ''} —
                         счёт выставлен сам по себе.</div>`}
                ${(r.order_missing || []).length
                    ? `<div class="muted">В МойСклад не нашлось: ${this.esc(r.order_missing.join('; '))}</div>` : ''}
                ${r.delivery_missing
                    ? '<div class="no">Доставка в счёт не вошла: укажите услугу доставки (MS_DELIVERY_SERVICE_ID) в настройках</div>' : ''}
                ${(r.skipped || []).length
                    ? `<div class="muted">В счёт не вошли (нет цены или карточки МойСклад):
                       ${this.esc(r.skipped.join('; '))}</div>` : ''}
                ${r.pdf_error ? `<div class="muted">${this.esc(r.pdf_error)}</div>` : ''}
                <div class="flex flex--wrap" style="margin-top:8px;align-items:center">
                    ${r.pdf_url ? `
                        <button class="btn btn--primary btn--sm"
                                onclick="App.attachDoc('invoice', ${r.invoice_id}, this)">📎 Приложить к письму</button>
                        <button class="btn btn--outline btn--sm"
                                onclick="App.saveAs('${r.pdf_url}', 'Счёт ${this.jsStr(r.name)}.pdf')">⬇ Файлом</button>` : ''}
                    <a class="btn btn--outline btn--sm" href="${this.esc(r.url)}" target="_blank" rel="noopener">МойСклад ↗</a>
                </div>
            </div>`;
    },

    /**
     * Приложить к ответу документ, который уже лежит на сервере, — счёт или КП.
     *
     * Композер на карточке один, поэтому вложение уходит в него: если ответ
     * пишется по переписке — в него, если открыт бланк нового письма — в него.
     */
    async attachDoc(kind, id, btn) {
        const composer = document.querySelector('[data-composer]');
        if (!composer) { this.toast('Сначала откройте письмо, к которому приложить', 'error'); return; }
        btn.disabled = true;
        try {
            const r = await this.api('mail.php?action=attach_doc', {method: 'POST', body: {kind, id}});
            const f = r.file;
            composer.querySelector('[data-cmp-files]').insertAdjacentHTML('beforeend', `
                <span class="chip" data-cmp-file="${this.esc(f.name)}">📎 ${this.esc(f.filename)}
                    <a onclick="this.parentElement.remove()" title="Убрать">×</a></span>`);
            this.toast('Файл приложен к письму', 'success');
            composer.scrollIntoView({behavior: 'smooth', block: 'center'});
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; }
    },

    /** The block one position belongs to — a page may hold several tables. */
    matchHost(el) {
        return (el && el.closest && el.closest('[data-match-host]')) || document.getElementById('matchCard');
    },

    // Where a candidate came from: the words of the letter, its meaning, or both
    matchSourceLabel(source) {
        return {words: 'по словам', meaning: 'по смыслу', both: 'по словам и смыслу',
                site_url: 'по ссылке на товар',
                // Слова запроса нашлись только в описании — такая строка стоит
                // ниже всего, что совпало названием, и «ок» ей не ставится
                description: 'по описанию'}[source] || '';
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
                variant_stock: i.variant_stock, source: i.match_source, description: i.comment_text || '',
            }] : []),
            ...(i.variants || []),
        ];
        if (!options.length) return '';
        return `
            <div class="choice">
                <div class="choice__title">Равнозначные варианты — выберите один</div>
                ${options.map(v => `
                    <button type="button" class="choice__opt" data-description="${this.esc(v.description || '')}"
                            onclick="App.chooseMatch(${i.id || 0}, '${this.jsStr(v.moysklad_id)}', this)">
                        <span class="choice__name">${this.esc(v.name)}</span>
                        <span class="muted">${this.fmtMoney(v.price)}${this.stockLabel(v) ? ' · ' + this.stockLabel(v) : ''}
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

    /**
     * ==== Аналог: чем это называл клиент (модуль 036) ====
     *
     * Галочка «аналог» открывает поле, а в поле уже стоит строка из письма или
     * из таблицы клиента. Менеджер её правит — «Шлем ЗШ-1-2М (пр-во Армоком)»
     * закупщик писал для себя, и в КП оно должно читаться так, как читается у
     * него. В документе эта строка встаёт НАД названием нашего товара,
     * курсивом серым: клиент находит в предложении свою позицию, не сверяя два
     * документа глазами.
     *
     * Поле хранится отдельно от `raw_name`: письмо — это то, что написал
     * клиент, и правка менеджера не имеет права его переписывать.
     */
    analogField(i = {}) {
        const on = !!i.is_alternative;
        return `
            <div class="analog" data-analog ${on ? '' : 'hidden'}>
                <span class="muted">в КП над нашим названием:</span>
                <input type="text" data-field="alt_of" value="${this.esc(i.alt_of || i.raw_name || '')}"
                       placeholder="Как эту позицию называл клиент"
                       title="Печатается в КП курсивом серым над названием нашего товара">
            </div>`;
    },

    /** Галочку подняли — поле открылось и ждёт правки; сняли — спряталось. */
    toggleAnalog(box) {
        const row = box.closest('[data-match-row]');
        const field = row && row.querySelector('[data-analog]');
        if (!field) return;
        field.hidden = !box.checked;
        if (!box.checked) return;
        const input = field.querySelector('[data-field="alt_of"]');
        if (input) input.focus();
    },

    /**
     * Вилка цен товара, у которого своей цены нет (модуль 036).
     *
     * У общего товара цена часто стоит только на модификациях — и стоит там
     * по-разному. В поле цены — низ вилки, здесь её верх: строка честно
     * говорит, что «от 1 200» это не вся правда. Цены модификаций совпали —
     * вилки нет и писать нечего.
     */
    priceRangeNote(i = {}) {
        const max = Number(i.price_max) || 0;
        if (max <= (Number(i.price) || 0)) return '';
        return `<span class="muted price-range" title="Цена стоит на модификациях и отличается между ними">
            до ${this.fmtMoney(max)}</span>`;
    },

    matchRow(i = {}, index = -1) {
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
                <input type="hidden" data-field="is_out_of_scope" value="${i.is_out_of_scope ? 1 : 0}">
                <input type="hidden" data-field="price_max" value="${i.price_max ?? 0}">
                <input type="hidden" data-field="price_is_manual" value="${i.price_is_manual ? 1 : 0}">
                <div class="match-row__name">
                    <!-- «Не наша номенклатура» — справа от строки «из письма…» (issue #60) -->
                    <div class="match-row__src">
                        <span class="muted">${i.raw_name ? `из письма: ${this.esc(i.raw_name)}${conf !== null ? ` · совпадение ${conf}%` : ''}${src ? ` · ${src}` : ''}` : 'добавлено вручную'}</span>
                        <button class="btn btn--outline btn--sm ${i.is_out_of_scope ? 'btn--primary' : ''}"
                                title="${i.is_out_of_scope ? 'Вернуть строку в работу' : 'Мы этим не занимаемся: в КП строка встанет серым с прочерками, в ответ клиенту не попадёт'}"
                                onclick="App.setItemScope(this, ${i.id || 0}, ${i.is_out_of_scope ? 0 : 1})">${i.is_out_of_scope ? '↩' : '🚫'}</button>
                        ${index === 0 && !i.is_out_of_scope ? this.hint('match-scope') : ''}
                    </div>
                    ${this.variantNote(i)}
                    ${this.stockNote(i)}
                    ${this.scopeNote(i)}
                    ${this.altNote(i)}
                    <!-- «×» — справа от поля названия (issue #60) -->
                    <div class="match-row__nameline">
                        <input type="text" data-field="product_name" autocomplete="off" placeholder="Название позиции из каталога"
                               value="${this.esc(i.product_name || '')}" oninput="App.matchSuggest(this)" onblur="App.hideSuggest(this)">
                        <button class="btn btn--outline btn--sm" title="Убрать строку"
                                onclick="const h=App.matchHost(this); this.closest('[data-match-row]').remove(); App.updateMatchTotal(h)">×</button>
                        <div class="suggest" hidden></div>
                    </div>
                    ${this.analogField(i)}
                    ${i.needs_choice ? this.matchChoice(i) : ((i.variants || []).length ? `<div class="muted">ещё похожие:
                        ${i.variants.map(v => `<a onclick="App.pickVariant(this, '${this.jsStr(JSON.stringify(v))}')">${this.esc(v.name)}</a>`).join(' · ')}</div>` : '')}
                </div>
                <span class="qty-cell">
                    <input type="number" step="0.01" min="0" data-field="quantity" value="${i.quantity ?? 1}"
                           placeholder="Кол-во" title="Количество" oninput="App.updateMatchTotal(this); App.toggleQtyWarning(this)">
                    ${this.qtyWarning(i)}
                </span>
                <input type="text" data-field="unit" value="${this.esc(i.unit || 'шт.')}" placeholder="Ед." title="Единица измерения">
                <span class="price-cell">
                    <input type="number" step="0.01" min="0" data-field="price" value="${i.price ?? 0}"
                           placeholder="Цена" title="Цена за единицу"
                           oninput="App.updateMatchTotal(this); App.markPriceManual(this)">
                    ${this.priceRangeNote(i)}
                </span>
                <span class="price-opts-slot">${this.priceOptsSelect(i.price_options || {})}</span>
                <input type="text" data-field="notes" value="${this.esc(i.notes || '')}" placeholder="Примечание">
                <label title="Позиция подтверждена менеджером — автоподбор её больше не трогает">
                    <input type="checkbox" data-field="is_confirmed" ${i.is_confirmed ? 'checked' : ''}> ок
                </label>
                <label title="Мы предлагаем не то, что клиент назвал: в КП над нашим названием встанет его собственное">
                    <input type="checkbox" data-field="is_alternative" ${i.is_alternative ? 'checked' : ''}
                           onchange="App.toggleAnalog(this)"> аналог
                </label>
                ${index === 0 ? this.hint('match-analog') : ''}
                ${this.matchRowExtra(i)}
                <!-- Порядок строк — внизу карточки позиции (issue #60) -->
                <div class="match-row__tools match-row__tools--bottom">
                    <span class="match-row__grip" draggable="true" title="Перетащить строку мышью">⠿</span>
                    <button class="btn btn--outline btn--sm" title="Выше" onclick="App.moveMatchRow(this, -1)">↑</button>
                    <button class="btn btn--outline btn--sm" title="Ниже" onclick="App.moveMatchRow(this, 1)">↓</button>
                </div>
            </div>`;
    },

    /**
     * Вторая строка позиции: скидка, условия ожидания и развёрнутый комментарий
     * (модуль 023).
     *
     * Комментарий приходит заполненным: в нём стоит описание товара из
     * МойСклад — то самое, синхронизированное в каталог, а не запрошенное
     * заново. Менеджер правит его здесь, и ровно в этом виде оно печатается
     * карточкой КП. В письмо клиенту описание не уходит: ответ называет
     * позиции, цены и сроки, а читается товар в КП (модуль 032).
     */
    matchRowExtra(i = {}) {
        const backorder = Number(i.is_backorder) === 1 || (i.stock !== null && i.stock !== undefined && Number(i.stock) <= 0);
        // Товара нет — «под заказ» встаёт сама, без ручной галочки (issue #60)
        const waitOn = i.wait_on ? 1 : (backorder ? 1 : 0);
        return `
            <div class="match-extra">
                <div class="match-extra__money">
                    <label title="Скидка на эту позицию, %">скидка
                        <input type="number" step="0.01" min="0" max="100" data-field="discount_percent"
                               value="${i.discount_percent ?? 0}" oninput="App.updateMatchTotal(this)">%
                    </label>
                    <label class="${backorder ? '' : 'muted'}" title="Товара нет на складе: срок ожидания, скидка за ожидание и предоплата">
                        <input type="checkbox" data-field="wait_on" ${waitOn ? 'checked' : ''}
                               onchange="App.updateMatchTotal(this); App.toggleWaitFields(this)"> под заказ
                    </label>
                    <span class="match-extra__wait" ${waitOn ? '' : 'hidden'}>
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
                    </span>
                    ${i.wait_note ? `<span class="muted">${this.esc(i.wait_note)}</span>` : ''}
                </div>
                <div class="match-extra__photos">
                    <!-- Фото выбираются здесь, сразу после товара (модуль 040).
                         Полоса открыта сразу (issue #60): выбор фотографий —
                         часть подбора, а не спрятанная за кнопкой настройка.
                         Кнопка осталась, чтобы длинную таблицу можно было
                         свернуть. -->
                    <button class="btn btn--outline btn--sm" ${i.id ? '' : 'disabled title="Сначала сохраните строку"'}
                            onclick="App.toggleMatchPhotos(this, ${i.id || 0})">🖼 Фото в КП ▾</button>
                    <div class="match-photos" data-match-photos data-item-id="${i.id || 0}"></div>
                </div>
                <textarea data-field="comment_text" rows="4" class="match-extra__comment"
                          data-from-catalog="${i.comment_from_catalog ? 1 : 0}" oninput="this.dataset.fromCatalog = 0"
                          placeholder="Описание товара — печатается в карточке КП. Подставлено из МойСклад, правьте как нужно"
                          >${this.esc(i.comment_text || '')}</textarea>
            </div>`;
    },

    /**
     * Порядок позиций мышью (последний пункт issue #38).
     *
     * Стрелки ↑↓ остаются — на телефоне тащить строку неудобно, — но на
     * десктопе список из десяти позиций собирается перетаскиванием за ручку
     * ⠿, как и ожидается от списка. Обработчик один на всю таблицу: строки
     * перерисовываются, а `[data-match-rows]` — нет.
     */
    bindMatchDnd(host) {
        const box = host && host.querySelector('[data-match-rows]');
        if (!box || box.dataset.dnd === '1') return;
        box.dataset.dnd = '1';
        let dragged = null;

        box.addEventListener('dragstart', e => {
            const grip = e.target.closest('.match-row__grip');
            if (!grip) return;
            dragged = grip.closest('[data-match-row]');
            if (!dragged) return;
            dragged.classList.add('match-row--dragging');
            e.dataTransfer.effectAllowed = 'move';
            // Firefox не начинает перетаскивание без данных
            e.dataTransfer.setData('text/plain', 'row');
        });

        box.addEventListener('dragover', e => {
            if (!dragged) return;
            e.preventDefault();
            const over = e.target.closest('[data-match-row]');
            if (!over || over === dragged) return;
            const r = over.getBoundingClientRect();
            box.insertBefore(dragged, e.clientY < r.top + r.height / 2 ? over : over.nextSibling);
        });

        box.addEventListener('drop', e => e.preventDefault());

        box.addEventListener('dragend', () => {
            if (!dragged) return;
            dragged.classList.remove('match-row--dragging');
            dragged.classList.add('match-row--moved');
            const row = dragged;
            setTimeout(() => row.classList.remove('match-row--moved'), 600);
            dragged = null;
        });
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
        // Подсказка про «не наш профиль» — у строки, которую отбросили
        return `<div class="note note--out">
            <strong>Не наша номенклатура.</strong> В КП встанет серой строкой с прочерками, в ответ клиенту не уйдёт.
            ${i.out_of_scope_reason ? `<span class="muted">Правило: «${this.esc(i.out_of_scope_reason)}»</span>` : ''}
            ${this.hint('match-scope')}
        </div>`;
    },

    /**
     * Остаток так, как его читает человек (модуль 026).
     *
     * У товара с модификациями собственного остатка в МойСклад нет — он лежит
     * на размерах и цветах. Поэтому при выборе позиции показываются
     * КОЛИЧЕСТВА МОДИФИКАЦИЙ, а не ноль абстрактного товара: «остаток 25
     * (S 5, M 13, L 7)». У товара без модификаций — просто его остаток.
     */
    stockLabel(p) {
        if (p === null || p === undefined) return '';
        const free = p.stock;
        if (free === null || free === undefined || free === '') return '';
        const parts = (p.variant_stock || []).filter(v => v && v.label);
        if (!parts.length) return `остаток ${free}`;
        return `остаток ${free} (${parts.map(v => `${this.esc(v.label)} ${v.free}`).join(', ')})`;
    },

    /** Остаток одной карточки — модификации или товара без модификаций. */
    stockPlain(free) {
        if (free === null || free === undefined || free === '') return '';
        // Ноль тоже количество, и врать о нём нечем: строка честно говорит,
        // что эта модификация поедет под заказ
        return Number(free) > 0 ? `остаток ${free}` : 'остаток 0 · под заказ';
    },

    /** Та же разбивка отдельной строкой — под выбранной позицией. */
    stockNote(i) {
        const parts = (i.variant_stock || []).filter(v => v && v.label);
        if (!parts.length) return '';
        return `<div class="muted">по модификациям: ${parts.map(v =>
            `<strong>${this.esc(v.label)}</strong> — ${v.free}`).join(', ')}</div>`;
    },

    /** Размер или цвет, который просила эта строка письма (модуль 022). */
    variantNote(i) {
        if (!i.variant_label) return '';
        const kind = i.variant_kind === 'color' ? 'цвет' : 'размер';
        // Подсказка про модификации — у самой модификации, а не в заголовке таблицы
        return `<div class="muted">модификация · ${this.esc(kind)}: <strong>${this.esc(i.variant_label)}</strong>${this.hint('match-variant')}</div>`;
    },

    /** Отметить строку «не наш профиль» — или вернуть её в работу. */
    async setItemScope(btn, itemId, outOfScope) {
        const host = this.matchHost(btn);
        if (!host) return;
        const requestId = Number(host.dataset.requestId);
        if (!itemId) { this.toast('Сначала сохраните строку', 'error'); return; }
        btn.disabled = true;
        try {
            // Сохранить то, что менеджер уже набрал в ДРУГИХ строках, — иначе
            // перерисовка ниже вернёт их к тому, что лежит в базе, и незасохранённый
            // выбор товара пропадёт (issue #60)
            await this.api(`requests.php?action=items_save&id=${requestId}`, {
                method: 'POST', body: {items: this.collectMatchedItems(host), delivery: this.collectDelivery(host)},
            });
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
        if (price) {
            price.value = sel.value;
            // Выбор из списка — это снова цена «по умолчанию», а не то, что
            // менеджер вписал руками: следующий выбор из списка должен её
            // перетереть (issue #60, вместо убранной галочки «цена вручную»)
            const manual = row.querySelector('[data-field="price_is_manual"]');
            if (manual) manual.value = 0;
            this.updateMatchTotal(sel);
        }
    },

    // Ручной ввод в поле «Цена» помечает её как выставленную человеком — так
    // повторный подбор и общие условия КП её не перетрут (issue #60, замена
    // убранной галочки «цена вручную»)
    markPriceManual(input) {
        const row = input.closest('[data-match-row]');
        const manual = row && row.querySelector('[data-field="price_is_manual"]');
        if (manual) manual.value = 1;
    },

    // «Ждать»/«за ожидание»/«предоплата» видны только когда включено «под заказ»
    toggleWaitFields(checkbox) {
        const wrap = checkbox.closest('.match-extra__money') || checkbox.closest('.conditions__row');
        const fields = wrap && wrap.querySelector('.match-extra__wait');
        if (fields) fields.hidden = !checkbox.checked;
    },

    // Красный «!» у количества: товар есть, но меньше, чем просит клиент
    qtyWarning(i) {
        const stock = Number(i.stock);
        const qty = Number(i.quantity ?? 1);
        if (!(stock > 0) || !(qty > stock)) return '';
        return `<span class="qty-warn" title="На складе ${stock}, запрошено ${qty} — товара меньше, чем нужно">!</span>`;
    },

    toggleQtyWarning(input) {
        const cell = input.closest('.qty-cell');
        if (!cell) return;
        const warn = cell.querySelector('.qty-warn');
        const row = input.closest('[data-match-row]');
        const stock = Number(row && row.querySelector('[data-field="stock"]') && row.querySelector('[data-field="stock"]').value);
        const short = stock > 0 && Number(input.value) > stock;
        if (short && !warn) {
            cell.insertAdjacentHTML('beforeend',
                `<span class="qty-warn" title="На складе ${stock}, запрошено ${input.value} — товара меньше, чем нужно">!</span>`);
        } else if (!short && warn) {
            warn.remove();
        } else if (short && warn) {
            warn.title = `На складе ${stock}, запрошено ${input.value} — товара меньше, чем нужно`;
        }
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
            // Описание принадлежит товару и должно подставляться сразу — а не
            // только когда его подобрала нейросеть (issue #60)
            this.fillCatalogComment(row, btn.dataset.description || '');
            row.classList.remove('match-row--choice');
            btn.closest('.choice').remove();
            this.reloadMatchPhotos(row);
            this.updateMatchTotal(host);
            return;
        }
        const requestId = Number(host && host.dataset.requestId);
        try {
            // Сохранить незасохранённые правки других строк перед перерисовкой
            // всей таблицы (issue #60, та же защита, что и у смены области/условий)
            await this.api(`requests.php?action=items_save&id=${requestId}`, {
                method: 'POST', body: {items: this.collectMatchedItems(host), delivery: this.collectDelivery(host)},
            });
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

    /**
     * Подсказка каталога: КОНКРЕТНЫЕ модификации с КОНКРЕТНЫМИ количествами.
     *
     * Строка «остаток 0 (Coyote Brown 0, Олива 0)» — это справка о товаре,
     * которого на складе нет как такового: выбрать по ней нечего, а в позицию
     * всё равно должен встать цвет или размер со своим артикулом и своей
     * ценой. Поэтому сервер отдаёт сами модификации (`variant_label`,
     * `group_name`), каждую со своим остатком, и только товар без модификаций
     * стоит в списке сам — тоже с количеством (модуль 022).
     */
    matchSuggest(input) {
        const box = input.parentElement.querySelector('.suggest');
        const q = input.value.trim();
        // A hand-typed name is no longer the catalog row that was there before
        const row = input.closest('[data-match-row]');
        row.querySelector('[data-field="moysklad_product_id"]').value = '';
        // Позиции больше нет — нет и её описания, пока не выбрана другая
        const auto = row.querySelector('[data-field="comment_text"][data-from-catalog="1"]');
        if (auto) auto.value = '';
        clearTimeout(this._suggestTimer);
        if (q.length < 2) { box.hidden = true; return; }

        this._suggestTimer = setTimeout(async () => {
            try {
                const d = await this.api('products.php?action=search&limit=8&q=' + encodeURIComponent(q));
                if (!d.items.length) { box.hidden = true; return; }
                let group = null;
                box.innerHTML = d.items.map(p => {
                    // Заголовок — один на семью: имя товара у каждого размера
                    // читать невозможно, а без него непонятно, чей это цвет
                    let head = '';
                    if (p.group_name && p.group_name !== group) {
                        head = `<div class="suggest__group">${this.esc(p.group_name)}${
                            p.group_article ? ' · ' + this.esc(p.group_article) : ''}</div>`;
                    }
                    group = p.group_name || null;
                    // Товар целиком: цена — вилка по модификациям, остаток — их сумма
                    const range = Number(p.price_max) > Number(p.price)
                        ? `${this.fmtMoney(p.price)} — ${this.fmtMoney(p.price_max)}` : this.fmtMoney(p.price);
                    const meta = [p.article || p.code || '', range,
                                  this.stockPlain(p.stock)].filter(Boolean).join(' · ');
                    return head + `
                    <div class="suggest__item ${p.variant_label ? 'suggest__item--variant' : ''} ${p.is_group ? 'suggest__item--group' : ''}"
                         onmousedown="App.pickSuggest(this, '${this.jsStr(JSON.stringify({
                        moysklad_id: p.moysklad_id, name: p.name, article: p.article || p.code || '',
                        unit: p.unit || 'шт.', price: p.price || 0, price_max: p.price_max || 0,
                        stock: p.stock ?? '', prices: p.prices || {},
                        // Описание едет вместе с позицией — иначе поле комментария
                        // остаётся пустым при выборе из подсказки (модуль 032)
                        description: p.description || '',
                    }))}')">
                        <div>${this.esc(p.variant_label || p.name)}${
                            p.is_group ? ' <span class="muted">— весь товар, без размера</span>' : ''}</div>
                        <div class="muted">${this.esc(meta)}</div>
                    </div>`;
                }).join('');
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
        set('price_max', p.price_max || 0);
        set('stock', p.stock);
        // Вилка цен рисуется рядом с ценой и обновляется вместе с выбором
        const range = row.querySelector('.price-cell .price-range');
        if (range) range.remove();
        if (Number(p.price_max) > Number(p.price)) {
            row.querySelector('.price-cell').insertAdjacentHTML('beforeend',
                this.priceRangeNote({price: p.price, price_max: p.price_max}));
        }
        // Описание принадлежит товару, а не строке: выбрали другую позицию —
        // в поле её описание. Написанное менеджером не трогаем (модуль 032).
        this.fillCatalogComment(row, p.description || '');
        row.querySelector('[data-field="is_confirmed"]').checked = true;
        const slot = row.querySelector('.price-opts-slot');
        if (slot) slot.innerHTML = this.priceOptsSelect(p.prices || {});
        const suggest = el.closest ? el.closest('.suggest') : null;
        if (suggest) suggest.hidden = true;
        // Другой товар — другие фотографии (issue #60)
        this.reloadMatchPhotos(row);
        this.updateMatchTotal();
    },

    /**
     * Описание товара — в поле сразу при выборе (модуль 047). Пустое поле тоже
     * считается «из каталога»: раньше оно заполнялось только после
     * перерисовки таблицы. Текст, который писал менеджер, не трогаем.
     */
    fillCatalogComment(row, text) {
        const comment = row.querySelector('[data-field="comment_text"]');
        if (!comment) return;
        if (comment.dataset.fromCatalog !== '1' && comment.value.trim() !== '') return;
        comment.value = text;
        comment.dataset.fromCatalog = '1';
    },

    pickVariant(el, json) {
        const v = JSON.parse(json);
        this.pickSuggest(el, JSON.stringify({
            moysklad_id: v.moysklad_id, name: v.name, article: v.article || '',
            unit: v.unit || 'шт.', price: v.price || 0, stock: v.stock ?? '', prices: v.prices || {},
            description: v.description || '',
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
        let total = rows.reduce((s, r) => s + priceOf(r) * r.quantity, 0);
        // Доставка входит в сумму КП, если её не оплачивают отдельно (модуль 049)
        const delivery = this.collectDelivery(host);
        const apart = delivery && delivery.mode === 'separate';
        if (delivery && !apart) total += delivery.price;
        const noPrice = rows.filter(r => !r.price).length;
        el.innerHTML = all.length || delivery
            ? `Позиций: ${rows.length} · сумма по каталогу: ${this.fmtMoney(total)}`
              + (delivery ? (apart ? ` · доставка отдельно: ${this.fmtMoney(delivery.price)}`
                                   : ` · в т.ч. доставка ${this.fmtMoney(delivery.price)}`) : '')
              + (noPrice ? ` · без цены: ${noPrice}` : '')
              + (dropped ? ` · не наша номенклатура: ${dropped}` : '')
            : '';
    },

    // $from — any element of the table (a button of its own toolbar)
    /**
     * ==== Подбор сохраняется сам (issue #60) ====
     *
     * Кнопки «Сохранить» больше нет. Она была источником целого класса потерь:
     * менеджер правил строки, нажимал что-нибудь, что перерисовывает таблицу с
     * сервера, — и правки исчезали, потому что сохранить их он не успел.
     *
     * Слушатель один на весь блок подбора: строки перерисовываются, а
     * `[data-match-host]` — нет. Правка откладывается на 1,2 секунды, чтобы
     * набор названия не превратился в тридцать запросов подряд.
     *
     * Автосохранение НЕ перерисовывает таблицу — курсор остаётся там, где его
     * оставили. Идентификаторы новых строк, которые придумал сервер,
     * проставляются на месте: без них строке некуда сохранять фотографии.
     */
    bindMatchAutosave(host) {
        if (!host || host.dataset.autosave === '1') return;
        host.dataset.autosave = '1';
        const schedule = e => {
            // Фотографии сохраняются сами и своим запросом; общие условия
            // проставляются кнопкой «Применить ко всем» — им автосейв не нужен
            if (e.target.closest('[data-match-photos]') || e.target.closest('[data-conditions]')) return;
            if (!e.target.closest('[data-match-row], [data-delivery-row]')) return;
            clearTimeout(host._autosaveTimer);
            host._autosaveTimer = setTimeout(() => this.autosaveMatch(host), 1200);
        };
        host.addEventListener('input', schedule);
        host.addEventListener('change', schedule);
    },

    /** Тихое сохранение: без тоста и без перерисовки. */
    async autosaveMatch(host) {
        const requestId = Number(host && host.dataset.requestId);
        if (!requestId) return;
        const note = host.querySelector('[data-match-saved]');
        if (note) note.textContent = 'сохраняем...';
        try {
            const res = await this.api(`requests.php?action=items_save&id=${requestId}`, {
                method: 'POST', body: {items: this.collectMatchedItems(host), delivery: this.collectDelivery(host)},
            });
            this.adoptItemIds(host, res.items || []);
            // Полосы, ждавшие сохранения нового товара, теперь спросят сервер и
            // получат фотографии ЭТОГО товара, а не предыдущего (issue #60)
            host.querySelectorAll('[data-match-photos][data-state="stale"]').forEach(box => {
                box.dataset.state = '';
                if (Number(box.dataset.itemId) > 0) this.loadMatchPhotos(box);
                else box.innerHTML = '<div class="muted">Фотографии подтянутся после сохранения строки.</div>';
            });
            if (note) note.textContent = 'сохранено ' + new Date().toLocaleTimeString('ru-RU',
                {hour: '2-digit', minute: '2-digit'});
        } catch (err) {
            if (note) note.textContent = 'не сохранилось: ' + err.message;
        }
    },

    /**
     * Проставить строкам идентификаторы, которые придумал сервер.
     *
     * Сопоставление по порядку — тот же порядок, в котором строки уезжали, —
     * и только когда числа сошлись: сервер выбрасывает пустые строки, и при
     * расхождении угадывать нельзя. Не сошлось — идентификаторы приедут со
     * следующей полной перерисовкой.
     */
    adoptItemIds(host, items) {
        const rows = [...host.querySelectorAll('[data-match-row]')];
        if (rows.length !== items.length) return;
        rows.forEach((row, n) => {
            const idField = row.querySelector('[data-field="id"]');
            const id = Number(items[n].id || 0);
            if (!idField || !id || Number(idField.value) === id) return;
            idField.value = id;
            const box = row.querySelector('[data-match-photos]');
            if (box && Number(box.dataset.itemId) === 0) {
                box.dataset.itemId = id;
                const btn = row.querySelector('.match-extra__photos .btn');
                if (btn) { btn.disabled = false; btn.removeAttribute('title'); }
                // Полосу со `state="stale"` догрузит общий проход ниже
                if (box.dataset.state !== 'stale') this.loadMatchPhotos(box);
            }
        });
    },

    async saveMatchedItems(from, silent = false) {
        const host = this.matchHost(from);
        if (!host) return [];
        const requestId = Number(host.dataset.requestId);
        const res = await this.api(`requests.php?action=items_save&id=${requestId}`, {
            method: 'POST', body: {items: this.collectMatchedItems(host), delivery: this.collectDelivery(host)},
        });
        if (!silent) this.toast('Позиции сохранены', 'success');
        this.renderMatchedItems(requestId, res.items || [], host, {...this.matchOpts(host), delivery: res.delivery});
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
            await this.api(`requests.php?action=items_save&id=${requestId}`, {method: 'POST',
                body: {items: pending, delivery: this.collectDelivery(host)}});
            const res = await this.api(`requests.php?action=items_rematch&id=${requestId}&smart=${smart ? 1 : 0}`, {method: 'POST'});
            this.renderMatchedItems(requestId, res.items || [], host, {...opts, delivery: res.delivery});
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
        const opts = {};
        if (host && host.dataset.kp) opts.kp = JSON.parse(host.dataset.kp);
        if (host && host.dataset.delivery) opts.delivery = JSON.parse(host.dataset.delivery);
        return opts;
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
            <div class="modal__box card" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
                <div class="flex flex--between" style="margin-bottom:12px">
                    <strong id="modalTitle">${this.esc(title)}</strong>
                    <button class="btn btn--sm btn--outline" onclick="App.closeModal()" aria-label="Закрыть" title="Закрыть">✕</button>
                </div>
                ${html}
            </div>`;
        if (!this._modalEsc) {
            this._modalEsc = true;
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape' && document.getElementById('modal')) this.closeModal();
            });
        }
        el.querySelector('input, select, textarea, button:not([aria-label="Закрыть"])')?.focus();
    },

    closeModal() {
        const el = document.getElementById('modal');
        if (el) el.remove();
        // Окно с вопросом закрыли крестиком или уходом со страницы — тот, кто
        // его ждал, должен узнать об этом, а не висеть вечно (модуль 029)
        if (this._orgPick) { const r = this._orgPick; this._orgPick = null; r(null); }
    },

    /**
     * Собрать КП — и показать его ЗДЕСЬ ЖЕ.
     *
     * Раньше кнопка уводила на отдельную страницу редактора: менеджер терял
     * письмо, позиции и поле ответа, а по несобравшемуся КП попадал на
     * «КП #undefined не найдено». Теперь КП раскрывается предпросмотром в той
     * же карточке — всё управление остаётся в одном месте.
     */
    async generateKP(requestId, from) {
        const btn = from || document.getElementById('genBtn');
        const label = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = 'Собираем КП...'; }
        // Сборка КП — это каталог, реквизиты, фотографии и рендер PDF: полминуты
        // на живом каталоге. Молчащий экран всё это время читался как «кнопка не
        // работает», поэтому ход работы виден и ошибка тоже (модуль 035).
        const say = this.kpProgress(from, 'Собираем КП: каталог, реквизиты, фотографии, документ...');
        try {
            // The KP is built from «Подходящие позиции» — send the table as it
            // looks on screen, not as it was last saved. On the letter card
            // several tables can be open, so take this request's own.
            const host = document.querySelector(`[data-match-host][data-request-id="${requestId}"]`);
            if (host && host.querySelector('[data-match-row]')) {
                await this.saveMatchedItems(host, true);
            }
            say('Собираем документ — это может занять до минуты...');
            const id = this.proposalId(
                await this.api(`proposals.php?action=generate&request_id=${requestId}`, {method: 'POST', body: {}}));
            say('');
            this.toast('КП сформировано', 'success');
            if (host) {
                this.setKpButtons(requestId, host, {proposal_id: id});
                this.openKp(id, host);
            } else {
                location.hash = `mail/proposal/${id}`;
            }
        } catch (err) {
            // Ошибка остаётся НА ЭКРАНЕ: тост гаснет через пару секунд, а
            // разбираться с «не собралось» приходится дольше
            say(err.message, true);
            this.toast(err.message, 'error');
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = label || 'Сформировать КП'; }
        }
    },

    /**
     * Строка хода работы под кнопками подбора (модуль 035).
     *
     * Возвращает функцию: зовут её с текстом — он появляется, с пустым — гаснет,
     * со вторым аргументом — остаётся красным до следующего действия.
     */
    kpProgress(from, initial = '') {
        const host = this.matchHost(from);
        if (!host) return () => {};
        // Строка живёт РЯДОМ с таблицей подбора, а не внутри неё: сборка КП
        // начинается с сохранения позиций, а оно перерисовывает таблицу целиком
        // — вложенная строка исчезала вместе с ней, не успев показаться
        // (модуль 035)
        let box = host.parentElement && host.parentElement.querySelector('[data-kp-progress]');
        if (!box) {
            box = document.createElement('div');
            box.dataset.kpProgress = '1';
            host.insertAdjacentElement('afterend', box);
        }
        const say = (text, bad = false) => {
            box.className = text ? (bad ? 'no' : 'loading') : '';
            box.innerHTML = text ? this.esc(text) : '';
        };
        if (initial) say(initial);
        return say;
    },

    // Assign request to current manager
    async assignRequest(id) {
        await this.api(`requests.php?action=assign&id=${id}`, {method:'POST'});
        this.toast('Запрос взят в работу', 'success');
        location.hash = `mail/request/${id}`;
    },

    // Proposal editor
    async pageProposal(id) {
        // Адрес мог прийти битым («#mail/proposal/undefined» после неудачной
        // сборки). Такой экран — не «КП не найдено», а сломанная ссылка, и
        // говорить он должен именно это, да ещё и с дорогой обратно.
        const num = Number(id);
        if (!Number.isInteger(num) || num <= 0) {
            document.getElementById('app').innerHTML = `
                <div class="card card--alert">
                    <div class="card__title">Ссылка на КП битая</div>
                    <p>В адресе нет номера КП. Откройте КП из письма — кнопкой «Открыть КП» под таблицей позиций.</p>
                    <a class="btn btn--primary btn--sm" href="#mail">← К письмам</a>
                </div>`;
            return;
        }
        const proposal = await this.api(`proposals.php?action=get&id=${num}`).catch(() => null);
        if (!proposal) {
            document.getElementById('app').innerHTML = `
                <div class="card card--alert">
                    <div class="card__title">КП #${num} не найдено</div>
                    <p>Возможно, его удалили вместе с запросом.</p>
                    <a class="btn btn--primary btn--sm" href="#mail">← К письмам</a>
                </div>`;
            return;
        }
        id = num;
        this.proposal = proposal;
        const items = proposal.items || [];
        const addons = proposal.addons || [];

        document.getElementById('app').innerHTML = `
            ${this.pageHead({
                back: proposal.request_id ? `#mail/request/${proposal.request_id}` : '#mail',
                backLabel: proposal.request_id ? `← Запрос #${proposal.request_id}` : '← Письма',
                title: `КП #${this.esc(proposal.number) || id}`,
                after: this.hint('kp-editor'),
                actions: `
                    <button class="btn btn--outline" onclick="App.refreshPreview(${id})">Обновить PDF</button>
                    <button class="btn btn--primary" onclick="App.confirmAndSend(${id})">Подтвердить и отправить</button>`,
            })}
            ${this.proposalWarnings(proposal)}
            <div class="grid grid--2">
                <div>
                    <!-- Текст письма правится только в поле письма (модуль 049) -->
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
                    <!-- Условия одним правимым блоком вместо четырёх зашитых фраз
                         внизу документа (модуль 026). Гарантию мы по умолчанию не
                         обещаем, доставку в стоимость не включаем — и то и другое
                         теперь видно и правится здесь. -->
                    <div class="card">
                        <div class="card__title">Условия поставки</div>
                        <textarea id="termsText" rows="5"
                            placeholder="Печатается в конце КП. Пусто — условий в документе не будет."
                            >${this.esc(proposal.terms_text_edit || '')}</textarea>
                        <div class="muted" style="margin-top:4px"><code>{execution_term}</code> —
                            срок исполнения словами: дни из поля ниже, а если хоть одна позиция под заказ —
                            срок ожидания из таблицы подбора. <code>{validity_days}</code> — срок действия цены.
                            Сохранение делает этот текст заготовкой для следующих КП.</div>
                    </div>
                    <!-- Доставка отдельной строкой: она не спрятана в цене товара -->
                    <div class="card">
                        <div class="card__title">Доставка</div>
                        <p class="muted">Это значения ЭТОГО КП. Обычно доставку правят строкой под
                           позициями подбора — и она уходит во все КП запроса; собранное оттуда КП
                           перепишет то, что стоит здесь (модуль 034).</p>
                        <div class="form-group">
                            <label><input type="checkbox" id="deliveryOn" ${proposal.delivery_on == 1 ? 'checked' : ''}>
                                Доставка в этом КП</label>
                        </div>
                        <div class="form-group">
                            <label>Как учитывать</label>
                            ${this.deliveryModeSelect(proposal.delivery_mode || (this.ui || {}).delivery_mode, 'id="deliveryMode"')}
                        </div>
                        <div class="grid grid--2">
                            <div class="form-group">
                                <label>Как назвать в КП</label>
                                <input type="text" id="deliveryName" placeholder="Доставка"
                                       value="${this.esc(proposal.delivery_name || '')}">
                            </div>
                            <div class="form-group">
                                <label>Стоимость, руб.</label>
                                <input type="number" step="0.01" min="0" id="deliveryPrice"
                                       value="${proposal.delivery_price ?? 0}">
                            </div>
                        </div>
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
                                <!-- НДС печатается в КП всегда (модуль 030); выбирается
                                     только вид цены — с налогом внутри или с налогом сверху -->
                                <label>НДС в ценах</label>
                                <select id="vatMode">
                                    <option value="">как в настройках</option>
                                    <option value="included" ${proposal.vat_mode === 'included' ? 'selected' : ''}>цена в т.ч. НДС</option>
                                    <option value="added" ${proposal.vat_mode === 'added' ? 'selected' : ''}>цена + НДС сверху</option>
                                </select>
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
                    <span onclick="event.stopPropagation()">${this.hint('kp-exclude')}</span>
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
                <div class="photos__head">
                    <span class="muted">Фото в этом КП (<span data-photo-count>${chosen.length}</span> из ${d.available.length}):</span>
                    <button type="button" class="btn btn--outline btn--sm" data-photo-reset
                            ${chosen.length ? '' : 'disabled'}
                            onclick="App.resetPhotos(this, 0)">Сбросить выбор</button>
                </div>
                <div class="photos">
                    ${d.available.map(a => `
                        <label class="photo ${chosen.includes(a.key) ? 'photo--on' : ''}">
                            <input type="checkbox" data-photo-key="${this.esc(a.key)}"
                                   ${chosen.includes(a.key) ? 'checked' : ''}
                                   onchange="this.closest('.photo').classList.toggle('photo--on', this.checked); App.photoCount(this)">
                            <img src="${this.esc(a.url)}" alt="" loading="lazy">
                        </label>`).join('')}
                </div>`;
        } catch (err) {
            box.innerHTML = `<div class="no">${this.esc(err.message)}</div>`;
        }
    },

    /**
     * ==== Фотографии позиции прямо в таблице подбора (модуль 040) ====
     *
     * Картинки выбирались только в уже собранном КП — то есть после того, как
     * документ собран, а не тогда, когда определились с товаром. Теперь выбор
     * стоит на строке подбора и едет в КП вместе с позицией; уже собранные
     * неотправленные КП этого запроса подхватывают его сразу.
     */
    toggleMatchPhotos(btn, itemId) {
        const box = btn.parentElement.querySelector('[data-match-photos]');
        if (!box || !itemId) return;
        box.hidden = !box.hidden;
        btn.textContent = box.hidden ? '🖼 Фото в КП ▸' : '🖼 Фото в КП ▾';
        if (!box.hidden) this.loadMatchPhotos(box);
    },

    /**
     * ==== Фотографии грузятся по одной, и только когда нужны (issue #60) ====
     *
     * Таблица подбора на двадцать позиций открывала сотню картинок разом:
     * браузер вставал, а менеджер смотрел на пустые рамки. Теперь полоса
     * спрашивает список, только подъехав к экрану (`IntersectionObserver`), а
     * внутри полосы миниатюры выстраиваются в очередь: `src` следующей
     * ставится после `load` или `error` предыдущей. Первая фотография на
     * экране, пока остальные ещё едут.
     */
    watchMatchPhotos(host) {
        const boxes = [...(host || document).querySelectorAll('[data-match-photos]')]
            .filter(b => !b.hidden && Number(b.dataset.itemId) > 0 && b.dataset.state !== 'done');
        if (!boxes.length) return;
        if (!('IntersectionObserver' in window)) { boxes.forEach(b => this.loadMatchPhotos(b)); return; }
        if (!this.photoObserver) {
            this.photoObserver = new IntersectionObserver(entries => {
                entries.forEach(e => {
                    if (!e.isIntersecting) return;
                    this.photoObserver.unobserve(e.target);
                    this.loadMatchPhotos(e.target);
                });
            }, {rootMargin: '300px'});
        }
        boxes.forEach(b => this.photoObserver.observe(b));
    },

    /** Список фотографий строки — один запрос, и он не повторяется впустую. */
    async loadMatchPhotos(box) {
        const itemId = Number(box && box.dataset.itemId);
        // 'stale' — строка ждёт сохранения нового товара: спросить сервер сейчас
        // значит снова получить фотографии предыдущего
        if (!box || !itemId || ['loading', 'done', 'stale'].includes(box.dataset.state)) return;
        box.dataset.state = 'loading';
        box.innerHTML = '<div class="loading">Загружаем фотографии...</div>';
        try {
            const d = await this.api(`requests.php?action=item_images&item_id=${itemId}`);
            box.dataset.state = 'done';
            if (!d.available.length) {
                box.innerHTML = '<div class="muted">Фотографий у позиции нет. Их приносит синхронизация '
                              + 'с МойСклад или импорт каталога из Excel.</div>';
                return;
            }
            // «Выбор не делали» — это все фотографии, как было до выбора
            const chosen = d.selected === null ? d.available.map(a => a.key) : d.selected;
            box.innerHTML = `
                <div class="photos__head">
                    <span class="muted">В КП пойдут отмеченные (<span data-photo-count>${chosen.length}</span> из ${d.available.length}):</span>
                    <!-- Сбросить всё и отметить только нужное (issue #67) -->
                    <button type="button" class="btn btn--outline btn--sm" data-photo-reset
                            ${chosen.length ? '' : 'disabled'}
                            onclick="App.resetPhotos(this, ${itemId})">Сбросить выбор</button>
                </div>
                <div class="photos">
                    ${d.available.map(a => `
                        <label class="photo ${chosen.includes(a.key) ? 'photo--on' : ''}">
                            <input type="checkbox" data-photo-key="${this.esc(a.key)}"
                                   ${chosen.includes(a.key) ? 'checked' : ''}
                                   onchange="this.closest('.photo').classList.toggle('photo--on', this.checked);
                                             App.photoCount(this); App.saveMatchPhotos(this, ${itemId})">
                            <img data-src="${this.esc(a.url)}" alt="" loading="lazy">
                        </label>`).join('')}
                </div>
                <div class="muted" data-photo-saved></div>`;
            this.chainPhotos(box);
        } catch (err) {
            box.dataset.state = '';
            box.innerHTML = `<div class="no">${this.esc(err.message)}</div>`;
        }
    },

    /** Миниатюры одной полосы — строго по очереди, а не все разом. */
    chainPhotos(box) {
        const imgs = [...box.querySelectorAll('img[data-src]')];
        const next = () => {
            const img = imgs.shift();
            if (!img) return;
            img.addEventListener('load', next, {once: true});
            // Картинка, которой нет, не должна останавливать очередь
            img.addEventListener('error', next, {once: true});
            img.src = img.dataset.src;
            img.removeAttribute('data-src');
        };
        next();
    },

    /**
     * Товар на строке поменялся — полоса фотографий перечитывается (issue #60).
     *
     * Без этого на экране оставались картинки ПРЕДЫДУЩЕГО товара: полоса
     * грузилась один раз и больше себя не спрашивала. У строки, которую ещё
     * не сохранили, спрашивать нечего — там стоит просьба сохранить.
     */
    reloadMatchPhotos(row) {
        const box = row && row.querySelector('[data-match-photos]');
        if (!box) return;
        // Спрашивать сервер прямо сейчас нельзя: новый товар стоит пока только
        // на экране, и в ответ приедут фотографии ПРЕЖНЕГО. Полоса ждёт, пока
        // автосохранение довезёт строку, и обновляется после него.
        box.dataset.state = 'stale';
        box.innerHTML = '<div class="muted">Обновляем фотографии...</div>';
        this.touchMatch(row);
    },

    /**
     * Строку правили не руками, а выбором из списка — сохранить её поскорее.
     *
     * Выбор товара мышью не поднимает `input`, а значит и автосохранение: без
     * этого новая позиция висела бы несохранённой, пока менеджер не тронет
     * какое-нибудь поле.
     */
    touchMatch(el) {
        const host = this.matchHost(el);
        if (!host) return;
        clearTimeout(host._autosaveTimer);
        host._autosaveTimer = setTimeout(() => this.autosaveMatch(host), 400);
    },

    /** Счётчик «отмечено N» и доступность «Сбросить выбор» у полосы фото. */
    photoCount(el) {
        const strip = el.closest('[data-match-photos], [data-photos]');
        if (!strip) return;
        const n = strip.querySelectorAll('[data-photo-key]:checked').length;
        const count = strip.querySelector('[data-photo-count]');
        if (count) count.textContent = n;
        const reset = strip.querySelector('[data-photo-reset]');
        if (reset) reset.disabled = n === 0;
    },

    /**
     * «Сбросить выбор» (issue #67): снять все галочки разом, чтобы отметить
     * только нужные. Пустой выбор — это решение «без фото», оно сохраняется.
     * $itemId = 0 — полоса редактора КП: там выбор уходит с «Сохранить».
     */
    resetPhotos(btn, itemId) {
        const strip = btn.closest('[data-match-photos], [data-photos]');
        if (!strip) return;
        const boxes = [...strip.querySelectorAll('[data-photo-key]')];
        boxes.forEach(b => { b.checked = false; b.closest('.photo').classList.remove('photo--on'); });
        this.photoCount(btn);
        if (itemId && boxes.length) this.saveMatchPhotos(boxes[0], itemId);
    },

    /** Галочка на фотографии сохраняется сама — «Сохранить» для неё не нужно. */
    async saveMatchPhotos(input, itemId) {
        const box = input.closest('[data-match-photos]');
        const selected = [...box.querySelectorAll('[data-photo-key]')]
            .filter(b => b.checked).map(b => b.dataset.photoKey);
        const note = box.querySelector('[data-photo-saved]');
        try {
            const r = await this.api(`requests.php?action=item_images_save&item_id=${itemId}`,
                                     {method: 'POST', body: {selected}});
            if (note) note.textContent = `выбрано ${selected.length}`
                + (r.kp_items ? ` · в КП обновлено позиций: ${r.kp_items}` : '');
        } catch (err) {
            if (note) note.textContent = 'не сохранилось: ' + err.message;
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
            pre_table_text: document.getElementById('preTable').value,
            post_table_text: document.getElementById('postTable').value,
            terms_text: document.getElementById('termsText').value,
            delivery_on: document.getElementById('deliveryOn').checked ? 1 : 0,
            delivery_name: document.getElementById('deliveryName').value,
            delivery_price: parseFloat(document.getElementById('deliveryPrice').value) || 0,
            delivery_mode: document.getElementById('deliveryMode').value,
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
            vat_mode: document.getElementById('vatMode').value,
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
        this.foldKey = 'cp:' + id;
        document.getElementById('app').innerHTML = `
            ${this.pageHead({
                title: this.esc(cp.name),
                after: this.answerBadge(cp.answer_state),
                actions: cp.moysklad_id ? `<a class="btn btn--outline btn--sm" target="_blank" rel="noopener"
                    href="https://online.moysklad.ru/app/#counterparty/edit?id=${cp.moysklad_id}">МойСклад ↗</a>` : '',
                menu: [
                    {label: 'Обновить из МойСклад', onclick: `App.syncCompany(${cp.id})`},
                    (cp.senders_count || 0) > 1 ? {label: `Разделить по отправителям (${cp.senders_count})`,
                        title: `В карточке ${cp.senders_count} разных отправителей — развести по своим компаниям`,
                        onclick: `App.splitSenders(${cp.id}, ${cp.senders_count})`} : null,
                ],
            })}
            <div id="cardPlacement"></div>
            <!-- Одна раскладка для письма, откуда его ни открой: переписка
                 слева, подбор позиций справа на десктопе и снизу на телефоне.
                 Раньше карточка компании складывала подбор ВНУТРЬ переписки, а
                 страница письма — сбоку, и это читалось как два разных экрана
                 (issue #38). Теперь у обеих одна сетка. -->
            <div class="letter">
                <div class="letter__main">
                    <div class="card card--flush">
                        <!-- Кнопки «Архив» здесь больше нет (модуль 026): она
                             переключала ВЕСЬ список между работой и архивом, и
                             найти одну убранную переписку среди двух видов было
                             нечем. Архивные переписки стоят тут же, под рабочими,
                             отдельным блоком. -->
                        <div class="card__title" style="padding:12px 16px 0">
                            <span>Переписка${this.hint('thread')}</span>
                        </div>
                        <div id="cpThreads"><div class="loading">Загрузка...</div></div>
                    </div>
                </div>
                <aside class="letter__side">
                    <!-- Подбор открытой переписки — один на карточку. Пока
                         переписка раскрыта, он стоит в ней над полем письма
                         (issue #60), свёрнута — ждёт здесь -->
                    <div id="cpItems" data-thread-items></div>
                    <div id="companySide">${this.companySide(cp)}</div>
                </aside>
            </div>
            <!-- КП раскрывается здесь, во всю ширину: в колонке подбора
                 документ читать нечем — но и новой вкладки для него нет -->
            <div id="kpWide"></div>
        `;
        this.loadCompanyThreads(cp.id);
        this.loadCardPlacement(cp.id);
        this.loadChat(cp.id);

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
        // Панель подбора может стоять внутри переписки — не стираем её с ней
        this.parkCompanyItems();
        try {
            // Один запрос на обе половины: рабочие переписки и те, что убрали в
            // архив. Раньше это были два разных вида одного экрана, между
            // которыми переключала кнопка, — и переписка, которой на экране нет,
            // выглядела как потерянная (модуль 026).
            const d = await this.api(`counterparties.php?action=threads&id=${id}&archived=all`);
            const all = d.items || [];
            this.companyThreads = all.filter(t => !t.archived_at);
            const archivedThreads = all.filter(t => t.archived_at);
            this.companyMailboxes = d.mailboxes || this.companyMailboxes || [];
            // Архив — свёрнутой строкой СВЕРХУ, над рабочим списком: в самом
            // низу карточки должно стоять последнее письмо, а не архив (модуль 029)
            box.innerHTML = (archivedThreads.length ? `
                    <details class="mlist-archive">
                        <summary>Архив компании — переписок: ${archivedThreads.length}</summary>
                        <div class="mlist">${archivedThreads.map(t => this.companyThreadRow(t)).join('')}</div>
                    </details>` : '')
                + (this.companyThreads.length
                    ? `<div class="mlist">${this.companyThreads.map(t => this.companyThreadRow(t)).join('')}</div>`
                    : this.newLetterHtml(this.company || {id}));
            // Открытая переписка — а не кнопка «написать»: письмо, позиции по
            // каталогу и поле ответа видны сразу, без единого нажатия (модуль 019).
            // Раскрывается ПОСЛЕДНЯЯ — самая свежая, та, что стоит внизу списка,
            // чьей бы она ни была: карточку открывают, чтобы увидеть последнее
            // письмо, а не последнее неотвеченное (модуль 029). Раскрытие само
            // по себе письма не читает — отметку ставит нажатие менеджера.
            const latest = this.companyThreads[this.companyThreads.length - 1];
            if (latest) {
                await this.toggleCompanyThread(latest.thread_key, {markRead: false});
                const row = box.querySelector(`[data-thread="${CSS.escape(latest.thread_key)}"]`);
                if (row) row.scrollIntoView({block: 'center'});
            } else {
                this.setCompanyItems(null);
            }
            // «Написать новое письмо» — отдельной строкой под всем списком:
            // новое письмо не продолжает старую переписку, поэтому и стоит оно
            // под ней, а не внутри. Бланк разворачивается на месте кнопки.
            if (this.companyThreads.length) {
                box.insertAdjacentHTML('beforeend', `
                    <div style="padding:0 16px 16px">
                        <button class="btn btn--outline btn--sm" data-new-letter
                                onclick="App.openNewLetter(this, ${id})">✉ Написать новое письмо</button>
                        <span class="muted" style="margin-left:8px">Переписка выше разворачивается нажатием.</span>
                    </div>`);
            }
        } catch (err) {
            box.innerHTML = `<div class="mlist__empty">Переписка не загрузилась: ${this.esc(err.message)}</div>`;
        }
    },

    /**
     * Компании ещё не писали — поле ответа всё равно открыто. Раньше здесь была
     * кнопка «Написать» наверху карточки, которая открывала окно поверх экрана;
     * теперь письмо пишется там же, где читается переписка.
     */
    /** «Написать новое письмо» — бланк разворачивается на месте кнопки. */
    openNewLetter(btn, id) {
        const host = btn.closest('div');
        if (!host) return;
        host.outerHTML = this.newLetterHtml(this.company || {id}, '');
        this.restoreComposerDraft('');
    },

    newLetterHtml(cp, note = 'Писем от этой компании ещё нет — напишите первым.') {
        const to = cp.suggested_email || cp.contact_email || '';
        return `<div style="padding:0 16px 16px">
            ${note ? `<p class="muted">${this.esc(note)}</p>` : ''}
            ${this.threadComposer('', {to, subject: '', counterparty_id: cp.id}, this.companyMailboxes || [])}
        </div>`;
    },

    /**
     * Строка переписки в карточке компании.
     *
     * Ровно три этажа и ни одного вложенного слоя: шапка, под ней — лента
     * писем, под ней — ответ. Раньше строка была ячейкой сетки списка, внутрь
     * которой складывалась вторая прокручиваемая область с письмами, а в
     * каждом письме стояла рамка фиксированной высоты: письма налезали друг на
     * друга и обрезались (модуль 031).
     */
    companyThreadRow(t) {
        const cls = ['conv', t.unanswered ? 'conv--wait' : 'conv--done'];
        if (t.unread) cls.push('conv--unread');
        const kp = t.proposal
            ? `<a class="chip chip--kp" href="#mail/proposal/${t.proposal.id}" onclick="event.stopPropagation()">КП ${this.esc(t.proposal.number || '#' + t.proposal.id)}</a>`
            : (t.request_id ? `<a class="chip" href="#mail/request/${t.request_id}" onclick="event.stopPropagation()">запрос #${t.request_id}</a>` : '');
        const key = this.jsStr(t.thread_key);
        // Строка как в Gmail (issue #60): кто · тема — начало письма · дата.
        // Действия всплывают на месте даты при наведении, а не лежат отдельной
        // строкой под каждой перепиской
        return `
            <article class="${cls.join(' ')}" data-thread="${this.esc(t.thread_key)}">
                <div class="conv__head" onclick="App.toggleCompanyThread('${key}')">
                    <span class="conv__who" title="${this.esc(t.last_from_name || '')}">
                        <span class="conv__dir" title="${t.last_direction === 'in' ? 'последнее письмо клиента' : 'последнее письмо наше'}"
                              >${t.last_direction === 'in' ? '📥' : '📤'}</span>
                        <span class="conv__name">${this.esc(t.last_from_name || (t.last_mine ? 'Мы' : 'Клиент'))}</span>
                        ${t.count > 1 ? `<span class="conv__count" title="писем в переписке">${t.count}</span>` : ''}
                    </span>
                    <div class="conv__main">
                        <div class="conv__line">
                            <span class="conv__subject">${this.esc(t.subject) || '<em>без темы</em>'}</span>
                            ${t.preview ? `<span class="conv__preview">— ${this.esc(t.preview)}</span>` : ''}
                        </div>
                        <div class="conv__meta">
                            <!-- ЧЬЁ последнее письмо — словами: стрелка в списке из
                                 пятнадцати строк не читается (модуль 029) -->
                            <span class="chip ${t.last_mine ? 'chip--mine' : 'chip--theirs'}">${t.last_mine
                                ? 'последнее письмо наше' : 'последнее письмо клиента'}</span>
                            ${t.unread ? `<span class="pill pill--danger" title="непрочитанных">${t.unread}</span>` : ''}
                            ${t.category ? this.categoryBadge(t.category, App.categoryLabels[t.category]) : ''}
                            ${kp}
                            ${t.unanswered ? '<span class="badge badge--unanswered">ждёт ответа</span>' : ''}
                            ${(t.mailboxes || []).map(b => `<span class="chip chip--box">${this.esc(b.name)}</span>`).join('')}
                            ${t.archived_at ? `<span class="muted">${t.archived_reason === 'mailbox_off' ? 'ящик отключён' : 'не наш профиль'}</span>` : ''}
                        </div>
                    </div>
                    <span class="conv__date muted">${this.fmtDate(t.last_at)}</span>
                    <span class="conv__acts" onclick="event.stopPropagation()">
                        ${t.archived_at
                            ? `<button class="conv__act" title="Вернуть в работу" onclick="App.unarchiveThread('${key}')">↩</button>`
                            : `<button class="conv__act" title="В архив (не наш профиль): на сервере переписка уйдёт в «Архив»"
                                       onclick="App.archiveThread('${key}', ${t.count})">🗄</button>`}
                        <button class="conv__act" title="Удалить переписку" onclick="App.deleteThread('${key}', ${t.count})">🗑</button>
                    </span>
                </div>
                <div class="conv__open" id="th_${this.esc(this.threadDomId(t.thread_key))}" hidden></div>
            </article>`;
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
        if (!box.hidden) {
            box.hidden = true;
            const conv = box.closest('.conv');
            if (conv) conv.classList.remove('conv--open');
            this.setCompanyItems(null);
            return;
        }

        // Раскрыта ровно одна переписка: панель подбора справа одна на карточку,
        // и «чьи это позиции» не должно быть вопросом
        document.querySelectorAll('.conv__open:not([hidden])').forEach(el => {
            el.hidden = true;
            const conv = el.closest('.conv');
            if (conv) conv.classList.remove('conv--open');
        });

        box.hidden = false;
        const own = box.closest('.conv');
        if (own) own.classList.add('conv--open');
        this.companyOpenThread = key;
        // Блоки карточки помнят положение у своей переписки
        this.setFoldKey(key);
        if (box.dataset.loaded) {
            this.mountBodies(box);
            this.setCompanyItems(box.dataset.requestId || '', key);
            return;
        }
        this.parkCompanyItems();
        box.innerHTML = '<div class="loading">Загрузка писем...</div>';
        try {
            const d = await this.api('mail.php?action=thread&key=' + encodeURIComponent(key)
                                     + (markRead ? '&read=1' : ''));
            const reply = d.reply || {};
            box.innerHTML = this.threadHtml(d.messages, key)
                          + this.threadComposer(key, reply, d.mailboxes || []);
            this.mountBodies(box);
            box.dataset.loaded = '1';
            box.dataset.requestId = reply.request_id || '';
            this.restoreComposerDraft(key);
            this.fillSignatureNote(box);
            this.setCompanyItems(reply.request_id || '', key);
            // Сервер уже снял отметку вместе с загрузкой — строке остаётся
            // только перестать кричать
            if (markRead) this.markThreadRead(key, box, true);
        } catch (err) {
            box.innerHTML = `<p class="no">${this.esc(err.message)}</p>`;
        }
    },

    /** Вернуть панель подбора в боковую колонку карточки компании. */
    parkCompanyItems() {
        const side = document.getElementById('cpItems');
        const dock = document.getElementById('companySide');
        if (side && dock && side.nextElementSibling !== dock) dock.before(side);
    },

    /**
     * Панель подбора карточки компании — справа, для раскрытой переписки.
     *
     * `null` — переписку свернули: панель говорит, что выбрать нечего, а не
     * показывает позиции закрытого письма.
     */
    setCompanyItems(requestId, threadKey = '') {
        const side = document.getElementById('cpItems');
        if (!side) return;
        // Раскрытая переписка получает подбор над своим полем письма
        const open = threadKey ? document.getElementById('th_' + this.threadDomId(threadKey)) : null;
        const composer = open && open.querySelector('[data-composer]');
        // Порядок: подбор → КП → письмо (модуль 049)
        const kpBox = composer && composer.parentElement.querySelector(':scope > [data-kp-under-letter]');
        if (requestId !== null && composer) (kpBox || composer).before(side);
        else this.parkCompanyItems();
        if (requestId === null) {
            side.className = 'card card--items';
            side.innerHTML = `<div class="card__title">Подходящие позиции${this.hint('match')}</div>
                <p class="muted">Раскройте переписку слева — позиции по её запросу появятся здесь.</p>`;
            return;
        }
        // Хост подбора ищется как `[data-thread-items]` внутри переданного узла
        if (requestId) this.loadThreadItems(side.parentElement, Number(requestId));
        else this.noThreadItems(side.parentElement, threadKey);
        // Лента справа и счета под письмом смотрят на тот же запрос (модуль 029)
        this.companyRequestId = requestId ? String(requestId) : '';
        this.markChatScope();
        this.loadInvoiceDock();
    },

    /**
     * «Прочитано» по переписке: на сервере и на строке карточки.
     * $onServer — отметку уже поставила загрузка писем (`&read=1`).
     */
    async markThreadRead(key, box, onServer = false) {
        if (box.dataset.read === '1') return;
        box.dataset.read = '1';
        const row = box.closest('.conv');
        if (row) {
            row.classList.remove('conv--unread');
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
     * Переписка, из которой не завели запрос (модуль 039).
     *
     * Здесь стоял тупик: «запрос не заведён — подбирать по каталогу нечего».
     * Сервис живёт составлением КП, и отказать в подборе он не вправе ни по
     * какому письму: классификатор мог ошибиться, а запрос на бронеплиты
     * «от бр2 до бр5» — самый настоящий. Теперь это кнопка: подбор заводится
     * по этой переписке, письмо перечитывается и позиции ищутся в каталоге.
     */
    noThreadItems(box, threadKey = '') {
        const host = box.querySelector('[data-thread-items]');
        if (!host) return;
        host.className = 'card card--items';
        host.innerHTML = `<div class="card__title">Подходящие позиции${this.hint('match')}</div>
            <p class="muted">По этой переписке подбор ещё не заводили. Нажмите — письмо перечитается,
               позиции найдутся в каталоге, и отсюда же соберётся КП.</p>
            ${threadKey ? `<button class="btn btn--primary" data-make-request
                    onclick="App.makeThreadRequest('${this.jsStr(threadKey)}', this)">Подобрать товар</button>`
                : '<p class="muted">Раскройте переписку слева.</p>'}`;
    },

    /**
     * «Подобрать товар» по переписке без запроса (модуль 039).
     *
     * Один вызов модели — его просят, а не тратят на каждое входящее письмо.
     * Модель промолчала — таблица откроется пустой, и позицию в неё впишут
     * руками: подобрать товар можно ВСЕГДА.
     */
    async makeThreadRequest(key, btn) {
        btn.disabled = true;
        btn.textContent = 'Читаем письмо...';
        try {
            const r = await this.api('mail.php?action=make_request', {method: 'POST', body: {key}});
            // Панель рисуется там же, где стояла кнопка: у письма своей страницей
            // и у переписки в карточке компании это разные узлы
            const panel = btn.closest('[data-thread-items]');
            const thread = document.getElementById('th_' + this.threadDomId(key));
            if (thread) thread.dataset.requestId = r.request_id;
            if (panel && panel.parentElement) this.loadThreadItems(panel.parentElement, r.request_id);
            this.companyRequestId = String(r.request_id);
            this.markChatScope();
            this.loadInvoiceDock();
            this.toast(r.items
                ? `Подбор заведён · позиций: ${r.items}`
                : 'Подбор заведён — позиций в письме не нашлось, впишите их сами', r.items ? 'success' : 'info');
        } catch (err) {
            this.toast(err.message, 'error');
            btn.disabled = false;
            btn.textContent = 'Подобрать товар';
        }
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
                // Подбор свёрнут всегда, кроме первого письма запроса КП/прайса
                // (модуль 047). Раскрытый руками так и остаётся раскрытым.
                if (host.dataset.foldFor !== String(requestId)) {
                    host.dataset.foldFor = String(requestId);
                    host.dataset.folded = req.match_open ? '0' : '1';
                    // Свёрнутый или раскрытый руками — так и остаётся у этого письма (issue #67)
                    const own = this.foldGet('items');
                    if (own !== null) host.dataset.folded = own ? '1' : '0';
                }
                this.renderMatchedItems(requestId, req.items || [], host,
                    {kp, delivery: req.delivery, conditions: req.conditions, price_types: req.price_types});
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
        // Письмо, которое ещё никому не отвечает: ни цепочки, ни письма-исходника
        const fresh = !key && !reply.reply_to_id;
        return `
            <div class="composer" data-block="composer" data-composer="${this.esc(id)}">
                <div class="composer__head">
                    <span class="composer__title">${fresh ? 'Новое письмо' : 'Ответ'}${this.hint('composer')}</span>
                    ${fresh ? '' : `<span class="muted" data-cmp-target>кому: ${this.esc(reply.to || '')}</span>`}
                    <select data-cmp-box title="Из какого ящика отправить">
                        ${(mailboxes || []).map(b => `<option value="${b.id}" ${reply.mailbox_id === b.id ? 'selected' : ''}>${this.esc(b.name)}</option>`).join('')}
                    </select>
                </div>
                <!-- У первого письма адресата ещё нет — его пишут здесь же, и
                     он попадает и в черновик, и в карточку (модуль 033) -->
                ${fresh
                    ? `<input type="email" data-cmp-to value="${this.esc(reply.to || '')}" placeholder="Кому — адрес получателя"
                              oninput="App.composerChanged('${this.jsStr(key)}')">`
                    : `<input type="hidden" data-cmp-to value="${this.esc(reply.to || '')}">`}
                <input type="hidden" data-cmp-reply value="${reply.reply_to_id || ''}">
                <input type="hidden" data-cmp-cp value="${reply.counterparty_id || ''}">
                <input type="hidden" data-cmp-draft-id value="${reply.draft_id || ''}">
                <input type="text" data-cmp-subject value="${this.esc(reply.subject || '')}" placeholder="Тема"
                       oninput="App.composerChanged('${this.jsStr(key)}')">
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
                <!-- Подпись (модуль 039) — под полем письма, там, где она и
                     встанет в тексте (модуль 047); снимается одной галочкой -->
                <label class="muted composer__sign" title="Ваша подпись допишется в конец письма">
                    <input type="checkbox" data-cmp-sign checked
                           onchange="App.toggleSignatureNote(this)"> подпись
                    <span data-cmp-sign-text></span>
                </label>
                <div class="composer__files" data-cmp-files></div>
                <div class="composer__actions">
                    ${this.categorySelect(reply.category)}${this.hint('category')}
                    <label class="btn btn--outline btn--sm" title="Приложить свой файл к письму">
                        📎 Файл<input type="file" multiple hidden onchange="App.composerAttach('${this.jsStr(key)}', this)">
                    </label>
                    <button class="btn btn--primary btn--sm" onclick="App.threadSend('${this.jsStr(key)}', this)">Отправить</button>
                    <!-- Отложенная отправка (issue #60): письмо, написанное ночью,
                         приходит клиенту утром -->
                    <button class="btn btn--outline btn--sm" title="Отправить позже — в выбранный день и час"
                            onclick="App.scheduleMenu('${this.jsStr(key)}', this)">⏱ Отложить</button>
                    <button class="btn btn--outline btn--sm" data-cmp-draft
                            ${reply.reply_to_id ? '' : 'disabled title="Отвечать нечего: в переписке нет входящего письма"'}
                            onclick="App.threadDraft('${this.jsStr(key)}', this)">✨ Сгенерировать ответ</button>
                    <span class="muted" data-cmp-note></span>
                    <span class="muted" data-cmp-saved></span>
                </div>
                <div class="composer__schedule" data-cmp-schedule hidden></div>
                <!-- Счета, выставленные по этому запросу, стоят ЗДЕСЬ, под
                     письмом, которым их и отправляют (модуль 029): посмотреть,
                     переименовать, приложить — не уходя с письма -->
                <div data-invoice-dock></div>
            </div>`;
    },

    /**
     * Счета под полем ответа (модуль 029).
     *
     * Раньше счёт жил карточкой в самом низу правой колонки, с кнопкой
     * «Отправить счёт», которая открывала своё окно с адресом и темой —
     * второе письмо мимо того, которое менеджер как раз писал. Теперь счёт
     * стоит там, где письмо: «Просмотреть» открывает печатную форму,
     * «Прикрепить» кладёт файл в это самое письмо, а имя файла правится
     * строкой рядом — по умолчанию оно собрано по шаблону из настроек.
     */
    async loadInvoiceDock() {
        const dock = document.querySelector('[data-composer] [data-invoice-dock]');
        if (!dock) return;
        const requestId = this.composerRequestId();
        if (!requestId) { dock.innerHTML = ''; return; }
        if (dock.dataset.request === String(requestId)) return;   // уже показан этот же запрос
        dock.dataset.request = String(requestId);
        try {
            const d = await this.api('invoices.php?action=for_request&request_id=' + requestId);
            this.drawInvoiceDock(d.items || []);
        } catch (err) {
            dock.innerHTML = `<div class="muted">Счета не загрузились: ${this.esc(err.message)}</div>`;
        }
    },

    /** Запрос, к которому относится открытое письмо. */
    composerRequestId() {
        if (this.companyRequestId) return Number(this.companyRequestId) || 0;
        const host = document.querySelector('[data-match-host][data-request-id]');
        return host ? Number(host.dataset.requestId) || 0 : 0;
    },

    drawInvoiceDock(items) {
        const dock = document.querySelector('[data-composer] [data-invoice-dock]');
        if (!dock) return;
        if (!items.length) { dock.innerHTML = ''; return; }
        dock.innerHTML = `
            <div class="invdock" data-block="ms">
                <div class="invdock__title" data-block-head>Счета МойСклад по этому запросу (${items.length})</div>
                ${items.map(i => `
                    <div class="invdock__row" data-invoice="${i.id}">
                        <div class="invdock__head">
                            <a href="${this.esc(i.url)}" target="_blank" rel="noopener">Счёт ${this.esc(i.name)} ↗</a>
                            <strong>${this.fmtMoney(i.sum)}</strong>
                            ${i.payed_sum > 0 ? `<span class="ok">оплачено ${this.fmtMoney(i.payed_sum)}</span>` : ''}
                            ${i.sent_at ? `<span class="muted">отправлен ${this.fmtDate(i.sent_at)}</span>` : ''}
                        </div>
                        <label class="invdock__name">
                            <span class="muted">Имя файла</span>
                            <input type="text" data-inv-name value="${this.esc(i.filename)}"
                                   title="Так вложение назовётся в письме. Шаблон — «Настройки → Оформление КП»">
                        </label>
                        <div class="flex flex--wrap">
                            <button class="btn btn--outline btn--sm"
                                    onclick="App.previewInvoice(${i.id}, this)">👁 Просмотреть счёт</button>
                            <button class="btn btn--primary btn--sm"
                                    onclick="App.attachInvoice(${i.id}, this)">📎 Прикрепить счёт</button>
                        </div>
                        <div data-inv-preview></div>
                    </div>`).join('')}
            </div>`;
    },

    /** Печатная форма счёта — прямо в письме, а не вкладкой и не скачиванием. */
    previewInvoice(id, btn) {
        const row = btn.closest('.invdock__row');
        const box = row && row.querySelector('[data-inv-preview]');
        if (!box) return;
        if (box.dataset.open === '1') { box.dataset.open = ''; box.innerHTML = ''; btn.textContent = '👁 Просмотреть счёт'; return; }
        box.dataset.open = '1';
        btn.textContent = '👁 Свернуть счёт';
        box.innerHTML = `<iframe class="invdock__frame" src="/api/invoices.php?action=pdf&id=${id}"
                                 title="Счёт #${id}"></iframe>`;
    },

    /** Счёт только что выставили — список под письмом должен его увидеть. */
    refreshInvoiceDock() {
        const dock = document.querySelector('[data-composer] [data-invoice-dock]');
        if (!dock) return;
        dock.dataset.request = '';
        this.loadInvoiceDock();
    },

    /** Приложить счёт к этому письму — под тем именем, что стоит в строке. */
    async attachInvoice(id, btn) {
        const row = btn.closest('.invdock__row');
        const field = row && row.querySelector('[data-inv-name]');
        const composer = btn.closest('[data-composer]');
        if (!composer) { this.toast('Сначала откройте письмо, к которому приложить', 'error'); return; }
        btn.disabled = true;
        try {
            const r = await this.api('mail.php?action=attach_doc', {method: 'POST', body: {
                kind: 'invoice', id, filename: (field && field.value) || '',
            }});
            const f = r.file;
            composer.querySelector('[data-cmp-files]').insertAdjacentHTML('beforeend', `
                <span class="chip" data-cmp-file="${this.esc(f.name)}">📎 ${this.esc(f.filename)}
                    <a onclick="this.parentElement.remove()" title="Убрать">×</a></span>`);
            this.toast('Счёт приложен к письму: ' + f.filename, 'success');
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; }
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
     * ==== Подпись в письме (модуль 039) ====
     *
     * Подпись дописывает сервер — она одна и та же во всех письмах менеджера,
     * и держать её в поле ввода значит однажды её оттуда стереть. Но увидеть
     * её надо ДО отправки, поэтому она стоит строкой рядом с галочкой.
     */
    async mailSignature() {
        if (this._mailSign === undefined) {
            try { this._mailSign = (await this.api('settings.php?action=mail_signature')).effective || ''; }
            catch { this._mailSign = ''; }
        }
        return this._mailSign;
    },

    async fillSignatureNote(c) {
        const box = c && c.querySelector('[data-cmp-sign-text]');
        if (!box) return;
        const sign = await this.mailSignature();
        box.textContent = sign ? '· ' + sign.split('\n').join(' · ') : '· не заведена';
        box.title = sign || 'Подпись не заведена — «Настройки → Подпись»';
    },

    toggleSignatureNote(input) {
        const box = input.closest('.composer__sign');
        if (box) box.classList.toggle('composer__sign--off', !input.checked);
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

    /**
     * Черновик уходит на сервер и заводит карточку в «В работе» (модуль 033).
     *
     * Сохраняется ЛЮБОЕ письмо, в том числе первое письмо компании: раньше у
     * такого не было ни id письма, ни ключа цепочки, и сервер отказывал — текст
     * жил только в этой вкладке и пропадал вместе с ней.
     */
    async saveComposerDraft(key) {
        const c = this.composerOf(key);
        if (!c) return;
        const {html} = this.composerBody(c);
        const saved = c.querySelector('[data-cmp-saved]');
        const idBox = c.querySelector('[data-cmp-draft-id]');
        try {
            const r = await this.api('mail.php?action=draft_save', {method: 'POST', body: {
                draft_id: Number(idBox && idBox.value) || 0,
                id: Number(c.querySelector('[data-cmp-reply]').value) || 0,
                thread_key: key || '',
                counterparty_id: Number((c.querySelector('[data-cmp-cp]') || {}).value) || 0,
                to: ((c.querySelector('[data-cmp-to]') || {}).value || '').trim(),
                subject: (c.querySelector('[data-cmp-subject]') || {}).value || '',
                body: html,
            }});
            if (idBox) idBox.value = r.draft_id || '';
            // Компанию мог опознать сервер — по ИНН и подписи в теле письма
            const cpBox = c.querySelector('[data-cmp-cp]');
            if (cpBox && !cpBox.value && r.counterparty_id) cpBox.value = r.counterparty_id;
            if (saved) saved.textContent = r.saved
                ? 'черновик сохранён' + (r.column ? ` · карточка в «${r.column}»` : '')
                : 'черновик пуст';
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
            const cp = Number((c.querySelector('[data-cmp-cp]') || {}).value) || 0;
            const d = await this.api('mail.php?action=draft_get&id=' + id
                                     + '&counterparty_id=' + cp
                                     + '&thread_key=' + encodeURIComponent(key || ''));
            if (!d.draft || !d.draft.body) return;
            box.innerHTML = d.draft.body;
            const idBox = c.querySelector('[data-cmp-draft-id]');
            if (idBox) idBox.value = d.draft.id || '';
            const subj = c.querySelector('[data-cmp-subject]');
            if (subj && !subj.value.trim() && d.draft.subject) subj.value = d.draft.subject;
            const to = c.querySelector('[data-cmp-to]');
            if (to && !to.value.trim() && d.draft.to_email) to.value = d.draft.to_email;
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

    /**
     * ==== Перенаправить письмо (модуль 037) ====
     *
     * Письмо целиком — со вложениями и шапкой «от кого» — уходит на другой
     * адрес: запрос в снабжение, счёт в бухгалтерию. Делается это здесь, а не
     * в чужом почтовом клиенте, поэтому пересылка остаётся в переписке.
     *
     * Адрес запоминается сам: во второй раз его выбирают нажатием, а не
     * набирают. Список чистится крестиком — человек увольняется, а адрес
     * остаётся.
     */
    async forwardMail(id) {
        this._forwardId = id;
        this.modal('Перенаправить письмо', `
            <div class="form-group">
                <label>Кому переслать</label>
                <input type="email" id="fwdTo" placeholder="адрес@компания.ру" autocomplete="off">
            </div>
            <div id="fwdSaved" class="fwd-saved muted">Загружаем сохранённые адреса...</div>
            <div class="form-group">
                <label>Что дописать от себя</label>
                <textarea id="fwdNote" rows="3" placeholder="Необязательно: пара слов получателю"></textarea>
            </div>
            <div class="flex flex--end" style="gap:8px;margin-top:12px">
                <button class="btn btn--outline" onclick="App.closeModal()">Отмена</button>
                <button class="btn btn--primary" id="fwdGo" onclick="App.forwardSend(this)">Перенаправить</button>
            </div>`);
        this.forwardAddresses();
    },

    /** Сохранённые адреса — список под полем ввода. */
    async forwardAddresses(known) {
        const box = document.getElementById('fwdSaved');
        if (!box) return;
        try {
            const list = known || (await this.api('mail.php?action=forward_addresses')).addresses || [];
            box.innerHTML = list.length
                ? 'Сохранённые адреса: ' + list.map(a => `
                    <span class="chip fwd-chip">
                        <a onclick="App.forwardPick('${this.jsStr(a.email)}')"
                           title="${this.esc(a.name || a.email)}">${this.esc(a.email)}</a>
                        <button title="Убрать адрес из базы"
                                onclick="App.forwardForget(${Number(a.id)})">×</button>
                    </span>`).join(' ')
                : 'Сюда ещё не пересылали — наберите адрес, он запомнится сам.';
        } catch (err) {
            box.textContent = 'Сохранённые адреса не загрузились: ' + err.message;
        }
    },

    forwardPick(email) {
        const field = document.getElementById('fwdTo');
        if (field) { field.value = email; field.focus(); }
    },

    /** Убрать адрес из базы — вместе со счётчиком, которым он держался в списке. */
    async forwardForget(id) {
        try {
            const d = await this.api('mail.php?action=forward_address_delete', {method: 'POST', body: {id}});
            this.forwardAddresses(d.addresses || []);
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async forwardSend(btn) {
        const to = (document.getElementById('fwdTo') || {}).value || '';
        if (!to.trim()) { this.toast('Укажите адрес, на который переслать', 'error'); return; }
        btn.disabled = true;
        btn.textContent = 'Отправляем...';
        try {
            await this.api('mail.php?action=forward', {method: 'POST', body: {
                id:   this._forwardId,
                to:   to.trim(),
                text: (document.getElementById('fwdNote') || {}).value || '',
            }});
            this.closeModal();
            this.toast('Письмо перенаправлено на ' + to.trim(), 'success');
            this.route();
        } catch (err) {
            this.toast(err.message, 'error');
            btn.disabled = false;
            btn.textContent = 'Перенаправить';
        }
    },

    /**
     * Текст черновика → HTML редактора: абзацы, переносы и ссылки.
     * «см. на сайте: https://…» становится ссылкой со словами «см. на сайте»
     * (issue #60) — так же, как на сервере в `MailText::textToHtml()`.
     */
    draftHtml(text) {
        const link = /(см\. на сайте)\s*:?\s*(https?:\/\/[^\s<>"']+)|(https?:\/\/[^\s<>"']+)/g;
        return String(text).split(/\n{2,}/).map(p => '<p>' + this.esc(p).replace(link, (m, label, u1, u2) => {
            const raw = u1 || u2;
            const url = raw.replace(/[.,;)]+$/, '');
            return `<a href="${url}">${label || url}</a>` + raw.slice(url.length);
        }).replace(/\n/g, '<br>') + '</p>').join('');
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
            area.innerHTML = this.draftHtml(r.text || '');
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


    /**
     * ==== Отложенная отправка (issue #60) ====
     *
     * Подсказки считает СЕРВЕР, в часовом поясе сервиса: «завтра в 09:00» у
     * менеджера с часами на другом поясе означало бы не то время, которое
     * увидит клиент. Своё время всегда можно выставить руками.
     */
    async scheduleMenu(key, btn) {
        const c = this.composerOf(key);
        const box = c && c.querySelector('[data-cmp-schedule]');
        if (!box) return;
        if (!box.hidden) { box.hidden = true; return; }
        box.hidden = false;
        box.innerHTML = '<div class="loading">Считаем время...</div>';
        try {
            const d = await this.api('mail.php?action=scheduled');
            const mine = d.items || [];
            box.innerHTML = `
                <div class="flex flex--wrap">
                    ${(d.presets || []).map(p => `<button class="btn btn--outline btn--sm"
                        onclick="App.threadSend('${this.jsStr(key)}', this, '${this.jsStr(p.at)}')">${this.esc(p.label)}</button>`).join('')}
                    <label>своё время
                        <input type="datetime-local" data-cmp-when>
                    </label>
                    <button class="btn btn--primary btn--sm" onclick="App.threadSendAtCustom('${this.jsStr(key)}', this)">Отложить</button>
                </div>
                ${mine.length ? `<div class="muted" style="margin-top:6px">В очереди:
                    ${mine.map(r => `<span class="sched-item">${this.esc(r.send_at)} — ${this.esc(r.to_addr || '')}
                        <a onclick="App.cancelScheduled(${r.id}, '${this.jsStr(key)}')" title="Отменить отправку">×</a></span>`).join(' ')}
                    </div>` : ''}`;
        } catch (err) {
            box.innerHTML = `<div class="no">${this.esc(err.message)}</div>`;
        }
    },

    /** Время, выставленное руками, — в том же виде, что и подсказки. */
    threadSendAtCustom(key, btn) {
        const c = this.composerOf(key);
        const when = c && c.querySelector('[data-cmp-when]');
        if (!when || !when.value) { this.toast('Выберите день и время', 'error'); return; }
        // datetime-local отдаёт «2026-09-23T09:00» — сервер ждёт секунды
        this.threadSend(key, btn, when.value.replace('T', ' ') + ':00');
    },

    async cancelScheduled(id, key) {
        try {
            await this.api('mail.php?action=schedule_cancel', {method: 'POST', body: {id}});
            this.toast('Отправка отменена', 'success');
            const c = this.composerOf(key);
            const box = c && c.querySelector('[data-cmp-schedule]');
            if (box) { box.hidden = true; }
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async threadSend(key, btn, sendAt = null) {
        const c = this.composerOf(key);
        if (!c) return;
        const {text, html} = this.composerBody(c);
        if (!text.trim()) { this.toast('Письмо пустое', 'error'); return; }
        btn.disabled = true;
        try {
            const res = await this.api('mail.php?action=send', {method: 'POST', body: {
                // Пусто — уходит сейчас; время — ложится в очередь (issue #60)
                send_at:     sendAt || '',
                to:          c.querySelector('[data-cmp-to]').value.trim(),
                subject:     c.querySelector('[data-cmp-subject]').value.trim(),
                text,
                // Оформление уходит клиенту, а не остаётся в поле ввода
                html,
                mailbox_id:  (c.querySelector('[data-cmp-box]') || {}).value || null,
                reply_to_id: Number(c.querySelector('[data-cmp-reply]').value) || null,
                counterparty_id: Number(c.querySelector('[data-cmp-cp]').value) || null,
                thread_key:  key || null,
                draft_id:    Number((c.querySelector('[data-cmp-draft-id]') || {}).value) || null,
                files:       this.composerFiles(c),
                // Галочка «подпись» снята — письмо уходит ровно как набрано
                signature:   (c.querySelector('[data-cmp-sign]') || {checked: true}).checked ? 1 : 0,
            }});
            // Отложенное письмо ещё не ушло — и говорить «отправлено» о нём нельзя
            if (res.scheduled) {
                this.toast('Письмо уйдёт ' + res.scheduled.send_at, 'success');
                const sbox = c.querySelector('[data-cmp-schedule]');
                if (sbox) sbox.hidden = true;
                return;
            }
            // «Отправлено» is only half the news when the copy never reached the
            // server's «Отправленные» — the manager hears it now, not in a month
            if (res.warning) this.toast(res.warning, 'error');
            else this.toast('Письмо отправлено' + (res.sent_folder ? ` · копия в «${res.sent_folder}»` : ''), 'success');
            // The answer belongs in the conversation it answers — reopen it
            const box = key ? document.getElementById('th_' + this.threadDomId(key)) : null;
            if (box) { box.dataset.loaded = ''; box.hidden = true; this.toggleCompanyThread(key); }
            const cp = this.openCompanyId();
            if (cp) { this.loadCompanyThreads(cp); this.loadChat(cp); }
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; }
    },

    /**
     * Карточка компании, открытая ПРЯМО СЕЙЧАС.
     *
     * `this.company` помнит последнюю открытую карточку и никогда не
     * очищается: со страницы письма «обновить карточку компании» попадало в
     * никуда, потому что её разметки на экране уже нет. Спрашиваем экран, а не
     * память (модуль 031).
     */
    openCompanyId() {
        return document.getElementById('cpThreads') ? ((this.company || {}).id || null) : null;
    },

    /**
     * Where this company sits on the board, and a one-click move to a column.
     *
     * Здесь же — заметка, написанная на карточке ДОСКИ: раньше она жила только
     * там, в карточку компании не попадала и стереть её было нечем (модуль 031).
     */
    async loadCardPlacement(id) {
        const box = document.getElementById('cardPlacement');
        if (!box) return;
        try {
            const d = await this.api('boards.php?action=placement&counterparty_id=' + id);
            const t = await this.api('boards.php?action=targets');
            const columns = (t.items[0] || {}).columns || [];
            const here = (d.items || [])[0];
            const note = here && (here.note || '').trim();
            box.innerHTML = `<div class="card card--inline">
                <span class="muted">Этап:</span>
                ${columns.map(c => `<button class="btn btn--sm ${here && here.column_id == c.id ? 'btn--primary' : 'btn--outline'}"
                    style="border-color:${this.esc(c.color || '#ccc')}"
                    onclick="App.moveCompanyCard(${id}, ${c.id})">${this.esc(c.title)}</button>`).join('')}
                ${here ? `<span class="cardnote">
                    <span class="cardnote__label">Заметка на доске:</span>
                    <span class="cardnote__text">${note ? this.esc(note) : '<em class="muted">нет</em>'}</span>
                    <button class="btn btn--outline btn--sm"
                            onclick="App.boardCardNote(${here.card_id}, '${this.jsStr(note || '')}')">
                        ${note ? 'Править' : 'Добавить'}</button>
                    ${note ? `<button class="btn btn--outline btn--sm btn--danger"
                            onclick="App.boardCardNote(${here.card_id}, null, true)">Удалить</button>` : ''}
                </span>` : ''}
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

    /**
     * Правая колонка карточки (модуль 029).
     *
     * Раньше её составляли пять карточек подряд: «Информация», «Заметки и
     * события», «Контакты», «Заказы», «Счета». Контакты — это и есть сведения о
     * компании, а заказы и счета теперь стоят в ленте событий со ссылками на
     * МойСклад: сделка читается одним списком сверху вниз, а не собирается из
     * трёх углов экрана. Осталось две карточки: что мы про компанию знаем и
     * что с ней происходило.
     */
    companySide(cp) {
        const contacts = cp.contacts || [];
        const orgs = cp.orgs || [];
        // На десктопе обе карточки — вкладки у правого края, открываются шторкой (модуль 049)
        return `
            <div class="card" data-block="info" onclick="App.railOpen(event, this)">
                <div class="card__title">Информация</div>
                <p><strong>ИНН:</strong> ${this.esc(cp.inn) || '—'}</p>
                <p><strong>Домен:</strong> ${this.esc(cp.email_domain) || '—'}</p>
                <p><strong>МойСклад:</strong> ${cp.moysklad_id
                    ? `<a href="${this.esc(this.msUrl(cp.moysklad_id))}" target="_blank" rel="noopener">привязан ↗</a>`
                    : '<span class="muted">не привязан</span>'}</p>
                ${cp.merged_cards && cp.merged_cards.length
                    ? `<p class="muted">Объединено с: ${cp.merged_cards.map(m => this.esc(m.name)).join(', ')}</p>` : ''}

                <!-- Организации: в одном письме просят счёт на две фирмы сразу
                     («15 штук в адрес АО ТИКО-Пластик, 2 — в адрес ООО Нова
                     Ролл Пак»). Их столько же, сколько нужно, — как счетов.
                     Завести фирму в МойСклад — действие НАД СТРОКОЙ организации;
                     отдельной кнопки над списком больше нет (модуль 034). -->
                <div class="card__sub">Организации для счёта (${orgs.length})</div>
                <div id="cpOrgs">${this.orgListHtml(cp.id, orgs)}</div>
                <button class="btn btn--outline btn--sm" onclick="App.addOrgForm(${cp.id})">+ Организация</button>

                <div class="card__sub">Контакты (${contacts.length})</div>
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

            <div class="card" data-block="events" onclick="App.railOpen(event, this)">
                <div class="card__title">Заметки, заказы и счета<span data-notes-dot></span>${this.hint('events')}</div>
                <div id="chatFeed" class="chat"><div class="loading">Загрузка...</div></div>
                <div class="chat__composer">
                    <textarea id="noteText" rows="2" placeholder="Заметка для коллег (клиенту не уходит)..."></textarea>
                    <button class="btn btn--outline" onclick="App.addNote(${cp.id})">Добавить заметку</button>
                </div>
            </div>
        `;
    },

    /**
     * Список организаций карточки: сама компания и дописанные руками.
     *
     * Каждая строка несёт своё действие: не заведена в МойСклад — «Завести»,
     * заведена — ссылка на карточку в МойСклад. Отдельной кнопки «Создать
     * контрагента в МойСклад» над списком больше нет, она делала то же самое
     * (модуль 034).
     */
    orgListHtml(cpId, orgs) {
        if (!orgs.length) return '<p class="muted">Организаций нет</p>';
        return orgs.map(o => `
            <div class="flex flex--between" style="padding:6px 0;border-bottom:1px solid var(--border)">
                <div style="min-width:0">
                    <div>${this.esc(o.name)} ${o.primary ? '<span class="chip">карточка</span>' : ''}</div>
                    <small class="muted">
                        ${o.inn ? 'ИНН ' + this.esc(o.inn) : '<span class="no">без ИНН</span>'}${o.kpp ? ' / ' + this.esc(o.kpp) : ''}
                        · МойСклад ${o.moysklad_id
                            ? `<a href="${this.esc(this.msUrl(o.moysklad_id))}" target="_blank" rel="noopener">привязан ↗</a>`
                            : '<span class="no">не привязан</span>'}
                        ${o.edo_id ? ' · ЭДО ' + this.esc(o.edo_id) : ''}
                    </small>
                </div>
                <div class="flex flex--wrap">
                    ${o.moysklad_id ? '' : `<button class="btn btn--sm btn--outline"
                        title="Завести эту фирму в МойСклад. Контрагент с таким ИНН там уже есть — просто привяжем"
                        onclick="App.msCreateForm({counterparty_id: ${cpId}, org_id: ${o.id}, name: '${this.jsStr(o.name)}', inn: '${this.jsStr(o.inn || '')}'})">➕ Завести в МойСклад</button>`}
                    ${o.primary ? '' : `<button class="btn btn--sm btn--outline btn--danger"
                        onclick="App.deleteOrg(${cpId}, ${o.id}, '${this.jsStr(o.name)}')">Убрать</button>`}
                </div>
            </div>`).join('');
    },

    /** Бланк новой организации: ИНН ищется в МойСклад, как при связывании карточки. */
    addOrgForm(cpId) {
        this.modal('Организация для счёта', `
            <p class="muted">Клиент просит счёт на другое юрлицо — оно живёт здесь же, в карточке,
               и выбирается при выставлении счёта.</p>
            <div class="form-group"><label>Название</label>
                <input type="text" id="orgName" placeholder="ООО «Нова Ролл Пак»"></div>
            <div class="grid grid--2">
                <div class="form-group"><label>ИНН</label>
                    <input type="text" id="orgInn" placeholder="5038121998"></div>
                <div class="form-group"><label>КПП</label>
                    <input type="text" id="orgKpp" placeholder="503801001"></div>
            </div>
            <div class="form-group"><label>Идентификатор ЭДО</label>
                <input type="text" id="orgEdo" placeholder="2BM-5038121998-503801001-…"></div>
            <div class="flex flex--wrap">
                <button class="btn btn--outline btn--sm" onclick="App.lookupOrgInMoysklad()">Найти в МойСклад по ИНН</button>
            </div>
            <div id="orgLookup" style="margin:8px 0"></div>
            <input type="hidden" id="orgMsId" value="">
            <button class="btn btn--primary btn--block" onclick="App.saveOrg(${cpId})">Добавить</button>
        `);
    },

    async lookupOrgInMoysklad() {
        const out = document.getElementById('orgLookup');
        const inn = (document.getElementById('orgInn') || {}).value || '';
        const name = (document.getElementById('orgName') || {}).value || '';
        const q = inn.trim() || name.trim();
        if (!q) { out.innerHTML = '<p class="muted">Заполните ИНН или название</p>'; return; }
        out.innerHTML = '<p class="muted">Ищем...</p>';
        try {
            const d = await this.api('counterparties.php?action=lookup_moysklad&q=' + encodeURIComponent(q));
            out.innerHTML = (d.items || []).length
                ? (d.items || []).map(i => `<div class="flex flex--between" style="padding:4px 0">
                        <span>${this.esc(i.name)} <small class="muted">${this.esc(i.inn || '')}</small></span>
                        <button class="btn btn--sm btn--outline"
                            onclick="App.pickOrgMs('${this.jsStr(i.id)}', '${this.jsStr(i.name)}', '${this.jsStr(i.inn || '')}', this)">Выбрать</button>
                    </div>`).join('')
                : '<p class="muted">В МойСклад такой организации нет — её можно добавить и без привязки, но счёт на неё не выставится</p>';
        } catch (err) { out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`; }
    },

    pickOrgMs(msId, name, inn, btn) {
        document.getElementById('orgMsId').value = msId;
        if (name) document.getElementById('orgName').value = name;
        if (inn) document.getElementById('orgInn').value = inn;
        document.getElementById('orgLookup').innerHTML = `<p class="ok">Привязано: ${this.esc(name)}</p>`;
    },

    async saveOrg(cpId) {
        const body = {
            name: (document.getElementById('orgName') || {}).value || '',
            inn: (document.getElementById('orgInn') || {}).value || '',
            kpp: (document.getElementById('orgKpp') || {}).value || '',
            edo_id: (document.getElementById('orgEdo') || {}).value || '',
            moysklad_id: (document.getElementById('orgMsId') || {}).value || '',
        };
        try {
            const r = await this.api(`counterparties.php?action=org_add&id=${cpId}`, {method: 'POST', body});
            this.closeModal();
            this.toast('Организация добавлена', 'success');
            const box = document.getElementById('cpOrgs');
            if (box) box.innerHTML = this.orgListHtml(cpId, r.items || []);
            if (this.company) this.company.orgs = r.items || [];
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async deleteOrg(cpId, orgId, name) {
        if (!confirm(`Убрать организацию «${name}» из карточки?`)) return;
        try {
            const r = await this.api(`counterparties.php?action=org_delete&id=${cpId}&org_id=${orgId}`, {method: 'POST', body: {}});
            const box = document.getElementById('cpOrgs');
            if (box) box.innerHTML = this.orgListHtml(cpId, r.items || []);
            if (this.company) this.company.orgs = r.items || [];
            this.toast('Организация убрана');
        } catch (err) { this.toast(err.message, 'error'); }
    },

    /**
     * Резерв под неоплаченный счёт — строкой под заказом (модуль 026).
     *
     * Заказ, созданный кнопкой «Счёт», держит товар за клиентом. Срок вышел, а
     * денег нет — здесь стоит кнопка, снимающая с заказа проведение: резерв
     * уходит, заказ остаётся. Сервис этого сам не делает: решение про товар,
     * обещанный клиенту, принимает человек.
     */
    reserveRow(o) {
        const r = o.reserve || {};
        if (r.released) return `<div class="muted" style="font-size:11px">резерв снят</div>`;
        if (!r.held) return '';
        if (r.paid) return `<div class="muted" style="font-size:11px">счёт оплачен — резерв обоснован</div>`;
        if (!r.due) {
            return `<div class="muted" style="font-size:11px">резерв держим до ${this.fmtDate(r.until, false)}</div>`;
        }
        return `<div class="note note--swap" style="margin-top:6px">
            Резерв держится с ${this.fmtDate(r.until, false)}, счёт не оплачен
            на ${this.fmtMoney(r.unpaid)}.
            <button class="btn btn--outline btn--sm" style="margin-left:6px"
                    onclick="App.releaseReserve(${o.id}, this)">Снять резерв</button>
        </div>`;
    },

    /** Снять проведение заказа в МойСклад — товар возвращается в продажу. */
    async releaseReserve(orderId, btn) {
        if (!confirm('Снять резерв: заказ в МойСклад перестанет быть проведённым. Продолжить?')) return;
        btn.disabled = true;
        const label = btn.textContent;
        btn.textContent = 'Снимаем...';
        try {
            const r = await this.api(`invoices.php?action=release_reserve&order_id=${orderId}`,
                                     {method: 'POST', body: {}});
            this.toast(`Резерв по заказу ${r.name} снят`, 'success');
            if (this.company) this.syncCompany(this.company.id, true);
        } catch (err) {
            this.toast(err.message, 'error');
            btn.disabled = false;
            btn.textContent = label;
        }
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

            // Заказы и счета встают в ту же ленту по своей дате (модуль 029):
            // «заказ создан» и сам заказ со ссылкой на МойСклад — одна строка,
            // а не веха здесь и карточка с документом где-то ниже.
            const rows = this.mergeDocEvents(data.items, data.docs || [])
                .sort((a, b) => String(a.created_at).localeCompare(String(b.created_at)));

            feed.innerHTML = rows.length ? rows.map(m => {
                if (m.kind === 'doc') return this.chatDocRow(m);
                const isEvent = m.kind === 'event' || !!m.event_type;
                const cls = isEvent ? 'msg--event' : 'msg--note';
                const who = isEvent
                    ? (App.eventLabels[m.event_type] || 'событие')
                    : (this.esc(m.manager_name) || 'система');
                const files = (m.attachments || []).map(a => this.attachmentLink(a, 'requests.php')).join('');
                const body = this.esc(m.body || '');
                return `
                    <div class="msg ${cls}" data-chat-req="${m.request_id || ''}">
                        <div class="msg__head">
                            <span>${who}</span>
                            <span class="muted">${this.fmtDate(m.created_at)}</span>
                            ${isEvent ? '' : `<button class="msg__del" title="Удалить заметку"
                                onclick="App.deleteNote(${id}, ${m.id})">🗑</button>`}
                        </div>
                        ${m.subject ? `<div class="msg__subject">${this.esc(m.subject)}</div>` : ''}
                        ${m.email_to ? `<div class="muted">кому: ${this.esc(m.email_to)}</div>` : ''}
                        ${body ? `<div class="msg__body ${isEvent ? 'msg__body--fold' : ''}"
                                       ${isEvent ? `onclick="this.classList.toggle('msg__body--fold')" title="Показать текст целиком"` : ''}
                                  >${body}</div>` : ''}
                        ${files ? `<div class="msg__files">${files}</div>` : ''}
                        ${m.request_id ? `<a class="muted" href="#mail/request/${m.request_id}">→ запрос #${m.request_id}</a>` : ''}
                    </div>`;
            }).join('') : '<p class="muted">Пока пусто.</p>';

            // Красная точка на вкладке — у компании есть заметки (модуль 049).
            // Точка вставляется и убирается, а не прячется `hidden`
            const hasNotes = rows.some(m => m.kind !== 'doc' && m.kind !== 'event' && !m.event_type);
            const dot = document.querySelector('[data-block="events"] [data-notes-dot]');
            if (dot) dot.innerHTML = hasNotes ? '<span class="notif-dot" title="Есть заметки"></span>' : '';

            // Всё, что не про открытый запрос, уходит в серый — но остаётся на
            // экране: у компании за год десятки заказов, и спрятать их значит
            // спрятать историю (модуль 029)
            this.markChatScope();
            feed.scrollTop = feed.scrollHeight;
        } catch (err) { this.toast(err.message, 'error'); }
    },

    /**
     * «Заказ создан» / «счёт выставлен» и сам документ — одна строка (модуль 047).
     * Веха уходит, документ берёт её время и её слова в заголовок; веха без
     * документа (удалён в МойСклад) остаётся как была.
     */
    mergeDocEvents(items, docs) {
        const MAP = {order_created: ['order', 'order_id'], invoice_created: ['invoice', 'invoice_id']};
        const byKey = new Map(docs.map(d => [`${d.doc}:${d.id}`, d]));
        const kept = items.filter(m => {
            const rule = MAP[m.event_type];
            const id = rule && m.meta ? Number(m.meta[rule[1]]) : 0;
            const doc = id ? byKey.get(`${rule[0]}:${id}`) : null;
            if (!doc) return true;
            doc.event_label = App.eventLabels[m.event_type];
            if (m.created_at) doc.created_at = m.created_at;
            return false;
        });
        return [...kept, ...docs];
    },

    /** Строка заказа или счёта в ленте: сумма, статус и ссылка в МойСклад. */
    chatDocRow(d) {
        const paid = d.doc === 'invoice' && Number(d.payed_sum) > 0;
        return `
            <div class="msg msg--doc" data-chat-req="${d.request_id || ''}">
                <div class="msg__head">
                    <span>${d.event_label || (d.doc === 'order' ? 'заказ' : 'счёт')}</span>
                    <span class="muted">${this.fmtDate(d.created_at)}</span>
                </div>
                <div class="msg__subject">
                    <a href="${this.esc(d.url)}" target="_blank" rel="noopener">${this.esc(d.title)} ↗</a>
                    <strong style="margin-left:6px">${this.fmtMoney(d.sum)}</strong>
                </div>
                <div class="muted">
                    ${d.state_name ? this.esc(d.state_name) : ''}
                    ${paid ? ' · оплачено ' + this.fmtMoney(d.payed_sum) : ''}
                    ${d.sent_at ? ' · отправлен ' + this.fmtDate(d.sent_at) : ''}
                    ${d.proposal_id ? ` · <a href="#mail/proposal/${d.proposal_id}">КП #${d.proposal_id}</a>` : ''}
                </div>
                ${d.doc === 'order' ? this.reserveRow(d) : ''}
                ${d.pdf_url ? `<div class="msg__files">
                    <a class="btn btn--sm btn--outline" target="_blank" href="${this.esc(d.pdf_url)}">PDF</a>
                </div>` : ''}
                ${d.request_id ? `<a class="muted" href="#mail/request/${d.request_id}">→ запрос #${d.request_id}</a>` : ''}
            </div>`;
    },

    /**
     * Приглушить в ленте всё, что не относится к открытой переписке (модуль 029).
     *
     * Запрос открытой переписки — мера «про это»: заказ по другому запросу и
     * заметка полугодовой давности видны, но не спорят за внимание с тем, ради
     * чего карточку открыли. Переписка свёрнута — приглушать нечего.
     */
    markChatScope(requestId) {
        const feed = document.getElementById('chatFeed');
        if (!feed) return;
        const id = String(requestId ?? this.companyRequestId ?? '');
        feed.querySelectorAll('[data-chat-req]').forEach(el => {
            const own = el.dataset.chatReq || '';
            el.classList.toggle('msg--offtopic', id !== '' && own !== '' && own !== id);
        });
    },

    // Чем была веха сделки — читается в ленте без расшифровки в теле
    eventLabels: {
        mail_sent:     'письмо отправлено',
        kp_sent:       'КП отправлено',
        invoice_sent:  'счёт отправлен',
        followup_sent: 'напоминание отправлено',
        order_created: 'заказ создан',
        invoice_created: 'счёт выставлен',
        payment_received: 'оплата получена',
    },

    /**
     * Удалить заметку (модуль 031). Заметку — и только её: веха сделки и письмо
     * этой кнопки не имеют, стирать историю отправленного КП нечем.
     */
    async deleteNote(counterpartyId, noteId) {
        if (!confirm('Удалить заметку?')) return;
        try {
            await this.api(`counterparties.php?action=note_delete&id=${counterpartyId}&note_id=${noteId}`,
                           {method: 'POST', body: {note_id: noteId}});
            this.loadChat(counterpartyId);
        } catch (err) { this.toast(err.message, 'error'); }
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

    /**
     * Развести карточку по отправителям (модуль 025).
     *
     * Домен оказался общим — сервис-ретранслятор или публичная почта, — и в
     * одну карточку сложились разные фирмы. Каждый адрес уходит в свою
     * карточку вместе с письмами, запросами и КП; домен больше не склеивает.
     */
    async splitSenders(id, count) {
        if (!confirm(`В карточке ${count} разных отправителей. Развести их по отдельным компаниям?\n\n`
                   + 'Письма, запросы и КП уйдут за своим адресом. Обратно можно объединить вручную.')) return;

        const btn = document.getElementById('splitBtn');
        if (btn) { btn.disabled = true; btn.textContent = 'Разделяем...'; }
        try {
            const res = await this.api(`counterparties.php?action=split_senders&id=${id}`, {method: 'POST'});
            this.toast(res.message || 'Готово', res.created ? 'success' : 'info');
            this.pageCounterparty(id);
        } catch (err) {
            this.toast(err.message, 'error');
            if (btn) { btn.disabled = false; btn.textContent = `Разделить по отправителям (${count})`; }
        }
    },

    // Pull fresh orders and invoices, then repaint the right column (FR-030)
    async syncCompany(id, silent = false) {
        // Background refreshes fire on every focus — don't hammer the MoySklad API
        const now = Date.now();
        if (silent && this._lastSync && this._lastSync.id === id && now - this._lastSync.at < 15000) return;
        this._lastSync = {id, at: now};

        const btn = document.getElementById('syncBtn');
        if (btn && !silent) { btn.disabled = true; btn.textContent = 'Обновление...'; }
        // Пункт меню «⋯» закрывается сразу — ход работы виден сообщением
        else if (!silent) this.toast('Обновляем из МойСклад…', 'info');
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

    // «Отправить счёт» отдельным окном больше нет (модуль 029): счёт цепляется
    // к письму под полем ответа — «Просмотреть» и «Прикрепить», с именем файла,
    // которое можно поправить тут же. Отправляет его та же кнопка «Отправить»,
    // что и всё письмо, — а не второй, параллельный способ послать клиенту файл.

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
        const items = data.items || [];
        // Список — главное на экране, подписка на push — под ним (модуль 050)
        document.getElementById('app').innerHTML = `
            ${this.pageHead({back: '', title: 'Уведомления',
                after: items.length ? ` <span class="pill">${items.length}</span>` : '',
                actions: items.length ? `<button class="btn btn--outline btn--sm" onclick="App.readAllNotifs(this)">✓ Прочитать все</button>` : ''})}
            <div class="card" id="notifList">
                ${items.length ? items.map(n => `
                    <!-- Оплата и отправка заказа ждут действия — выделены (модуль 047) -->
                    <div class="notif ${['order_shipped', 'order_paid'].includes(n.type) ? 'notif--action' : ''}" data-notif="${n.id}">
                        <div class="notif__text">
                            <strong>${this.esc(n.title)}</strong>
                            ${n.body ? `<p class="notif__body">${this.esc(n.body)}</p>` : ''}
                            <small class="muted">${new Date(String(n.created_at).replace(' ', 'T')).toLocaleString('ru-RU')}</small>
                        </div>
                        <div class="flex">
                            ${this.notifLink(n)}
                            <button class="btn btn--sm btn--outline" onclick="App.readNotif(${n.id}, this)"
                                    aria-label="Отметить прочитанным: ${this.esc(n.title)}">✓ Прочитано</button>
                        </div>
                    </div>
                `).join('') : this.notifEmpty()}
            </div>
            <div class="card" id="pushCard"><div class="loading">Загрузка...</div></div>
        `;
        this.renderPushCard();
    },

    notifEmpty() {
        return `<div class="empty">
            <p><strong>Новых уведомлений нет.</strong></p>
            <p class="muted">Здесь появятся новые письма, оплаты и отправки заказов, ошибки сервиса.
               Чтобы узнавать о них, не держа вкладку открытой, включите push ниже.</p>
        </div>`;
    },

    async readAllNotifs(btn) {
        const ids = [...document.querySelectorAll('[data-notif]')].map(el => Number(el.dataset.notif));
        if (!ids.length) return;
        btn.disabled = true;
        const done = await Promise.allSettled(ids.map(id =>
            this.api(`notifications.php?action=read&id=${id}`, {method: 'POST'})));
        const failed = done.filter(r => r.status === 'rejected').length;
        if (failed) this.toast(`Не отметилось: ${failed}`, 'error');
        this.pageNotifications();
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
        try {
            await this.api(`notifications.php?action=read&id=${id}`, {method:'POST'});
        } catch (err) { this.toast(err.message, 'error'); return; }
        btn.closest('[data-notif]').remove();
        const list = document.getElementById('notifList');
        if (list && !list.querySelector('[data-notif]')) this.pageNotifications();
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
        'board':        ['Доска «Письма»', 'Каждая карточка — КОМПАНИЯ, а не письмо: внутри вся её переписка, запросы и КП. Новые письма попадают сюда сами при открытии доски и по кнопке «Забрать почту» — и входящие, и те, что отправлены мимо сервиса, с телефона или из другого почтового клиента. Колонку карточке вы назначаете сами — сервис её никогда не двигает.'],
        'mail-keys':    ['Список писем', 'Письма идут по дате последнего письма: пришло новое — строка поднимается наверх и на миг подсвечивается. Жирным — есть непрочитанные, красная точка — клиент ждёт ответа. Слева — статусы (это колонки доски) со своим цветом. Клавиши: j / k — вниз / вверх, o или Enter — открыть, x — отметить, e — в архив, Shift+I — прочитано, / — поиск.'],
        'events':       ['Заметки, заказы и счета', 'Заметки для коллег, вехи сделки, заказы и счета — одной лентой со ссылками в МойСклад. То, что не относится к открытой переписке, приглушено. Отправленные письма и ответы на них — слева, в «Переписке». Красная точка на вкладке — у компании есть заметки.'],
        'thread':       ['Переписка', 'Вся цепочка писем с этой компанией, из всех наших ящиков сразу, в одной ленте. Прочитанные и наши собственные письма свёрнуты в строку; чтобы прочитать письмо целиком — нажмите на его заголовок. Переписки идут по порядку: старые сверху, свежая — внизу, и она раскрыта. В строке видно, чьё в переписке последнее письмо. Любое одно письмо убирается корзиной в его заголовке — остальная переписка остаётся на месте. Убранные как «не наш профиль» стоят свёрнутым блоком «Архив компании» над списком.'],
        'composer':     ['Ответ клиенту', 'Одно окно ответа на переписку. Письмо уходит с того ящика, который выбран справа вверху, и его копия ложится в «Отправленные» этого ящика. К ответу сам приписывается текст письма, на которое вы отвечаете, — клиенту не приходится вспоминать, о каком заказе речь.'],
        'category':     ['Классификатор', 'Категория решает, каким промптом сервис пишет ответ и откуда берёт факты — из каталога, из заказов или из вики. Если сервис прочитал письмо неправильно, поменяйте категорию ДО генерации: правка запомнится, и в следующем похожем письме он повторит ваше решение.'],
        'match':        ['Подходящие позиции', 'Что строки письма означают в нашем каталоге. Подбираются сами при открытии карточки — модель на это не тратится. Равнозначные варианты сервис не выбирает молча: он спрашивает.'],
        'match-scope':  ['«Не наша номенклатура»', 'Кнопка 🚫 убирает строку из КП и из ответа клиенту целиком: мы ей не занимаемся и ничего по ней не обещаем. Строка остаётся на экране, чтобы вы видели, что из просьбы клиента отброшено. Её слова пополняют список правил — в следующем письме такая же строка отсеется сама.'],
        'kp-conditions':['Цены и условия на всё КП', 'Тип цены, скидка и условия «под заказ» — один выбор на все позиции, а не сорок раз по строкам. «Применить ко всем» проставляет его строкам и ЗАПОМИНАЕТ: следующее КП откроется этим же. Строку, где цену вписали руками, общий выбор не трогает, а условия ожидания получают только позиции, которых нет на складе.'],
        'match-analog': ['Аналог', 'Мы предлагаем не то, что клиент назвал. Галочка открывает поле с его собственной формулировкой — правьте её как нужно. В КП она встанет над названием нашего товара курсивом серым, и закупщик найдёт в предложении свою позицию, не сверяя два документа глазами.'],
        'match-variant':['Модификации', 'Если в письме один товар просят в нескольких размерах или цветах («р.S-5шт, р.M-13шт»), сервис делает из этого отдельные строки с их количествами и подставляет каждой свою карточку из МойСклад — со своим артикулом, ценой и остатком.'],
        'kp-editor':    ['Редактор КП', 'Здесь правится всё, что попадёт в документ: цены, количества, тексты карточек товаров и блоки вокруг таблицы. Реквизиты и НДС правке не подлежат — они приходят из МойСклад и замораживаются на КП в момент создания.'],
        'kp-exclude':   ['Свернуть позицию', 'Позиции, которой нет в наличии, в таблице КП не будет — но в документе она останется: КП назовёт её словами клиента и скажет, что мы по ней уточняем. Молча выкинуть строку нельзя.'],
        // Настройки
        'kp-settings':  ['Оформление КП', 'Тексты и значения по умолчанию для каждого нового КП: условия поставки, сроки, подписи под фотографиями. В самом КП их можно переписать — здесь стоит то, с чего КП начинается, и сюда же приезжает последняя правка условий из любого КП.'],
        'mail-signature': ['Подпись в письмах', 'Дописывается в конец каждого письма, которое вы отправляете из сервиса, и в конец черновика нейросети. Второй раз не приписывается — если подпись в письме уже стоит, она остаётся одна. Пусто — берётся общая подпись компании из настроек почты.'],
        'signature':    ['Подпись под КП', 'Выберите, чем подписывать ваши КП: без подписи (строка несёт только дату), своей подписью — картинка и расшифровка ниже, или подписью организации (её задаёт администратор).'],
        'knowledge':    ['База знаний', 'Вики компании из репозитория GitHub. В промпт она попадает не целиком, а теми разделами, которые относятся к тексту письма. Это ЗНАНИЯ О ТОВАРЕ — инструкции про кнопки сюда класть нельзя, они мешают модели отвечать.'],
        'knowledge-check': ['Проверка подбора', 'Вставьте текст письма — увидите, какие разделы вики попадут в промпт и что сервис на это ответит. Ответ можно тут же забраковать кнопкой 👎 и написать, как он должен был звучать: эта правка уйдёт в обучение.'],
        'rethink':      ['Переосмыслить правки', 'Модель читает последние правки менеджеров и отправленные письма и собирает из них короткий свод правил. Свод сам никуда не уходит: его читают, правят и одной кнопкой подмешивают в выбранный промпт — отдельным блоком «ИЗ ПРАВОК МЕНЕДЖЕРОВ». Модель для этой работы выбирается здесь же: читать сотню писем лучше моделью поумнее.'],
        'prompts':      ['Промпты', 'Инструкции, по которым нейросеть пишет каждый ответ и каждое письмо. Правится текстом; у каждого промпта есть история и кнопка «Вернуть встроенный». Ко всем добавляется общий блок дисциплины — его отдельно дублировать не надо.'],
        'tov':          ['Tone of Voice', 'Как мы разговариваем с клиентом: обращение, длина фраз, что обещаем и чего не обещаем. Подмешивается в каждый ответ. Это НЕ база знаний: факты о товаре живут в вики, здесь — только манера речи.'],
        'stores':       ['Склады для остатков', 'Отметьте склады, с которых вы реально отгружаете. Остаток считается только по ним: остаток витрины или брака, попавший в КП, превращается в обещание, которого не выполнить. Ничего не отмечено — считаем по всем складам.'],
        'learning':     ['Правки и обучение', 'Всё, что человек поправил за машиной: категория письма, текст ответа, забракованный подбор. Свежие правки подмешиваются в промпты примерами, а накопленное выгружается архивом в вики компании.'],
        'learning-export': ['Выгрузка правок', 'Архив со всеми новыми правками уходит файлом в репозиторий вики. После удачной выгрузки они помечаются выгруженными, и следующий архив собирается только из новых — повторов не будет.'],
        'logo':         ['Логотипы', 'Три разных знака: в шапке КП, иконка приложения на телефоне и значок вкладки браузера. Файлы лежат вне репозитория, поэтому обновление кода их не стирает. Для КП лучше PNG без прозрачного фона — прозрачность на некоторых серверах не печатается.'],
        'catalog':      ['Каталог товаров', 'Копия номенклатуры МойСклад: названия, артикулы, цены, остатки и модификации. Из неё собираются КП — чтобы документ не зависел от того, отвечает ли сейчас МойСклад. Если API недоступен, каталог можно загрузить из Excel-выгрузки.'],
        'support':      ['Обратная связь', 'Что-то сломалось или мешает — опишите прямо с того экрана, где это увидели, и приложите скриншот, документ или видео. Обращение уходит АДМИНИСТРАТОРУ на ревью, а не сразу в публичный трекер; с его подтверждения оно становится issue в репозитории, и вы увидите ссылку. Отклонённое обращение возвращается с причиной.'],
        'setup':        ['Мастер настройки', 'Всё, что нужно сервису для старта с нуля, по одному делу за раз: ключи МойСклад и нейросетей, почтовый ящик, логотип, люди. У каждого шага стоит прямая ссылка, где взять ключ, и какие права ему нужны. Шаг считается пройденным, только когда он РАБОТАЕТ: каталог синхронизирован, провайдер отвечает, ящик включён. Мастер можно пройти заново на работающем сервисе — настройки при этом не стираются.'],
    },

    /**
     * Значок «?» рядом с блоком. `text` перебивает текст из HINTS.
     *
     * Значок стоит У ТОГО МЕСТА, о котором рассказывает, — а не кучкой в
     * заголовке: три «?» подряд над таблицей не говорят, какой из них про
     * что. Поэтому один и тот же ключ встречается на экране столько раз,
     * сколько раз встречается сам предмет (у каждой строки «не наша
     * номенклатура» — свой значок), а гайд по ключу их схлопывает и
     * показывает только первый.
     */
    hint(key, text) {
        const known = this.HINTS[key] || [];
        const title = known[0] || '';
        const body = text || known[1] || '';
        if (!body) return '';
        return `<button type="button" class="hint" aria-label="Подсказка: ${this.esc(title || key)}"
                        data-hint-key="${this.esc(key)}"
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
        // Один ключ — один шаг: значок «не наша номенклатура» стоит у каждой
        // такой строки, но рассказывать про него пять раз подряд незачем
        const seen = new Set();
        const steps = [...document.querySelectorAll('.hint')].filter(el => {
            const key = el.dataset.hintKey || el.dataset.hintTitle || '';
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
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
            // Подпись под КП и в письмах — своя вкладка у каждого (модуль 048)
            ['signature',  'Подпись',         false],
            ['branding',   'Логотипы',        true],
            ['knowledge',  'База знаний',     false],
            ['tov',        'Tone of Voice',   false],
            ['prompts',    'Промпты',         false],
            ['learning',   'Правки и обучение', true],
            ['managers',   'Менеджеры',       true],
            // Мастер настройки и обратная связь (модуль 038): мастер — админский,
            // жалоба — общая, жалуется тот, кто увидел поломку
            ['setup',      'Мастер настройки', true],
            ['support',    'Обратная связь',  false],
            ['device',     'Это устройство',  false],
            ['all',        'Все параметры',   true],
            ['logs',       'Логи',            true],
        ].filter(([, , adminOnly]) => admin || !adminOnly);
    },

    /** Группы разделов настроек: по делу человека, а не по устройству сервиса. */
    SETTINGS_GROUPS: [
        ['Работа с КП',      ['guide', 'catalog', 'kp', 'signature', 'knowledge', 'tov', 'prompts']],
        ['Интеграции',       ['moysklad', 'llm', 'mail', 'processing']],
        ['Команда и сервис', ['overview', 'managers', 'branding', 'setup', 'support', 'device']],
        ['Система',          ['learning', 'all', 'logs']],
    ],

    pageSettings(tab, arg) {
        const tabs = this.settingsTabs();
        // «#settings/logs/error» — журнал, открытый сразу на нужном уровне:
        // уведомление об ошибке и счётчик на «Обзоре» ведут именно сюда (модуль 029)
        if (tab === 'logs' && ['error', 'warning', 'info', 'all'].includes(arg)) {
            this.logState = Object.assign(this.logState || {},
                                          {level: arg === 'all' ? '' : arg, channel: '', q: '', offset: 0});
        }
        // Без явной вкладки админ попадает в «Обзор» — он заходит сюда работать,
        // а менеджер в «Ликбез»: ему здесь нужно объяснение, а не тумблеры
        if (!tabs.some(([k]) => k === tab)) tab = (this.manager && this.manager.is_admin) ? 'overview' : 'guide';
        // Двадцать вкладок в ряд не читались: четыре группы слева, на телефоне —
        // один список (модуль 050). Адреса #settings/<вкладка> те же.
        const have = new Map(tabs.map(([k, l]) => [k, l]));
        const groups = this.SETTINGS_GROUPS
            .map(([title, keys]) => [title, keys.filter(k => have.has(k))])
            .filter(([, keys]) => keys.length);
        document.getElementById('app').innerHTML = `
            ${this.pageHead({back: '', title: 'Настройки'})}
            <div class="settings">
                <nav class="snav" aria-label="Разделы настроек">
                    ${groups.map(([title, keys]) => `
                        <div class="snav__group">
                            <div class="snav__head">${this.esc(title)}</div>
                            ${keys.map(k => `<a href="#settings/${k}" class="snav__item ${tab === k ? 'snav__item--on' : ''}"
                                ${tab === k ? 'aria-current="page"' : ''}>${this.esc(have.get(k))}</a>`).join('')}
                        </div>`).join('')}
                </nav>
                <label class="snav-select">
                    <span class="sr-only">Раздел настроек</span>
                    <select onchange="location.hash = 'settings/' + this.value">
                        ${groups.map(([title, keys]) => `<optgroup label="${this.esc(title)}">
                            ${keys.map(k => `<option value="${k}" ${tab === k ? 'selected' : ''}>${this.esc(have.get(k))}</option>`).join('')}
                        </optgroup>`).join('')}
                    </select>
                </label>
                <div class="settings__body" id="adminBody"><div class="loading">Загрузка...</div></div>
            </div>
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
            signature:  () => this.settingsSignature(),
            branding:   () => this.settingsBranding(),
            knowledge:  () => this.adminKnowledge(),
            tov:        () => this.settingsTov(),
            learning:   () => this.adminLearning(),
            managers:   () => this.adminManagers(),
            setup:      () => this.settingsSetup(),
            support:    () => this.settingsSupport(),
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
                    <li><strong>Переписка</strong> — цепочки писем, старые сверху, свежие снизу.
                        Самая свежая раскрывается сразу, вместе с полем ответа и таблицей подходящих позиций;
                        в строке написано, чьё в ней последнее письмо — ваше или клиента.</li>
                    <li><strong>Счета под полем ответа</strong> — выставленные по этому запросу.
                        «Просмотреть» показывает печатную форму, «Прикрепить» кладёт файл в письмо,
                        а имя файла можно поправить прямо там.</li>
                    <li><strong>Заметки и события</strong> — то, что вы записали руками, вехи сделки
                        и сами заказы со счетами, со ссылками в МойСклад. Всё, что не про открытую
                        переписку, показано серым. Писем здесь нет намеренно: письма читаются в «Переписке».</li>
                    <li><strong>Информация</strong> — справа: ИНН, домен, организации, на которые клиент
                        просит счета, и контакты. Организаций может быть несколько — счёт выставляется
                        на выбранную.</li>
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
                    <li><strong>«Забрать почту»</strong> — разовая синхронизация: входящие и отправленные
                        мимо сервиса (с телефона, из Outlook). То же самое делается при открытии страницы
                        писем, а регулярно — <code>cron/check_mail.php</code>.</li>
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
            ${this.manager.is_admin ? '<div class="card" id="bankCard"><div class="loading">Т-Банк...</div></div>' : ''}
        `;
        this.loadMoyskladSettings();
        this.loadStores();
        if (this.manager.is_admin) this.loadBankCard();
        if (this.manager.is_admin) {
            this.api('admin.php?action=settings').then(s => {
                this.settingsSpec = (s.items || []).filter(i => i.group === 'moysklad');
                const org = this.settingsSpec.find(i => i.key === 'MOYSKLAD_ORG_ID');
                const el = document.getElementById('set_MOYSKLAD_ORG_ID');
                if (el && org) el.value = org.value || '';
            }).catch(() => {});
        }
    },

    /**
     * Т-Банк (модуль 047): оплата по счёту → «Входящий платёж» в МойСклад и
     * карточка в «Сборку». Токен и счета — во «Все параметры» → «Банк».
     */
    async loadBankCard(result = null) {
        const box = document.getElementById('bankCard');
        if (!box) return;
        try {
            const d = await this.api('admin.php?action=bank_state');
            const last = d.last;
            const status = {matched: 'проведена', unmatched: 'счёт не найден', error: 'ошибка МойСклад'};
            box.innerHTML = `
                <div class="card__title">Т-Банк: входящие оплаты</div>
                <p class="muted">Оплата по счёту проводится «Входящим платежом» в МойСклад, карточка компании
                   встаёт в «Сборку», менеджеру приходит «Сообщить складу». Склад вписал трек-номер в заказ —
                   готов черновик письма клиенту. Токен и номера счетов — в
                   <a href="#settings/all">«Все параметры» → «Банк (Т-Банк)»</a>. Cron: <code>cron/check_payments.php</code> раз в 10 минут.</p>
                <p>${d.configured ? `Счета: ${d.accounts.map(a => this.esc(a)).join(', ')}` : '<span class="no">Не настроен: нет токена или счёта</span>'}
                   ${last ? `<span class="muted"> · последняя проверка ${this.fmtDate(last.at)}: новых ${last.new}, по счёту ${last.matched}, без счёта ${last.unmatched}</span>` : ''}</p>
                ${result ? `<p class="${result.payments.errors.length ? 'no' : 'ok'}">Проверено: новых операций ${result.payments.new || 0},
                    заказов в «Сборке» ${result.shipments.checked}, отправлено ${result.shipments.shipped}
                    ${[...result.payments.errors, ...result.shipments.errors].map(e => '<br>' + this.esc(e)).join('')}</p>` : ''}
                <button class="btn btn--outline btn--sm" onclick="App.bankCheck(this)">Проверить оплаты сейчас</button>
                ${(d.recent || []).length ? `<table class="table" style="margin-top:10px"><tbody>
                    ${d.recent.map(r => `<tr><td>${this.fmtDate(r.operation_date)}</td><td>${this.fmtMoney(r.amount)}</td>
                        <td>${this.esc(r.payer_name || '')}</td><td>${r.invoice_name ? 'счёт ' + this.esc(r.invoice_name) : ''}</td>
                        <td class="${r.status === 'matched' ? 'ok' : 'no'}" title="${this.esc(r.error || r.purpose || '')}">${status[r.status] || this.esc(r.status)}</td></tr>`).join('')}
                </tbody></table>` : ''}`;
        } catch (err) {
            box.innerHTML = `<p class="no">${this.esc(err.message)}</p>`;
        }
    },

    async bankCheck(btn) {
        btn.disabled = true;
        btn.textContent = 'Проверяем...';
        try {
            const r = await this.api('admin.php?action=bank_check', {method: 'POST', body: {}});
            this.loadBankCard(r);
        } catch (err) {
            this.toast(err.message, 'error');
            btn.disabled = false;
            btn.textContent = 'Проверить оплаты сейчас';
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

                <div class="card">
                    <div class="card__title">Подпись под КП</div>
                    <p class="muted">Подписывать КП или нет, своей подписью или подписью организации — во вкладке
                       <a href="#settings/signature">«Подпись»</a>.</p>
                </div>

                <div class="card">
                    <div class="card__title">Умолчания коммерческого предложения${this.hint('kp-settings')}</div>
                    <p class="muted">Применяются там, где МойСклад молчит: ставка позиции и реквизиты организации
                       всегда важнее этих полей.</p>
                    <div class="grid grid--3">
                        <div class="form-group"><label>НДС по умолчанию, %</label>
                            <input type="number" id="kpVat" value="${this.esc(g.default_vat_rate || 5)}"
                                   ${s.pays_vat ? '' : 'disabled'}>
                            ${vatHint}</div>
                        <div class="form-group"><label>Цены в КП и НДС</label>
                            <select id="kpVatMode" ${s.pays_vat ? '' : 'disabled'}>
                                <option value="included" ${d.vat_mode === 'added' ? '' : 'selected'}>цена в т.ч. НДС</option>
                                <option value="added" ${d.vat_mode === 'added' ? 'selected' : ''}>цена + НДС сверху</option>
                            </select>
                            <div class="muted">${d.vat_mode === 'added'
                                ? 'В таблице КП — «Цена за ед., без НДС», под итогом — «Итого без НДС», «НДС», «Итого с НДС»: налог прибавляется к итогу. В МойСклад цены тоже должны быть без НДС — счёт по такому КП выставляется с «цены не включают НДС».'
                                : 'В таблице КП — «Цена за ед., в т.ч. НДС», под итогом — «Итого» и «в т.ч. НДС»: налог уже внутри цены.'}
                               Применяется ко всем КП, кроме тех, где выбрано своё значение.</div></div>
                        <div class="form-group"><label>Срок исполнения, дней</label>
                            <input type="number" id="kpExec" value="${this.esc(g.default_execution_days || 30)}"></div>
                        <div class="form-group"><label>Срок действия КП, дней</label>
                            <input type="number" id="kpValid" value="${this.esc(g.default_validity_days || 14)}"></div>
                        <div class="form-group"><label>Фото на позицию, максимум</label>
                            <input type="number" id="kpMaxImages" min="0" max="12" value="${this.esc(g.kp_max_images_per_item || 5)}"></div>
                        <div class="form-group"><label>Папка модулей в МойСклад</label>
                            <input type="text" id="kpAddonCategory" value="${this.esc(g.addon_category || '')}">
                            <div class="muted">Товары из этой папки каталога МойСклад — «модули» для допродажи:
                               новое КП получает их таблицей «Дополнительные модули и доукомплектование»
                               (до 12 самых дешёвых, кроме уже стоящих в КП). Пусто — блока нет.</div></div>
                    </div>
                    <!-- Условия одним блоком (модуль 026). Четыре зашитых абзаца внизу
                         КП — упаковка, гарантия, срок исполнения, срок действия цены —
                         стали правимым текстом; последняя правка в любом КП приезжает
                         сюда и становится заготовкой для следующих. -->
                    <div class="form-group"><label>Условия поставки — заготовка для новых КП</label>
                        <textarea id="kpTerms" rows="5">${this.esc(g.default_terms_text || '')}</textarea>
                        <div class="muted">Печатается в конце КП одним блоком.
                            <code>{execution_term}</code> — срок исполнения словами (дни из полей КП,
                            а при позициях под заказ — срок ожидания из подбора),
                            <code>{validity_days}</code> — срок действия цены,
                            <code>{delivery_in_price}</code> — «доставку, », если доставка включена в цену товаров,
                            <code>{delivery_separate_clause}</code> — строка «Доставка в стоимость не включена…»,
                            если доставка не включена (настройка «Доставка в КП»).
                            Пусто — условия не печатаются вовсе.</div></div>
                    <div class="form-group"><label>Оговорка под фотографиями</label>
                        <textarea id="kpImagesNote" rows="2">${this.esc(g.kp_images_note || '')}</textarea></div>
                    <button class="btn btn--primary" onclick="App.saveKpSettings()">Сохранить</button>
                </div>
            `;
        } catch (err) { this.adminFail(err); }
    },

    /**
     * «Подпись» — своя вкладка настроек (модуль 048).
     *
     * КП подписывает тот, кто его отправляет (модуль 022): своя расшифровка и
     * картинка у каждого. Ничего не заведено — печатается подпись организации,
     * её задаёт администратор здесь же. Каждая карточка грузится сама по себе:
     * упавший запрос одной не прячет остальные.
     */
    settingsSignature() {
        const admin = !!(this.manager && this.manager.is_admin);
        document.getElementById('adminBody').innerHTML = `
            <div class="card" id="kpSignatureCard"><div class="loading">Читаем подпись...</div></div>
            ${admin ? '<div class="card" id="companySignatureCard"><div class="loading">Читаем подпись организации...</div></div>' : ''}
            <div class="card" id="mailSignatureCard"><div class="loading">Читаем подпись в письмах...</div></div>`;
        this.loadSignature();
        if (admin) this.loadCompanySignature();
        this.loadMailSignature();
    },

    /** Строка, которой КП заканчивается: дата, картинка, расшифровка (без прочерка — модуль 051). */
    signaturePreview(name, imgUrl) {
        const d = new Date();
        const date = `${String(d.getDate()).padStart(2, '0')}.${String(d.getMonth() + 1).padStart(2, '0')}.${d.getFullYear()}г.`;
        return `<div class="sig-line"><span>${date}</span>
            ${imgUrl ? `<span class="sig-line__mark"><img src="${imgUrl}" alt="Подпись"></span>` : ''}
            ${name ? `<strong>${this.esc(name)}</strong>` : ''}</div>`;
    },

    async loadSignature() {
        const card = document.getElementById('kpSignatureCard');
        if (!card) return;
        try {
            const d = await this.api('admin.php?action=signature');
            const mode = d.mode || 'none';
            const own = d.has_image ? `api/settings.php?action=signature_image&v=${Date.now()}` : '';
            const company = d.has_company_image ? `api/settings.php?action=company_signature_image&v=${Date.now()}` : '';
            const opt = (v, label, note) => `<label class="sig-mode__opt">
                <input type="radio" name="sigMode" value="${v}" ${mode === v ? 'checked' : ''}
                       onchange="App.saveSignatureMode(this.value)">
                <span><strong>${label}</strong><span class="muted"> — ${note}</span></span></label>`;
            card.innerHTML = `
                <div class="card__title">Подпись под КП${this.hint('signature')}</div>
                <p class="muted">Ставится под теми КП, которые делаете вы.</p>
                <fieldset class="sig-mode">
                    <legend class="sr-only">Чем подписывать КП</legend>
                    ${opt('none', 'Без подписи', 'под КП только дата')}
                    ${opt('own', 'Моя подпись', 'картинка и расшифровка ниже')}
                    ${opt('company', 'Подпись организации', this.esc(d.default_name || 'задаёт администратор'))}
                </fieldset>
                ${mode === 'own' ? `
                <div class="form-group"><label for="sigName">Расшифровка подписи</label>
                    <input type="text" id="sigName" value="${this.esc(d.signatory_name || '')}"
                           placeholder="${this.esc(this.manager && this.manager.name || 'Фамилия Имя Отчество')}"></div>
                <div class="flex flex--wrap">
                    <button class="btn btn--primary" onclick="App.saveSignatoryName(this)">Сохранить расшифровку</button>
                    <label class="btn btn--outline" style="cursor:pointer">
                        Загрузить картинку подписи
                        <input type="file" accept="image/png,image/jpeg" hidden onchange="App.uploadSignature(this)">
                    </label>
                    ${d.has_image ? '<button class="btn btn--outline btn--danger" onclick="App.resetSignature()">Убрать картинку</button>' : ''}
                </div>
                <p class="muted" style="margin-top:6px">PNG или JPG, лучше на прозрачном или белом фоне, высотой около 200 px.</p>` : ''}
                <p class="muted">Так заканчивается ваше КП:</p>
                ${this.signaturePreview(d.effective_name, mode === 'own' ? own : mode === 'company' ? company : '')}
                <div id="sigOut" style="margin-top:8px"></div>`;
        } catch (err) {
            card.innerHTML = `<div class="card__title">Подпись под КП</div><p class="no">${this.esc(err.message)}</p>`;
        }
    },

    async saveSignatureMode(mode) {
        try {
            await this.api('settings.php?action=signature_mode', {method: 'POST', body: {mode}});
            this.toast(mode === 'none' ? 'Ваши КП уходят без подписи' : 'Подпись под КП выбрана', 'success');
        } catch (err) { this.toast(err.message, 'error'); }
        this.loadSignature();
    },

    /** Подпись организации — под КП менеджера, который своей не завёл (модуль 048). */
    async loadCompanySignature() {
        const card = document.getElementById('companySignatureCard');
        if (!card) return;
        try {
            const d = await this.api('admin.php?action=company_signature');
            const img = d.has_image ? `api/settings.php?action=company_signature_image&v=${Date.now()}` : '';
            card.innerHTML = `
                <div class="card__title">Подпись организации</div>
                <p class="muted">Печатается под КП менеджеров, выбравших «Подпись организации».
                   ${d.moysklad_name ? `Подписант в МойСклад: <strong>${this.esc(d.moysklad_name)}</strong> — поле ниже важнее.` : ''}</p>
                <div class="form-group"><label>Расшифровка подписи организации</label>
                    <input type="text" id="companySigName" value="${this.esc(d.signatory_name || '')}"
                           placeholder="${this.esc(d.effective_name || 'Фамилия Имя Отчество')}"></div>
                ${this.signaturePreview(d.effective_name, img)}
                <div class="flex flex--wrap">
                    <button class="btn btn--primary" onclick="App.saveCompanySignatoryName(this)">Сохранить расшифровку</button>
                    <label class="btn btn--outline" style="cursor:pointer">
                        Загрузить картинку подписи
                        <input type="file" accept="image/png,image/jpeg" hidden onchange="App.uploadSignature(this, 'company')">
                    </label>
                    ${d.has_image ? '<button class="btn btn--outline btn--danger" onclick="App.resetCompanySignature()">Убрать картинку</button>' : ''}
                </div>
                <div id="companySigOut" style="margin-top:8px"></div>`;
        } catch (err) {
            card.innerHTML = `<div class="card__title">Подпись организации</div><p class="no">${this.esc(err.message)}</p>`;
        }
    },

    /** Подпись в письмах (модуль 039): письмо заканчивается именем того, кто его отправил. */
    async loadMailSignature() {
        const card = document.getElementById('mailSignatureCard');
        if (!card) return;
        try {
            const mail = await this.api('settings.php?action=mail_signature');
            card.innerHTML = `
                <div class="card__title">Подпись в письмах${this.hint('mail-signature')}</div>
                <p class="muted">Дописывается к каждому вашему письму — и к черновику, который пишет нейросеть.
                   ${mail.source === 'manager' ? 'Сейчас стоит ваша.'
                     : mail.source === 'company' ? 'Своей нет — подписывается общей подписью компании.'
                     : 'Своей нет и общей нет — подпись собирается из вашего имени и телефона.'}</p>
                <div class="form-group">
                    <textarea id="mailSig" rows="4"
                        placeholder="${this.esc(mail.fallback || 'С уважением,\nЯна, менеджер по оптовым заказам\n+7 977 508-45-85')}">${this.esc(mail.signature || '')}</textarea>
                </div>
                <p class="muted">Уйдёт с письмом:</p>
                <pre class="sig-preview">${this.esc(mail.effective || '')}</pre>
                <div class="flex flex--wrap">
                    <button class="btn btn--primary" onclick="App.saveMailSignature(this)">Сохранить подпись в письмах</button>
                </div>`;
        } catch (err) {
            card.innerHTML = `<div class="card__title">Подпись в письмах</div><p class="no">${this.esc(err.message)}</p>`;
        }
    },

    /** Звук уведомления — свой у каждого (issue #60); живёт в «Это устройство». */
    async loadMySound() {
        const card = document.getElementById('mySoundCard');
        if (!card) return;
        try {
            const snd = await this.api('settings.php?action=my_sound');
            card.innerHTML = `
                <div class="card__title">Мой звук уведомления</div>
                <p class="muted">Играет, когда приходит новое письмо. «Как в настройках» —
                   общий звук сервиса${snd.common ? ': ' + this.esc(snd.common) : ' (сейчас не выбран)'}.</p>
                <div class="flex flex--wrap">
                    <select id="mySound"><option value="">(как в настройках)</option></select>
                    <button type="button" class="btn btn--outline btn--sm" onclick="App.playSoundPreview('mySound')">▶ Послушать</button>
                    <label>громкость <input type="number" id="myVolume" min="0" max="100" style="width:5em"
                           placeholder="как в настройках" value="${this.esc(snd.volume)}"></label>
                    <button class="btn btn--primary btn--sm" onclick="App.saveMySound(this)">Сохранить звук</button>
                </div>`;
            this.loadSoundOptions('mySound', snd.sound || '');
        } catch (err) {
            card.innerHTML = `<div class="card__title">Мой звук уведомления</div><p class="no">${this.esc(err.message)}</p>`;
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

    async saveCompanySignatoryName(btn) {
        btn.disabled = true;
        try {
            await this.api('admin.php?action=company_signature', {method: 'POST',
                body: {signatory_name: document.getElementById('companySigName').value}});
            this.toast('Расшифровка организации сохранена', 'success');
            this.loadCompanySignature();
            this.loadSignature();
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; }
    },

    /** Подпись в письмах — своя у каждого (модуль 039). */
    async saveMailSignature(btn) {
        btn.disabled = true;
        try {
            await this.api('settings.php?action=mail_signature', {method: 'POST',
                body: {signature: document.getElementById('mailSig').value}});
            this._mailSign = undefined;   // строка у поля ответа покажет новую
            this.toast('Подпись в письмах сохранена', 'success');
            this.loadMailSignature();
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; }
    },

    /** Свой звук уведомления (issue #60): пусто — как в настройках сервиса. */
    async saveMySound(btn) {
        btn.disabled = true;
        try {
            const r = await this.api('settings.php?action=my_sound', {method: 'POST', body: {
                sound: document.getElementById('mySound').value,
                volume: document.getElementById('myVolume').value,
            }});
            // Экран должен зазвонить по-новому сразу, не дожидаясь перезагрузки
            this.ui.mail_sound = r.effective || '';
            if (document.getElementById('myVolume').value !== '') {
                this.ui.mail_sound_volume = Number(document.getElementById('myVolume').value);
            }
            this.toast('Звук сохранён', 'success');
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; }
    },

    /** scope = 'company' — общая подпись организации (только администратор). */
    async uploadSignature(input, scope = 'manager') {
        const file = input.files && input.files[0];
        if (!file) return;
        const out = document.getElementById(scope === 'company' ? 'companySigOut' : 'sigOut');
        out.innerHTML = '<p class="muted">Загружаем...</p>';
        const fd = new FormData();
        fd.append('file', file);
        try {
            const res = await fetch(`/api/settings.php?action=upload_signature&scope=${scope}`,
                                    {method: 'POST', body: fd, credentials: 'same-origin'});
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            out.innerHTML = '';
            this.toast('Подпись загружена', 'success');
            if (scope === 'company') this.loadCompanySignature();
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

    async resetCompanySignature() {
        if (!confirm('Убрать картинку подписи организации? Расшифровка останется.')) return;
        try {
            await this.api('admin.php?action=company_signature_reset', {method: 'POST', body: {}});
            this.toast('Картинка убрана', 'success');
            this.loadCompanySignature();
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
            // Вид цены — параметр из общего списка настроек, а не «умолчание КП»
            const vatMode = document.getElementById('kpVatMode');
            if (vatMode) {
                await this.api('admin.php?action=settings', {method: 'PUT',
                    body: {values: {KP_VAT_MODE: vatMode.value}}});
            }
            await this.api('settings.php?action=general', {method: 'PUT', body: {
                default_vat_rate: document.getElementById('kpVat').value,
                default_execution_days: document.getElementById('kpExec').value,
                default_validity_days: document.getElementById('kpValid').value,
                kp_max_images_per_item: document.getElementById('kpMaxImages').value,
                addon_category: document.getElementById('kpAddonCategory').value,
                default_terms_text: document.getElementById('kpTerms').value,
                kp_images_note: document.getElementById('kpImagesNote').value,
            }});
            this.toast('Сохранено', 'success');
            // Перерисовываем: под выбором вида цены написано, что теперь
            // печатается в документе, — эта подпись обязана совпасть с сохранённым
            this.settingsKp();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // ---- This device: push and the installable app ----

    settingsDevice() {
        document.getElementById('adminBody').innerHTML = `
            <div class="card" id="pushCard"><div class="loading">Загрузка...</div></div>
            <div class="card" id="mySoundCard"><div class="loading">Загрузка...</div></div>
            <div class="card" id="installCard"><div class="loading">Загрузка...</div></div>
        `;
        this.renderPushCard();
        this.loadMySound();
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

    async mailSync() {
        this.toast('Синхронизация почты...', 'info');
        try {
            const r = await this.api('mail.php?action=sync', {method: 'POST', body: {}});
            const total = (r.report || []).reduce((a, x) => a + x.in + x.out, 0);
            const errors = (r.report || []).filter(x => x.error);
            if (errors.length) this.toast(errors.map(e => `${e.name}: ${e.error}`).join('; '), 'error');
            else this.toast(`Загружено писем: ${total}`, 'success');
            this.route();
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
        this.foldKey = key;
        const t = d.thread;
        const reply = d.reply || {};
        // Кто написал: последнее входящее письмо цепочки — то, на что отвечают
        const lastIn = [...(d.messages || [])].reverse().find(m => m.direction === 'in') || (d.messages || [])[0] || {};
        const sender = {name: lastIn.real_from_name || lastIn.from_name || '',
                        email: lastIn.real_from_email || lastIn.from_email || ''};
        // В шапке карточки — компания, ОТПРАВИТЕЛЬ и тема, как они есть: по
        // одной теме понять, чьё это письмо, нельзя (модуль 023)
        document.getElementById('app').innerHTML = `
            ${this.pageHead({
                title: this.esc(t.counterparty_name || sender.name || sender.email || 'Без компании'),
                meta: `
                    <div class="thread-head__who">
                        ${sender.name || sender.email
                            ? `<span>${this.esc(sender.name || '')}${sender.email ? ` &lt;${this.esc(sender.email)}&gt;` : ''}</span>` : ''}
                        <span class="thread-head__subject">${this.esc(t.subject || 'Без темы')}</span>
                    </div>
                    <div class="muted">
                        писем: ${t.count} · входящих ${t.in_count} · исходящих ${t.out_count}
                        ${(t.mailboxes || []).map(b => `<span class="chip chip--box">${this.esc(b.name)}</span>`).join('')}
                        ${t.counterparty_id ? ` · <a href="#mail/company/${t.counterparty_id}">${this.esc(t.counterparty_name)}</a>` : ''}
                    </div>`,
                actions: t.archived_at
                    ? `<button class="btn btn--outline btn--sm" onclick="App.unarchiveThread('${this.jsStr(key)}')">↩ Вернуть в работу</button>`
                    : `<button class="btn btn--outline btn--sm" onclick="App.archiveThread('${this.jsStr(key)}', ${t.count})">🗄 В архив</button>`,
                menu: [{label: '🗑 Удалить переписку', danger: true,
                        onclick: `App.deleteThread('${this.jsStr(key)}', ${t.count})`}],
            })}
            <div id="threadPlacement"></div>
            <!-- Одна раскладка для всех писем: переписка слева, подбор справа на
                 десктопе и снизу на телефоне. Раньше «Подходящие позиции» стояли
                 то справа, то внизу — в зависимости от того, откуда письмо
                 открыли, и это читалось как два разных экрана (модуль 023). -->
            <div class="letter">
                <div class="letter__main">
                    ${this.threadHtml(d.messages, key)}
                    <!-- Подбор — в левой колонке над полем письма (issue #60):
                         читаем переписку, подбираем товары, собираем КП, ниже
                         пишем письмо -->
                    <div data-thread-items></div>
                    ${this.threadComposer(key, reply, d.mailboxes || [])}
                </div>
                <aside class="letter__side">
                    <div id="threadFacts"></div>
                </aside>
            </div>
            <div id="kpWide"></div>
        `;
        this.mountBodies(document.getElementById('app'));
        this.loadThreadPlacement(key);
        this.restoreComposerDraft(key);
        this.fillSignatureNote(document.getElementById('app'));
        this.loadThreadFacts(t, lastIn, d.moysklad);
        // Панель подбора есть у КАЖДОГО письма: у письма без запроса она честно
        // говорит, почему пуста, а не исчезает вовсе
        const host = document.getElementById('app');
        if (reply.request_id) this.loadThreadItems(host, reply.request_id);
        else this.noThreadItems(host, key);
        // Счета этого запроса — под полем ответа (модуль 029)
        this.companyRequestId = reply.request_id ? String(reply.request_id) : '';
        this.loadInvoiceDock();
    },

    /**
     * Правая панель письма: что мы про него знаем (модуль 023).
     *
     * Раньше её не было у писем без запроса — «письмо с вопросом по заказу»
     * открывалось голым экраном, и непонятно было, от кого оно и куда пришло.
     * Панель одна на все письма; пустых полей в ней просто нет.
     */
    loadThreadFacts(t, lastIn, ms) {
        const box = document.getElementById('threadFacts');
        if (!box) return;
        const rows = [];
        const add = (label, value) => { if (value) rows.push(`<div class="facts__row"><span>${label}</span><b>${value}</b></div>`); };
        add('Отправитель', this.esc([lastIn.real_from_name || lastIn.from_name,
                                     lastIn.real_from_email || lastIn.from_email].filter(Boolean).join(' · ')));
        add('Кому', this.esc(lastIn.to_emails || ''));
        add('Ящик', this.esc(lastIn.mailbox_name || ''));
        add('Получено', this.fmtDate(lastIn.date_at));
        add('Компания', t.counterparty_id
            ? `<a href="#mail/company/${t.counterparty_id}">${this.esc(t.counterparty_name || '')}</a>`
            : '<span class="muted">не опознана</span>');
        if (ms && ms.inn) add('ИНН', this.esc(ms.inn) + (ms.inn_from_letter ? ' <span class="muted">— из письма</span>' : ''));
        if (ms && ms.linked) add('МойСклад', `<a href="${this.esc(this.msUrl(ms.moysklad_id))}" target="_blank" rel="noopener">контрагент ↗</a>`);
        add('Категория', this.esc(this.categoryLabels[lastIn.category] || lastIn.category || ''));
        add('Вложений', (lastIn.attachments || []).length || '');
        box.className = 'card';
        box.dataset.block = 'info';
        box.innerHTML = `<div class="card__title">Информация</div>
            <div class="facts">${rows.join('') || '<p class="muted">Ничего, кроме самого письма.</p>'}</div>
            ${ms && !ms.linked ? this.msCreateLink(ms, t.thread_key) : ''}`;
    },

    // ---- Контрагент, которого нет в МойСклад (модуль 033) ----

    msUrl(id) {
        return 'https://online.moysklad.ru/app/#counterparty/edit?id=' + encodeURIComponent(id || '');
    },

    /**
     * Ссылка «завести контрагента» прямо из письма.
     *
     * Письмо от компании, которой нет в МойСклад, упиралось в тупик: ни счёта,
     * ни заказа, а завести контрагента можно было только руками, перепечатав
     * ИНН из подписи. Здесь ИНН уже подставлен — тот, что нашёлся в письме или
     * во вложенной карточке предприятия.
     */
    msCreateLink(hint, threadKey) {
        // Подсказка живёт на странице, а не в атрибуте onclick: в названии
        // компании бывают кавычки, и склейка их в разметку кончается ничем
        this._ms = {hint: hint || {}, threadKey: threadKey || ''};
        const inn = hint.inn || '';
        return `<div class="ms-offer">
            <p class="muted">Контрагента нет в МойСклад${inn
                ? ` · ИНН ${this.esc(inn)}`
                : ' · ИНН в переписке не нашёлся — его поищет нейросеть'}</p>
            <button class="btn btn--outline btn--sm" onclick="App.msCreateForm()">
                ➕ Создать контрагента в МойСклад</button>
        </div>`;
    },

    /**
     * ИНН и название перед отправкой видно и можно поправить.
     *
     * $hint передаётся, когда форму открывает строка организации в карточке
     * компании (модуль 034); без него берётся подсказка письма.
     */
    msCreateForm(hint) {
        if (hint) this._ms = {hint, threadKey: (this._ms || {}).threadKey || ''};
        hint = (this._ms || {}).hint || {};
        this.modal('Контрагент в МойСклад', `
            <p class="muted">Заведём контрагента с этим ИНН. Если контрагент с таким ИНН
               в МойСклад уже есть, компания просто привяжется к нему — двойника не будет.</p>
            <div class="form-group">
                <label>Название</label>
                <input type="text" id="msName" value="${this.esc(hint.name || '')}" placeholder="ООО «Ромашка»">
            </div>
            <div class="form-group">
                <label>ИНН</label>
                <input type="text" id="msInn" value="${this.esc(hint.inn || '')}" inputmode="numeric"
                       placeholder="10 или 12 цифр">
            </div>
            <div class="flex flex--wrap" style="margin-bottom:8px">
                <button class="btn btn--outline btn--sm" id="msFind" onclick="App.msFindInn()"
                        title="Прочитать переписку и вложения и найти ИНН в них">✦ Найти ИНН в переписке</button>
                <span id="msInnNote" class="muted"></span>
            </div>
            <button class="btn btn--primary btn--block" id="msGo" onclick="App.msCreate()">
                Создать в МойСклад</button>`);
    },

    /**
     * Найти ИНН в письмах и вложениях: сначала по образцу, потом нейросетью.
     *
     * Раньше ИНН подставлялся, только если стоял словом «ИНН» в последнем
     * письме. В карточке предприятия, в скане счёта и в подписи первого письма
     * его не видели — и менеджер перепечатывал руками (модуль 034).
     */
    async msFindInn() {
        const {hint = {}, threadKey = ''} = this._ms || {};
        const btn = document.getElementById('msFind');
        const note = document.getElementById('msInnNote');
        if (btn) { btn.disabled = true; btn.textContent = 'Читаем переписку...'; }
        if (note) note.textContent = '';
        try {
            const q = [];
            if (hint.counterparty_id) q.push('id=' + Number(hint.counterparty_id));
            if (threadKey) q.push('thread_key=' + encodeURIComponent(threadKey));
            const d = await this.api('counterparties.php?action=find_inn&' + q.join('&'));
            const field = document.getElementById('msInn');
            if (d.inn) {
                if (field) field.value = d.inn;
                const nameField = document.getElementById('msName');
                if (nameField && !nameField.value && d.legal_title) nameField.value = d.legal_title;
                if (note) note.textContent = d.by_llm ? `нашла нейросеть — ${d.source}` : d.source;
            } else if (note) {
                note.textContent = 'ИНН в переписке и во вложениях не нашёлся — впишите руками';
            }
        } catch (err) { this.toast(err.message, 'error'); }
        finally { if (btn) { btn.disabled = false; btn.textContent = '✦ Найти ИНН в переписке'; } }
    },

    async msCreate() {
        const {hint = {}, threadKey = ''} = this._ms || {};
        const btn = document.getElementById('msGo');
        if (btn) { btn.disabled = true; btn.textContent = 'Создаём...'; }
        try {
            const r = await this.api('counterparties.php?action=moysklad_create', {method: 'POST', body: {
                counterparty_id: hint.counterparty_id || 0,
                org_id: hint.org_id || 0,
                thread_key: threadKey,
                name: (document.getElementById('msName') || {}).value || '',
                inn: (document.getElementById('msInn') || {}).value || '',
                email: hint.email || '',
                phone: hint.phone || '',
            }});
            this.closeModal();
            this.toast(r.created ? 'Контрагент заведён в МойСклад'
                                 : 'Контрагент с таким ИНН уже был в МойСклад — привязали', 'success');
            if (r.url) window.open(r.url, '_blank', 'noopener');
            this.route();
        } catch (err) {
            this.toast(err.message, 'error');
            if (btn) { btn.disabled = false; btn.textContent = 'Создать в МойСклад'; }
        }
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
        // Свёрнутое письмо показывает начало текста в ТОМ ЖЕ поле, где потом
        // раскроется тело: два поля подряд читались как два письма (модуль 037)
        const preview = (m.body_text || '').replace(/\s+/g, ' ').trim().slice(0, 240);
        // Почтовый адрес рядом с именем: «Иванов» в переписке бывает не один,
        // и отвечать надо на адрес, а не на имя (модуль 037)
        const addr = m.direction === 'in'
            ? (m.real_from_email || m.from_email || '')
            : (m.from_email || '');
        const cls = ['lmsg', m.direction === 'in' ? 'lmsg--in' : 'lmsg--out'];
        if (open) cls.push('lmsg--open');
        return `
            <article class="${cls.join(' ')}" data-tmsg data-mail="${m.id}">
                <header class="lmsg__head" onclick="App.toggleTmsg(this)">
                    <!-- Кто написал: у пересланного письма это человек из шапки
                         пересылки, а не наш ящик, через который оно пришло -->
                    <span class="lmsg__who">${this.esc(m.real_from_name || m.from_name || m.from_email || '—')}</span>
                    ${addr ? `<span class="lmsg__addr muted" title="Почтовый адрес отправителя">&lt;${this.esc(addr)}&gt;</span>` : ''}
                    <span class="lmsg__to muted">${m.direction === 'in' ? '→ нам' : '→ ' + this.esc(m.to_emails)}</span>
                    <span class="lmsg__date muted">${this.fmtDate(m.date_at)}</span>
                    <!-- Удалить одно письмо, не открывая его и не трогая переписку:
                         кнопка проявляется на наведении, чтобы случайное касание
                         не выбросило письмо (модуль 031) -->
                    <button class="lmsg__del" title="Удалить это письмо"
                            onclick="event.stopPropagation(); App.deleteMail(${m.id}, {thread: '${this.jsStr(key || '')}'})">🗑</button>
                </header>
                <div class="lmsg__tags">
                    ${m.is_forwarded && m.real_from_email && m.real_from_email !== m.from_email
                        ? '<span class="chip" title="Письмо переслано через наш ящик">переслано</span>' : ''}
                    <span class="chip chip--box">${this.esc(m.mailbox_name || 'без ящика')}</span>
                    ${m.folder ? `<span class="muted lmsg__folder">${this.esc(m.folder)}</span>` : ''}
                    ${m.has_attachment ? '<span title="есть вложения">📎</span>' : ''}
                    ${sentBad ? '<span class="badge badge--warning" title="Копия не попала в «Отправленные» на сервере">нет в «Отправленных»</span>' : ''}
                </div>
                <div class="lmsg__text">
                    ${preview ? `<div class="lmsg__peek muted">${this.esc(preview)}</div>` : ''}
                    <div class="lmsg__full">
                        ${m.cc_emails ? `<div class="muted" style="margin-bottom:6px">Копия: ${this.esc(m.cc_emails)}</div>` : ''}
                        ${this.msgBodyHtml(m)}
                        ${(m.attachments || []).length ? `<div class="msg__files">
                            ${m.attachments.map(a => this.attachmentLink(a, 'mail.php')).join('')}
                        </div>` : ''}
                        <div class="lmsg__actions">
                            <button class="btn btn--outline btn--sm"
                                    onclick="App.replyToMessage('${this.jsStr(key || '')}', ${m.id}, '${this.jsStr(m.direction === 'in' ? (m.from_email || '') : (m.to_emails || ''))}')">
                                Ответить на это письмо</button>
                            ${m.direction === 'in' && !m.archived_at ? `<button class="btn btn--outline btn--sm" title="Не наш профиль: письмо уйдёт в «Архив» на сервере"
                                onclick="App.archiveMail(${m.id})">🗄 В архив</button>` : ''}
                            ${m.archived_at ? `<button class="btn btn--outline btn--sm" onclick="App.unarchiveMail(${m.id})">↩ Вернуть в работу</button>` : ''}
                            <button class="btn btn--outline btn--sm"
                                    title="Отправить это письмо целиком на другой адрес — со вложениями и шапкой «от кого»"
                                    onclick="App.forwardMail(${m.id})">↪ Перенаправить</button>
                            <!-- Спам и удаление — не рядом с «Ответить»: в меню «⋯» (модуль 050) -->
                            ${this.menuHtml([
                                m.direction === 'in' ? {label: '🚫 Спам', danger: true, onclick: `App.markSpam(${m.id})`} : null,
                                {label: '🗑 Удалить письмо', danger: true,
                                 onclick: `App.deleteMail(${m.id}, {thread: '${this.jsStr(key || '')}'})`},
                            ], {aria: 'Ещё действия с письмом'})}
                        </div>
                    </div>
                </div>
            </article>`;
    },

    /**
     * Вся лента писем переписки — одной разметкой на оба входа в письмо.
     *
     * Карточка компании и страница письма рисовали ленту по-разному, и правка
     * в одной не доезжала до другой. Теперь обе зовут это.
     */
    threadHtml(messages, key) {
        const n = (messages || []).length;
        return `<section class="lblock" data-block="thread">
            <div class="lblock__head" data-block-head>Письма <span class="muted">· ${n}</span></div>
            <div class="thread">
                ${(messages || []).map((m, i) => this.threadMessage(m, i === messages.length - 1, key)).join('')}
            </div>
        </section>`;
    },

    /**
     * Этап переписки — строкой кнопок, у КАЖДОГО письма (модуль 034).
     *
     * Раньше здесь стояла надпись «На доске: Входящие»: она сообщала, где
     * карточка лежит, и не давала её сдвинуть. Перевести письмо на другой этап
     * можно было только через «▦ В доску» — а у писем, чья карточка на доске
     * ещё не заведена, и того не было. Теперь это те же кнопки, что на карточке
     * компании: ряд этапов, текущий выделен, нажатие переносит. Карточки нет —
     * первое нажатие её и заводит, поэтому переводить можно ЛЮБОЕ письмо.
     */
    async loadThreadPlacement(key) {
        const box = document.getElementById('threadPlacement');
        if (!box) return;
        try {
            const [d, t] = await Promise.all([
                this.api('boards.php?action=placement&thread_key=' + encodeURIComponent(key)),
                this.api('boards.php?action=targets'),
            ]);
            const columns = (t.items[0] || {}).columns || [];
            if (!columns.length) { box.innerHTML = ''; return; }
            const here = (d.items || [])[0];
            box.innerHTML = `<div class="card card--inline">
                <span class="muted">Этап:</span>
                ${columns.map(c => `<button class="btn btn--sm ${here && here.column_id == c.id ? 'btn--primary' : 'btn--outline'}"
                    style="border-color:${this.esc(c.color || '#ccc')}"
                    onclick="App.moveThreadCard('${this.jsStr(key)}', ${c.id})">${this.esc(c.title)}</button>`).join('')}
                ${here ? '' : '<span class="muted">карточки на доске ещё нет — нажатие её заведёт</span>'}
            </div>`;
        } catch { box.innerHTML = ''; }
    },

    /** Перенести переписку на этап; карточки нет — она заводится этим же нажатием. */
    async moveThreadCard(key, columnId) {
        try {
            await this.api('boards.php?action=card_add', {method: 'POST',
                body: {column_id: columnId, thread_key: key}});
            this.toast('Переписка переведена на этап', 'success');
            this.loadThreadPlacement(key);
        } catch (err) { this.toast(err.message, 'error'); }
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
            ${this.pageHead({
                title: this.esc(m.subject) || 'Без темы',
                actions: `<button class="btn btn--primary btn--sm" onclick="App.mailCompose(${m.id})">Ответить</button>`,
                menu: [
                    m.direction === 'in' && !m.archived_at ? {label: '🗄 В архив', onclick: `App.archiveMail(${m.id})`} : null,
                    m.archived_at ? {label: '↩ Вернуть в работу', onclick: `App.unarchiveMail(${m.id})`} : null,
                    m.direction === 'in' ? {label: '🚫 Спам', danger: true, onclick: `App.markSpam(${m.id})`} : null,
                    {label: '🗑 Удалить', danger: true, onclick: `App.deleteMail(${m.id}, 'mail')`},
                ],
            })}
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
                ${this.msgBodyHtml(m)}
                ${(m.attachments || []).length ? `
                    <div class="msg__files">
                        ${m.attachments.map(a => this.attachmentLink(a, 'mail.php')).join('')}
                    </div>` : ''}
            </div>
        `;
        this.mountBodies(document.getElementById('app'));
    },


    // ==== The board (modules 010 + 011) ====
    // Trello, without Trello: columns you rename, and companies you drag by
    // hand. Exactly one board, and it is the whole section — a card is a
    // COMPANY with all of its correspondence on it, so there is nothing else to
    // switch to. Every company that writes to us is already in «Входящие» when
    // the page opens; the manager only decides which column it moves on to.

    async pageMailBoard() {
        this.setMailView('board');
        document.getElementById('app').innerHTML = this.mailShellHtml('board', this.mailToolbarHtml('board'));
        // action=get syncs first: the intake is not a button somebody remembers
        // to press, it is what opening the board means
        const b = await this.api('boards.php?action=get');
        this.board = b;
        this.boardPicked = new Set();
        document.getElementById('mailBody').innerHTML = `
            ${this.boardFiltersHtml(b)}
            <div id="boardSupport"></div>
            <div id="boardBulk"></div>
            <div id="boardSearchOut"></div>
            <div class="board" id="board">
                ${b.columns.map(c => this.boardColumn(c)).join('')}
            </div>
        `;
        this.boardBindDnd();
        this.applyBoardFilters();
        this.loadBoardSupport();
        const added = (b.sync && (b.sync.created || b.sync.upgraded)) || 0;
        if (added) this.toast(`Новых карточек на доске: ${added}`, 'success');
    },

    /**
     * ==== «Письма» списком, как в Gmail (модуль 050) ====
     *
     * Те же карточки, что на доске, — одной лентой по дате последнего письма:
     * новое письмо поднимает свою строку наверх. Колонки доски — статусы, они
     * слева, со своим цветом и числом непрочитанных.
     */
    async pageMailList(colId = '') {
        this.setMailView('list');
        this.mailListCol = /^\d+$/.test(String(colId)) ? String(colId) : '';
        document.getElementById('app').innerHTML = this.mailShellHtml('list', this.mailToolbarHtml('list'));
        const b = await this.api('boards.php?action=get');
        this.board = b;
        this.boardPicked = new Set();
        document.getElementById('mailBody').innerHTML = `
            ${this.boardFiltersHtml(b, {list: true})}
            <div id="boardSupport"></div>
            <div id="boardSearchOut"></div>
            <div class="mailbox">
                <nav class="mailbox__side" id="mailSide" aria-label="Статусы писем"></nav>
                <section class="mailbox__main" aria-label="Письма">
                    <div class="mailbox__bar">
                        <input type="checkbox" class="mailbox__all" id="mailAll" aria-label="Отметить все видимые"
                               title="Отметить все видимые" onchange="App.boardPickAll(this.checked)">
                        <button class="btn btn--ghost btn--sm btn--icon" onclick="App.boardSync()"
                                aria-label="Забрать почту" title="Забрать почту">⟳</button>
                        <div id="boardBulk" class="mailbox__bulk"></div>
                        <span class="mailbox__count muted" data-list-shown></span>
                        ${this.hint('mail-keys')}
                    </div>
                    <ul class="glist" id="mailList"></ul>
                </section>
            </div>`;
        this.drawMailList();
        this.bindMailKeys();
        this.loadBoardSupport();
        const added = (b.sync && (b.sync.created || b.sync.upgraded)) || 0;
        if (added) this.toast(`Новых писем в списке: ${added}`, 'success');
    },

    /** Время строки: последнее письмо, у черновика — последняя правка. */
    mailRowAt(card) {
        return String(card.last_at || (card.draft && card.draft.updated_at) || '');
    },

    drawMailList() {
        const list = document.getElementById('mailList');
        if (!list || !this.board) return;
        const cols = this.board.columns || [];
        const colOf = new Map();
        const all = [];
        cols.forEach(c => (c.cards || []).forEach(card => { colOf.set(String(card.id), c); all.push(card); }));
        this.drawMailSide(cols);

        // Строка, поднявшаяся с прошлой отрисовки, на миг подсвечивается
        const prev = this.mailStamps;
        const risen = new Set();
        all.forEach(card => {
            const at = this.mailRowAt(card), was = prev && prev.get(String(card.id));
            if (prev && at && (was === undefined || at > was)) risen.add(String(card.id));
        });
        this.mailStamps = new Map(all.map(card => [String(card.id), this.mailRowAt(card)]));

        const rows = all
            .filter(card => !this.mailListCol || String(colOf.get(String(card.id)).id) === this.mailListCol)
            .sort((a, b) => {
                const x = this.mailRowAt(a), y = this.mailRowAt(b);
                return x === y ? b.id - a.id : (x < y ? 1 : -1);
            });
        list.innerHTML = rows.map(card =>
            this.mailListRow(card, colOf.get(String(card.id)), risen.has(String(card.id)))).join('');
        this.applyBoardFilters();
        if (this.boardQuery) this.boardFilter(this.boardQuery);
    },

    drawMailSide(cols) {
        const side = document.getElementById('mailSide');
        if (!side) return;
        const unread = cards => cards.filter(x => x.unread || x.unanswered).length;
        const allCards = cols.flatMap(c => c.cards || []);
        const item = (href, label, cards, color, on) => {
            const u = unread(cards);
            return `
                <a class="mside__item ${on ? 'mside__item--on' : ''}" href="${href}" ${on ? 'aria-current="page"' : ''}
                   ${color ? `style="--col:${this.esc(color)}"` : ''}>
                    <span class="mside__dot ${color ? '' : 'mside__dot--all'}" aria-hidden="true"></span>
                    <span class="mside__name">${this.esc(label)}</span>
                    <span class="mside__n ${u ? 'mside__n--unread' : ''}"
                          title="${u ? 'новых и неотвеченных' : 'всего'}">${u || cards.length || ''}<span class="sr-only">${u ? ' новых и неотвеченных' : ' всего'}</span></span>
                </a>`;
        };
        side.innerHTML = `
            ${item('#mail/list', 'Все письма', allCards, '', !this.mailListCol)}
            <div class="mside__head">Статусы</div>
            ${cols.map(c => item('#mail/list/' + c.id, c.title, c.cards || [], c.color || '#8a8f98',
                                 this.mailListCol === String(c.id))).join('')}
            <div class="mside__sep" role="separator"></div>
            <a class="mside__item" href="#mail" onclick="App.setBoardFilter('archived', 1); return false;">
                <span class="mside__ico" aria-hidden="true">🗄</span><span class="mside__name">Архив</span></a>
            <a class="mside__item" href="#mail/trash">
                <span class="mside__ico" aria-hidden="true">🗑</span><span class="mside__name">Корзина</span></a>`;
    },

    mailListRow(card, col, risen) {
        const c = card.company || {};
        const t = card.thread || {};
        const d = card.draft || null;
        const id = card.id;
        const href = card.counterparty_id
            ? `#mail/company/${card.counterparty_id}`
            : (card.thread_key ? `#mail/t/${encodeURIComponent(card.thread_key)}` : '');
        const subject = (d && d.subject) || c.subject || t.subject || '';
        const preview = (d && d.preview) || c.preview || '';
        const letters = c.letters || t.count || 0;
        const at = this.mailRowAt(card);
        const cls = ['grow'];
        // Жирным — новое и неотвеченное, как на доске (модуль 051)
        if (card.unread || card.unanswered) cls.push('grow--unread');
        if (risen) cls.push('grow--risen');
        const tags = [
            `<span class="stag" style="--col:${this.esc(col.color || '#8a8f98')}">${this.esc(col.title)}</span>`,
            card.attention ? '<span class="grow__draft">письмо готово</span>' : (d ? '<span class="grow__draft">Черновик</span>' : ''),
            c.proposal_status ? this.proposalBadge(c.proposal_status) : '',
            card.kind === 'thread' ? '<span class="chip chip--new" title="Отправитель ещё не привязан к компании">новый адрес</span>' : '',
        ].join('');
        const inner = `
            <span class="grow__from">${this.esc(card.title || 'Без названия')}${letters > 1 ? `<span class="grow__n">${letters}</span>` : ''}</span>
            <span class="grow__body">
                <span class="grow__tags">${tags}</span>
                <span class="grow__subj">${this.esc(subject) || (col.kind === 'closed' ? '' : '(без темы)')}</span>${preview
                    ? `<span class="grow__prev"> — ${this.esc(preview)}</span>` : ''}
                ${card.note ? `<span class="grow__note">✎ ${this.esc(card.note)}</span>` : ''}
            </span>
            ${c.has_attachment ? '<span class="grow__clip" title="Есть вложения">📎<span class="sr-only">Есть вложения.</span></span>' : ''}
            <time class="grow__date" ${at ? `datetime="${this.esc(at.replace(' ', 'T'))}" title="${this.esc(this.fmtDate(at))}"` : ''}>${this.fmtShort(at)}</time>`;
        return `
            <li class="${cls.join(' ')}" data-card="${id}" style="--col:${this.esc(col.color || '#8a8f98')}">
                <input type="checkbox" class="bcard__pick" aria-label="Отметить: ${this.esc(card.title)}"
                       onchange="App.boardCardPick(${id}, this.checked)">
                <span class="grow__wait">${card.unanswered
                    ? '<span class="wait-dot" title="Ждёт ответа"><span class="sr-only">Ждёт ответа.</span></span>' : ''}</span>
                ${href ? `<a class="grow__link" href="${href}">${inner}</a>` : `<span class="grow__link">${inner}</span>`}
                <span class="grow__acts">
                    ${card.unread || card.unanswered ? `<button type="button" class="grow__act" title="Прочитано (Shift+I)"
                        aria-label="Отметить прочитанным" onclick="App.boardBulk('read', {}, ${id})">✓</button>` : ''}
                    ${card.kind === 'company' || card.kind === 'thread' ? `<button type="button" class="grow__act" title="В архив (e)"
                        aria-label="В архив" onclick="App.boardBulk('archive', {}, ${id})">🗄</button>` : ''}
                    <button type="button" class="grow__act" title="Убрать из списка и с доски"
                        aria-label="Убрать с доски" onclick="App.boardBulk('remove', {}, ${id})">✕</button>
                </span>
            </li>`;
    },

    /** Счётчик строк и пустое состояние — после каждого фильтра и поиска. */
    mailListShown() {
        const list = document.getElementById('mailList');
        if (!list) return;
        const rows = [...list.querySelectorAll('[data-card]')];
        const shown = rows.filter(el => !el.hidden).length;
        const out = document.querySelector('[data-list-shown]');
        if (out) out.textContent = shown === rows.length ? `${rows.length} писем` : `${shown} из ${rows.length}`;
        const all = document.getElementById('mailAll');
        if (all) all.checked = shown > 0 && rows.every(el => el.hidden || (this.boardPicked || new Set()).has(el.dataset.card));
        let empty = list.querySelector('.glist__empty');
        if (shown) { if (empty) empty.remove(); return; }
        const narrowed = rows.length || this.boardQuery || this.mailListCol
            || Object.entries(this.boardFilters || {}).some(([k, v]) => k !== 'archived' && v);
        if (!empty) { empty = document.createElement('li'); empty.className = 'glist__empty'; list.appendChild(empty); }
        empty.innerHTML = narrowed
            ? `<p>Под выбранные статус, фильтры и поиск писем нет.</p>
               <button class="btn btn--outline btn--sm" onclick="App.mailListReset()">Показать все письма</button>`
            : `<p>Писем пока нет. Новые появятся здесь сами — или заберите почту сейчас.</p>
               <button class="btn btn--primary btn--sm" onclick="App.boardSync()">⟳ Забрать почту</button>`;
    },

    mailListReset() {
        this.boardFilters = {period: '', state: '', mailbox: '', archived: 0};
        this.boardQuery = '';
        if (location.hash === '#mail/list') this.route(); else location.hash = 'mail/list';
    },

    /**
     * Клавиши списка, как в Gmail: j/k — вниз/вверх, o или Enter — открыть,
     * x — отметить, e — в архив, Shift+I — прочитано, / — поиск.
     */
    bindMailKeys() {
        if (this._mailKeys) return;
        this._mailKeys = true;
        document.addEventListener('keydown', e => {
            const list = document.getElementById('mailList');
            if (!list || e.ctrlKey || e.metaKey || e.altKey) return;
            const el = e.target;
            if (el.closest && el.closest('input, textarea, select, [contenteditable="true"], .modal, details.menu[open]')) return;
            if (document.getElementById('modal')) return;
            if (e.key === '/') {
                e.preventDefault();
                document.getElementById('boardFilter')?.focus();
                return;
            }
            const rows = [...list.querySelectorAll('[data-card]:not([hidden])')];
            if (!rows.length) return;
            const cur = el.closest ? el.closest('#mailList [data-card]') : null;
            const i = rows.indexOf(cur);
            const go = r => {
                if (!r) return;
                (r.querySelector('.grow__link[href]') || r.querySelector('.bcard__pick')).focus();
                r.scrollIntoView({block: 'nearest'});
            };
            const id = cur && cur.dataset.card;
            switch (e.key) {
                case 'j': e.preventDefault(); go(rows[i + 1] || rows[i < 0 ? 0 : i]); break;
                case 'k': e.preventDefault(); go(rows[i - 1] || rows[0]); break;
                case 'o': if (cur) { e.preventDefault(); cur.querySelector('.grow__link[href]')?.click(); } break;
                case 'x': if (cur) { e.preventDefault(); cur.querySelector('.bcard__pick')?.click(); } break;
                case 'e': if (id) { e.preventDefault(); this.boardBulk('archive', {}, id); } break;
                case 'I': if (id) { e.preventDefault(); this.boardBulk('read', {}, id); } break;
            }
        });
    },

    /**
     * ==== Корзина писем (модуль 040) ====
     *
     * Удалить можно любое письмо, и любое можно вернуть: до сих пор удаление
     * было окончательным, и «удалить» приходилось выбирать как приговор.
     * Здесь письмо лежит целиком, со своими файлами, пока корзину не очистят.
     */
    async pageMailTrash() {
        document.getElementById('app').innerHTML = this.mailShellHtml('trash', `
            <button class="btn btn--outline btn--sm btn--danger" onclick="App.purgeTrash()">
                Очистить корзину</button>`);
        const box = document.getElementById('mailBody');
        box.innerHTML = '<div class="loading">Загрузка корзины...</div>';
        try {
            const d = await this.api('mail.php?action=trash');
            const items = d.items || [];
            box.innerHTML = `
                <div class="card card--flush">
                    <div class="mlist">
                        ${items.map(t => `
                            <div class="mrow">
                                <div class="mrow__main">
                                    <div class="mrow__subject">${this.esc(t.subject) || '<em>без темы</em>'}</div>
                                    <div class="mrow__meta">${this.esc(t.direction === 'in'
                                        ? 'от ' + (t.from_email || '') : 'кому ' + (t.to_emails || ''))}
                                        · удалено ${this.fmtDate(t.deleted_at)}
                                        ${t.deleted_by_name ? '· ' + this.esc(t.deleted_by_name) : ''}</div>
                                </div>
                                <div class="mrow__date">${this.fmtDate(t.date_at)}</div>
                                <div class="flex" style="gap:6px">
                                    <button class="btn btn--outline btn--sm"
                                            onclick="App.restoreFromTrash(${t.id})">↩ Вернуть</button>
                                    <button class="btn btn--outline btn--sm btn--danger"
                                            onclick="App.purgeTrash(${t.id})">🗑 Насовсем</button>
                                </div>
                            </div>`).join('')}
                        ${items.length ? '' : '<div class="mlist__empty">Корзина пуста</div>'}
                    </div>
                </div>`;
        } catch (err) {
            box.innerHTML = `<p class="no">${this.esc(err.message)}</p>`;
        }
    },

    async restoreFromTrash(id) {
        try {
            const r = await this.api('mail.php?action=trash_restore', {method: 'POST', body: {id}});
            this.toast('Письмо вернулось в почту', 'success');
            if (r.thread_key) location.hash = 'mail/t/' + encodeURIComponent(r.thread_key);
            else this.pageMailTrash();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    /** Без id — вся корзина. Вот это уже насовсем, вместе с файлами. */
    async purgeTrash(id = 0) {
        const what = id ? 'Удалить это письмо насовсем? Вернуть его будет нельзя.'
                        : 'Очистить корзину? Все письма в ней удалятся насовсем, вместе с файлами.';
        if (!confirm(what)) return;
        try {
            const r = await this.api('mail.php?action=trash_purge', {method: 'POST', body: id ? {id} : {}});
            this.toast(`Удалено насовсем: ${r.purged}`, 'success');
            this.pageMailTrash();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    /**
     * Архив — та же страница «Письма», а не отдельный экран (по просьбе).
     *
     * Отдельная страница «Архив писем» показывала ВСЕ письма подряд, плоским
     * списком, со своим поиском и своими фильтрами — то есть второй почтовый
     * клиент рядом с доской. Здесь архив — это ссылка на доске: переписки,
     * убранные как «не наш профиль», с теми же групповыми действиями.
     */
    async pageBoardArchive() {
        document.getElementById('app').innerHTML = this.mailShellHtml('board', this.mailToolbarHtml('archive'));
        const b = this.board || await this.api('boards.php?action=get&sync=0');
        this.board = b;
        this.archivePicked = new Set();
        document.getElementById('mailBody').innerHTML = `
            ${this.boardFiltersHtml(b)}
            <div id="archiveBulk"></div>
            <div id="archiveList"><div class="loading">Загрузка архива...</div></div>`;
        this.loadArchiveList();
    },

    async loadArchiveList() {
        const box = document.getElementById('archiveList');
        if (!box) return;
        const f = this.boardFilters || {};
        const qs = new URLSearchParams({
            archived: 1, limit: 100,
            ...(f.mailbox ? {mailbox_id: f.mailbox} : {}),
            ...(this.boardQuery ? {q: this.boardQuery} : {}),
        });
        try {
            const d = await this.api('mail.php?action=threads&' + qs);
            const items = (d.items || []).filter(t => this.archiveRowMatches(t));
            box.innerHTML = `
                <div class="card card--flush">
                    <div class="mlist">
                        ${items.map(t => `
                            <div class="mrow mrow--answered" data-arch="${this.esc(t.thread_key)}">
                                <input type="checkbox" class="bcard__pick" title="Отметить"
                                       onchange="App.archivePick('${this.jsStr(t.thread_key)}', this.checked)">
                                <div class="mrow__main" onclick="location.hash='mail/t/${encodeURIComponent(t.thread_key)}'">
                                    <div class="mrow__subject">${this.esc(t.subject) || '<em>без темы</em>'}
                                        ${t.count > 1 ? `<span class="mrow__count">${t.count}</span>` : ''}</div>
                                    <div class="mrow__meta">${this.esc((t.participants || []).join(', '))}
                                        ${t.archived_reason === 'mailbox_off'
                                            ? '<span class="chip">ящик отключён</span>'
                                            : '<span class="chip">не наш профиль</span>'}</div>
                                    <div class="mrow__preview">${this.esc(t.preview)}</div>
                                </div>
                                <div class="mrow__date">${this.fmtDate(t.last_at)}</div>
                            </div>`).join('')}
                        ${items.length ? '' : '<div class="mlist__empty">В архиве пусто</div>'}
                    </div>
                </div>`;
            this.archiveSyncPicks();
        } catch (err) {
            box.innerHTML = `<p class="no">${this.esc(err.message)}</p>`;
        }
    },

    /** Период и состояние действуют и в архиве — фильтры одни на весь экран. */
    archiveRowMatches(t) {
        const f = this.boardFilters || {};
        if (f.period) {
            const last = t.last_at ? Date.parse(String(t.last_at).replace(' ', 'T')) : 0;
            if (!last || Date.now() - last > Number(f.period) * 86400000) return false;
        }
        if (f.state === 'unread' && !t.unread) return false;
        if (f.state === 'unanswered' && t.last_direction !== 'in') return false;
        if (f.state === 'answered' && t.last_direction === 'in') return false;
        return true;
    },

    archiveSearch(q) {
        this.boardQuery = q.trim();
        clearTimeout(this._archTimer);
        this._archTimer = setTimeout(() => this.loadArchiveList(), 350);
    },

    archiveSearchClear() {
        this.boardQuery = '';
        const input = document.getElementById('boardFilter');
        if (input) { input.value = ''; input.focus(); }
        this.loadArchiveList();
    },

    archivePick(key, on) {
        this.archivePicked = this.archivePicked || new Set();
        if (on) this.archivePicked.add(key); else this.archivePicked.delete(key);
        this.archiveSyncPicks();
    },

    archivePickAll(on) {
        this.archivePicked = this.archivePicked || new Set();
        document.querySelectorAll('[data-arch]').forEach(el => {
            const key = el.dataset.arch;
            if (on) this.archivePicked.add(key); else this.archivePicked.delete(key);
            const box = el.querySelector('.bcard__pick');
            if (box) box.checked = on;
        });
        this.archiveSyncPicks();
    },

    archiveSyncPicks() {
        const picked = this.archivePicked = this.archivePicked || new Set();
        document.querySelectorAll('[data-arch]').forEach(el => {
            const on = picked.has(el.dataset.arch);
            const box = el.querySelector('.bcard__pick');
            if (box) box.checked = on;
            el.classList.toggle('bcard--picked', on);
        });
        const bar = document.getElementById('archiveBulk');
        if (!bar) return;
        bar.innerHTML = picked.size ? `
            <div class="card card--inline bulkbar">
                <strong>Отмечено: ${picked.size}</strong>
                <button class="btn btn--outline btn--sm" onclick="App.archiveBulk('unarchive')">↩ Вернуть в работу</button>
                <button class="btn btn--outline btn--sm" onclick="App.archiveBulk('read')">✓ Прочитано</button>
                <button class="btn btn--outline btn--sm btn--danger" onclick="App.archiveBulk('spam')">🚫 Спам</button>
                <button class="btn btn--outline btn--sm btn--danger" onclick="App.archiveBulk('delete')">🗑 Удалить</button>
                <button class="btn btn--outline btn--sm" onclick="App.archivePickAll(false)">Снять отметки</button>
            </div>` : '';
    },

    async archiveBulk(op) {
        const keys = [...(this.archivePicked || [])];
        if (!keys.length) return;
        const ask = {
            delete: `Удалить переписок: ${keys.length}? Письма уйдут в «Корзину» на почтовом сервере.`,
            spam:   `Отметить спамом входящие письма ${keys.length} переписок?`,
        }[op];
        if (ask && !confirm(ask)) return;
        try {
            const r = await this.api('mail.php?action=bulk_threads', {method: 'POST', body: {keys, op}});
            this.toast(r.failed ? `Сделано: ${r.done}, не вышло: ${r.failed}` : `Готово: ${r.done}`,
                       r.failed ? 'error' : 'success');
        } catch (err) { this.toast(err.message, 'error'); }
        this.archivePicked = new Set();
        this.loadArchiveList();
    },

    /**
     * Обращения менеджеров на доске «Письма» (issue #60) — только у
     * администратора и только те, что ждут ревью. Пусто — полосы нет.
     */
    async loadBoardSupport() {
        const box = document.getElementById('boardSupport');
        if (!box || !(this.manager && this.manager.is_admin)) return;
        try {
            const d = await this.api('support.php?action=list&status=new');
            const items = d.items || [];
            if (!items.length || !box.isConnected) { box.innerHTML = ''; return; }
            const kinds = d.kinds || {};
            box.innerHTML = `
                <div class="card card--inline board-support">
                    <strong>🛟 Обращения менеджеров на ревью: ${items.length}</strong>
                    ${items.slice(0, 5).map(t => `
                        <a class="board-support__row" href="#settings/support">
                            <span class="chip">${this.esc(kinds[t.kind] || t.kind)}</span>
                            ${this.esc(t.title)}
                            <span class="muted">· ${this.esc(t.manager_name || '')} · ${this.fmtDate(t.created_at)}</span>
                        </a>`).join('')}
                    ${items.length > 5 ? `<a href="#settings/support">и ещё ${items.length - 5} →</a>` : ''}
                </div>`;
        } catch { box.innerHTML = ''; }
    },

    /**
     * Фильтры доски — те, что есть в любом почтовом клиенте (по просьбе).
     *
     * Ими отбирают КАРТОЧКИ, уже стоящие в колонках, — доска не превращается в
     * список: колонки остаются на месте, в них остаётся то, что подошло, и
     * счётчик в шапке колонки говорит, сколько это из скольких.
     */
    boardFiltersHtml(b, {list = false} = {}) {
        const f = this.boardFilters = this.boardFilters
            || {period: '', state: '', mailbox: '', archived: 0};
        const sel = (name, value, options, title) => `
            <label class="bfilter" title="${this.esc(title)}">
                <span>${this.esc(name)}</span>
                <select onchange="App.setBoardFilter('${value}', this.value)">
                    ${options.map(([v, label]) => `<option value="${v}" ${String(f[value]) === String(v) ? 'selected' : ''}>${label}</option>`).join('')}
                </select>
            </label>`;
        // На телефоне фильтры сложены: развёрнутые, они съедали четверть экрана
        // ещё до первой карточки. На десктопе места хватает — стоят открытыми.
        const on = [f.period, f.state, f.mailbox].filter(Boolean).length;
        // В списке фильтры сложены, пока ни один не выбран: над строками писем
        // их держит поиск, как в Gmail (модуль 050)
        const open = (list ? on > 0 : window.innerWidth > 640) ? ' open' : '';
        return `
            <details class="card card--inline bfilters"${open}>
                <summary class="bfilters__summary">Фильтры${on ? ` <span class="pill">${on}</span>` : ''}</summary>
                ${sel('Период', 'period', [['', 'за всё время'], ['1', 'сегодня'], ['7', '7 дней'],
                                           ['30', '30 дней'], ['90', '90 дней']],
                      'По дате последнего письма компании')}
                ${sel('Состояние', 'state', [['', 'любое'], ['unanswered', 'ждут ответа'],
                                             ['answered', 'отвечены'], ['unread', 'есть непрочитанные'],
                                             ['kp', 'с КП'], ['nokp', 'без КП'], ['draft', 'черновики']],
                      'Что сейчас с карточкой')}
                ${sel('Ящик', 'mailbox', [['', 'все ящики'],
                        ...(b.mailboxes || []).map(m => [String(m.id), this.esc(m.name)])],
                      'Компании, писавшие в этот ящик')}
                <!-- «Архив» — в меню «Ещё» и в списке статусов (модуль 050): в ряду
                     фильтров он читался как ещё один фильтр -->
                <span class="flex flex--wrap" style="margin-left:auto">
                    ${list ? '' : `<button class="btn btn--outline btn--sm"
                            onclick="App.${f.archived ? 'archivePickAll' : 'boardPickAll'}(true)">Выбрать все</button>
                    <button class="btn btn--outline btn--sm"
                            onclick="App.${f.archived ? 'archivePickAll' : 'boardPickAll'}(false)">Снять</button>`}
                    <button class="btn btn--outline btn--sm" onclick="App.resetBoardFilters()">Сбросить фильтры</button>
                </span>
                ${f.archived ? '' : this.boardPickByStateHtml()}
                <span class="muted" data-board-shown></span>
            </details>`;
    },

    setBoardFilter(key, value) {
        this.boardFilters = Object.assign(this.boardFilters || {}, {[key]: value});
        // «Показать архив» — это другой вид экрана; остальное только прячет карточки
        if (key === 'archived') { this.boardQuery = ''; this.route(); return; }
        if (this.boardFilters.archived) { this.loadArchiveList(); return; }
        this.applyBoardFilters();
    },

    resetBoardFilters() {
        this.boardFilters = {period: '', state: '', mailbox: '', archived: 0};
        this.boardQuery = '';
        this.route();
    },

    /** Подходит ли карточка под текущие фильтры. */
    boardCardMatches(card) {
        const f = this.boardFilters || {};
        const c = card.company || {};

        if (f.period) {
            const days = Number(f.period);
            const last = card.last_at ? Date.parse(String(card.last_at).replace(' ', 'T')) : 0;
            if (!last || Date.now() - last > days * 86400000) return false;
        }
        // Состояние разбирается в одном месте — им же пользуется «Отметить по
        // статусу»: два разбора одних и тех же слов разошлись бы (модуль 036)
        if (f.state && !this.cardInState(card, f.state)) return false;
        if (f.mailbox) {
            const boxes = (c.mailbox_ids || []).map(String);
            if (!boxes.includes(String(f.mailbox))) return false;
        }
        return true;
    },

    /** Показать те карточки, что подошли, и пересчитать шапки колонок. */
    applyBoardFilters() {
        const byId = new Map();
        (this.board && this.board.columns || []).forEach(col =>
            (col.cards || []).forEach(card => byId.set(String(card.id), card)));

        let shown = 0, total = 0;
        document.querySelectorAll('[data-card]').forEach(el => {
            const card = byId.get(el.dataset.card);
            total++;
            const ok = !card || this.boardCardMatches(card);
            el.hidden = !ok;
            // Скрытая карточка не может оставаться отмеченной: групповое
            // действие должно касаться ровно того, что человек видит
            if (!ok && this.boardPicked) this.boardPicked.delete(el.dataset.card);
            if (ok) shown++;
        });
        this.boardRecount();
        this.boardSyncPicks();
        const out = document.querySelector('[data-board-shown]');
        if (out) out.textContent = shown === total ? `карточек: ${total}` : `показано ${shown} из ${total}`;
        this.mailListShown();
    },

    // Fetch mail from the servers, then pull whatever arrived onto the board
    async boardSync() {
        this.toast('Синхронизация почты...', 'info');
        try {
            const r = await this.api('mail.php?action=sync', {method: 'POST', body: {}});
            const report = r.report || [];
            // Ошибка папки отправленных не отменяет разбор входящих — но и
            // молчать о ней нельзя: письма с телефона так и не подтянутся
            const errors = report.filter(x => x.error || x.sent_error)
                .map(e => `${e.name}: ${e.error || 'Отправленные — ' + e.sent_error}`);
            if (errors.length) this.toast(errors.join('; '), 'error');
            else this.toast(this.syncSummary(report), 'success');
        } catch (err) { this.toast(err.message, 'error'); }
        // Возвращаемся в тот вид, из которого нажали: доска или архив
        this.route();
    },

    /** Что забрала синхронизация — словами: входящие и отправленные мимо сервиса. */
    syncSummary(report) {
        const sum = (f) => (report || []).reduce((n, x) => n + Number(x[f] || 0), 0);
        const inbound = sum('in'), outbound = sum('out');
        if (!inbound && !outbound) return 'Новых писем нет';
        const parts = [];
        if (inbound) parts.push(`входящих: ${inbound}`);
        if (outbound) parts.push(`отправленных: ${outbound}`);
        return 'Забрано — ' + parts.join(', ');
    },

    // Client-side filter over the cards already on the page — every company on
    // this board, not just the one column being looked at
    boardFilter(q) {
        q = q.trim();
        this.boardQuery = q;
        const low = q.toLowerCase();
        let shown = 0;
        document.querySelectorAll('[data-card]').forEach(card => {
            // Поиск сужает то, что уже отобрали фильтры, а не отменяет их
            const hit = (!q || card.textContent.toLowerCase().includes(low))
                && this.boardCardMatchesEl(card);
            card.hidden = !hit;
            if (hit && q) shown++;
        });
        this.boardRecount();
        this.mailListShown();

        // Заголовок карточки — это тема и компания, и только. Письмо ищут по
        // адресу, по фамилии, по имени файла — по всему, что в нём есть, и
        // это умеет только сервер (модуль 023).
        clearTimeout(this._boardSearchTimer);
        const out = document.getElementById('boardSearchOut');
        if (out && q.length < 2) { out.innerHTML = ''; return; }
        this._boardSearchTimer = setTimeout(() => this.boardSearchServer(q, shown), 350);
    },

    /** Очистить поиск: поле и выдача пустеют вместе. */
    boardFilterClear() {
        this.boardQuery = '';
        const input = document.getElementById('boardFilter');
        if (input) { input.value = ''; input.focus(); }
        const out = document.getElementById('boardSearchOut');
        if (out) out.innerHTML = '';
        clearTimeout(this._boardSearchTimer);
        this.applyBoardFilters();
    },

    /** Тот же разбор фильтров, но по узлу карточки. */
    boardCardMatchesEl(el) {
        const id = el.dataset.card;
        const cols = (this.board && this.board.columns) || [];
        for (const col of cols) {
            for (const card of (col.cards || [])) {
                if (String(card.id) === id) return this.boardCardMatches(card);
            }
        }
        return true;
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
        // Колонка — это статус, и у статуса свой цвет: им окрашена шапка и край
        // каждой карточки колонки (модуль 050)
        const kinds = {
            inbox:    ['авто', 'Сюда сами падают новые письма'],
            work:     ['черновики', 'Сюда сама встаёт карточка письма, которое пишут'],
            closed:   ['закрыто', 'Переписка карточек этой колонки не читается — только счётчики'],
            assembly: ['оплачено', 'Сюда сама встаёт карточка, чей заказ оплачен (Т-Банк или платёж в МойСклад)'],
        };
        const kind = kinds[c.kind];
        return `
            <div class="bcol" data-col="${c.id}" style="--col:${this.esc(c.color || '#8a8f98')}">
                <div class="bcol__head">
                    <span class="bcol__dot" aria-hidden="true"></span>
                    <span class="bcol__title" title="Переименовать колонку"
                          onclick="App.boardRenameColumn(${c.id}, '${this.jsStr(c.title)}')">${this.esc(c.title)}</span>
                    ${kind ? `<span class="bcol__kind" title="${this.esc(kind[1])}">${kind[0]}</span>` : ''}
                    <span class="bcol__count" title="Показано карточек в колонке${c.card_limit ? ' (лимит ' + c.card_limit + ')' : ''}">${c.cards.length}</span>
                    ${this.menuHtml([
                        {label: 'Переименовать', onclick: `App.boardRenameColumn(${c.id}, '${this.jsStr(c.title)}')`},
                        {label: 'Цвет статуса…', onclick: `App.boardColumnColor(${c.id}, '${this.jsStr(c.color || '')}')`},
                        {label: `Лимит карточек${c.card_limit ? ': ' + c.card_limit : ''}…`,
                         onclick: `App.boardSetColumnLimit(${c.id}, ${c.card_limit || 0})`},
                        {label: 'Удалить колонку', onclick: `App.boardDeleteColumn(${c.id})`, danger: true},
                    ], {aria: 'Действия с колонкой «' + c.title + '»'})}
                </div>
                ${c.kind === 'inbox' ? `
                    <a class="bcol__new" href="#mail/new"
                       title="Запрос принесли не почтой: мессенджер, звонок, файл">+ Запрос не из почты</a>` : ''}
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
        // Готово письмо «заказ отправлен» — карточка жирная, пока его не отправят (модуль 047)
        if (card.attention) cls.push('bcard--attention');
        const href = card.counterparty_id
            ? `#mail/company/${card.counterparty_id}`
            : (card.thread_key ? `#mail/t/${encodeURIComponent(card.thread_key)}` : '');

        const d = card.draft || null;
        const letters = c.letters || t.count || 0;
        // Тема и начало текста — из черновика, если письмо ещё пишут: карточка
        // говорит, кому и о чём, ещё до отправки (модуль 033)
        const subject = (d && (d.subject || d.preview)) || c.subject || t.subject || '';
        const kp = c.proposal_status ? this.proposalBadge(c.proposal_status) : '';
        return `
            <div class="${cls.join(' ')}" draggable="true" data-card="${card.id}">
                <div class="bcard__title">
                    <input type="checkbox" class="bcard__pick" title="Отметить для группового действия"
                           onclick="event.stopPropagation()" onchange="App.boardCardPick(${card.id}, this.checked)">
                    ${card.unanswered ? '<span class="wait-dot" title="Ждёт ответа"><span class="sr-only">Ждёт ответа.</span></span>' : ''}
                    ${href ? `<a href="${href}">${this.esc(card.title)}</a>` : this.esc(card.title)}
                    ${card.unread ? `<span class="pill pill--danger" title="непрочитанных писем">${card.unread}</span>` : ''}
                </div>
                ${subject ? `<div class="bcard__subject">${d ? '✎ ' : ''}${this.esc(subject)}</div>` : ''}
                ${card.note ? `<div class="bcard__note">${this.esc(card.note)}</div>` : ''}
                <div class="bcard__meta">
                    ${card.attention ? `<span class="chip chip--ship" title="Склад вписал трек-номер: письмо клиенту готово, осталось отправить">отправлен: письмо готово</span>`
                        : (d ? `<span class="chip chip--work" title="Письмо пишут: ${this.esc(d.to || '')}">черновик</span>` : '')}
                    ${letters ? `<span class="chip">писем ${letters}</span>` : ''}
                    ${c.requests_open ? `<span class="chip chip--work">запросов ${c.requests_open}</span>` : ''}
                    ${kp}
                    ${card.kind === 'thread' ? '<span class="chip chip--new" title="Отправитель ещё не привязан к компании">новый адрес</span>' : ''}
                    ${card.last_at || (d && d.updated_at)
                        ? `<span class="muted">${this.fmtDate(card.last_at || d.updated_at, false)}</span>` : ''}
                </div>
                <div class="bcard__actions">
                    ${href ? `<a href="${href}">открыть</a>` : ''}
                    <a onclick="App.boardCardNote(${card.id}, '${this.jsStr(card.note || '')}')">заметка</a>
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
     *
     * ==== Переезжает ГРУППА, и переезжает по-настоящему (модуль 036) ====
     *
     * Перетаскивание знало ровно одну карточку — ту, за которую тянули. Отметив
     * галочками пять и потащив одну, менеджер видел на экране переехавшую
     * группу, а база получала одну строку: обновление страницы возвращало
     * остальные на место, «как будто я ничего не меняла». Поэтому здесь:
     *
     *   — тянут отмеченную карточку — едут ВСЕ отмеченные, своим порядком;
     *   — экран перерисовывается ДОСКОЙ ИЗ ОТВЕТА сервера, а не тем, что
     *     нарисовало перетаскивание: показанное и сохранённое — одно и то же;
     *   — перетаскивание, которое ничем не кончилось (отпустили над шапкой
     *     колонки, мимо доски, нажали Esc), возвращает экран как был. Раньше
     *     карточки так и оставались лежать на новом месте — до перезагрузки.
     */
    boardBindDnd() {
        const board = document.getElementById('board');
        if (!board) return;
        let dragged = null;
        let group = [];
        let dropped = false;

        board.addEventListener('dragstart', e => {
            const card = e.target.closest('.bcard');
            if (!card) return;
            dragged = card;
            dropped = false;
            // Тянут отмеченную — едет вся отметка; тянут неотмеченную — она одна
            const picked = this.boardPicked || new Set();
            group = picked.has(card.dataset.card)
                ? [...board.querySelectorAll('.bcard')].filter(el => picked.has(el.dataset.card))
                : [card];
            group.forEach(el => el.classList.add('bcard--dragging'));
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.dataset.card);
        });

        board.addEventListener('dragend', () => {
            group.forEach(el => el.classList.remove('bcard--dragging'));
            board.querySelectorAll('.bcol__cards--over').forEach(el => el.classList.remove('bcol__cards--over'));
            // Бросок не состоялся — экран врёт о переезде, которого не было
            if (dragged && !dropped) this.boardRedraw();
            dragged = null;
            group = [];
        });

        board.addEventListener('dragover', e => {
            const drop = e.target.closest('[data-drop]');
            if (!drop || !dragged) return;
            e.preventDefault();
            drop.classList.add('bcol__cards--over');

            // Insert before the first card whose middle is below the cursor
            const after = [...drop.querySelectorAll('.bcard:not(.bcard--dragging)')]
                .find(el => e.clientY < el.getBoundingClientRect().top + el.offsetHeight / 2);
            // Группа встаёт целиком и своим порядком — перед той же карточкой
            group.forEach(el => after ? drop.insertBefore(el, after) : drop.appendChild(el));
        });

        board.addEventListener('dragleave', e => {
            const drop = e.target.closest('[data-drop]');
            if (drop && !drop.contains(e.relatedTarget)) drop.classList.remove('bcol__cards--over');
        });

        board.addEventListener('drop', async e => {
            const drop = e.target.closest('[data-drop]');
            if (!drop || !dragged) return;
            e.preventDefault();
            dropped = true;
            drop.classList.remove('bcol__cards--over');

            const all = [...drop.querySelectorAll('.bcard')];
            const ids = group.map(el => Number(el.dataset.card));
            const columnId = Number(drop.dataset.drop);
            const position = Math.max(0, all.indexOf(group[0]));
            try {
                const r = await this.api('boards.php?action=card_move',
                                         {method: 'POST', body: {ids, column_id: columnId, position}});
                // Экран — это то, что лежит в базе, а не то, что нарисовала мышь
                this.boardRedraw(r.board);
                if (ids.length > 1) this.toast(`Перенесено карточек: ${ids.length}`, 'success');
            } catch (err) {
                this.toast(err.message, 'error');
                this.boardRedraw();   // the server said no — show what it really holds
            }
        });
    },

    /**
     * Перерисовать колонки доски — из ответа сервера или из того, что уже
     * загружено (модуль 036).
     *
     * Фильтры, поиск и отметки при этом остаются: перерисовка — это способ
     * показать правду, а не повод сбросить человеку его работу.
     */
    boardRedraw(board) {
        if (board) this.board = board;
        if (document.getElementById('mailList')) { this.drawMailList(); return; }
        const box = document.getElementById('board');
        if (!box || !this.board) return;
        box.innerHTML = (this.board.columns || []).map(c => this.boardColumn(c)).join('');
        this.applyBoardFilters();
        if (this.boardQuery) this.boardFilter(this.boardQuery);
    },

    /**
     * ==== Групповые действия на доске ====
     *
     * Сорок писем разбирают не по одному: отмеченным карточкам говорят одно и
     * то же — прочитано, в архив, спам, в колонку, с доски. Панель действий
     * появляется только когда есть что отмечено, и всё время говорит, сколько
     * карточек под ней, — «применить к 12» должно быть видно ДО нажатия.
     */
    boardCardPick(cardId, on) {
        this.boardPicked = this.boardPicked || new Set();
        if (on) this.boardPicked.add(String(cardId)); else this.boardPicked.delete(String(cardId));
        this.boardSyncPicks();
    },

    /**
     * ==== Отметить по статусу (модуль 036) ====
     *
     * «Выбрать все» — это все сорок карточек, а разбирают их не все сразу:
     * сегодня переносят те, что ждут ответа, завтра — те, у которых уже есть
     * КП. Фильтр для этого не годится: он ПРЯЧЕТ остальные, а отметить надо,
     * продолжая видеть доску целиком.
     *
     * Отмечается всегда то, что на экране: скрытую фильтром карточку групповое
     * действие тронуть не может, и отметить её отсюда нельзя тоже.
     */
    boardPickByStateHtml() {
        const states = [
            ['unanswered', 'ждут ответа'], ['answered', 'отвечены'], ['unread', 'непрочитанные'],
            ['kp', 'с КП'], ['nokp', 'без КП'], ['draft', 'черновики'],
        ];
        return `
            <span class="bfilter bfilter--pick" title="Отметить галочками карточки этого состояния — не пряча остальные">
                <span>Отметить</span>
                ${states.map(([v, label]) =>
                    `<a onclick="App.boardPickByState('${v}')">${label}</a>`).join('')}
            </span>`;
    },

    /** Поставить галочки видимым карточкам этого состояния, к уже отмеченным. */
    boardPickByState(state) {
        this.boardPicked = this.boardPicked || new Set();
        const byId = new Map();
        (this.board && this.board.columns || []).forEach(col =>
            (col.cards || []).forEach(card => byId.set(String(card.id), card)));

        let added = 0;
        document.querySelectorAll('[data-card]').forEach(el => {
            if (el.hidden) return;
            const card = byId.get(el.dataset.card);
            if (!card || !this.cardInState(card, state)) return;
            if (!this.boardPicked.has(el.dataset.card)) added++;
            this.boardPicked.add(el.dataset.card);
        });
        this.boardSyncPicks();
        if (!added) this.toast('Таких карточек на экране нет', 'info');
    },

    /** Состояние карточки словами фильтра — один разбор на фильтр и на отметку. */
    cardInState(card, state) {
        const c = card.company || {};
        return {
            unanswered: !!card.unanswered,
            answered:   !card.unanswered,
            unread:     !!card.unread,
            kp:         !!c.proposal_status,
            nokp:       !c.proposal_status,
            draft:      !!card.draft,
        }[state] ?? false;
    },

    /** Отметить или снять всё, что сейчас видно (скрытое фильтром — не трогаем). */
    boardPickAll(on) {
        this.boardPicked = this.boardPicked || new Set();
        document.querySelectorAll('[data-card]').forEach(el => {
            if (el.hidden) return;
            const box = el.querySelector('.bcard__pick');
            if (box) box.checked = on;
            if (on) this.boardPicked.add(el.dataset.card); else this.boardPicked.delete(el.dataset.card);
        });
        this.boardSyncPicks();
    },

    /** Галочки на карточках и панель действий — по одному состоянию. */
    boardSyncPicks() {
        const picked = this.boardPicked = this.boardPicked || new Set();
        document.querySelectorAll('[data-card]').forEach(el => {
            const on = picked.has(el.dataset.card);
            const box = el.querySelector('.bcard__pick');
            if (box) box.checked = on;
            el.classList.toggle('bcard--picked', on);
        });

        this.mailListShown();
        const bar = document.getElementById('boardBulk');
        if (!bar) return;
        if (!picked.size) { bar.innerHTML = ''; return; }
        const columns = (this.board && this.board.columns) || [];
        bar.innerHTML = `
            <div class="card card--inline bulkbar">
                <strong>Отмечено: ${picked.size}</strong>
                <button class="btn btn--outline btn--sm" onclick="App.boardBulk('read')"
                        title="Пометить всю переписку этих компаний прочитанной">✓ Прочитано</button>
                <button class="btn btn--outline btn--sm" onclick="App.boardBulk('archive')"
                        title="Не наш профиль: переписка уйдёт в архив, карточки — с доски">🗄 В архив</button>
                <button class="btn btn--outline btn--sm btn--danger" onclick="App.boardBulk('spam')"
                        title="Входящие письма уйдут в спам, карточки — с доски">🚫 Спам</button>
                <label class="bfilter" title="Переместить отмеченные карточки в колонку — своей доски или другой">
                    <span>В колонку</span>
                    <select onchange="if(this.value) App.boardBulk('move', {column_id: Number(this.value)}); this.value='';">
                        <option value="">выбрать…</option>
                        ${this.bulkColumnOptions(columns)}
                    </select>
                </label>
                ${(this.boardFilters || {}).archived
                    ? `<button class="btn btn--outline btn--sm" onclick="App.boardBulk('unarchive')"
                               title="Вернуть переписку в работу">↩ Из архива</button>` : ''}
                <button class="btn btn--outline btn--sm" onclick="App.boardBulk('remove')"
                        title="Убрать карточки с доски: письма останутся в почте, а карточка вернётся, когда компания напишет снова">Убрать с доски</button>
                <button class="btn btn--outline btn--sm" onclick="App.boardPickAll(false)">Снять отметки</button>
            </div>`;
    },

    /**
     * Колонки для «В колонку»: своей доски, а при нескольких досках — и чужих,
     * группами (модуль 036). Доска у сервиса одна по замыслу, но заведённые
     * руками существуют, и «перенести карточки с доски на доску» — это они.
     *
     * Список чужих досок подгружается один раз за сеанс и до тех пор список
     * показывает свои колонки: ожидание ответа ради выпадающего списка
     * читалось бы как зависший экран.
     */
    bulkColumnOptions(columns) {
        const own = columns.map(c => `<option value="${c.id}">${this.esc(c.title)}</option>`).join('');
        if (!this.boardTargets) { this.loadBoardTargets(); return own; }
        const others = this.boardTargets.filter(b => Number(b.id) !== Number(this.board && this.board.id));
        if (!others.length) return own;
        return own + others.map(b => `
            <optgroup label="${this.esc(b.name)}">
                ${(b.columns || []).map(c => `<option value="${c.id}">${this.esc(c.title)}</option>`).join('')}
            </optgroup>`).join('');
    },

    /** Доски и их колонки — один раз за сеанс, молча. */
    async loadBoardTargets() {
        if (this._boardTargetsPending) return;
        this._boardTargetsPending = true;
        try {
            const d = await this.api('boards.php?action=targets');
            this.boardTargets = d.items || [];
            // Список уже нарисован своими колонками — перерисовываем панель
            if (this.boardTargets.length > 1) this.boardSyncPicks();
        } catch { this.boardTargets = []; }
    },

    /** Применить групповое действие и перерисовать доску по факту, а не по надежде. */
    async boardBulk(op, extra = {}, only = 0) {
        const ids = only ? [Number(only)] : [...(this.boardPicked || [])].map(Number);
        if (!ids.length) return;
        const ask = {
            archive: `Убрать в архив как «не наш профиль» — карточек: ${ids.length}?`,
            spam:    `Отметить спамом входящие письма и убрать с доски — карточек: ${ids.length}?`,
            remove:  `Убрать с доски карточек: ${ids.length}? Письма останутся в почте, `
                   + 'а карточка вернётся сама, когда компания напишет снова.',
        }[op];
        if (ask && !confirm(ask)) return;

        const bar = document.getElementById('boardBulk');
        if (bar) bar.innerHTML = '<div class="loading">Применяем...</div>';
        try {
            const r = await this.api('boards.php?action=bulk', {method: 'POST', body: {ids, op, ...extra}});
            if (only) (this.boardPicked || new Set()).delete(String(only)); else this.boardPicked = new Set();
            if (r.failed) {
                this.toast(`Сделано: ${r.done}, не вышло: ${r.failed}. ${(r.errors || []).join('; ')}`, 'error');
            } else {
                this.toast(`Готово: ${r.done}`, 'success');
            }
            // Доска из ответа — это то, что получилось, а не то, на что надеялись
            if (r.board && !(this.boardFilters || {}).archived) { this.boardRedraw(r.board); return; }
        } catch (err) {
            this.toast(err.message, 'error');
        }
        this.route();
    },

    boardRecount() {
        document.querySelectorAll('.bcol').forEach(col => {
            const all = [...col.querySelectorAll('.bcard')];
            const shown = all.filter(el => !el.hidden).length;
            const badge = col.querySelector('.bcol__count');
            if (!badge) return;
            // Под фильтром счётчик говорит «сколько из скольких», иначе — просто
            // сколько: «3» над одной видимой карточкой читается как ошибка
            badge.textContent = shown === all.length ? String(all.length) : `${shown} / ${all.length}`;
        });
    },

    async boardAddColumn() {
        const title = prompt('Название колонки', 'Новая колонка');
        if (title === null) return;
        await this.api('boards.php?action=column_save', {method: 'POST', body: {board_id: this.board.id, title}});
        this.route();
    },

    async boardRenameColumn(id, current) {
        const title = prompt('Название колонки', current);
        if (title === null) return;
        await this.api('boards.php?action=column_save', {method: 'POST', body: {board_id: this.board.id, id, title}});
        this.route();
    },

    /** Цвет статуса: восемь готовых и любой свой (модуль 050). */
    boardColumnColor(id, current) {
        const swatches = ['#6b7fd7', '#e0a53c', '#4f9e57', '#b45cc0', '#2f8f8a', '#d9534f', '#3d8bd9', '#8a8f98'];
        const cur = /^#[0-9a-f]{6}$/i.test(current) ? current.toLowerCase() : '#8a8f98';
        this.modal('Цвет статуса', `
            <div class="swatches" role="radiogroup" aria-label="Готовые цвета">
                ${swatches.map(c => `
                    <button type="button" class="swatch ${c === cur ? 'swatch--on' : ''}" style="--col:${c}"
                            role="radio" aria-checked="${c === cur}" aria-label="Цвет ${c}"
                            onclick="document.getElementById('colColor').value='${c}';
                                     this.parentNode.querySelectorAll('.swatch').forEach(b => { b.classList.toggle('swatch--on', b === this); b.setAttribute('aria-checked', b === this); })"></button>`).join('')}
            </div>
            <label class="swatch-own">Свой цвет <input type="color" id="colColor" value="${cur}"></label>
            <div class="flex flex--end" style="margin-top:14px">
                <button class="btn btn--outline" onclick="App.closeModal()">Отмена</button>
                <button class="btn btn--primary" onclick="App.saveColumnColor(${id})">Сохранить цвет</button>
            </div>`);
    },

    async saveColumnColor(id) {
        const color = (document.getElementById('colColor') || {}).value || '';
        try {
            await this.api('boards.php?action=column_save', {method: 'POST', body: {board_id: this.board.id, id, color}});
            this.closeModal();
            this.route();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Сколько карточек показывать в колонке — не грузить интерфейс лишним (issue #60)
    async boardSetColumnLimit(id, current) {
        const raw = prompt('Сколько карточек показывать в колонке? Пусто или 0 — без ограничения', current || '');
        if (raw === null) return;
        const card_limit = parseInt(raw, 10) || 0;
        await this.api('boards.php?action=column_save', {method: 'POST', body: {board_id: this.board.id, id, card_limit}});
        this.route();
    },

    async boardDeleteColumn(id) {
        if (!confirm('Удалить колонку вместе с карточками?')) return;
        await this.api('boards.php?action=column_delete', {method: 'POST', body: {id}});
        this.route();
    },

    async boardAddCard(columnId) {
        const title = prompt('Название карточки', '');
        if (title === null || !title.trim()) return;
        await this.api('boards.php?action=card_add', {method: 'POST', body: {column_id: columnId, title}});
        this.route();
    },

    /**
     * Заметка карточки доски: правится и стирается там же, где написана, и в
     * карточке компании — тоже (модуль 031). `$drop` — удалить, не спрашивая
     * текст; пустой ответ на вопрос тоже удаляет.
     */
    async boardCardNote(id, current, drop) {
        let note = '';
        if (!drop) {
            const typed = prompt('Заметка на карточке (пусто — убрать заметку)', current || '');
            if (typed === null) return;
            note = typed.trim();
        } else if (!confirm('Удалить заметку с карточки?')) return;

        try {
            await this.api('boards.php?action=card_save', {method: 'POST', body: {id, note}});
        } catch (err) { this.toast(err.message, 'error'); return; }
        // Заметку правят с двух экранов — перерисовываем тот, на котором стоим
        const cp = this.openCompanyId();
        if (cp) this.loadCardPlacement(cp);
        else this.route();
    },

    /**
     * Убрать карточку — и, если попросят, удалить насовсем (модуль 040).
     *
     * «Убрать» помнит, где карточка стояла, и возвращает её, когда компания
     * напишет снова. Карточку, за которой не осталось ни одного письма, это
     * не убирало насовсем — и удалить её было нечем.
     */
    async boardCardDelete(id) {
        if (!confirm('Убрать карточку с доски? Письма останутся в почте, '
                   + 'а карточка вернётся сама, когда компания напишет снова.')) return;
        const r = await this.api('boards.php?action=card_delete', {method: 'POST', body: {id}});
        // Карточка, за которой не осталось ни писем, ни запроса, ни заметки,
        // удаляется совсем: возвращаться ей неоткуда и незачем
        if (r.purged) this.toast('Карточка была пустой — удалена совсем', 'success');
        this.route();
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
                        ${p.ready && m.state !== 'missing' ? '' : 'disabled'}>${this.esc(m.label)}${p.ready ? '' : ' — нет ключа'}</option>`).join('')}
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

    /**
     * Удалить ОДНО письмо: из панели и в «Корзину» на почтовом сервере, чтобы
     * следующая синхронизация не вернула его обратно.
     *
     * Второй аргумент — либо адрес, куда уйти, если удалили ту самую страницу,
     * на которой стоим, либо `{thread: ключ}`: тогда лента переписки
     * перечитывается на месте, а карточка компании никуда не уезжает
     * (модуль 031).
     */
    async deleteMail(id, opts) {
        if (!confirm('Удалить это письмо? Оно уйдёт в «Корзину» на почтовом сервере '
                   + 'и исчезнет из панели. Остальные письма переписки останутся.')) return;
        const o = typeof opts === 'string' ? {back: opts} : (opts || {});
        try {
            const r = await this.api('mail.php?action=delete', {method: 'POST', body: {id}});
            // «Удалено» has to mean the same thing on both sides — say which one happened
            const ok = r.server_state === 'trashed' ? 'Письмо удалено и перенесено в «Корзину»'
                     : r.server_state === 'expunged' ? 'Письмо удалено с почтового сервера'
                     : 'Письмо удалено из панели';
            this.toast(r.warning || ok, r.warning ? 'error' : 'success');

            // Последнее письмо переписки — переписки больше нет, список надо
            // перечитать целиком; иначе перерисовываем только её ленту
            if (r.thread_empty) {
                const cp = this.openCompanyId();
                if (cp) this.loadCompanyThreads(cp);
                else this.goAfterDelete('mail');
                return;
            }
            const box = o.thread ? document.getElementById('th_' + this.threadDomId(o.thread)) : null;
            if (box) {
                box.dataset.loaded = '';
                box.hidden = true;
                this.toggleCompanyThread(o.thread, {markRead: false});
                return;
            }
            this.goAfterDelete(o.back);
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async deleteThread(key, count) {
        const what = count > 1 ? `Удалить всю переписку (${count} писем)?` : 'Удалить переписку?';
        if (!confirm(what + ' Письма уйдут в «Корзину» на почтовом сервере и исчезнут из панели.')) return;
        try {
            const r = await this.api('mail.php?action=delete_thread', {method: 'POST', body: {thread_key: key}});
            this.toast(r.warning || `Удалено писем: ${r.deleted}`, r.warning ? 'error' : 'success');
            this.goAfterDelete('mail');
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
            const cp = this.openCompanyId();
            if (cp) this.loadCompanyThreads(cp);
            else this.goAfterDelete('mail');
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async unarchiveThread(key) {
        try {
            await this.api('mail.php?action=unarchive', {method: 'POST', body: {thread_key: key}});
            this.toast('Переписка вернулась в работу', 'success');
            const cp = this.openCompanyId();
            if (cp) this.loadCompanyThreads(cp);
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
    msgBodyHtml(m) {
        if (!(m.body_html && m.body_html.trim() !== '')) {
            return `<div class="mail-plain">${this.esc(m.body_text)}</div>`;
        }
        // Рамка письма СТРОИТСЯ ПРИ РАСКРЫТИИ, а не лежит в разметке свёрнутой.
        // У скрытого документа высота равна нулю: рамка, посчитанная заранее,
        // оставалась ростом с CSS-заглушку и налезала на соседние письма —
        // ровно то, что было видно в карточке компании (модуль 031).
        this.mailBodies = this.mailBodies || {};
        const ref = 'mb' + (this._bodySeq = (this._bodySeq || 0) + 1);
        this.mailBodies[ref] = m.body_html;
        // Долгая смена открывает сотни писем — держим в памяти только те, чьё
        // место в разметке ещё существует
        const keys = Object.keys(this.mailBodies);
        if (keys.length > 200) {
            keys.forEach(k => {
                if (k !== ref && !document.querySelector(`[data-body="${k}"]`)) delete this.mailBodies[k];
            });
        }
        return `<div class="mail-frame" data-body="${ref}"></div>`;
    },

    /**
     * Построить рамки писем, которые сейчас видны, и только их.
     *
     * Вызывается после отрисовки ленты и при раскрытии письма. Уже построенная
     * рамка не строится второй раз: `data-mounted` — это отметка «здесь уже есть
     * документ», а не «здесь когда-то был».
     */
    mountBodies(root) {
        const host = root || document;
        host.querySelectorAll('.mail-frame[data-body]:not([data-mounted])').forEach(box => {
            // У свёрнутого письма тело скрыто, а у скрытого документа высота
            // равна нулю: рамку строим при раскрытии, а не «на всякий случай»
            const letter = box.closest('[data-tmsg]');
            if (letter && !letter.classList.contains('lmsg--open')) return;
            box.dataset.mounted = '1';
            box.innerHTML = this.htmlPreviewFrame((this.mailBodies || {})[box.dataset.body] || '');
        });
    },

    // Auto-sized iframe for HTML we do not fully trust: an email body (item 4)
    // or a client-side DOCX/XLSX-to-HTML conversion (item 5). No allow-scripts
    // in the sandbox — even a gap in sanitizeHtml() or a bug in the docx/xlsx
    // converter still cannot execute anything here. allow-same-origin is safe
    // to add precisely because scripts never run, and is only there so this
    // page may read the frame's scrollHeight to size it.
    htmlPreviewFrame(innerHtml, {bg = '#fff', maxHeight = 0} = {}) {
        const doc = `<!doctype html><html><head><meta charset="utf-8"><style>
            html,body{margin:0;padding:10px;background:${bg};color:#1a1a1a;
                font:14px/1.55 -apple-system,'Segoe UI',Roboto,sans-serif;
                word-wrap:break-word;overflow-wrap:break-word;overflow-x:hidden;}
            img{max-width:100%;height:auto}
            table{border-collapse:collapse;max-width:100%;table-layout:fixed}
            td,th{border:1px solid #ddd;padding:4px 6px;font-size:12px;text-align:left}
            a{color:#8a2a24}
            </style></head><body>${this.lazyImages(innerHtml)}</body></html>`;
        return `<iframe class="html-frame" data-maxh="${maxHeight}" sandbox="allow-same-origin allow-popups"
                    loading="lazy" onload="App.sizeFrame(this)" srcdoc="${this.esc(doc)}"></iframe>`;
    },

    /**
     * Картинки письма грузятся, когда до них доходит прокрутка (issue #60):
     * длинная переписка с десятком писем и логотипов в подписях не тянет всё
     * разом. Атрибут работает и без скриптов — рамка письма их не исполняет.
     */
    lazyImages(html) {
        return String(html || '').replace(/<img\b(?![^>]*\bloading=)/gi, '<img loading="lazy" decoding="async"');
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
        const box = head.closest('[data-tmsg]');
        if (!box) return;
        box.classList.toggle('lmsg--open');
        if (box.classList.contains('lmsg--open')) {
            this.mountBodies(box);
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
        let doc;
        try { doc = iframe.contentWindow.document; } catch { iframe.style.height = '480px'; return; }

        const max = Number(iframe.dataset.maxh) || 0;
        const apply = () => {
            const h = Math.max(doc.documentElement.scrollHeight, doc.body ? doc.body.scrollHeight : 0);
            if (!h) return;                       // документ ещё пуст — смерим позже
            const want = Math.max(80, h + 8);
            iframe.style.height = (max ? Math.min(max, want) : want) + 'px';
        };
        apply();

        // Дальше рамка следит за своим документом сама: картинки догружаются
        // после `load`, шрифты — ещё позже, и высота, посчитанная один раз,
        // обрезала письмо или оставляла под ним пустое поле.
        if (!iframe.dataset.watched && window.ResizeObserver) {
            iframe.dataset.watched = '1';
            const ro = new ResizeObserver(apply);
            ro.observe(doc.documentElement);
            if (doc.body) ro.observe(doc.body);
        } else if (!iframe.dataset.watched) {
            iframe.dataset.watched = '1';
            setTimeout(apply, 350);
            setTimeout(apply, 1200);
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
            if (key) location.hash = 'mail/t/' + encodeURIComponent(key); else this.route();
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
                        <!-- Счётчики — ссылки: число без журнала за ним ничего не
                             даёт сделать, а искать фильтр руками никто не идёт (модуль 029) -->
                        <p>Ошибок за сутки: <a href="#settings/logs/error"
                              class="${d.log.errors_24h ? 'no' : 'ok'}"><strong>${d.log.errors_24h}</strong></a>
                           · предупреждений: <a href="#settings/logs/warning">${d.log.warnings_24h}</a>
                           · записей всего: <a href="#settings/logs/all">${d.log.total}</a></p>
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
                ${this.setupCard(d)}
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

    testOut(html, cls = 'ok', target = 'testResult') {
        const el = document.getElementById(target);
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

    /**
     * Список организаций МойСклад в поле настройки (модуль 041).
     *
     * Поле требовало «ID организации» — 36 знаков, которые брали из адресной
     * строки МойСклад и переписывали руками. Теперь это список имён с ИНН;
     * МойСклад недоступен — поле остаётся с тем, что в нём стоит, и его
     * по-прежнему можно вписать.
     */
    async loadOrganizations(selectId, current) {
        try {
            const d = await this.api('admin.php?action=moysklad_organizations');
            const sel = document.getElementById(selectId);
            if (!sel || !(d.items || []).length) return;
            sel.innerHTML = `<option value="">(первая организация аккаунта)</option>`
                + d.items.map(o => `<option value="${this.esc(o.id)}" ${o.id === current ? 'selected' : ''}>
                        ${this.esc(o.name)}${o.inn ? ' · ИНН ' + this.esc(o.inn) : ''}</option>`).join('');
            sel.value = current || '';
        } catch { /* нет связи с МойСклад — остаётся то, что уже выбрано */ }
    },

    // target: the provider card's own result box, so the answer shows next to its button
    async testLlm(provider, target = 'testResult') {
        const dirty = target !== 'testResult' && this.llmCardDirty(provider)
            ? '<br><span class="muted">Проверяется сохранённая настройка — изменения на карточке сначала сохраните.</span>' : '';
        this.testOut('Спрашиваем модель...', 'muted', target);
        try {
            const r = await this.api('admin.php?action=test_llm', {method: 'POST', body: {provider}});
            this.testOut(`${this.esc(provider)} — ответ за ${r.result.ms} мс, модель ${this.esc(r.result.model)}
                          (${this.esc(r.result.route)}): «${this.esc(r.result.answer)}»${dirty}`, 'ok', target);
        } catch (err) { this.testOut(this.esc(err.message) + dirty, 'no', target); }
    },

    // Unsaved edits on a provider card: the test runs on stored settings, not the form
    llmCardDirty(provider) {
        const keys = provider === 'yandex'
            ? ['YANDEX_MODEL', 'YANDEX_API_KEY', 'YANDEX_FOLDER_ID'] : ['OPENROUTER_MODEL', 'OPENROUTER_API_KEY'];
        return keys.some(k => {
            const el = document.getElementById('set_' + k);
            return el && el.value !== (this.llmFormInitial || {})[k];
        });
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
                // Организация выбирается из списка МойСклад, а не переписывается
                // идентификатором из адресной строки (модуль 041)
                if (it.type === 'organization') {
                    this.loadOrganizations(id, String(it.value));
                    return `<select id="${id}">
                        <option value="${this.esc(it.value)}">${this.esc(it.value) || '(первая организация аккаунта)'}</option>
                    </select>`;
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
                                ${this.settingLink(it)}
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

    /**
     * «Где взять» — ссылкой, а не советом поискать (issue #60).
     *
     * Значение, которое выдаёт другой сервис, объясняется не только словами:
     * рядом с полем стоит ссылка ровно на ту страницу, где токен создаётся, —
     * и в настройках, и в мастере. Ссылка на свой же экран (`#…`) открывается
     * в этой вкладке, чужая — в новой.
     */
    settingLink(it) {
        const l = it && it.link;
        if (!l || !l.url) return '';
        return l.url.startsWith('#')
            ? `<div class="setting__link"><a href="${this.esc(l.url)}">${this.esc(l.label)}</a></div>`
            : `<div class="setting__link"><a href="${this.esc(l.url)}" target="_blank" rel="noopener">↗ ${this.esc(l.label)}</a></div>`;
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
                    ${list.map(m => `<option value="${this.esc(m.id)}" ${m.id === value ? 'selected' : ''}
                        ${m.state === 'missing' ? 'disabled' : ''}>${this.esc(m.label)}</option>`).join('')}
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
            const yx = d.yandex_catalog || {ok: 0, missing: 0, total: 0, synced_at: null};

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
                        <div class="form-group"><label>Предел длины ответа, токенов</label>
                            <input type="number" id="set_LLM_MAX_TOKENS" value="${this.esc(val('LLM_MAX_TOKENS'))}"
                                   title="Ограничивает ответ Yandex. Если в журнале «ответ оборвался по пределу длины» — увеличьте"></div>
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

                <div class="card">
                    <div class="card__title">Каталог моделей Yandex</div>
                    <p class="muted">Открытые модели (Llama, DeepSeek, Qwen, Gemma) включены не в каждом облаке:
                       слаг, которого у провайдера нет, отвечает «unknown model». Проверка спрашивает у облака
                       его список моделей (Models API) и вычёркивает то, чего в вашем Folder ID не оказалось —
                       дальше такой слаг в запрос не уходит, вместо него отвечает YandexGPT. Модели, которых нет
                       в списке ниже, а в облаке есть, добавляются в выбор. Если Models API не ответил, список
                       проверяется по-старому — по одному короткому запросу на слаг.
                       Маршрут запоминается отдельно: открытые модели отвечают не на общем адресе, а на
                       OpenAI-совместимом, и запросы к ним уходят туда сами.</p>
                    <p class="muted">${yx.synced_at
                        ? `Проверено ${this.fmtDate(yx.synced_at)}: отвечает <strong class="ok">${yx.ok}</strong>,
                           нет в облаке <strong class="${yx.missing ? 'no' : ''}">${yx.missing}</strong> из ${yx.total}${
                           yx.openai ? `; по OpenAI-совместимому API — <strong>${yx.openai}</strong>` : ''}.`
                        : 'Каталог ещё не проверялся — список ниже показывает кандидатов, а не факт.'}</p>
                    <div class="flex flex--wrap">
                        <button class="btn btn--outline" onclick="App.verifyYandexModels()">Проверить каталог Yandex</button>
                        ${yx.synced_at ? '<button class="btn btn--outline" onclick="App.forgetYandexModels()">Забыть проверку</button>' : ''}
                    </div>
                    <div id="yxCatalogResult" style="margin-top:10px"></div>
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
                        <button class="btn btn--outline btn--sm" onclick="App.testLlm('${p.provider}', 'llmTest_${p.provider}')">Проверить подключение</button>
                        <div id="llmTest_${p.provider}" style="margin-top:10px"></div>
                    </div>`;
                }).join('')}
                <div class="flex flex--end"><button class="btn btn--primary" onclick="App.saveSettings()">Сохранить</button></div>
            `;
            this.settingsSpec = s.items.filter(i => document.getElementById('set_' + i.key));
            this.llmFormInitial = Object.fromEntries(this.settingsSpec.map(i => [i.key, document.getElementById('set_' + i.key).value]));
        } catch (err) { this.adminFail(err); }
    },

    async refreshOpenRouterModels() {
        const out = document.getElementById('orCatalogResult');
        out.innerHTML = '<p class="muted">Спрашиваем OpenRouter...</p>';
        try {
            const r = await this.api('admin.php?action=openrouter_models_refresh', {method: 'POST', body: {}});
            await this.adminLlm();   // re-render first: it replaces the result box
            this.testOut(`Загружено моделей: ${r.count}`, 'ok', 'orCatalogResult');
        } catch (err) { out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`; }
    },

    async forgetOpenRouterModels() {
        try {
            await this.api('admin.php?action=openrouter_models_forget', {method: 'POST', body: {}});
            this.toast('Списки вернулись к встроенному каталогу');
            this.adminLlm();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    /**
     * Спросить у облака его каталог моделей (модуль 029). Так же сделано в
     * CGM-diet: каталог приходит от провайдера, а не берётся из кода.
     */
    async verifyYandexModels() {
        const out = document.getElementById('yxCatalogResult');
        out.innerHTML = '<p class="muted">Спрашиваем у Yandex список моделей каталога...</p>';
        try {
            const r = await this.api('admin.php?action=yandex_models_verify', {method: 'POST', body: {}});
            const how = r.source === 'models_api'
                ? 'Список пришёл от облака (Models API).'
                : 'Models API не ответил — слаги проверены по одному.';
            const html = `
                <p class="muted">${how}</p>
                <p class="ok">Отвечает: ${(r.ok || []).join(', ') || '— ни одна'}</p>
                ${(r.openai || []).length ? `<p class="muted">Только по OpenAI-совместимому API
                    (запросы уходят туда сами): ${this.esc(r.openai.join(', '))}</p>` : ''}
                ${(r.missing || []).length ? `<p class="no">Нет в этом облаке: ${this.esc(r.missing.join(', '))}</p>` : ''}
                ${(r.unclear || []).length ? `<p class="muted">Не удалось выяснить (ответ не про модель):<br>
                    ${r.unclear.map(x => this.esc(x)).join('<br>')}</p>` : ''}`;
            await this.adminLlm();   // re-render first: it replaces the result box
            const box = document.getElementById('yxCatalogResult');
            if (box) box.innerHTML = html;
        } catch (err) { out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`; }
    },

    async forgetYandexModels() {
        try {
            await this.api('admin.php?action=yandex_models_forget', {method: 'POST', body: {}});
            this.toast('Проверка каталога Yandex забыта');
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
                                        ${b.sync_sent ? '' : '<span class="badge badge--draft" title="Письма, отправленные с телефона или из другого клиента, в сервис не попадают">«Отправленные» не забираются</span>'}
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
            // Папка отправленных упала одна — входящие разобраны, но сказать надо
            if (!rep.error && rep.sent_error) this.toast('Отправленные: ' + rep.sent_error, 'error');
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
                                    <td class="flex flex--wrap">
                                        <button class="btn btn--sm btn--outline" onclick="App.editManager(${m.id})">Изменить</button>
                                        <!-- «Админ может обнулить логин» (issue #60): все сессии
                                             этого человека закрываются, где бы они ни были открыты -->
                                        <button class="btn btn--sm btn--outline" onclick="App.resetManagerLogin(${m.id}, this)"
                                                title="Закрыть все открытые сессии этого менеджера — ему придётся войти заново">Обнулить вход</button>
                                        <button class="btn btn--sm btn--outline" onclick="App.showManagerLogins(${m.id})"
                                                title="Последние входы: когда и с какого адреса">Входы</button>
                                    </td>
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

    /** Обнулить вход менеджера — issue #60. */
    async resetManagerLogin(id, btn) {
        if (!confirm('Закрыть все сессии этого менеджера? Ему придётся войти заново.')) return;
        btn.disabled = true;
        try {
            await this.api('admin.php?action=manager_logout', {method: 'POST', body: {id}});
            this.toast('Вход обнулён — сессии закрыты', 'success');
        } catch (err) { this.toast(err.message, 'error'); }
        finally { btn.disabled = false; }
    },

    /** Последние входы менеджера: когда и откуда. */
    async showManagerLogins(id) {
        const box = document.getElementById('managerForm');
        if (!box) return;
        box.innerHTML = '<div class="card"><div class="loading">Смотрим входы...</div></div>';
        try {
            const d = await this.api(`admin.php?action=manager_logins&id=${id}`);
            box.innerHTML = `
                <div class="card">
                    <div class="card__title">Последние входы</div>
                    ${(d.items || []).length ? `<table class="table">
                        <thead><tr><th>Когда</th><th>Адрес</th><th>Браузер</th></tr></thead>
                        <tbody>${d.items.map(r => `<tr>
                            <td>${this.esc(r.created_at)}</td>
                            <td><code>${this.esc(r.ip) || '—'}</code></td>
                            <td class="muted">${this.esc(r.user_agent) || '—'}</td>
                        </tr>`).join('')}</tbody></table>`
                      : '<p class="muted">Входов пока не записано.</p>'}
                </div>`;
        } catch (err) { box.innerHTML = `<div class="card"><p class="no">${this.esc(err.message)}</p></div>`; }
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
            const llm = await this.loadLlmModels();
            this.promptKeys = (d.items || []).map(p => ({key: p.key, title: p.title}));
            document.getElementById('adminBody').innerHTML = `
                <div class="card"><div class="card__title">Промпты${this.hint('prompts')}</div>
                   <p>Системные промпты, с которыми сервис обращается к нейросети.
                   Пустое поле вернёт встроенный текст. Плейсхолдеры <code>{{...}}</code> подставляются кодом — не удаляйте их.</p></div>
                ${this.rethinkCard(llm)}
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
                            ${p.content.includes('ИЗ ПРАВОК МЕНЕДЖЕРОВ')
                                ? '<span class="badge badge--confirmed">есть блок из правок</span>' : ''}
                        </div>
                    </div>`).join('')}
            `;
        } catch (err) { this.adminFail(err); }
    },

    /**
     * ==== Промпты учатся на правках (модуль 041) ====
     *
     * Правок набирается сотня, и читать их подряд некому. Модель — её выбирают
     * здесь же, можно взять поумнее — читает их и пишет короткий свод правил.
     * Свод не уходит в промпт сам: его читает человек и подмешивает кнопкой,
     * в тот промпт, который сам и выберет. Неудачно — откат из истории.
     */
    rethinkCard(llm) {
        return `
            <div class="card">
                <div class="card__title">Переосмыслить правки нейросетью${this.hint('rethink')}</div>
                <p class="muted">Модель прочитает последние правки и отправленные письма и напишет
                   короткий свод правил — его можно подмешать в любой промпт одной кнопкой.</p>
                <div class="flex flex--wrap" style="gap:8px">
                    <select id="rethinkKind" title="По каким правкам учиться">
                        <option value="sent">Отправленные письма</option>
                        <option value="reply">Правки ответов</option>
                        <option value="category">Правки классификации</option>
                        <option value="answer">Правки подбора</option>
                        <option value="kp">Правки текста КП</option>
                    </select>
                    <select id="rethinkLimit" title="Сколько последних правок прочитать">
                        <option value="20">20 последних</option>
                        <option value="40" selected>40 последних</option>
                        <option value="80">80 последних</option>
                    </select>
                    ${this.replyModelSelect(llm).replace('id="cmpModel"', 'id="rethinkModel"')}
                    <button class="btn btn--primary btn--sm" onclick="App.rethinkLearning(this)">Переосмыслить</button>
                </div>
                <div id="rethinkOut" style="margin-top:10px"></div>
            </div>`;
    },

    async rethinkLearning(btn) {
        const out = document.getElementById('rethinkOut');
        btn.disabled = true;
        out.innerHTML = '<div class="loading">Модель читает правки — это занимает до минуты...</div>';
        try {
            const r = await this.api('admin.php?action=learning_rethink', {method: 'POST', body: {
                kind:  document.getElementById('rethinkKind').value,
                limit: Number(document.getElementById('rethinkLimit').value) || 40,
                model: (document.getElementById('rethinkModel') || {}).value || '',
            }});
            out.innerHTML = `
                <p class="muted">Прочитано правок: ${r.samples} · модель ${this.esc(r.model)}</p>
                <textarea id="rethinkText" rows="10">${this.esc(r.text)}</textarea>
                <div class="flex flex--wrap" style="margin-top:8px;gap:8px">
                    <select id="rethinkTarget" title="В какой промпт подмешать">
                        ${(this.promptKeys || []).map(p =>
                            `<option value="${this.esc(p.key)}">${this.esc(p.title)}</option>`).join('')}
                    </select>
                    <button class="btn btn--primary btn--sm" onclick="App.mergeIntoPrompt(this)">Подмешать в промпт</button>
                </div>`;
        } catch (err) {
            out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`;
        } finally { btn.disabled = false; }
    },

    async mergeIntoPrompt(btn) {
        const key = document.getElementById('rethinkTarget').value;
        const block = document.getElementById('rethinkText').value;
        if (!confirm('Подмешать этот блок в промпт? Прежний текст останется в истории — откат возможен.')) return;
        btn.disabled = true;
        try {
            await this.api('admin.php?action=prompt_append', {method: 'POST', body: {key, block}});
            this.toast('Блок подмешан в промпт', 'success');
            this.adminPrompts();
        } catch (err) { this.toast(err.message, 'error'); btn.disabled = false; }
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

    /**
     * История промпта: видно, ЧЕМ версия отличается от нынешней, и её можно
     * вернуть (модуль 041). Раньше это был список текстов для чтения: чтобы
     * откатить неудачную правку, её выделяли и копировали руками.
     */
    async promptHistory(key) {
        const d = await this.api(`admin.php?action=prompt_history&key=${encodeURIComponent(key)}`);
        this.modal('История промпта', (d.items || []).map(h => `
            <div class="card" style="margin-bottom:8px">
                <div class="flex flex--between">
                    <div class="muted">${this.fmtDate(h.created_at)} ${h.manager_name ? '· ' + this.esc(h.manager_name) : ''}</div>
                    <button class="btn btn--outline btn--sm"
                            onclick="App.restorePrompt('${this.jsStr(key)}', ${h.id})">Вернуть эту версию</button>
                </div>
                <pre class="diff">${this.diffHtml(h.content || '', d.current || '')}</pre>
            </div>`).join('') || '<p class="muted">Пока пусто</p>');
    },

    /**
     * Построчная разница: что было в этой версии и чего нет сейчас — красным,
     * что появилось с тех пор — зелёным. Сравнение по строкам, а не по словам:
     * промпт читают абзацами, и строка целиком — правильная единица правки.
     */
    diffHtml(oldText, newText) {
        const was = new Set(String(oldText).split('\n').map(l => l.trim()));
        const now = new Set(String(newText).split('\n').map(l => l.trim()));
        return String(oldText).split('\n').map(line => {
            const t = line.trim();
            if (t !== '' && !now.has(t)) return `<span class="diff--gone">${this.esc(line)}</span>`;
            return this.esc(line);
        }).join('\n') + (
            String(newText).split('\n').filter(l => l.trim() !== '' && !was.has(l.trim())).length
                ? `\n<span class="diff--new">— и появилось с тех пор: `
                  + this.esc(String(newText).split('\n').filter(l => l.trim() !== '' && !was.has(l.trim())).length)
                  + ` строк(и)</span>` : '');
    },

    async restorePrompt(key, historyId) {
        if (!confirm('Вернуть эту версию промпта? Нынешний текст тоже попадёт в историю.')) return;
        try {
            await this.api('admin.php?action=prompt_restore', {method: 'POST', body: {key, history_id: historyId}});
            this.closeModal();
            this.toast('Версия промпта возвращена', 'success');
            this.adminPrompts();
        } catch (err) { this.toast(err.message, 'error'); }
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

// ==== Мастер настройки, обратная связь и проверочное КП (модуль 038) ====
//
// Три просьбы из одного обращения: чтобы менеджер мог пожаловаться прямо с
// экрана, чтобы запуск с нуля не был устной традицией и чтобы после настройки
// сервис проверили на живом запросе, а не на первом письме клиента.
Object.assign(App, {

    // ---- Обратная связь ----

    /**
     * Жалоба пишется ТАМ, где её увидели: модал открывается с любого экрана и
     * сам запоминает адрес страницы. Уходит она не в GitHub, а администратору
     * на ревью — публичный трекер не место для «у меня всё пропало».
     */
    supportModal(kind = 'bug') {
        this.supportFiles = [];
        this.modal('Написать в поддержку', `
            <div class="form-group">
                <label>О чём</label>
                <select id="supKind">
                    ${Object.entries({bug: 'Не работает', idea: 'Предложение', question: 'Вопрос'}).map(
                        ([k, l]) => `<option value="${k}" ${k === kind ? 'selected' : ''}>${l}</option>`).join('')}
                </select>
            </div>
            <div class="form-group">
                <label>Коротко</label>
                <input type="text" id="supTitle" placeholder="Например: не отправляется КП из карточки">
            </div>
            <div class="form-group">
                <label>Что случилось</label>
                <textarea id="supBody" rows="6" onpaste="App.supportPaste(event)"
                          placeholder="Что делали, что ожидали увидеть и что увидели. Скриншот можно вставить сюда — Ctrl+V"></textarea>
            </div>
            <div class="form-group">
                <label>Файлы</label>
                <input type="file" id="supFiles" multiple onchange="App.supportAttach(this)">
                <div class="flex flex--wrap" id="supFileList" style="gap:6px;margin-top:6px"></div>
                <div class="muted">Картинки, документы, видео — всё, что можно приложить к issue.</div>
            </div>
            <p class="muted">Экран: <code>${this.esc(location.hash || '#mail')}</code> — уйдёт вместе с обращением.
               Администратор посмотрит и заведёт issue в репозитории.</p>
            <div class="flex flex--end" style="gap:8px">
                <button class="btn btn--outline" onclick="App.closeModal()">Отмена</button>
                <button class="btn btn--primary" onclick="App.supportSend(this)">Отправить</button>
            </div>`);
    },

    async supportAttach(input) {
        for (const file of [...(input.files || [])]) await this.supportUpload(file);
        input.value = '';
    },

    supportPaste(ev) {
        const items = [...((ev.clipboardData || {}).items || [])].filter(i => i.kind === 'file');
        if (!items.length) return;
        ev.preventDefault();
        items.forEach(i => {
            const file = i.getAsFile();
            if (file) this.supportUpload(file);
        });
    },

    async supportUpload(file) {
        const fd = new FormData();
        fd.append('file', file, file.name || ('снимок-' + Date.now() + '.png'));
        try {
            const res = await fetch('/api/support.php?action=upload', {method: 'POST', body: fd, credentials: 'same-origin'});
            const d = await res.json();
            if (!res.ok || d.error) throw new Error(d.error || 'Файл не загрузился');
            (this.supportFiles = this.supportFiles || []).push(d.file);
            const box = document.getElementById('supFileList');
            if (box) box.innerHTML = (this.supportFiles || []).map((f, i) => `
                <span class="chip">📎 ${this.esc(f.filename)}
                    <a onclick="App.supportDrop(${i})" title="Убрать">×</a></span>`).join('');
        } catch (err) { this.toast(err.message, 'error'); }
    },

    supportDrop(i) {
        (this.supportFiles || []).splice(i, 1);
        const box = document.getElementById('supFileList');
        if (box) box.innerHTML = (this.supportFiles || []).map((f, n) => `
            <span class="chip">📎 ${this.esc(f.filename)}
                <a onclick="App.supportDrop(${n})" title="Убрать">×</a></span>`).join('');
    },

    async supportSend(btn) {
        const title = document.getElementById('supTitle').value.trim();
        const body  = document.getElementById('supBody').value.trim();
        if (!title && !body) return this.toast('Опишите, что случилось', 'error');
        btn.disabled = true;
        try {
            await this.api('support.php?action=submit', {method: 'POST', body: {
                kind:  document.getElementById('supKind').value,
                title, body,
                page:  location.hash || '#mail',
                files: (this.supportFiles || []).map(f => f.name),
            }});
            this.closeModal();
            this.toast('Отправили администратору — ответ придёт в уведомления', 'success');
        } catch (err) { this.toast(err.message, 'error'); btn.disabled = false; }
    },

    /** Список обращений: менеджер видит свои, администратор — все и с кнопками. */
    async settingsSupport() {
        try {
            const d = await this.api('support.php?action=list');
            this.supportData = d;
            const admin = !!d.is_admin;
            const warn = [];
            if (!d.enabled) warn.push('Обратная связь выключена в «Настройках → Все параметры» (SUPPORT_ENABLED).');
            if (admin && !d.repo) warn.push('Не указан репозиторий (SUPPORT_REPO) — обращения останутся в панели.');
            if (admin && !d.token_set) warn.push('Нет токена GitHub с правом Issues: Write — issue завести не получится.');

            document.getElementById('adminBody').innerHTML = `
                <div class="card">
                    <div class="card__title">Обратная связь${this.hint('support')}</div>
                    <p>Что-то сломалось или мешает — напишите отсюда или кнопкой «Поддержка» в шапке.
                       Обращение уходит администратору${admin ? '' : ' и вернётся ответом в уведомления'}.</p>
                    ${warn.map(w => `<p class="no">${this.esc(w)}</p>`).join('')}
                    <div class="flex flex--wrap" style="gap:8px">
                        <button class="btn btn--primary" onclick="App.supportModal('bug')">Написать в поддержку</button>
                        <button class="btn btn--outline" onclick="App.supportModal('idea')">Предложить улучшение</button>
                    </div>
                </div>
                ${(d.items || []).length ? (d.items || []).map(t => this.supportRow(t, admin)).join('')
                    : '<div class="card"><p class="muted">Обращений пока нет.</p></div>'}
            `;
        } catch (err) { this.adminFail(err); }
    },

    supportRow(t, admin) {
        const badge = {new: 'badge--new', approved: 'badge--sent', declined: 'badge--draft'}[t.status] || 'badge--new';
        const label = {new: 'на ревью', approved: 'в GitHub', declined: 'отклонено'}[t.status] || t.status;
        const kinds = (this.supportData || {}).kinds || {};
        return `
            <div class="card" data-ticket="${t.id}">
                <div class="flex flex--between flex--wrap" style="gap:8px">
                    <div class="card__title" style="margin:0">${this.esc(t.title)}</div>
                    <div><span class="badge ${badge}">${label}</span>
                         <span class="badge badge--draft">${this.esc(kinds[t.kind] || t.kind)}</span></div>
                </div>
                <div class="muted">${this.esc(t.manager_name || 'менеджер')} · ${this.fmtDate(t.created_at)}
                    ${t.page ? ' · экран <code>' + this.esc(t.page) + '</code>' : ''}
                    ${t.model ? ' · модель <code>' + this.esc(t.model) + '</code>' : ''}
                    ${t.rating ? ' · ' + (t.rating === 'up' ? '👍' : '👎') : ''}</div>
                ${t.body ? `<p style="white-space:pre-wrap;margin-top:8px">${this.esc(t.body)}</p>` : ''}
                ${(t.files || []).length ? `<div class="flex flex--wrap" style="gap:8px;margin-top:8px">
                    ${(t.files || []).map(f => (f.mime || '').startsWith('image/')
                        ? `<a href="/api/support.php?action=file&id=${f.id}" target="_blank" rel="noopener">
                             <img loading="lazy" src="/api/support.php?action=file&id=${f.id}" alt="${this.esc(f.filename)}"
                                  style="max-height:120px;border:1px solid var(--border);border-radius:4px"></a>`
                        : `<a class="chip" href="/api/support.php?action=file&id=${f.id}" target="_blank" rel="noopener">📎 ${this.esc(f.filename)}</a>`
                    ).join('')}</div>` : ''}
                ${t.issue_url ? `<p class="ok" style="margin-top:8px">Issue
                    <a href="${this.esc(t.issue_url)}" target="_blank" rel="noopener">#${t.issue_number}</a></p>` : ''}
                ${t.status === 'declined' && t.review_note ? `<p class="muted" style="margin-top:8px">Причина: ${this.esc(t.review_note)}</p>` : ''}
                ${admin && t.status === 'new' ? `
                    <div class="flex flex--wrap" style="gap:8px;margin-top:10px">
                        <button class="btn btn--primary btn--sm" onclick="App.supportApprove(${t.id}, this)">Завести issue</button>
                        <button class="btn btn--outline btn--sm" onclick="App.supportDecline(${t.id})">Отклонить</button>
                    </div>` : ''}
            </div>`;
    },

    async supportApprove(id, btn) {
        const t = ((this.supportData || {}).items || []).find(i => i.id === id) || {};
        const title = prompt('Заголовок issue:', t.title || '');
        if (title === null) return;
        btn.disabled = true;
        try {
            const r = await this.api('support.php?action=approve', {method: 'POST', body: {id, title}});
            this.toast('Issue #' + r.number + ' заведён' + (r.failed && r.failed.length
                ? '; файлы не ушли: ' + r.failed.join('; ') : ''), r.failed && r.failed.length ? 'error' : 'success');
            this.settingsSupport();
        } catch (err) { this.toast(err.message, 'error'); btn.disabled = false; }
    },

    async supportDecline(id) {
        const note = prompt('Почему отклоняем? Автор увидит эту строку:');
        if (note === null) return;
        try {
            await this.api('support.php?action=decline', {method: 'POST', body: {id, note}});
            this.toast('Отклонено', 'success');
            this.settingsSupport();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // ---- Мастер настройки ----

    /**
     * Карточка на «Обзоре»: чего не хватает для запуска и кто чего просит.
     * Пока всё закрыто — одна строка, а не блок на пол-экрана.
     */
    setupCard(d) {
        const s = d.setup || {};
        const sup = d.support || {};
        const waiting = (s.waiting || []);
        if (!waiting.length && !sup.pending) {
            return `<div class="card">
                <div class="card__title">Настройка и обратная связь</div>
                <p class="ok">Всё обязательное настроено${s.finished ? ', мастер пройден' : ''};
                   обращений на ревью нет.</p>
                <div class="flex flex--wrap" style="gap:8px">
                    <a href="#settings/setup" class="btn btn--outline btn--sm">Мастер настройки</a>
                    <a href="#settings/support" class="btn btn--outline btn--sm">Обращения</a>
                </div>
            </div>`;
        }
        return `<div class="card card--alert">
            <div class="card__title">Настройка и обратная связь${this.hint('setup')}</div>
            ${waiting.length ? `<p class="no">Не настроено: ${this.esc(waiting.join(', '))}
                <span class="muted">· пройдено ${s.done} из ${s.total}</span></p>` : ''}
            ${sup.pending ? `<p>Обращений ждут ревью: <strong>${sup.pending}</strong>
                ${sup.repo ? '' : '<span class="muted">· репозиторий для issue не указан</span>'}</p>` : ''}
            <div class="flex flex--wrap" style="gap:8px">
                ${waiting.length ? '<a href="#settings/setup" class="btn btn--primary btn--sm">Открыть мастер</a>' : ''}
                ${sup.pending ? '<a href="#settings/support" class="btn btn--outline btn--sm">Разобрать обращения</a>' : ''}
            </div>
        </div>`;
    },

    /**
     * Мастер спрашивает то же, что «Все параметры», но по одному делу за раз и
     * со ссылкой, где взять ключ. Состояние шага считает сервер — по факту
     * (есть ящик, отвечает провайдер, загружен логотип), а не по «поле заполнено».
     */
    async settingsSetup() {
        try {
            const d = await this.api('setup.php?action=state');
            this.setupData = d;
            const bar = Math.round(100 * (d.total ? d.done / d.total : 1));
            document.getElementById('adminBody').innerHTML = `
                <div class="card">
                    <div class="card__title">Мастер настройки${this.hint('setup')}</div>
                    <p>Всё, что нужно сервису для старта с нуля: ключи, ящик, логотип и люди.
                       Шаг считается пройденным, только когда он реально работает.</p>
                    <div class="setup-bar"><span style="width:${bar}%"></span></div>
                    <p class="muted">Пройдено ${d.done} из ${d.total}${d.state && d.state.done_at
                        ? ' · мастер завершён ' + this.fmtDate(d.state.done_at) : ''}</p>
                    <div class="flex flex--wrap" style="gap:8px">
                        <button class="btn btn--outline btn--sm" onclick="App.setupRestart()">Пройти заново</button>
                        ${d.ready && !(d.state || {}).done_at
                            ? '<button class="btn btn--primary btn--sm" onclick="App.setupFinish()">Всё готово</button>' : ''}
                    </div>
                </div>
                ${(d.steps || []).map((s, i) => this.setupStep(s, i, d)).join('')}
            `;
        } catch (err) { this.adminFail(err); }
    },

    setupStep(s, i, d) {
        const ok = (s.state || {}).status === 'ok';
        const open = !ok && !s.skipped && s.key === d.next;
        return `
            <div class="card setup-step ${ok ? 'setup-step--ok' : ''}" id="setupStep_${s.key}">
                <div class="flex flex--between flex--wrap" style="gap:8px;cursor:pointer"
                     onclick="App.setupToggle('${s.key}')">
                    <div class="card__title" style="margin:0">
                        ${ok ? '✅' : (s.skipped ? '⏭' : '▫️')} ${i + 1}. ${this.esc(s.title)}
                        ${s.optional ? '<span class="badge badge--draft">по желанию</span>' : ''}
                    </div>
                    <div class="muted">${this.esc((s.state || {}).note || '')}</div>
                </div>
                <div class="setup-step__body" id="setupBody_${s.key}" ${open ? '' : 'hidden'}>
                    <p class="muted">${this.esc(s.why)}</p>
                    ${(s.links || []).map(l => `
                        <p>${l.url.startsWith('#')
                            ? `<a href="${this.esc(l.url)}">${this.esc(l.label)}</a>`
                            : `<a href="${this.esc(l.url)}" target="_blank" rel="noopener">${this.esc(l.label)} ↗</a>`}
                           ${l.note ? '<br><span class="muted">' + this.esc(l.note) + '</span>' : ''}</p>`).join('')}
                    ${(s.fields || []).map(f => `
                        <div class="setting">
                            <div class="setting__label">
                                <label for="wz_${f.key}">${this.esc(f.label)}</label>
                                <div class="muted"><code>${f.key}</code>${f.hint ? ' · ' + this.esc(f.hint) : ''}</div>
                                ${this.settingLink(f)}
                            </div>
                            <div class="setting__field">${this.setupField(f)}</div>
                        </div>`).join('')}
                    ${s.key === 'trial' ? this.setupTrial(d) : ''}
                    <div class="flex flex--wrap" style="gap:8px;margin-top:10px">
                        ${(s.fields || []).length
                            ? `<button class="btn btn--primary btn--sm" onclick="App.setupSave('${s.key}')">Сохранить</button>` : ''}
                        ${s.test ? `<button class="btn btn--outline btn--sm" onclick="App.setupTest('${s.test}', this)">Проверить связь</button>` : ''}
                        ${ok ? '' : `<button class="btn btn--outline btn--sm" onclick="App.setupSkip('${s.key}')">Пропустить пока</button>`}
                    </div>
                    <div class="test-out" id="setupOut_${s.key}"></div>
                </div>
            </div>`;
    },

    setupField(f) {
        const id = 'wz_' + f.key;
        if (f.secret) return `<input type="password" id="${id}" placeholder="${f.filled
            ? 'задан ' + this.esc(f.tail) + ' — оставьте пустым, чтобы не менять' : 'не задан'}">`;
        if (f.type === 'bool') return `<select id="${id}">
            <option value="1" ${String(f.value) === '1' ? 'selected' : ''}>Да</option>
            <option value="0" ${String(f.value) !== '1' ? 'selected' : ''}>Нет</option></select>`;
        if (f.type === 'int') return `<input type="number" id="${id}" value="${this.esc(f.value)}">`;
        if (f.type === 'textarea') return `<textarea id="${id}" rows="4">${this.esc(f.value)}</textarea>`;
        return `<input type="text" id="${id}" value="${this.esc(f.value)}">`;
    },

    setupToggle(key) {
        const body = document.getElementById('setupBody_' + key);
        if (body) body.hidden = !body.hidden;
    },

    async setupSave(key) {
        const step = ((this.setupData || {}).steps || []).find(s => s.key === key);
        if (!step) return;
        const values = {};
        (step.fields || []).forEach(f => {
            const el = document.getElementById('wz_' + f.key);
            if (el) values[f.key] = el.value;
        });
        try {
            await this.api('setup.php?action=save', {method: 'POST', body: {step: key, values}});
            this.toast('Сохранено', 'success');
            this.settingsSetup();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async setupSkip(key) {
        try {
            await this.api('setup.php?action=skip', {method: 'POST', body: {step: key}});
            this.settingsSetup();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async setupRestart() {
        if (!confirm('Пройти мастер заново? Настройки останутся на месте — обнулится только отметка «пройдено».')) return;
        try {
            await this.api('setup.php?action=restart', {method: 'POST'});
            this.toast('Мастер сброшен', 'success');
            this.settingsSetup();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async setupFinish() {
        try {
            await this.api('setup.php?action=finish', {method: 'POST'});
            this.toast('Настройка завершена', 'success');
            this.settingsSetup();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    /**
     * Проверки связи — те же, что на вкладках «МойСклад» и «Нейросети».
     * Мастер не изобретает вторую проверку: он зовёт ту же ручку и печатает
     * ответ словами, а не «ok: true».
     */
    async setupTest(action, btn) {
        const key = btn.closest('.setup-step').id.replace('setupStep_', '');
        const out = document.getElementById('setupOut_' + key);
        btn.disabled = true;
        if (out) out.innerHTML = '<div class="loading">Проверяем…</div>';
        try {
            if (action === 'test_llm') {
                const lines = [];
                for (const p of ['yandex', 'openrouter']) {
                    try {
                        const r = await this.api('admin.php?action=test_llm', {method: 'POST', body: {provider: p}});
                        const d = r.result || {};
                        lines.push(`<p class="ok">${this.esc(p)} · ${this.esc(d.model || '')} ответил за ${d.ms} мс: «${this.esc(d.answer || '')}»</p>`);
                    } catch (err) {
                        lines.push(`<p class="no">${this.esc(p)}: ${this.esc(err.message)}</p>`);
                    }
                }
                if (out) out.innerHTML = lines.join('');
            } else {
                const r = await this.api('admin.php?action=' + action, {method: 'POST', body: {}});
                const perms = r.permissions || {};
                const names = {products: 'товары', counterparties: 'контрагенты', orders_read: 'заказы (чтение)',
                               orders_write: 'заказы (запись)', stock: 'остатки', invoices: 'счета', webhooks: 'вебхуки'};
                const list = Object.entries(perms).map(([k, v]) =>
                    `${v ? '✅' : '❌'} ${names[k] || k}`).join(' · ');
                if (out) out.innerHTML = Object.keys(perms).length
                    ? `<p class="${r.ok_any ? 'ok' : 'no'}">${this.esc(list)}</p>`
                    : `<p class="ok">${this.esc(r.message || 'Проверка прошла')}</p>`;
            }
        } catch (err) {
            if (out) out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`;
        }
        btn.disabled = false;
    },

    // ---- Проверочные КП и письмо ----

    /**
     * Последний шаг мастера: не «всё заполнено», а «вот КП и письмо, годится?».
     * Палец вниз — это не просто цифра: он уходит в обращения вместе с моделью,
     * которой это сделано, и панель тут же предлагает модель подороже.
     */
    setupTrial(d) {
        const st = d.state || {};
        const votes = st.trial_votes || {};
        const link = st.trial_counterparty_id
            ? '#mail/company/' + st.trial_counterparty_id
            : (st.trial_request_id ? '#mail/request/' + st.trial_request_id : '');
        return `
            <div class="note note--choice">
                ${st.trial_request_id ? `
                    <p>Проверочный запрос #${st.trial_request_id} создан — он стоит карточкой в «В работе».
                       <a href="${this.esc(link)}">Открыть карточку</a>: там соберите КП и черновик письма.</p>
                    ${this.trialVoteHtml(votes)}
                    <p class="muted" style="margin-top:8px">Нужен ещё один —
                       <a href="#mail/new/trial" onclick="App.trialAgain()">создать заново</a>.</p>
                ` : `
                    <p>Создайте проверочный запрос: сервис разберёт текст, подберёт позиции по каталогу
                       и даст собрать КП и письмо.</p>
                    <textarea id="trialText" rows="7">${this.esc(d.trial_text || '')}</textarea>
                    <button class="btn btn--primary btn--sm" style="margin-top:8px"
                            onclick="App.trialStart(this)">Создать проверочный запрос</button>
                `}
            </div>`;
    },

    /** Текст примера живёт в ответе сервера — в обработчик его вставлять нечего:
        перевод строки внутри onclick — это сломанный JS, а не многострочный текст. */
    trialAgain() {
        this.trialText = ((this.setupData || {}).trial_text) || '';
    },

    trialVoteHtml(votes) {
        const row = (what, label) => {
            const v = votes[what] || '';
            return `<div class="flex flex--wrap" style="gap:8px;align-items:center;margin-top:6px">
                <span>${label}:</span>
                <button class="btn btn--sm ${v === 'up' ? 'btn--primary' : 'btn--outline'}"
                        onclick="App.trialVote('${what}','up',this)">👍 хорошо</button>
                <button class="btn btn--sm ${v === 'down' ? 'btn--primary' : 'btn--outline'}"
                        onclick="App.trialVote('${what}','down',this)">👎 плохо</button>
                ${v ? '<span class="muted">оценено</span>' : ''}
            </div>`;
        };
        return `<div id="trialVotes">
            ${row('kp', 'Качество КП')}
            ${row('letter', 'Качество письма')}
            <div id="trialModels"></div>
        </div>`;
    },

    async trialStart(btn) {
        const text = (document.getElementById('trialText') || {}).value || '';
        btn.disabled = true; btn.textContent = 'Создаём…';
        try {
            const r = await this.api('requests.php?action=create', {method: 'POST', body: {text, trial: true}});
            this.trialWatch(r.hash);
            location.hash = r.hash || ('mail/request/' + r.id);
        } catch (err) {
            this.toast(err.message, 'error');
            btn.disabled = false; btn.textContent = 'Создать проверочный запрос';
        }
    },

    /** Карточку проверочного запроса помним до оценки — она переживает перезагрузку. */
    trialWatch(hash) {
        try { sessionStorage.setItem('trialCard', hash || ''); } catch { /* приватный режим */ }
    },

    trialForget() {
        try { sessionStorage.removeItem('trialCard'); } catch { /* */ }
    },

    /**
     * Полоска над карточкой, на которую увёл мастер: человек пришёл сюда
     * проверять качество, и возвращаться за оценкой ему незачем.
     */
    trialStrip(hash) {
        let want = '';
        try { want = sessionStorage.getItem('trialCard') || ''; } catch { /* */ }
        if (!want || want !== hash) return;
        const app = document.getElementById('app');
        if (!app || document.getElementById('trialStrip')) return;
        const box = document.createElement('div');
        box.id = 'trialStrip';
        box.className = 'card card--alert';
        box.innerHTML = `
            <div class="card__title">Проверка сервиса</div>
            <p>Соберите здесь КП и черновик письма, а потом скажите, годится ли качество.</p>
            ${this.trialVoteHtml(((this.setupData || {}).state || {}).trial_votes || {})}
            <div class="flex flex--wrap" style="gap:8px;margin-top:8px">
                <a class="btn btn--outline btn--sm" href="#settings/setup">К мастеру настройки</a>
                <button class="btn btn--outline btn--sm" onclick="App.trialForget();document.getElementById('trialStrip').remove()">Убрать</button>
            </div>`;
        app.prepend(box);
    },

    async trialVote(what, vote, btn) {
        const comment = vote === 'down'
            ? (prompt('Что именно не так? Строка уйдёт в обращения поддержки:') || '')
            : '';
        if (btn) btn.disabled = true;
        try {
            const r = await this.api('setup.php?action=vote', {method: 'POST', body: {what, vote, comment}});
            this.setupData = Object.assign(this.setupData || {}, r);
            this.toast(vote === 'up' ? 'Спасибо!' : 'Записали — подберём модель получше', 'success');
            const box = document.getElementById('trialModels');
            if (vote === 'down' && box) box.innerHTML = this.trialModelsHtml(r.pricier || [], r.current || {});
            else if (box) box.innerHTML = '';
        } catch (err) { this.toast(err.message, 'error'); }
        if (btn) btn.disabled = false;
    },

    trialModelsHtml(models, current) {
        if (!models.length) return `<p class="muted" style="margin-top:8px">Моделей дороже нынешней
            (<code>${this.esc((current.provider || '') + ':' + (current.model || ''))}</code>) в каталоге нет.
            Обновите каталог OpenRouter или добавьте ключ второго провайдера в «Настройках → Нейросети».</p>`;
        return `
            <div style="margin-top:10px">
                <p>Сейчас работает <code>${this.esc((current.provider || '') + ':' + (current.model || ''))}</code>.
                   Возьмите модель подороже — она отвечает лучше:</p>
                <div class="flex flex--wrap" style="gap:8px">
                    ${models.map(m => `
                        <button class="btn btn--outline btn--sm" onclick="App.trialPickModel('${this.jsStr(m.spec)}', this)"
                                title="${this.esc(m.group || '')}">${this.esc(m.label)}</button>`).join('')}
                </div>
                <p class="muted" style="margin-top:6px">Выбранная станет моделью по умолчанию —
                   её можно поменять в «Настройках → Нейросети».</p>
            </div>`;
    },

    async trialPickModel(spec, btn) {
        btn.disabled = true;
        try {
            const r = await this.api('setup.php?action=model', {method: 'POST', body: {spec}});
            this.toast('Теперь отвечает ' + (r.current || {}).model, 'success');
            const box = document.getElementById('trialModels');
            if (box) box.innerHTML = `<p class="ok" style="margin-top:8px">Модель по умолчанию:
                <code>${this.esc(spec)}</code>. Соберите КП и письмо ещё раз и сравните.</p>`;
        } catch (err) { this.toast(err.message, 'error'); btn.disabled = false; }
    },
});

document.addEventListener('DOMContentLoaded', () => App.init());
