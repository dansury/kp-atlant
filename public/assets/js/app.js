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
        });
        if (res.headers.get('content-type')?.includes('application/pdf')) return res;
        const data = await res.json();
        if (data.error) {
            const err = new Error(data.error);
            err.data = data;
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

    typeBadge(type) {
        return type === 'order'
            ? '<span class="badge badge--order">Заказ</span>'
            : '<span class="badge badge--kp">Запрос КП</span>';
    },

    // Init app
    async init() {
        try {
            this.manager = await this.api('auth.php?action=me');
            this.renderNav();
            this.startPolling();
            this.route();
        } catch {
            this.renderLogin();
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
        nav.innerHTML = `
            <a href="#requests" data-page="requests">Запросы</a>
            <a href="#new" data-page="new">+ Новый</a>
            <a href="#counterparties" data-page="counterparties">Компании</a>
            <a href="#settings" data-page="settings">Настройки</a>
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
        };
        poll();
        this.pollTimer = setInterval(poll, 30000);
    },

    // Router
    route() {
        const hash = location.hash.slice(1) || 'requests';
        const [page, ...params] = hash.split('/');
        document.querySelectorAll('.header__nav a').forEach(a => {
            a.classList.toggle('active', a.dataset.page === page);
        });
        const app = document.getElementById('app');
        app.innerHTML = '<div class="loading">Загрузка...</div>';
        this.onTabVisible = null; // only the company card re-syncs on focus
        this.closeModal();

        switch (page) {
            case 'requests': this.pageRequests(); break;
            case 'new': this.pageNewRequest(); break;
            case 'request': this.pageRequest(params[0]); break;
            case 'proposal': this.pageProposal(params[0]); break;
            case 'counterparties': this.pageCounterparties(); break;
            case 'counterparty': this.pageCounterparty(params[0]); break;
            case 'notifications': this.pageNotifications(); break;
            case 'settings': this.pageSettings(); break;
            default: this.pageRequests();
        }
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
                                <td>${this.typeBadge(r.type)}</td>
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
                    <div class="card">
                        <div class="card__title">Распознанные позиции</div>
                        ${parsed.items && parsed.items.length ? `
                            <table class="table">
                                <thead><tr><th>Наименование</th><th>Кол-во</th></tr></thead>
                                <tbody>
                                    ${parsed.items.map(i => `<tr><td>${this.esc(i.name)}</td><td>${this.esc(i.qty)}</td></tr>`).join('')}
                                </tbody>
                            </table>
                        ` : '<p class="muted">Позиции ещё не распознаны</p>'}
                        ${parsed.delivery_terms ? `<p style="margin-top:10px"><strong>Доставка:</strong> ${this.esc(parsed.delivery_terms)}</p>` : ''}
                    </div>
                    ${this.attachmentsCard(req.attachments)}
                    ${this.requestDocsCard(req)}
                </div>
            </div>
        `;
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
        // Fetch proposal data via a simple endpoint
        const proposal = await this.api(`proposals.php?action=get&id=${id}`).catch(() => null);
        // For now, show the PDF preview + editor
        document.getElementById('app').innerHTML = `
            <div class="flex flex--between" style="margin-bottom:16px">
                <h2>КП #${id}</h2>
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
                            <textarea id="coverLetter" rows="6" placeholder="Текст сопроводительного письма..."></textarea>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card__title">Текст перед таблицей</div>
                        <textarea id="preTable" rows="3" placeholder="Условия отгрузки, самовывоз..."></textarea>
                    </div>
                    <div class="card">
                        <div class="card__title">Текст после таблицы</div>
                        <textarea id="postTable" rows="3" placeholder="Комплектация, дополнительные условия..."></textarea>
                    </div>
                    <div class="card">
                        <div class="grid grid--3">
                            <div class="form-group">
                                <label>НДС %</label>
                                <select id="vatRate"><option value="5">5%</option><option value="0">0%</option><option value="20">20%</option></select>
                            </div>
                            <div class="form-group">
                                <label>Срок исполнения</label>
                                <select id="execDays"><option value="10">10 дней</option><option value="30" selected>30 дней</option></select>
                            </div>
                            <div class="form-group">
                                <label>Показать НДС</label>
                                <select id="showVat"><option value="0">Нет</option><option value="1">Да</option></select>
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
                        <input type="email" id="sendTo" placeholder="client@company.ru">
                    </div>
                    <div class="form-group">
                        <label>Тема письма</label>
                        <input type="text" id="sendSubject" value="Коммерческое предложение от Atlant Armour">
                    </div>
                </div>
                <button class="btn btn--primary" onclick="App.sendProposal(${id})">Отправить КП</button>
            </div>
        `;
    },

    // Save proposal edits
    async saveProposal(id) {
        try {
            await this.api(`proposals.php?action=update&id=${id}`, {method: 'PUT', body: {
                cover_letter_final: document.getElementById('coverLetter').value,
                pre_table_text: document.getElementById('preTable').value,
                post_table_text: document.getElementById('postTable').value,
                vat_rate: parseInt(document.getElementById('vatRate').value),
                execution_days: parseInt(document.getElementById('execDays').value),
                show_vat_total: parseInt(document.getElementById('showVat').value),
            }});
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

    // Settings page
    async pageSettings() {
        document.getElementById('app').innerHTML = `
            <h2 style="margin-bottom:16px">Настройки</h2>
            <div class="card">
                <div class="card__title">Обновить кэш товаров</div>
                <p style="margin-bottom:10px">Загрузить актуальный каталог из МойСклад</p>
                <button class="btn btn--outline" onclick="App.refreshProducts()">Обновить каталог</button>
            </div>
            <div class="card" id="msCard">
                <div class="card__title">Интеграция с МойСклад</div>
                <div class="loading">Проверяем доступ...</div>
            </div>
            <div class="card" id="processingCard">
                <div class="card__title">Обработка писем</div>
                <div class="loading">Загрузка...</div>
            </div>
        `;
        this.loadMoyskladSettings();
        this.loadProcessingSettings();
    },

    // MoySklad access + webhook status (FR-029, FR-039)
    async loadMoyskladSettings() {
        const card = document.getElementById('msCard');
        try {
            const d = await this.api('settings.php?action=moysklad');
            const p = d.permissions || {};
            const yes = v => v ? '<span class="ok">есть</span>' : '<span class="no">нет</span>';
            card.innerHTML = `
                <div class="card__title">Интеграция с МойСклад</div>
                ${d.ms_error ? `<p class="no">${this.esc(d.ms_error)}</p>` : ''}
                ${d.diag ? `<p class="muted">Токен в config.php на сервере: длина ${d.diag.token_len}, конец «…${this.esc(d.diag.token_tail)}»${Object.entries(d.diag.probes || {}).map(([k, v]) => ` · ${k}: HTTP ${v.code}`).join('')}</p>` : ''}
                <p>Товары: ${yes(p.products)} · Контрагенты: ${yes(p.counterparties)} · Заказы: ${yes(p.orders_write)}
                   · Счета: ${yes(p.invoices)} · Вебхуки: ${yes(p.webhooks)}</p>
                <p class="muted">Адрес вебхука: <code>${this.esc(d.webhook_url)}</code></p>
                ${!d.app_url_ok ? '<p class="no">APP_URL в config.php должен быть публичным https-адресом — иначе вебхуки не придут, останется подтяжка при открытии карточки.</p>' : ''}
                <p>Зарегистрировано вебхуков: <strong>${(d.webhooks || []).length}</strong> из 4
                   ${d.last_webhook ? `<span class="muted">· последний: ${this.esc(d.last_webhook.entity_type)}/${this.esc(d.last_webhook.action)} — ${this.esc(d.last_webhook.result)}, ${this.fmtDate(d.last_webhook.created_at)}</span>` : ''}</p>
                <div class="flex">
                    <button class="btn btn--primary" onclick="App.registerWebhooks()">Зарегистрировать вебхуки</button>
                    <button class="btn btn--outline" onclick="App.removeWebhooks()">Удалить вебхуки</button>
                </div>
            `;
        } catch (err) {
            card.innerHTML = `<div class="card__title">Интеграция с МойСклад</div><p class="no">${this.esc(err.message)}</p>`;
        }
    },

    async registerWebhooks() {
        try {
            const r = await this.api('settings.php?action=webhooks_register', {method: 'POST'});
            this.toast(`Вебхуки зарегистрированы (новых: ${r.created})`, 'success');
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

    async refreshProducts() {
        this.toast('Обновление каталога...', 'info');
        try {
            const r = await this.api('products.php?action=refresh_cache', {method:'POST'});
            this.toast(`Загружено ${r.count} товаров за ${r.elapsed_sec}с`, 'success');
        } catch (err) { this.toast(err.message, 'error'); }
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
document.addEventListener('DOMContentLoaded', () => App.init());
