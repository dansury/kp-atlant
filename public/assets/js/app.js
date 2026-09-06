/**
 * Atlant Armour KP — SPA client.
 * Vanilla JS, hash-based routing, fetch API.
 */
const App = {
    manager: null,
    pollTimer: null,
    unread: 0,

    // API helper
    async api(url, opts = {}) {
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
            this.startPolling();
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
        // One «Настройки» for everything: the split between it and a separate
        // «Админ» meant two menu items with the same name and no way to guess
        // which one held the setting you were after
        nav.innerHTML = `
            <a href="#requests" data-page="requests">Запросы</a>
            <a href="#new" data-page="new">+ Новый</a>
            <a href="#counterparties" data-page="counterparties">Компании</a>
            <a href="#mail" data-page="mail">Почта<span id="mailBadge"></span></a>
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
            } catch {}
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

    // Router
    route() {
        const hash = location.hash.slice(1) || 'requests';
        const [page, ...params] = hash.split('/');
        document.querySelectorAll('.header__nav a').forEach(a => {
            a.classList.toggle('active', a.dataset.page === (page === 'admin' ? 'settings' : page));
        });
        const app = document.getElementById('app');
        app.innerHTML = '<div class="loading">Загрузка...</div>';
        this.onTabVisible = null; // only the company card re-syncs on focus
        this.closeModal();

        const open = () => {
            switch (page) {
                case 'requests': return this.pageRequests();
                case 'new': return this.pageNewRequest();
                case 'request': return this.pageRequest(params[0]);
                case 'proposal': return this.pageProposal(params[0]);
                case 'counterparties': return this.pageCounterparties();
                case 'counterparty': return this.pageCounterparty(params[0]);
                case 'notifications': return this.pageNotifications();
                case 'settings': return this.pageSettings(params[0]);
                case 'mail': return this.pageMail(params[0]);
                // Bookmarks and links from before the merge still work
                case 'admin': location.replace('#settings/' + (params[0] || '')); return;
                default: return this.pageRequests();
            }
        };
        // Whatever the page throws, the user sees the reason and a retry button —
        // never a spinner that spins forever
        Promise.resolve().then(open).catch(err => this.pageFail(err));
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

    // === Pages ===

    // Requests list
    async pageRequests() {
        const data = await this.api('requests.php?action=list');
        const statusBadge = s => {
            const map = {new:'new',processing:'draft',draft_ready:'draft',sent:'sent',ordered:'confirmed',closed:'sent'};
            const labels = {new:'Новый',processing:'Обработка',draft_ready:'Черновик',sent:'Отправлен',ordered:'Заказ создан',closed:'Закрыт'};
            return `<span class="badge badge--${map[s]||'new'}">${labels[s]||s}</span>`;
        };
        document.getElementById('app').innerHTML = `
            <div class="flex flex--between" style="margin-bottom:16px">
                <h2>Запросы и заказы</h2>
                <a href="#new" class="btn btn--primary">+ Новый запрос</a>
            </div>
            <div class="card">
                <table class="table">
                    <thead><tr><th class="num">#</th><th>Контрагент</th><th>Тип</th><th>Источник</th><th>Позиций</th><th>Менеджер</th><th>Статус</th><th>Дата</th></tr></thead>
                    <tbody>
                        ${data.items.map(r => `
                            <tr class="${r.answer_state && r.answer_state.unanswered ? 'row--unanswered row--' + r.answer_state.level : ''}"
                                style="cursor:pointer" onclick="location.hash='request/${r.id}'">
                                <td class="num">${r.id}</td>
                                <td>${this.esc(r.counterparty_name) || '—'} ${this.answerBadge(r.answer_state)}</td>
                                <td>${r.category ? this.categoryBadge(r.category, App.categoryLabels[r.category]) : this.typeBadge(r.type)}</td>
                                <td>${r.source === 'email' ? '📧 Email' : '📋 Ручной'}${r.attachments_count > 0 ? ' 📎' + r.attachments_count : ''}</td>
                                <td class="num">${r.items_count || 0}</td>
                                <td>${this.esc(r.manager_name) || '<em>пул</em>'}</td>
                                <td>${statusBadge(r.status)}</td>
                                <td>${this.fmtDate(r.created_at, false)}</td>
                            </tr>
                        `).join('')}
                        ${data.items.length === 0 ? '<tr><td colspan="8" style="text-align:center;color:var(--text-muted)">Нет запросов</td></tr>' : ''}
                    </tbody>
                </table>
            </div>
        `;
    },

    // New request (manual paste, US2)
    pageNewRequest() {
        document.getElementById('app').innerHTML = `
            <h2 style="margin-bottom:16px">Новый запрос</h2>
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
                location.hash = `request/${r.id}`;
            } catch (err) {
                App.toast(err.message, 'error');
                btn.disabled = false; btn.textContent = 'Создать запрос и сформировать КП';
            }
        };
    },

    // Single request view
    async pageRequest(id) {
        const req = await this.api(`requests.php?action=get&id=${id}`);
        const app = document.getElementById('app');
        const isOrder = req.type === 'order';
        const parsed = req.parsed || {};

        const actions = isOrder
            ? `<button class="btn btn--primary" id="orderBtn" onclick="App.createOrderFromRequest(${req.id})">Создать заказ в МойСклад</button>`
            : (['new','processing'].includes(req.status)
                ? `<button class="btn btn--primary" id="genBtn" onclick="App.generateKP(${req.id})">Сформировать КП</button>` : '');

        app.innerHTML = `
            <div class="flex flex--between" style="margin-bottom:16px">
                <h2>${isOrder ? 'Заказ' : 'Запрос'} #${req.id} ${this.typeBadge(req.type)}</h2>
                <div class="flex">
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
                        ? `<a href="#counterparty/${req.counterparty_id}">${this.esc(req.counterparty_name) || 'без названия'}</a>`
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

    renderMatchedItems(requestId, items) {
        const card = document.getElementById('matchCard');
        if (!card) return;
        card.innerHTML = `
            <div class="card__title">Подходящие позиции</div>
            <p class="muted">Позиции каталога, из которых соберётся КП. Начните печатать название —
               подскажет локальная база товаров.</p>
            <div id="matchRows">${items.map(i => this.matchRow(i)).join('')}</div>
            ${items.length ? '' : '<p class="muted" id="matchEmpty">Пока пусто — добавьте позицию или подберите по каталогу.</p>'}
            <div class="flex flex--wrap" style="margin-top:10px">
                <button class="btn btn--outline btn--sm" onclick="App.addMatchRow()">+ Позиция</button>
                <button class="btn btn--outline btn--sm" onclick="App.rematchItems(${requestId}, false)">Подобрать по каталогу</button>
                <button class="btn btn--outline btn--sm" onclick="App.rematchItems(${requestId}, true)"
                        title="Нейросеть сначала приведёт формулировки клиента к нашим названиям — это один запрос к модели">Подобрать нейросетью</button>
                <button class="btn btn--primary btn--sm" onclick="App.saveMatchedItems(${requestId})">Сохранить</button>
            </div>
            <div id="matchTotal" class="muted" style="margin-top:8px"></div>
        `;
        this.updateMatchTotal();
    },

    matchRow(i = {}) {
        const conf = i.match_confidence ? Math.round(i.match_confidence * 100) : null;
        return `
            <div class="match-row" data-match-row>
                <input type="hidden" data-field="id" value="${this.esc(i.id || '')}">
                <input type="hidden" data-field="raw_name" value="${this.esc(i.raw_name || '')}">
                <input type="hidden" data-field="moysklad_product_id" value="${this.esc(i.moysklad_product_id || '')}">
                <input type="hidden" data-field="article" value="${this.esc(i.article || '')}">
                <input type="hidden" data-field="stock" value="${i.stock ?? ''}">
                <div class="match-row__name">
                    ${i.raw_name ? `<div class="muted">из письма: ${this.esc(i.raw_name)}${conf !== null ? ` · совпадение ${conf}%` : ''}</div>` : ''}
                    <input type="text" data-field="product_name" autocomplete="off" placeholder="Название позиции из каталога"
                           value="${this.esc(i.product_name || '')}" oninput="App.matchSuggest(this)" onblur="App.hideSuggest(this)">
                    <div class="suggest" hidden></div>
                    ${(i.variants || []).length ? `<div class="muted">ещё похожие:
                        ${i.variants.map(v => `<a onclick="App.pickVariant(this, '${this.jsStr(JSON.stringify(v))}')">${this.esc(v.name)}</a>`).join(' · ')}</div>` : ''}
                </div>
                <input type="number" step="0.01" min="0" data-field="quantity" value="${i.quantity ?? 1}"
                       placeholder="Кол-во" title="Количество" oninput="App.updateMatchTotal()">
                <input type="text" data-field="unit" value="${this.esc(i.unit || 'шт.')}" placeholder="Ед." title="Единица измерения">
                <input type="number" step="0.01" min="0" data-field="price" value="${i.price ?? 0}"
                       placeholder="Цена" title="Цена за единицу" oninput="App.updateMatchTotal()">
                <input type="text" data-field="notes" value="${this.esc(i.notes || '')}" placeholder="Примечание">
                <label title="Позиция подтверждена менеджером — автоподбор её больше не трогает">
                    <input type="checkbox" data-field="is_confirmed" ${i.is_confirmed ? 'checked' : ''}> ок
                </label>
                <button class="btn btn--outline btn--sm" title="Убрать строку"
                        onclick="this.closest('[data-match-row]').remove(); App.updateMatchTotal()">×</button>
            </div>`;
    },

    addMatchRow() {
        const box = document.getElementById('matchRows');
        if (!box) return;
        const empty = document.getElementById('matchEmpty');
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
                        unit: p.unit || 'шт.', price: p.price || 0, stock: p.stock ?? '',
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
        el.closest('.suggest').hidden = true;
        this.updateMatchTotal();
    },

    pickVariant(el, json) {
        const v = JSON.parse(json);
        this.pickSuggest(el, JSON.stringify({
            moysklad_id: v.moysklad_id, name: v.name, article: v.article || '',
            unit: v.unit || 'шт.', price: v.price || 0, stock: v.stock ?? '',
        }));
    },

    collectMatchedItems() {
        return [...document.querySelectorAll('[data-match-row]')].map(row => {
            const out = {};
            row.querySelectorAll('[data-field]').forEach(el => {
                out[el.dataset.field] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
            });
            out.quantity = parseFloat(out.quantity) || 0;
            out.price = parseFloat(out.price) || 0;
            return out;
        });
    },

    updateMatchTotal() {
        const el = document.getElementById('matchTotal');
        if (!el) return;
        const rows = this.collectMatchedItems();
        const total = rows.reduce((s, r) => s + r.price * r.quantity, 0);
        const noPrice = rows.filter(r => !r.price).length;
        el.innerHTML = rows.length
            ? `Позиций: ${rows.length} · сумма по каталогу: ${this.fmtMoney(total)}`
              + (noPrice ? ` · без цены: ${noPrice}` : '')
            : '';
    },

    async saveMatchedItems(requestId, silent = false) {
        const res = await this.api(`requests.php?action=items_save&id=${requestId}`, {
            method: 'POST', body: {items: this.collectMatchedItems()},
        });
        if (!silent) this.toast('Позиции сохранены', 'success');
        this.renderMatchedItems(requestId, res.items || []);
        return res.items;
    },

    async rematchItems(requestId, smart) {
        // What the manager has already typed must survive the re-match
        const pending = this.collectMatchedItems();
        const card = document.getElementById('matchCard');
        card.innerHTML = `<div class="card__title">Подходящие позиции</div>
                          <div class="loading">${smart ? 'Спрашиваем нейросеть и подбираем...' : 'Подбираем по каталогу...'}</div>`;
        try {
            await this.api(`requests.php?action=items_save&id=${requestId}`, {method: 'POST', body: {items: pending}});
            const res = await this.api(`requests.php?action=items_rematch&id=${requestId}&smart=${smart ? 1 : 0}`, {method: 'POST'});
            this.renderMatchedItems(requestId, res.items || []);
            this.toast('Подбор обновлён — подтверждённые строки не тронуты', 'success');
        } catch (err) {
            this.toast(err.message, 'error');
            this.renderMatchedItems(requestId, []);
        }
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
                        <a href="/api/requests.php?action=attachment&id=${a.id}" target="_blank">📎 ${this.esc(a.filename)}</a>
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
                        <a href="#proposal/${p.id}">КП ${this.esc(p.number) || '#' + p.id}</a>
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
            // looks on screen, not as it was last saved
            if (document.querySelector('[data-match-row]')) {
                await this.saveMatchedItems(requestId, true);
            }
            const p = await this.api(`proposals.php?action=generate&request_id=${requestId}`, {method:'POST'});
            this.toast('КП сформировано', 'success');
            location.hash = `proposal/${p.id}`;
        } catch (err) {
            this.toast(err.message, 'error');
            if (btn) { btn.disabled = false; btn.textContent = 'Сформировать КП'; }
        }
    },

    // Assign request to current manager
    async assignRequest(id) {
        await this.api(`requests.php?action=assign&id=${id}`, {method:'POST'});
        this.toast('Запрос взят в работу', 'success');
        location.hash = `request/${id}`;
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
            <div class="flex flex--between" style="margin-bottom:16px">
                <h2>КП #${this.esc(proposal.number) || id}</h2>
                <div class="flex">
                    <button class="btn btn--outline" onclick="App.refreshPreview(${id})">Обновить PDF</button>
                    <button class="btn btn--primary" onclick="App.confirmAndSend(${id})">Подтвердить и отправить</button>
                </div>
            </div>
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
                <button class="btn btn--primary" onclick="App.sendProposal(${id})">Отправить КП</button>
            </div>
        `;
        // Thumbnails load per position, so a KP with many photos still opens fast
        items.forEach(it => { if (it.moysklad_product_id) this.loadItemPhotos(it.id); });
    },

    // One product card in the editor: texts, photo count, "от" price flags
    itemCardEditor(it) {
        const photos = (() => { try { return JSON.parse(it.images_json || '[]').length; } catch (e) { return 0; } })();
        return `
            <div class="item-card" data-item-id="${it.id}" style="border:1px solid #ddd;border-radius:6px;padding:10px;margin-bottom:10px">
                <div class="flex flex--between">
                    <strong>${this.esc(it.product_name)}</strong>
                    <span class="note">${photos} фото</span>
                </div>
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
                <div class="flex">
                    <label><input type="checkbox" data-field="show_images" ${it.show_images != 0 ? 'checked' : ''}> Фото</label>
                    <label><input type="checkbox" data-field="price_from" ${it.price_from == 1 ? 'checked' : ''}> Цена «от»</label>
                    <label><input type="checkbox" data-field="qty_from" ${it.qty_from == 1 ? 'checked' : ''}> Кол-во «от»</label>
                </div>
                <div class="item-card__photos" data-photos>
                    ${it.moysklad_product_id ? '<div class="muted">Фотографии загружаются...</div>' : ''}
                </div>
            </div>`;
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
            vat_rate: parseInt(document.getElementById('vatRate').value),
            execution_days: parseInt(document.getElementById('execDays').value),
            show_vat_total: parseInt(document.getElementById('showVat').value),
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

    // Confirm and prepare for sending
    async confirmAndSend(id) {
        try {
            await this.api(`proposals.php?action=confirm&id=${id}`, {method:'POST'});
            this.toast('КП подтверждено', 'success');
            this.refreshPreview(id);
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Send proposal email
    async sendProposal(id) {
        const to = document.getElementById('sendTo').value.trim();
        if (!to) return this.toast('Укажите email получателя', 'error');
        try {
            await this.api(`proposals.php?action=send&id=${id}`, {method:'POST', body: {
                to, subject: document.getElementById('sendSubject').value
            }});
            this.toast('КП отправлено!', 'success');
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Counterparties list — unanswered first (FR-038)
    async pageCounterparties() {
        document.getElementById('app').innerHTML = `
            <h2 style="margin-bottom:16px">Компании</h2>
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
                        style="cursor:pointer" onclick="location.hash='counterparty/${c.id}'">
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

    // Company card: chat, contacts, orders, invoices (US9, US10)
    async pageCounterparty(id) {
        document.getElementById('app').innerHTML = `<div class="loading">Загрузка...</div>`;
        try {
            const cp = await this.api(`counterparties.php?action=get&id=${id}`);
            document.getElementById('app').innerHTML = `
                <div class="flex flex--between" style="margin-bottom:16px">
                    <h2>${this.esc(cp.name)} ${this.answerBadge(cp.answer_state)}</h2>
                    <div class="flex">
                        <button class="btn btn--outline" id="syncBtn" onclick="App.syncCompany(${cp.id})">Обновить из МойСклад</button>
                        ${cp.moysklad_id ? `<a class="btn btn--outline" target="_blank"
                            href="https://online.moysklad.ru/app/#counterparty/edit?id=${cp.moysklad_id}">Открыть в МойСклад ↗</a>` : ''}
                    </div>
                </div>
                <div class="grid grid--chat">
                    <div>
                        <div class="card">
                            <div class="card__title">История компании</div>
                            <div id="chatFeed" class="chat"><div class="loading">Загрузка...</div></div>
                            <div class="chat__composer">
                                <textarea id="noteText" rows="2" placeholder="Заметка для коллег (клиенту не уходит)..."></textarea>
                                <button class="btn btn--outline" onclick="App.addNote(${cp.id})">Добавить заметку</button>
                            </div>
                        </div>
                    </div>
                    <div id="companySide">${this.companySide(cp)}</div>
                </div>
            `;
            this.loadChat(cp.id);

            // Returning from the MoySklad tab refreshes the card (FR-030)
            this.onTabVisible = () => {
                if (location.hash === `#counterparty/${cp.id}`) this.syncCompany(cp.id, true);
            };
            this.syncCompany(cp.id, true);
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

    // Chat feed (FR-033)
    async loadChat(id) {
        try {
            const data = await this.api(`counterparties.php?action=chat&id=${id}&limit=50`);
            const feed = document.getElementById('chatFeed');
            if (!feed) return;

            feed.innerHTML = data.items.length ? data.items.map(m => {
                const cls = m.direction === 'in' ? 'msg--in' : (m.direction === 'out' ? 'msg--out' : 'msg--note');
                const who = m.direction === 'in'
                    ? (this.esc(m.email_from) || 'клиент')
                    : (m.direction === 'out' ? 'мы → ' + (this.esc(m.email_to) || 'клиент') : (this.esc(m.manager_name) || 'система'));
                const files = (m.attachments || []).map(a =>
                    `<a class="chip" target="_blank" href="/api/requests.php?action=attachment&id=${a.id}">📎 ${this.esc(a.filename)}</a>`
                ).join('');
                return `
                    <div class="msg ${cls} ${m.event_type ? 'msg--system' : ''}">
                        <div class="msg__head">
                            <span>${who}</span>
                            <span class="muted">${this.fmtDate(m.created_at)}</span>
                        </div>
                        ${m.subject ? `<div class="msg__subject">${this.esc(m.subject)}</div>` : ''}
                        <div class="msg__body">${this.esc(m.body)}</div>
                        ${files ? `<div class="msg__files">${files}</div>` : ''}
                        ${m.request_id ? `<a class="muted" href="#request/${m.request_id}">→ запрос #${m.request_id}</a>` : ''}
                    </div>`;
            }).join('') : '<p class="muted">Переписки пока нет</p>';

            feed.scrollTop = feed.scrollHeight;
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
            if (side) side.innerHTML = this.companySide(cp);
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
            const m = location.hash.match(/counterparty\/(\d+)/);
            if (m) this.syncCompany(Number(m[1]), true);
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Split a contact into its own company card (FR-037)
    async splitContact(id, email) {
        if (!confirm(`Отделить ${email} в отдельную карточку компании?`)) return;
        try {
            const r = await this.api(`counterparties.php?action=split&id=${id}`, {method: 'POST', body: {email}});
            this.toast('Карточка разделена', 'success');
            location.hash = `counterparty/${r.id}`;
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // Notifications
    async pageNotifications() {
        const data = await this.api('notifications.php?action=poll');
        document.getElementById('app').innerHTML = `
            <h2 style="margin-bottom:16px">Уведомления</h2>
            <div class="card">
                ${data.items.length ? data.items.map(n => `
                    <div class="flex flex--between" style="padding:10px 0;border-bottom:1px solid var(--border)">
                        <div>
                            <strong>${n.title}</strong>
                            ${n.body ? `<p style="color:var(--text-muted);font-size:13px">${n.body}</p>` : ''}
                            <small style="color:var(--text-muted)">${new Date(n.created_at).toLocaleString('ru-RU')}</small>
                        </div>
                        <div class="flex">
                            ${n.ref_type === 'request' ? `<a href="#request/${n.ref_id}" class="btn btn--sm btn--outline">Открыть</a>` : ''}
                            <button class="btn btn--sm btn--outline" onclick="App.readNotif(${n.id}, this)">✓</button>
                        </div>
                    </div>
                `).join('') : '<p style="color:var(--text-muted);text-align:center;padding:20px">Нет новых уведомлений</p>'}
            </div>
        `;
    },

    async readNotif(id, btn) {
        await this.api(`notifications.php?action=read&id=${id}`, {method:'POST'});
        btn.closest('.flex').parentElement.remove();
    },

    // ==== Settings: one menu item, tabs inside (modules 004 and 008) ====
    // There used to be two «Настройки» in the header — a personal one and an
    // admin one — and no way to tell from the name which held what. Everything
    // lives here now; the tabs an ordinary manager may not touch are hidden.

    settingsTabs() {
        const admin = !!(this.manager && this.manager.is_admin);
        return [
            ['overview',   'Обзор',           true],
            ['catalog',    'Каталог товаров', false],
            ['moysklad',   'МойСклад',        true],
            ['llm',        'Нейросети',       true],
            ['mail',       'Почта',           true],
            ['processing', 'Обработка писем', true],
            ['kp',         'Оформление КП',   true],
            ['knowledge',  'База знаний',     true],
            ['managers',   'Менеджеры',       true],
            ['prompts',    'Промпты',         true],
            ['device',     'Это устройство',  false],
            ['all',        'Все параметры',   true],
            ['logs',       'Логи',            true],
        ].filter(([, , adminOnly]) => admin || !adminOnly);
    },

    pageSettings(tab) {
        const tabs = this.settingsTabs();
        if (!tabs.some(([k]) => k === tab)) tab = tabs[0][0];
        document.getElementById('app').innerHTML = `
            <h2 style="margin-bottom:12px">Настройки</h2>
            <div class="tabs">
                ${tabs.map(([k, l]) => `<a href="#settings/${k}" class="tab ${tab === k ? 'tab--active' : ''}">${this.esc(l)}</a>`).join('')}
            </div>
            <div id="adminBody"><div class="loading">Загрузка...</div></div>
        `;
        const render = {
            overview:   () => this.adminOverview(),
            catalog:    () => this.settingsCatalog(),
            moysklad:   () => this.settingsMoysklad(),
            llm:        () => this.adminLlm(),
            mail:       () => this.adminMail(),
            processing: () => this.settingsProcessing(),
            kp:         () => this.settingsKp(),
            knowledge:  () => this.adminKnowledge(),
            managers:   () => this.adminManagers(),
            prompts:    () => this.adminPrompts(),
            device:     () => this.settingsDevice(),
            all:        () => this.adminSettings(),
            logs:       () => this.adminLogs(),
        }[tab] || (() => this.settingsCatalog());
        render();
    },

    // ---- Catalog: the local product base the KP and the matcher read ----

    async settingsCatalog() {
        document.getElementById('adminBody').innerHTML = `
            <div class="card" id="catalogStats"><div class="loading">Считаем каталог...</div></div>
            <div class="card">
                <div class="card__title">Обновить из МойСклад</div>
                <p class="muted">Тянет номенклатуру, цены и остатки через API. Нужен рабочий токен.</p>
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
                        <label>Колонка цены</label>
                        <input type="text" id="catalogPriceCol" placeholder="Цена: Опт безнал">
                        <div class="muted">Пусто — берётся «Цена: Опт безнал», затем «Опт», затем «Розница»</div>
                    </div>
                </div>
                <label style="display:block;margin-bottom:10px">
                    <input type="checkbox" id="catalogPrune">
                    Удалить из базы позиции, которых нет в файле (те, что уже попали в КП, останутся)
                </label>
                <button class="btn btn--primary" id="catalogImportBtn" onclick="App.importCatalog()">Загрузить файл</button>
                <div id="catalogImportResult" style="margin-top:12px"></div>
            </div>
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
        try {
            const s = await this.api('admin.php?action=settings');
            const col = (s.items || []).find(i => i.key === 'CATALOG_PRICE_COLUMN');
            const el = document.getElementById('catalogPriceCol');
            if (el && col) el.value = col.value || '';
        } catch { /* a plain manager cannot read settings — the field just stays empty */ }
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

    async importCatalog() {
        const input = document.getElementById('catalogFile');
        const out = document.getElementById('catalogImportResult');
        const btn = document.getElementById('catalogImportBtn');
        if (!input || !input.files.length) { this.toast('Выберите файл выгрузки', 'error'); return; }

        // The price column is a stored setting, so the next import repeats this choice
        const col = (document.getElementById('catalogPriceCol') || {}).value;
        if (col !== undefined && this.manager.is_admin) {
            try {
                await this.api('admin.php?action=settings', {method: 'PUT', body: {values: {CATALOG_PRICE_COLUMN: col}}});
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
                ${r.price_column ? `<p class="muted">Цены взяты из колонки «${this.esc(r.price_column)}».</p>` : ''}
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
            const d = await this.api('products.php?action=search&limit=15&q=' + encodeURIComponent(q));
            out.innerHTML = d.items.length ? `
                ${d.from_api ? '<p class="muted">В локальной базе не нашлось — показан ответ МойСклад.</p>' : ''}
                <table class="table">
                    <thead><tr><th>Наименование</th><th>Артикул</th><th class="price">Цена</th><th class="num">Остаток</th></tr></thead>
                    <tbody>${d.items.map(i => `<tr>
                        <td>${this.esc(i.name)}${i.characteristics ? `<div class="muted">${this.esc(i.characteristics)}</div>` : ''}</td>
                        <td class="muted">${this.esc(i.article || i.code || '')}</td>
                        <td class="price">${this.fmtMoney(i.price)}</td>
                        <td class="num">${i.stock ?? '—'}</td>
                    </tr>`).join('')}</tbody>
                </table>` : '<p class="muted">Ничего не найдено.</p>';
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
        `;
        this.loadMoyskladSettings();
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

    // ---- KP look: default texts, photos and upsell ----

    async settingsKp() {
        try {
            const d = await this.api('settings.php?action=general');
            document.getElementById('adminBody').innerHTML = `
                <div class="card">
                    <div class="card__title">Умолчания коммерческого предложения</div>
                    <div class="grid grid--3">
                        <div class="form-group"><label>НДС по умолчанию, %</label>
                            <input type="number" id="kpVat" value="${this.esc(d.default_vat_rate || 5)}"></div>
                        <div class="form-group"><label>Срок исполнения, дней</label>
                            <input type="number" id="kpExec" value="${this.esc(d.default_execution_days || 30)}"></div>
                        <div class="form-group"><label>Срок действия КП, дней</label>
                            <input type="number" id="kpValid" value="${this.esc(d.default_validity_days || 14)}"></div>
                        <div class="form-group"><label>Фото на позицию, максимум</label>
                            <input type="number" id="kpMaxImages" min="0" max="12" value="${this.esc(d.kp_max_images_per_item || 5)}"></div>
                        <div class="form-group"><label>Папка модулей в МойСклад</label>
                            <input type="text" id="kpAddonCategory" value="${this.esc(d.addon_category || '')}"></div>
                    </div>
                    <div class="form-group"><label>Условия поставки</label>
                        <textarea id="kpConditions" rows="2">${this.esc(d.default_conditions_text || '')}</textarea></div>
                    <div class="form-group"><label>Гарантия</label>
                        <textarea id="kpWarranty" rows="2">${this.esc(d.default_warranty_text || '')}</textarea></div>
                    <div class="form-group"><label>Оговорка под фотографиями</label>
                        <textarea id="kpImagesNote" rows="2">${this.esc(d.kp_images_note || '')}</textarea></div>
                    <button class="btn btn--primary" onclick="App.saveKpSettings()">Сохранить</button>
                </div>
            `;
        } catch (err) { this.adminFail(err); }
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
                ${d.diag ? `<p class="muted">Токен: ${this.esc(source)}, длина ${d.diag.token_len}, конец «…${this.esc(d.diag.token_tail)}»${Object.entries(d.diag.probes || {}).map(([k, v]) => ` · ${k}: HTTP ${v.code}`).join('')}</p>` : ''}
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

    async pageMail(id) {
        if (id) return this.pageMailMessage(id);
        const state = this.mailState = this.mailState || {direction: '', mailbox_id: '', q: '', offset: 0};
        const qs = new URLSearchParams({
            limit: 50, offset: state.offset,
            ...(state.direction ? {direction: state.direction} : {}),
            ...(state.mailbox_id ? {mailbox_id: state.mailbox_id} : {}),
            ...(state.q ? {q: state.q} : {}),
            ...(state.unread ? {unread: 1} : {}),
        });
        const d = await this.api('mail.php?action=list&' + qs);
        const tab = (key, label) => `<button class="btn btn--sm ${state.direction === key && !state.unread ? 'btn--primary' : 'btn--outline'}"
            onclick="App.mailFilter({direction:'${key}',unread:0})">${label}</button>`;

        document.getElementById('app').innerHTML = `
            <div class="flex flex--between" style="margin-bottom:16px">
                <h2>Почта</h2>
                <div class="flex">
                    <button class="btn btn--outline" onclick="App.mailSync()">⟳ Синхронизировать</button>
                    <button class="btn btn--primary" onclick="App.mailCompose()">✉ Написать</button>
                </div>
            </div>
            ${(d.mailboxes || []).filter(b => b.last_error).map(b => `
                <div class="card card--alert"><strong>${this.esc(b.name)}</strong>: ${this.esc(b.last_error)}</div>
            `).join('')}
            ${!d.mailboxes || !d.mailboxes.length ? `<div class="card">Почтовые ящики ещё не настроены.
                ${this.manager.is_admin ? '<a href="#settings/mail">Добавить ящик</a>' : 'Обратитесь к администратору.'}</div>` : ''}
            <div class="card card--inline">
                ${tab('', 'Все')}${tab('in', 'Входящие')}${tab('out', 'Исходящие')}
                <button class="btn btn--sm ${state.unread ? 'btn--primary' : 'btn--outline'}" onclick="App.mailFilter({unread:1,direction:'in'})">Непрочитанные</button>
                <select id="mailBox" onchange="App.mailFilter({mailbox_id:this.value})">
                    <option value="">Все ящики</option>
                    ${(d.mailboxes || []).map(b => `<option value="${b.id}" ${String(state.mailbox_id) === String(b.id) ? 'selected' : ''}>${this.esc(b.name)}</option>`).join('')}
                </select>
                <input type="text" id="mailQ" placeholder="Поиск по теме, адресу и тексту" value="${this.esc(state.q)}"
                       style="max-width:320px" onkeydown="if(event.key==='Enter')App.mailFilter({q:this.value})">
                <span class="muted">Всего: ${d.total}</span>
            </div>
            <div class="card">
                <table class="table">
                    <thead><tr><th></th><th>Тема</th><th>Кто</th><th>Компания</th><th>Ящик</th><th>Дата</th></tr></thead>
                    <tbody>
                        ${d.items.map(m => `
                            <tr style="cursor:pointer" onclick="location.hash='mail/${m.id}'" class="${m.direction === 'in' && !m.is_read ? 'row--unread' : ''}">
                                <td>${m.direction === 'in' ? '📥' : '📤'}${m.has_attachment ? ' 📎' : ''}</td>
                                <td>${this.esc(m.subject) || '<em>без темы</em>'}
                                    <div class="muted">${this.esc((m.preview || '').slice(0, 110))}</div></td>
                                <td>${this.esc(m.direction === 'in' ? (m.from_email || '') : (m.to_emails || ''))}</td>
                                <td>${m.counterparty_id ? `<a href="#counterparty/${m.counterparty_id}" onclick="event.stopPropagation()">${this.esc(m.counterparty_name)}</a>` : '—'}</td>
                                <td class="muted">${this.esc(m.mailbox_name) || '—'}</td>
                                <td class="muted">${this.fmtDate(m.date_at)}</td>
                            </tr>
                        `).join('')}
                        ${d.items.length === 0 ? '<tr><td colspan="6" style="text-align:center;color:var(--text-muted)">Писем нет</td></tr>' : ''}
                    </tbody>
                </table>
                <div class="flex flex--between" style="margin-top:12px">
                    <button class="btn btn--sm btn--outline" ${state.offset === 0 ? 'disabled' : ''} onclick="App.mailPage(-1)">← Новее</button>
                    <button class="btn btn--sm btn--outline" ${state.offset + 50 >= d.total ? 'disabled' : ''} onclick="App.mailPage(1)">Старее →</button>
                </div>
            </div>
        `;
    },

    mailFilter(patch) {
        this.mailState = Object.assign(this.mailState || {}, patch, {offset: 0});
        this.pageMail();
    },

    mailPage(dir) {
        this.mailState.offset = Math.max(0, (this.mailState.offset || 0) + dir * 50);
        this.pageMail();
    },

    async mailSync() {
        this.toast('Синхронизация почты...', 'info');
        try {
            const r = await this.api('mail.php?action=sync', {method: 'POST', body: {}});
            const total = (r.report || []).reduce((a, x) => a + x.in + x.out, 0);
            const errors = (r.report || []).filter(x => x.error);
            if (errors.length) this.toast(errors.map(e => `${e.name}: ${e.error}`).join('; '), 'error');
            else this.toast(`Загружено писем: ${total}`, 'success');
            this.pageMail();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    async pageMailMessage(id) {
        const m = await this.api(`mail.php?action=get&id=${id}`);
        document.getElementById('app').innerHTML = `
            <div class="flex flex--between" style="margin-bottom:16px">
                <h2>${this.esc(m.subject) || 'Без темы'}</h2>
                <div class="flex">
                    <a href="#mail" class="btn btn--outline btn--sm">← К списку</a>
                    ${m.direction === 'in' ? `<button class="btn btn--primary btn--sm" onclick="App.mailCompose(${m.id}, true)">Создать ответ</button>` : ''}
                    <button class="btn btn--outline btn--sm" onclick="App.mailCompose(${m.id})">Ответить</button>
                </div>
            </div>
            <div class="card">
                <p><strong>${m.direction === 'in' ? 'От' : 'Кому'}:</strong>
                   ${this.esc(m.direction === 'in' ? (m.from_email || '') : (m.to_emails || ''))}
                   ${m.from_name ? '· ' + this.esc(m.from_name) : ''}</p>
                ${m.cc_emails ? `<p><strong>Копия:</strong> ${this.esc(m.cc_emails)}</p>` : ''}
                <p class="muted">${this.esc(m.mailbox_name) || ''} · ${this.esc(m.folder)} · ${this.fmtDate(m.date_at)}</p>
                <p><strong>Компания:</strong> ${m.counterparty_id
                    ? `<a href="#counterparty/${m.counterparty_id}">${this.esc(m.counterparty_name)}</a>`
                    : 'не определена'}
                   ${m.request_id ? ` · <a href="#request/${m.request_id}">Запрос #${m.request_id}</a>` : ''}</p>
                ${m.error ? `<p class="no">Ошибка обработки: ${this.esc(m.error)}</p>` : ''}
                <hr style="margin:12px 0">
                <div class="msg__body" style="max-height:none">${this.esc(m.body_text)}</div>
                ${(m.attachments || []).length ? `
                    <div class="msg__files">
                        ${m.attachments.map(a => `<a class="chip" href="/api/mail.php?action=attachment&id=${a.id}" target="_blank">📎 ${this.esc(a.filename)}</a>`).join('')}
                    </div>` : ''}
            </div>
        `;
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

    // $generate=true — the manager pressed «Создать ответ»: draft the text right away
    async mailCompose(replyToId, generate) {
        const d = await this.api('mail.php?action=list&limit=1');
        let src = null;
        if (replyToId) src = await this.api(`mail.php?action=get&id=${replyToId}`);
        await this.loadCategories();
        const llm = await this.loadLlmModels();
        const subject = src ? (src.subject || '').replace(/^(Re:\s*)?/i, 'Re: ') : '';
        const to = src ? (src.direction === 'in' ? src.from_email : src.to_emails) : '';
        this.modal(replyToId ? 'Ответ' : 'Новое письмо', `
            <div class="form-group">
                <label>Отправить из ящика</label>
                <select id="cmpBox">
                    ${(d.mailboxes || []).map(b => `<option value="${b.id}" ${src && src.mailbox_id === b.id ? 'selected' : ''}>${this.esc(b.name)}</option>`).join('')}
                </select>
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
        };
        try {
            await this.api('mail.php?action=send', {method: 'POST', body});
            this.closeModal();
            this.toast('Письмо отправлено', 'success');
            this.pageMail();
        } catch (err) { this.toast(err.message, 'error'); }
    },

    // ==== Settings tabs that only an administrator sees (module 004) ====

    adminFail(err) {
        document.getElementById('adminBody').innerHTML = `<div class="card"><p class="no">${this.esc(err.message)}</p></div>`;
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
                        <p class="muted">Запросы идут ${this.esc(d.llm_route || 'напрямую')}${d.llm_catalog && d.llm_catalog.count
                            ? ` · каталог OpenRouter: ${d.llm_catalog.count} моделей, ${this.fmtDate(d.llm_catalog.synced_at)}` : ''}</p>
                        <a href="#settings/llm" class="btn btn--outline btn--sm">Настроить</a>
                    </div>
                </div>
                <div class="card">
                    <div class="card__title">База знаний (вики)</div>
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
                    <div class="card__title">Состояние копии</div>
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

                <div class="card">
                    <div class="card__title">Проверка подбора</div>
                    <p class="muted">Вставьте текст письма или список позиций — увидите, какие разделы вики попадут в промпт.</p>
                    <textarea id="kbQuery" rows="4" placeholder="Например: какой класс защиты у шлема Атом и есть ли размер L?"></textarea>
                    <div class="flex flex--wrap" style="margin-top:8px">
                        <select id="kbTask">${d.tasks.map(t => `<option value="${t.key}">${this.esc(t.label)}</option>`).join('')}</select>
                        <button class="btn btn--outline" onclick="App.knowledgePreview()">Подобрать</button>
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
        } catch (err) { this.adminFail(err); }
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

    async knowledgePreview() {
        const out = document.getElementById('kbPreview');
        const query = document.getElementById('kbQuery').value;
        const task = document.getElementById('kbTask').value;
        out.innerHTML = '<p class="muted">Ищем...</p>';
        try {
            const d = await this.api('admin.php?action=knowledge_preview', {method: 'POST', body: {query, task}});
            if (!d.items.length) {
                out.innerHTML = '<p class="muted">Ничего подходящего — вики в этот промпт не попадёт.</p>';
                return;
            }
            out.innerHTML = (d.enabled ? '' : '<p class="no">Для этой задачи база знаний выключена — показан «сухой» подбор.</p>')
                + d.items.map(i => `<p>${this.esc(i.title)}
                    <span class="muted">· ${i.chars} симв. · совпало терминов: ${i.hits} · вес ${i.score}</span></p>`).join('');
        } catch (err) { out.innerHTML = `<p class="no">${this.esc(err.message)}</p>`; }
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
                if (it.secret) return `<input type="password" id="${id}" placeholder="${it.filled ? 'задан ' + this.esc(it.tail) + ' — оставьте пустым' : 'не задан'}">`;
                if (it.type === 'int') return `<input type="number" id="${id}" value="${this.esc(it.value)}">`;
                return `<input type="text" id="${id}" value="${this.esc(it.value)}">`;
            };
            const groups = Object.entries(d.groups).map(([key, title]) => {
                const items = d.items.filter(i => i.group === key);
                if (!items.length) return '';
                return `<div class="card">
                    <div class="card__title">${this.esc(title)}</div>
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
                    <p class="muted">Сейчас запросы идут <strong>${this.esc(d.llm_route || 'напрямую')}</strong>.
                       Если провайдер отвечает «Access denied by security policy» или подобным, при живом ключе —
                       значит, до него запрос не доходит: его завернул фильтр по дороге. Тогда нужен прокси
                       вне фильтрации либо свой зеркальный адрес API.</p>
                    <div class="grid grid--2">
                        <div class="form-group"><label>Прокси</label>
                            <input type="text" id="set_LLM_PROXY" value="${this.esc(val('LLM_PROXY'))}"
                                   placeholder="http://host:port или socks5h://host:port"></div>
                        <div class="form-group"><label>Логин:пароль прокси</label>
                            <input type="password" id="set_LLM_PROXY_AUTH"
                                   placeholder="${secret('LLM_PROXY_AUTH').filled ? 'задан — оставьте пустым' : 'если прокси без авторизации — пусто'}"></div>
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
                                        <div class="muted">${this.esc(b.email)} · ${this.esc(this.providerTitle(b.provider))} · IMAP ${this.esc(b.imap_host)}</div></td>
                                    <td>${this.esc(b.manager_name) || '<em>общий</em>'}</td>
                                    <td class="num">${b.messages}${b.oldest_at ? `<div class="muted">с ${this.fmtDate(b.oldest_at)}</div>` : ''}</td>
                                    <td id="bf_${b.id}">${this.backfillLabel(b.backfill)}</td>
                                    <td class="muted">${b.last_error ? `<span class="no">${this.esc(b.last_error)}</span>` : this.fmtDate(b.last_check_at)}</td>
                                    <td>
                                        <button class="btn btn--sm btn--outline" onclick="App.editMailbox(${b.id})">Изменить</button>
                                        <button class="btn btn--sm btn--outline" onclick="App.syncMailbox(${b.id})">Забрать почту</button>
                                        <button class="btn btn--sm btn--outline" onclick="App.backfillMailbox(${b.id})">Скачать весь архив</button>
                                        <a class="btn btn--sm btn--outline" href="api/admin.php?action=mailbox_export&id=${b.id}">Выгрузить .mbox</a>
                                    </td>
                                </tr>`).join('')}
                            ${d.items.length === 0 ? '<tr><td colspan="6" style="text-align:center;color:var(--text-muted)">Ящиков пока нет</td></tr>' : ''}
                        </tbody>
                    </table>
                    <p class="muted">«Скачать весь архив» забирает ВСЮ переписку ящика, а не только новое: письма идут шагами,
                        кнопку можно нажать повторно — загрузка продолжится с того же места. Старые письма попадают в архив,
                        но запросы КП из них не создаются.</p>
                </div>
                <div id="mailboxForm"></div>
            `;
            this.mailboxes = d.items;
            this.mailboxBlank = d.blank;
        } catch (err) { this.adminFail(err); }
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

    async deleteMailbox(id) {
        if (!confirm('Удалить ящик? Архив писем останется.')) return;
        try {
            await this.api('admin.php?action=mailbox_delete', {method: 'POST', body: {id}});
            this.toast('Ящик удалён', 'success');
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

    async adminPrompts() {
        try {
            const d = await this.api('admin.php?action=prompts');
            document.getElementById('adminBody').innerHTML = `
                <div class="card"><p>Системные промпты, с которыми сервис обращается к нейросети.
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
