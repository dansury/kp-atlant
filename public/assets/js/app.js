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
        if (data.error) throw new Error(data.error);
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
    },

    // Render navigation
    renderNav() {
        const nav = document.getElementById('nav');
        nav.innerHTML = `
            <a href="#requests" data-page="requests">Запросы</a>
            <a href="#new" data-page="new">+ Новый</a>
            <a href="#counterparties" data-page="counterparties">Контрагенты</a>
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
                <h2>Запросы на КП</h2>
                <a href="#new" class="btn btn--primary">+ Новый запрос</a>
            </div>
            <div class="card">
                <table class="table">
                    <thead><tr><th class="num">#</th><th>Контрагент</th><th>Источник</th><th>Позиций</th><th>Менеджер</th><th>Статус</th><th>Дата</th></tr></thead>
                    <tbody>
                        ${data.items.map(r => `
                            <tr style="cursor:pointer" onclick="location.hash='request/${r.id}'">
                                <td class="num">${r.id}</td>
                                <td>${r.counterparty_name || '—'}</td>
                                <td>${r.source === 'email' ? '📧 Email' : '📋 Ручной'}</td>
                                <td class="num">${r.items_count || 0}</td>
                                <td>${r.manager_name || '<em>пул</em>'}</td>
                                <td>${statusBadge(r.status)}</td>
                                <td>${new Date(r.created_at).toLocaleDateString('ru-RU')}</td>
                            </tr>
                        `).join('')}
                        ${data.items.length === 0 ? '<tr><td colspan="7" style="text-align:center;color:var(--text-muted)">Нет запросов</td></tr>' : ''}
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
        app.innerHTML = `
            <div class="flex flex--between" style="margin-bottom:16px">
                <h2>Запрос #${req.id}</h2>
                <div class="flex">
                    ${!req.manager_id ? `<button class="btn btn--outline" onclick="App.assignRequest(${req.id})">Взять в работу</button>` : ''}
                    ${req.status === 'new' || req.status === 'processing' ? `<button class="btn btn--primary" id="genBtn" onclick="App.generateKP(${req.id})">Сформировать КП</button>` : ''}
                </div>
            </div>
            <div class="grid grid--2">
                <div class="card">
                    <div class="card__title">Исходный запрос</div>
                    <p><strong>Источник:</strong> ${req.source === 'email' ? 'Email' : 'Ручной ввод'}</p>
                    ${req.email_from ? `<p><strong>От:</strong> ${req.email_from}</p>` : ''}
                    ${req.email_subject ? `<p><strong>Тема:</strong> ${req.email_subject}</p>` : ''}
                    <p><strong>Контрагент:</strong> ${req.counterparty_name || 'не определён'}</p>
                    <hr style="margin:10px 0">
                    <pre style="white-space:pre-wrap;font-size:13px">${req.raw_text}</pre>
                </div>
                <div class="card">
                    <div class="card__title">Распознанные позиции</div>
                    ${req.parsed?.items?.length ? `
                        <table class="table">
                            <thead><tr><th>Наименование</th><th>Кол-во</th></tr></thead>
                            <tbody>
                                ${req.parsed.items.map(i => `<tr><td>${i.name}</td><td>${i.qty}</td></tr>`).join('')}
                            </tbody>
                        </table>
                    ` : '<p style="color:var(--text-muted)">Позиции ещё не распознаны</p>'}
                    ${req.parsed?.delivery_terms ? `<p style="margin-top:10px"><strong>Доставка:</strong> ${req.parsed.delivery_terms}</p>` : ''}
                </div>
            </div>
            <div id="proposalArea"></div>
        `;

        // Load existing proposals
        // Check if there are proposals for this request
        const proposals = await this.api(`requests.php?action=get&id=${id}`);
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

    // Counterparties list
    async pageCounterparties() {
        document.getElementById('app').innerHTML = `
            <h2 style="margin-bottom:16px">Контрагенты</h2>
            <div class="card">
                <div class="form-group">
                    <input type="text" id="cpSearch" placeholder="Поиск по названию или ИНН..." oninput="App.searchCounterparties()">
                </div>
                <div id="cpList"><p style="color:var(--text-muted)">Начните вводить для поиска</p></div>
            </div>
        `;
    },

    async searchCounterparties() {
        const q = document.getElementById('cpSearch').value.trim();
        if (q.length < 2) return;
        try {
            const data = await this.api(`counterparties.php?action=search&q=${encodeURIComponent(q)}`);
            document.getElementById('cpList').innerHTML = data.items?.length
                ? `<table class="table"><thead><tr><th>Название</th><th>ИНН</th><th>Контакт</th><th>Email</th></tr></thead><tbody>
                    ${data.items.map(c => `<tr style="cursor:pointer" onclick="location.hash='counterparty/${c.id}'">
                        <td>${c.name}</td><td>${c.inn||'—'}</td><td>${c.contact_person||'—'}</td><td>${c.contact_email||'—'}</td>
                    </tr>`).join('')}</tbody></table>`
                : '<p style="color:var(--text-muted)">Не найдено</p>';
        } catch {}
    },

    // Single counterparty
    async pageCounterparty(id) {
        document.getElementById('app').innerHTML = `<div class="loading">Загрузка...</div>`;
        try {
            const cp = await this.api(`counterparties.php?action=get&id=${id}`);
            document.getElementById('app').innerHTML = `
                <h2 style="margin-bottom:16px">${cp.name}</h2>
                <div class="grid grid--2">
                    <div class="card">
                        <div class="card__title">Информация</div>
                        <p><strong>ИНН:</strong> ${cp.inn||'—'}</p>
                        <p><strong>Контакт:</strong> ${cp.contact_person||'—'}</p>
                        <p><strong>Email:</strong> ${cp.contact_email||'—'}</p>
                        <p><strong>Телефон:</strong> ${cp.contact_phone||'—'}</p>
                    </div>
                    <div class="card">
                        <div class="card__title">История</div>
                        <p style="color:var(--text-muted)">Переписка и КП загружаются...</p>
                    </div>
                </div>
            `;
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
    pageSettings() {
        document.getElementById('app').innerHTML = `
            <h2 style="margin-bottom:16px">Настройки</h2>
            <div class="card">
                <div class="card__title">Юридическое лицо</div>
                <p style="color:var(--text-muted);margin-bottom:10px">Загрузка...</p>
            </div>
            <div class="card">
                <div class="card__title">Обновить кэш товаров</div>
                <p style="margin-bottom:10px">Загрузить актуальный каталог из МойСклад</p>
                <button class="btn btn--outline" onclick="App.refreshProducts()">Обновить каталог</button>
            </div>
        `;
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
