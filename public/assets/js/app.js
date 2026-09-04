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
        nav.innerHTML = `
            <a href="#requests" data-page="requests">Запросы</a>
            <a href="#new" data-page="new">+ Новый</a>
            <a href="#counterparties" data-page="counterparties">Компании</a>
            <a href="#mail" data-page="mail">Почта<span id="mailBadge"></span></a>
            <a href="#settings" data-page="settings">Настройки</a>
            ${this.manager.is_admin ? '<a href="#admin" data-page="admin">Админ<span id="logBadge"></span></a>' : ''}
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
            case 'mail': this.pageMail(params[0]); break;
            case 'admin': this.pageAdmin(params[0] || 'overview'); break;
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
            </div>`;
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
            const stale = (d.webhooks_all || []).filter(w => !w.current).length;
            card.innerHTML = `
                <div class="card__title">Интеграция с МойСклад</div>
                ${d.ms_error ? `<p class="no">${this.esc(d.ms_error)}</p>` : ''}
                ${d.diag ? `<p class="muted">Токен в config.php на сервере: длина ${d.diag.token_len}, конец «…${this.esc(d.diag.token_tail)}»${Object.entries(d.diag.probes || {}).map(([k, v]) => ` · ${k}: HTTP ${v.code}`).join('')}</p>` : ''}
                <p>Товары: ${yes(p.products)} · Контрагенты: ${yes(p.counterparties)} · Заказы: ${yes(p.orders_write)}
                   · Счета: ${yes(p.invoices)} · Вебхуки: ${yes(p.webhooks)}</p>
                <p class="muted">Адрес вебхука: <code>${this.esc(d.webhook_url)}</code></p>
                ${!d.app_url_ok ? '<p class="no">APP_URL в config.php должен быть публичным https-адресом — иначе вебхуки не придут, останется подтяжка при открытии карточки.</p>' : ''}
                <p>Зарегистрировано вебхуков: <strong>${(d.webhooks || []).length}</strong> из 4
                   ${stale ? `<span class="muted">· с устаревшим адресом: ${stale} (кнопка ниже их обновит)</span>` : ''}
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

    async refreshProducts() {
        this.toast('Обновление каталога...', 'info');
        try {
            const r = await this.api('products.php?action=refresh_cache', {method:'POST'});
            this.toast(`Загружено ${r.count} товаров за ${r.elapsed_sec}с`, 'success');
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
                ${this.manager.is_admin ? '<a href="#admin/mail">Добавить ящик</a>' : 'Обратитесь к администратору.'}</div>` : ''}
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
                    <button class="btn btn--primary btn--sm" onclick="App.mailCompose(${m.id})">Ответить</button>
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

    async mailCompose(replyToId) {
        const d = await this.api('mail.php?action=list&limit=1');
        let src = null;
        if (replyToId) src = await this.api(`mail.php?action=get&id=${replyToId}`);
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
            <div class="form-group"><label>Текст</label><textarea id="cmpText" rows="9"></textarea></div>
            <button class="btn btn--primary btn--block" onclick="App.mailSend(${replyToId || 'null'})">Отправить</button>
        `);
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

    // ==== Admin console (module 004) ====

    pageAdmin(tab) {
        const tabs = [
            ['overview', 'Обзор'], ['settings', 'Настройки'], ['llm', 'Нейросети'],
            ['mail', 'Почта'], ['managers', 'Менеджеры'], ['prompts', 'Промпты'], ['logs', 'Логи'],
        ];
        document.getElementById('app').innerHTML = `
            <h2 style="margin-bottom:12px">Администрирование</h2>
            <div class="tabs">
                ${tabs.map(([k, l]) => `<a href="#admin/${k}" class="tab ${tab === k ? 'tab--active' : ''}">${l}</a>`).join('')}
            </div>
            <div id="adminBody"><div class="loading">Загрузка...</div></div>
        `;
        const render = {
            overview: () => this.adminOverview(), settings: () => this.adminSettings(),
            llm: () => this.adminLlm(), mail: () => this.adminMail(),
            managers: () => this.adminManagers(), prompts: () => this.adminPrompts(),
            logs: () => this.adminLogs(),
        }[tab] || (() => this.adminOverview());
        render();
    },

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
                        <a href="#admin/llm" class="btn btn--outline btn--sm">Настроить</a>
                    </div>
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
                        <a href="#admin/mail" class="btn btn--outline">Проверить почту</a>
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
            this.testOut(`${this.esc(provider)} — ответ за ${r.result.ms} мс, модель ${this.esc(r.result.model)}: «${this.esc(r.result.answer)}»`);
        } catch (err) { this.testOut(this.esc(err.message), 'no'); }
    },

    // ---- Settings: every key, with its source and an override ----

    async adminSettings() {
        try {
            const d = await this.api('admin.php?action=settings');
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

    // ---- LLM: providers, order and models ----

    async adminLlm() {
        try {
            const d = await this.api('admin.php?action=overview');
            const s = await this.api('admin.php?action=settings');
            this.settingsSpec = s.items.filter(i => i.group === 'llm');
            const val = k => (s.items.find(i => i.key === k) || {}).value || '';
            const secret = k => s.items.find(i => i.key === k) || {};
            document.getElementById('adminBody').innerHTML = `
                <div class="card">
                    <div class="card__title">Порядок провайдеров</div>
                    <p class="muted">Первый отвечает, следующий подхватывает, если первый недоступен.</p>
                    <div class="form-group">
                        <input type="text" id="set_LLM_PROVIDER_PRIORITY" value="${this.esc(val('LLM_PROVIDER_PRIORITY'))}"
                               placeholder="yandex, openrouter">
                    </div>
                    <div class="grid grid--2">
                        <div class="form-group"><label>Таймаут запроса, сек</label>
                            <input type="number" id="set_LLM_TIMEOUT_SEC" value="${this.esc(val('LLM_TIMEOUT_SEC'))}"></div>
                        <div class="form-group"><label>Температура по умолчанию</label>
                            <input type="text" id="set_LLM_TEMPERATURE" value="${this.esc(val('LLM_TEMPERATURE'))}"></div>
                    </div>
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
                                <input type="text" id="set_${modelName}" list="models_${p.provider}" value="${this.esc(p.model)}">
                                <datalist id="models_${p.provider}">
                                    ${p.models.map(m => `<option value="${this.esc(m.id)}">${this.esc(m.label)}</option>`).join('')}
                                </datalist>
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
                <div id="testResult"></div>
                <div class="flex flex--end"><button class="btn btn--primary" onclick="App.saveSettings()">Сохранить</button></div>
            `;
            this.settingsSpec = s.items.filter(i => document.getElementById('set_' + i.key));
        } catch (err) { this.adminFail(err); }
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
                        <input type="password" id="mb_imap_password" placeholder="${b.imap_password_set ? 'сохранён — оставьте пустым' : 'не задан'}"></div>
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
                        <input type="password" id="mb_smtp_password" placeholder="${b.smtp_password_set ? 'сохранён — оставьте пустым' : 'не задан'}"
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

    /** One application password serves both IMAP and SMTP — no need to type it twice. */
    mirrorImapPassword() {
        const smtp = document.getElementById('mb_smtp_password');
        const imap = document.getElementById('mb_imap_password');
        if (smtp && imap && !imap.value) imap.value = smtp.value;
    },

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
                : `<p class="ok">SMTP работает: ${this.esc(r.result.host)}:${r.result.port}${r.result.sent_to ? ' · письмо отправлено на ' + this.esc(r.result.sent_to) : ''}</p>`;
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
                    <span class="muted">Найдено: ${d.total} · за сутки ошибок ${d.counts.errors_24h}, предупреждений ${d.counts.warnings_24h}</span>
                    <button class="btn btn--sm btn--outline" onclick="App.adminLogs()">⟳ Обновить</button>
                    <button class="btn btn--sm btn--danger" onclick="App.clearLogs()">Очистить</button>
                </div>
                <div class="card">
                    <table class="table">
                        <thead><tr><th>Когда</th><th>Уровень</th><th>Источник</th><th>Сообщение</th></tr></thead>
                        <tbody>
                            ${d.items.map(r => `
                                <tr onclick="App.logDetails(${r.id})" style="cursor:pointer">
                                    <td class="muted" style="white-space:nowrap">${this.fmtDate(r.created_at)}</td>
                                    <td>${levelBadge(r.level)}</td>
                                    <td>${this.esc(r.channel)}<div class="muted">${this.esc(r.source || '')}</div></td>
                                    <td>${this.esc(r.message)}
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
            <p><strong>${this.esc(r.level)}</strong> · ${this.esc(r.channel)} · ${this.fmtDate(r.created_at)}</p>
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
document.addEventListener('DOMContentLoaded', () => App.init());
