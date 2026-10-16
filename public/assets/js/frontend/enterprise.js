(function () {
    'use strict';

    var TOKEN_KEY = 'enterprise_token';
    var state = {
        token: localStorage.getItem(TOKEN_KEY) || '',
        keywordPage: 1,
        keywordPages: 1,
        keywordPageItems: [],
        questionPage: 1,
        questionPages: 1,
        selectedKeyword: null,
        selectedMediaIds: [],
        mediaItems: {},
        selectedMediaNames: {},
        mediaChannelOptions: [],
        mediaChannelGroups: [],
        orderPage: 1,
        orderPages: 1,
        orderDetailId: null,
        orderDetailSignature: '',
        orderTimer: null,
        aiTaskId: null,
        aiTaskStatus: null,
        aiTimer: null,
        walletPage: 1,
        walletPages: 1,
        notificationPage: 1,
        notificationPages: 1,
        notificationUnreadOnly: false,
        articleFormLoaded: false,
        companyDraft: null,
        companyKnowledgeItemId: null,
        contentTab: 'knowledge',
        ordersTab: 'orders',
        mediaPage: 1,
        mediaPerPage: 20,
        mediaRequestSeq: 0,
        mediaAbortController: null,
        mediaSearchTimer: null,
        mediaCatalogStatus: null,
        mediaFilters: {
            keyword: '',
            exact: false,
            remark: '',
            channel_id: '',
            platform: '',
            source_catalog: '/media/type/1',
            region: '',
            media_category: '',
            portal_type: '',
            channel_type: '',
            inclusion: '',
            draft_speed: '',
            industry: '',
            fans_range: '',
            read_range: '',
            occupation: '',
            special_projects: '',
            verification: '',
            distribution_zone: '',
            contact_type: '',
            ai_inclusion: '',
            gender: '',
            is_collect: '',
            special_tag: '',
            start_price: '',
            end_price: ''
        },
        mediaFilterOptions: {},
        mediaFilterOptionsByCatalog: {},
        mediaFilterLoaded: false,
        mediaFilterLoading: false,
        mediaFilterExpanded: {},
        mediaFavoriteOnly: false,
        mediaLastItems: [],
        geoRequestSeq: 0,
        geoKeywordId: null,
        geoQuestionId: null,
        geoAnswerHistory: [],
        geoSelectedAnswer: null,
        geoBaselineData: null,
        geoPlansData: null,
        geoHistoryData: null,
        geoActiveResult: null,
        geoTrendData: null,
        geoSuggestionData: null,
        aiGeoAnswerVersionId: null,
        aiGeoAnswerLabel: ''
    };

    function el(id) {
        return document.getElementById(id);
    }

    function clear(node) {
        while (node && node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function say(text, isError) {
        var node = el('message');
        node.textContent = text || '';
        node.className = 'page-message' + (isError ? ' error' : '');
    }

    function setDisabled(node, disabled) {
        if (node) {
            node.disabled = !!disabled;
        }
    }

    function formBody(values) {
        var body = new URLSearchParams();
        Object.keys(values || {}).forEach(function (key) {
            var value = values[key];
            if (Array.isArray(value)) {
                body.set(key, JSON.stringify(value));
            } else if (value !== null && value !== undefined) {
                body.set(key, String(value));
            }
        });
        return body.toString();
    }

    async function api(path, options) {
        var settings = options || {};
        var headers = settings.headers || {};
        headers.Accept = 'application/json';
        if (state.token) {
            headers.Authorization = 'Bearer ' + state.token;
        }
        var body = settings.body;
        if (body && typeof body === 'object' && !(body instanceof FormData)) {
            headers['Content-Type'] = 'application/x-www-form-urlencoded';
            body = formBody(body);
        }
        if (settings.idempotencyKey) {
            headers['Idempotency-Key'] = settings.idempotencyKey;
        }

        var response;
        try {
            response = await fetch(path, {
                method: settings.method || 'GET',
                headers: headers,
                body: body,
                credentials: 'same-origin',
                signal: settings.signal
            });
        } catch (error) {
            if (error && error.name === 'AbortError') {
                throw error;
            }
            throw new Error('网络请求失败，请重试。');
        }

        var payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }

        if (response.status === 401) {
            clearToken();
            showLogin(payload && payload.msg ? payload.msg : '登录状态无效或已过期。');
            throw new Error(payload && payload.msg ? payload.msg : '登录状态无效或已过期。');
        }
        if (!response.ok || !payload || (payload.code !== 0 && payload.code !== 1)) {
            var requestError = new Error((payload && payload.msg) || '请求失败，请重试。');
            requestError.details = payload && payload.data ? payload.data : null;
            throw requestError;
        }
        return payload.data;
    }

    function post(path, values, idempotencyKey) {
        return api(path, {
            method: 'POST',
            body: values || {},
            idempotencyKey: idempotencyKey || ''
        });
    }

    function postForm(path, values, idempotencyKey) {
        var body = new URLSearchParams();
        Object.keys(values || {}).forEach(function (key) {
            var value = values[key];
            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    body.append(key + '[]', String(item));
                });
            } else if (value !== null && value !== undefined) {
                body.set(key, String(value));
            }
        });
        return api(path, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString(),
            idempotencyKey: idempotencyKey || ''
        });
    }

    function button(label, handler, disabled, kind) {
        var node = document.createElement('button');
        node.type = 'button';
        node.textContent = label;
        node.className = 'btn' + (kind ? ' btn-' + kind : ' btn-secondary');
        node.disabled = !!disabled;
        node.addEventListener('click', handler);
        return node;
    }

    function cell(row, value, className) {
        var node = document.createElement('td');
        node.textContent = value === null || value === undefined ? '' : String(value);
        if (className) {
            node.className = className;
        }
        row.appendChild(node);
        return node;
    }

    function statusBadge(status) {
        var span = document.createElement('span');
        span.className = 'status-badge';
        var label = status;
        var tone = '';
        var map = {
            normal: ['启用', 'success'],
            active: ['可售', 'success'],
            draft: ['草稿', 'info'],
            disabled: ['停用', 'warning'],
            offline: ['下架', 'danger'],
            funds_frozen: ['待提交', 'info'],
            queued: ['排队中', 'warning'],
            submitted: ['已提交', 'info'],
            processing: ['处理中', 'info'],
            published: ['已发布', 'success'],
            rejected: ['退稿', 'danger'],
            cancelled: ['已取消', 'warning'],
            refunded: ['已退款', 'warning'],
            partially_completed: ['部分完成', 'warning'],
            manual_review: ['待人工', 'danger'],
            after_sale: ['售后', 'warning'],
            running: ['生成中', 'processing'],
            succeeded: ['生成成功', 'success'],
            failed: ['生成失败', 'danger'],
            pending: ['待处理', 'info'],
            pending_review: ['待审核', 'warning'],
            approved: ['已批准', 'success'],
            confirmed: ['已确认', 'success'],
            not_visible: ['未检测到', 'warning'],
            mentioned: ['被提及', 'info'],
            recommended: ['被推荐', 'success'],
            baseline: ['Baseline', 'info'],
            recurring: ['复检', 'warning'],
            initial: ['初始', 'info'],
            read: ['已读', 'info'],
            unread: ['未读', 'warning']
        };
        if (map[status]) {
            label = map[status][0];
            tone = map[status][1];
        }
        span.textContent = label;
        if (tone) {
            span.className = 'status-badge ' + tone;
        }
        return span;
    }

    function demoBadge() {
        var span = document.createElement('span');
        span.className = 'status-badge demo';
        span.textContent = 'DEMO/TEST';
        return span;
    }

    function isDemoText(value) {
        return /DEMO|TEST|demo\.local|MM-MOCK/i.test(String(value || ''));
    }

    function ledgerBadge(type) {
        var labels = {
            manual_credit: ['人工增加', 'success'],
            manual_debit: ['人工减少', 'danger'],
            freeze: ['冻结', 'info'],
            capture: ['确认扣款', 'warning'],
            release: ['解冻', 'success'],
            refund: ['退款', 'warning']
        };
        var row = labels[type] || [type, ''];
        var span = document.createElement('span');
        span.className = 'status-badge' + (row[1] ? ' ' + row[1] : '');
        span.textContent = row[0];
        return span;
    }

    function formatDelta(value) {
        if (value === null || value === undefined || value === '') {
            return '-';
        }
        var number = Number(value);
        if (number > 0) {
            return '+' + value;
        }
        return String(value);
    }

    function loadingRow(tbody, colspan) {
        var row = document.createElement('tr');
        var td = document.createElement('td');
        td.colSpan = colspan;
        td.className = 'loading-cell';
        td.textContent = '加载中...';
        row.appendChild(td);
        tbody.appendChild(row);
    }

    function emptyRow(tbody, colspan, text) {
        var row = document.createElement('tr');
        var td = document.createElement('td');
        td.colSpan = colspan;
        td.className = 'empty-cell';
        td.textContent = text || '暂无数据';
        row.appendChild(td);
        tbody.appendChild(row);
    }

    function errorRow(tbody, colspan, message, retryHandler) {
        var row = document.createElement('tr');
        var td = document.createElement('td');
        td.colSpan = colspan;
        td.className = 'error-cell';
        td.textContent = message || '加载失败';
        if (retryHandler) {
            td.appendChild(document.createTextNode(' '));
            td.appendChild(button('重试', retryHandler));
        }
        row.appendChild(td);
        tbody.appendChild(row);
    }

    function renderPager(container, page, total, perPage, onPage) {
        clear(container);
        var pages = Math.max(1, Math.ceil((total || 0) / (perPage || 20)));
        var prev = button('上一页', function () {
            if (page > 1) {
                onPage(page - 1);
            }
        }, page <= 1);
        var next = button('下一页', function () {
            if (page < pages) {
                onPage(page + 1);
            }
        }, page >= pages);
        var span = document.createElement('span');
        span.textContent = page + ' / ' + pages + '，共 ' + (total || 0) + ' 条';
        container.appendChild(prev);
        container.appendChild(span);
        container.appendChild(next);
    }

    function parseHash() {
        var raw = location.hash.replace(/^#/, '');
        var parts = raw.split('&');
        var params = {};
        for (var i = 1; i < parts.length; i++) {
            var pair = parts[i].split('=');
            if (pair[0]) {
                params[decodeURIComponent(pair[0])] = decodeURIComponent(pair[1] || '');
            }
        }
        return { view: parts[0] || 'overview', params: params };
    }

    function navigate(view, params) {
        var hash = view;
        if (params) {
            Object.keys(params).forEach(function (key) {
                hash += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
            });
        }
        if (location.hash === '#' + hash) {
            renderView();
            return;
        }
        location.hash = hash;
    }

    function showLogin(message) {
        el('workspace').hidden = true;
        el('login-view').hidden = false;
        var node = el('login-message');
        node.textContent = message || '';
        node.className = message ? 'error-text' : '';
    }

    function clearToken() {
        localStorage.removeItem(TOKEN_KEY);
        state.token = '';
        stopOrderPolling();
        stopAiPolling();
    }

    async function logout() {
        try {
            if (state.token) {
                await fetch('/api/auth/logout', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Authorization': 'Bearer ' + state.token
                    }
                });
            }
        } catch (error) {
            // local logout must still proceed
        }
        clearToken();
        showLogin('已退出登录。');
    }

    async function enterWorkspace() {
        var identity = await api('/api/auth/current', { method: 'GET' });
        el('identity').textContent = identity.company.name + ' · ' + identity.username;
        el('login-view').hidden = true;
        el('workspace').hidden = false;
        say('');
        renderView();
    }

    async function handleLogin(event) {
        event.preventDefault();
        var submit = el('login-submit');
        setDisabled(submit, true);
        el('login-message').textContent = '';
        try {
            var data = await post('/api/auth/login', {
                account: el('account').value,
                password: el('password').value
            });
            state.token = data.token;
            localStorage.setItem(TOKEN_KEY, data.token);
            el('password').value = '';
            await enterWorkspace();
        } catch (error) {
            el('login-message').textContent = error.message;
            el('login-message').className = 'error-text';
        } finally {
            setDisabled(submit, false);
        }
    }

    function setActiveNav(view) {
        var links = document.querySelectorAll('#nav a[data-view]');
        for (var i = 0; i < links.length; i++) {
            links[i].classList.toggle('active', links[i].getAttribute('data-view') === view);
        }
    }

    function renderView() {
        var parsed = parseHash();
        var resolved = resolveView(parsed.view);
        var view = resolved.view;
        var views = document.querySelectorAll('.view');
        for (var i = 0; i < views.length; i++) {
            views[i].hidden = views[i].id !== 'view-' + view;
        }
        setActiveNav(view);
        el('message').textContent = '';
        el('message').className = 'page-message';

        if (view === 'overview') {
            loadOverview();
        } else if (view === 'profile') {
            loadProfile();
        } else if (view === 'answers') {
            loadKeywords(state.keywordPage);
            if (parsed.params.keyword_id) {
                loadKeywords(state.keywordPage).then(function () {
                    var found = Array.prototype.filter.call(
                        document.querySelectorAll('.keyword-selection'),
                        function (node) {
                            return String(node.value) === String(parsed.params.keyword_id);
                        }
                    )[0];
                    if (found) {
                        var items = state.keywordPageItems || [];
                        var keyword = items.filter(function (item) {
                            return String(item.id) === String(parsed.params.keyword_id);
                        })[0];
                        if (keyword) {
                            openQuestions(keyword);
                        }
                    }
                });
            }
        } else if (view === 'content') {
            var contentTab = resolved.tab || parsed.params.tab || state.contentTab || 'knowledge';
            setContentTab(contentTab);
            loadContentTab(contentTab, parsed);
        } else if (view === 'media') {
            loadMedia(state.mediaPage);
        } else if (view === 'orders') {
            var ordersTab = resolved.tab || parsed.params.tab || state.ordersTab || 'orders';
            setOrdersTab(ordersTab);
            loadOrdersTab(ordersTab, parsed);
        } else if (view === 'geo') {
            loadGeo(parsed.params.question_id || state.geoQuestionId || null);
        }
    }

    function resolveView(raw) {
        var aliases = {
            keywords: { view: 'answers' },
            knowledge: { view: 'content', tab: 'knowledge' },
            prompts: { view: 'content', tab: 'prompts' },
            articles: { view: 'content', tab: 'articles' },
            ai: { view: 'content', tab: 'ai' },
            wallet: { view: 'orders', tab: 'wallet' },
            notifications: { view: 'orders', tab: 'notifications' }
        };
        return aliases[raw] || { view: raw || 'overview' };
    }

    function setContentTab(tab) {
        state.contentTab = tab;
        var tabs = document.querySelectorAll('#content-tabbar [data-content-tab]');
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('active', tabs[i].getAttribute('data-content-tab') === tab);
        }
        var panels = ['knowledge', 'prompts', 'articles', 'ai'];
        panels.forEach(function (name) {
            var panel = el('content-' + name + '-panel');
            if (panel) {
                panel.hidden = name !== tab;
            }
        });
    }

    function loadContentTab(tab, parsed) {
        if (tab === 'prompts') {
            loadPrompts(1);
        } else if (tab === 'articles') {
            loadArticleSelectors().then(function () {
                loadArticles(1);
                if (parsed && parsed.params.article_id) {
                    openArticleEdit(parsed.params.article_id);
                }
            }).catch(showError);
        } else if (tab === 'ai') {
            loadAiFormData(parsed);
        } else {
            loadKnowledge(1);
        }
    }

    function setOrdersTab(tab) {
        state.ordersTab = tab;
        var tabs = document.querySelectorAll('#orders-tabbar [data-orders-tab]');
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('active', tabs[i].getAttribute('data-orders-tab') === tab);
        }
        var panels = ['orders', 'wallet', 'notifications'];
        panels.forEach(function (name) {
            var panel = el('orders-' + name + '-panel');
            if (panel) {
                panel.hidden = name !== tab;
            }
        });
    }

    function loadOrdersTab(tab, parsed) {
        if (tab === 'wallet') {
            stopOrderPolling();
            loadWallet();
        } else if (tab === 'notifications') {
            stopOrderPolling();
            loadNotifications(1);
        } else {
            el('order-confirm').hidden = false;
            el('order-list-panel').hidden = false;
            el('order-detail-panel').hidden = true;
            stopOrderPolling();
            loadOrderArticles();
            loadOrders(state.orderPage);
            if (parsed && parsed.params.detail_id) {
                showOrderDetail(parsed.params.detail_id);
            }
        }
    }

    function showError(error) {
        say(error.message || '操作失败，请重试。', true);
    }

    async function loadOverview() {
        var profilePromise = api('/api/company/profile').catch(function () { return null; });
        var keywordPromise = api('/api/keyword/index?page=1&per_page=100&sort=createtime&direction=desc').catch(function () { return null; });
        var knowledgePromise = api('/api/knowledge/index?page=1&per_page=1').catch(function () { return null; });
        var articlePromise = api('/api/article/index?page=1&per_page=1').catch(function () { return null; });
        var orderPromise = api('/api/order?page=1&per_page=1').catch(function () { return null; });
        var walletPromise = api('/api/wallet/balance').catch(function () { return null; });
        var notificationPromise = api('/api/order/notifications?page=1&per_page=5').catch(function () { return null; });
        var unreadPromise = api('/api/order/notifications?page=1&per_page=1&unread=1').catch(function () { return null; });
        var mediaPromise = api('/api/media/index?page=1&per_page=1').catch(function () { return null; });
        var geoPlanPromise = api('/api/geo_visibility/plans?page=1&per_page=5').catch(function () { return null; });
        var settled = await Promise.all([
            profilePromise,
            keywordPromise,
            knowledgePromise,
            articlePromise,
            orderPromise,
            walletPromise,
            notificationPromise,
            unreadPromise,
            mediaPromise,
            geoPlanPromise
        ]);
        var profile = settled[0];
        var keywordData = settled[1];
        var knowledgeData = settled[2];
        var articleData = settled[3];
        var orderData = settled[4];
        var balance = settled[5];
        var notificationData = settled[6];
        var unreadData = settled[7];
        var mediaData = settled[8];
        var geoPlanData = settled[9];

        var questionTotal = 0;
        if (keywordData && keywordData.items && keywordData.items.length) {
            var questionResults = await Promise.all(keywordData.items.map(function (item) {
                return api('/api/question/index?keyword_id=' + encodeURIComponent(item.id) + '&page=1&per_page=1')
                    .then(function (data) { return Number(data.total || 0); })
                    .catch(function () { return 0; });
            }));
            questionTotal = questionResults.reduce(function (sum, value) { return sum + value; }, 0);
        }

        var completeness = profile ? Number(profile.profile_completeness || 0) : 0;
        el('overview-profile-score').textContent = profile ? completeness + '%' : '--';
        el('overview-profile-bar').style.width = Math.max(0, Math.min(100, completeness)) + '%';
        el('overview-profile-missing').textContent = profile && profile.missing_fields && profile.missing_fields.length
            ? '待完善：' + profile.missing_fields.join('、')
            : (profile ? '企业资料已完善' : '企业资料暂不可用');
        el('overview-keywords').textContent = keywordData ? String(keywordData.total || 0) : '--';
        el('overview-questions').textContent = keywordData ? String(questionTotal) : '--';
        el('overview-knowledge').textContent = knowledgeData ? String(knowledgeData.total || 0) : '--';
        el('overview-articles').textContent = articleData ? String(articleData.total || 0) : '--';
        el('overview-balance').textContent = balance ? String(balance.available_balance) : '--';
        el('overview-frozen').textContent = balance ? String(balance.frozen_balance) : '--';
        el('overview-orders').textContent = orderData ? String(orderData.total || 0) : '--';
        el('overview-unread').textContent = unreadData ? String(unreadData.total || 0) : '--';
        el('overview-media').textContent = mediaData ? String(mediaData.total || 0) : '--';
        el('overview-media-note').textContent = mediaData ? '媒体目录已同步' : '媒体目录暂不可用';
        el('top-unread').textContent = unreadData ? String(unreadData.total || 0) : '0';
        renderOverviewGeo(geoPlanData);
        renderOverviewNotifications(notificationData ? notificationData.items : []);
        await renderOverviewAiState();
    }

    function renderOverviewGeo(data) {
        el('overview-geo-plan-count').textContent = data ? String(data.total || 0) : '--';
        var next = data && data.items && data.items.length ? data.items[0].next_run_at : '';
        el('overview-geo-next-run').textContent = next || (data ? '暂无复检计划' : '暂不可用');
    }

    function renderOverviewNotifications(items) {
        var node = el('overview-notifications');
        clear(node);
        if (!items || items.length === 0) {
            node.className = 'notice-empty';
            node.textContent = '暂无通知';
            return;
        }
        node.className = 'notice-list';
        items.forEach(function (notification) {
            var item = document.createElement('div');
            item.className = 'notice-item';
            var title = document.createElement('strong');
            title.className = 'text-truncate';
            title.textContent = notification.title;
            var meta = document.createElement('span');
            meta.textContent = (notification.is_read ? '已读' : '未读') + ' · ' + (notification.created_at || '');
            item.appendChild(title);
            item.appendChild(meta);
            node.appendChild(item);
        });
    }

    async function renderOverviewAiState() {
        var node = el('overview-ai-state');
        clear(node);
        if (!state.aiTaskId) {
            node.className = 'notice-empty';
            node.textContent = '暂无最近任务记录';
            return;
        }
        try {
            var task = await api('/api/ai_task/detail?id=' + state.aiTaskId);
            var labels = {
                queued: '排队中',
                running: '生成中',
                succeeded: '生成成功',
                failed: '生成失败',
                cancelled: '已取消'
            };
            var item = document.createElement('div');
            item.className = 'notice-item';
            var title = document.createElement('strong');
            title.className = 'text-truncate';
            title.textContent = labels[task.status] || task.status;
            item.appendChild(statusBadge(task.status));
            item.appendChild(title);
            if (task.status === 'failed' && task.error) {
                var detail = document.createElement('span');
                detail.textContent = task.error.message || '生成失败';
                item.appendChild(detail);
            }
            node.className = 'notice-list';
            node.appendChild(item);
        } catch (error) {
            node.className = 'notice-empty';
            node.textContent = '暂无最近任务记录';
        }
    }

    async function loadProfile() {
        try {
            var profile = await api('/api/company/profile');
            el('profile-company-name').value = profile.company_name || '';
            el('profile-brand').value = profile.brand || '';
            el('profile-industry').value = profile.industry || '';
            el('profile-products').value = (profile.products_services || []).join('\n');
            el('profile-website').value = profile.website || '';
            el('profile-introduction').value = profile.introduction || '';
            el('profile-mobile').value = profile.contacts ? profile.contacts.mobile || '' : '';
            el('profile-email').value = profile.contacts ? profile.contacts.email || '' : '';
            el('profile-completeness').textContent = (profile.profile_completeness || 0) + '%';
            el('profile-ai-ready').textContent = profile.ai_ready ? '已具备 AI 写作基础资料' : '尚未具备 AI 写作基础资料';
            el('profile-missing').textContent = (profile.missing_fields || []).join('，') || '无缺失字段';
            if (!el('company-discovery-name').value) {
                el('company-discovery-name').value = profile.company_name || '';
            }
            await loadCompanyIntelligenceStatus();
        } catch (error) {
            showError(error);
        }
    }

    function intelligenceStatus(text, tone) {
        var node = el('company-intelligence-status');
        node.textContent = text;
        node.className = 'status-badge' + (tone ? ' ' + tone : '');
    }

    async function loadCompanyIntelligenceStatus() {
        try {
            var data = await api('/api/company_intelligence/status');
            if (data && data.draft) {
                state.companyDraft = data.draft;
                state.companyKnowledgeItemId = data.draft.knowledge_item_id || null;
                renderCompanyDraft(data.draft);
                intelligenceStatus(data.draft.status === 'verified' ? '已确认' : '待审核', data.draft.status === 'verified' ? 'success' : 'warning');
            }
        } catch (error) {
            intelligenceStatus('暂不可用', 'warning');
        }
    }

    function renderCompanyCandidates(candidates) {
        var container = el('company-candidate-list');
        clear(container);
        if (!candidates || !candidates.length) {
            var empty = document.createElement('p');
            empty.className = 'notice-empty';
            empty.textContent = '未发现可核验候选，请补充官网或信用代码后重试。';
            container.appendChild(empty);
            return;
        }
        candidates.forEach(function (candidate) {
            var card = document.createElement('article');
            card.className = 'company-candidate-card';
            var title = document.createElement('h4');
            title.textContent = candidate.name;
            card.appendChild(title);
            if (candidate.ai_candidate) {
                var badge = document.createElement('span');
                badge.className = 'status-badge warning';
                badge.textContent = 'AI候选 / pending_review / 未联网核验';
                card.appendChild(badge);
            }
            ['地区：' + (candidate.region || '-'), '官网：' + (candidate.website || '-'), candidate.public_summary || ''].forEach(function (text) {
                if (!text) { return; }
                var paragraph = document.createElement('p');
                paragraph.textContent = text;
                card.appendChild(paragraph);
            });
            var sources = document.createElement('ul');
            (candidate.sources || []).forEach(function (source) {
                var item = document.createElement('li');
                var link = document.createElement('a');
                link.textContent = source.source_title;
                link.href = source.source_url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                item.appendChild(link);
                sources.appendChild(item);
            });
            card.appendChild(sources);
            card.appendChild(button('这是我的企业', function () { confirmCompanyCandidate(candidate.id); }, false, 'primary'));
            container.appendChild(card);
        });
    }

    async function discoverCompany(event) {
        event.preventDefault();
        var submit = el('company-discovery-submit');
        setDisabled(submit, true);
        intelligenceStatus('发现中', 'processing');
        try {
            var result = await post('/api/company_intelligence/discover', {
                company_name: el('company-discovery-name').value,
                region: el('company-discovery-region').value,
                website: el('company-discovery-website').value,
                unified_social_credit_code: el('company-discovery-credit-code').value
            });
            var hasAi = (result.candidates || []).some(function (candidate) {
                return !!candidate.ai_candidate;
            });
            renderCompanyCandidates(result.candidates || []);
            intelligenceStatus(hasAi ? 'AI候选待审核' : '请选择候选', 'warning');
            say(hasAi ? '候选为 AI 生成，未联网核验，请人工确认或修改。' : '候选仅为公开信息发现结果，请确认目标企业。');
        } catch (error) {
            renderCompanyCandidates([]);
            intelligenceStatus('外部能力不可用', 'warning');
            say(error.message, true);
        } finally {
            setDisabled(submit, false);
        }
    }

    async function confirmCompanyCandidate(candidateId) {
        intelligenceStatus('整理草稿中', 'processing');
        try {
            var draft = await post('/api/company_intelligence/confirm_candidate', { candidate_id: candidateId });
            state.companyDraft = draft;
            renderCompanyDraft(draft);
            intelligenceStatus('待审核', 'warning');
            say('已确认候选企业，请审核画像草稿。');
        } catch (error) {
            intelligenceStatus('草稿失败', 'danger');
            say(error.message, true);
        }
    }

    function fieldLabel(field) {
        var labels = {
            company_name: '企业名称', brand: '品牌', region: '地区', website: '官网', industry: '行业',
            main_business: '主营业务', products_services: '产品/服务', introduction: '企业简介',
            advantages: '公开优势', qualifications: '公开资质', cases: '公开案例'
        };
        return labels[field] || field;
    }

    function renderCompanyDraft(draft) {
        var form = el('company-draft-form');
        var container = el('company-draft-fields');
        clear(container);
        clear(el('company-draft-conflicts'));
        el('company-draft-id').value = String(draft.id || '');
        var facts = {};
        (draft.facts || []).forEach(function (fact) { facts[fact.field_key] = fact; });
        var inferences = {};
        (draft.inferences || []).forEach(function (item) { inferences[item.field_key] = item; });
        Object.keys(draft.draft || {}).concat(Object.keys(inferences)).filter(function (field, index, list) {
            return list.indexOf(field) === index;
        }).forEach(function (field) {
            var wrapper = document.createElement('label');
            wrapper.className = 'company-draft-field';
            var heading = document.createElement('span');
            var provenance = facts[field] && facts[field].status === 'verified' && facts[field].fact_type === 'source_fact'
                ? '公开来源'
                : (draft.status === 'verified' ? '用户已确认' : 'AI 推断（需用户确认）');
            heading.textContent = fieldLabel(field) + ' · ' + provenance;
            wrapper.appendChild(heading);
            var input = document.createElement('textarea');
            input.rows = ['introduction', 'products_services', 'advantages', 'qualifications', 'cases'].indexOf(field) >= 0 ? 4 : 2;
            input.dataset.field = field;
            var value = Object.prototype.hasOwnProperty.call(draft.draft || {}, field) ? draft.draft[field] : inferences[field].value;
            input.value = Array.isArray(value) ? value.join('\n') : (value || '');
            input.disabled = draft.status === 'verified';
            wrapper.appendChild(input);
            container.appendChild(wrapper);
        });
        form.hidden = false;
        el('company-draft-confirm').hidden = draft.status === 'verified';
        el('company-intelligence-next').hidden = draft.status !== 'verified';
    }

    function collectCompanyDraftProfile() {
        var profile = {};
        el('company-draft-fields').querySelectorAll('textarea[data-field]').forEach(function (input) {
            var field = input.dataset.field;
            if (['products_services', 'advantages', 'qualifications', 'cases'].indexOf(field) >= 0) {
                profile[field] = input.value.split(/\r?\n/).map(function (item) { return item.trim(); }).filter(Boolean);
            } else {
                profile[field] = input.value.trim();
            }
        });
        return profile;
    }

    function collectConflictResolutions() {
        var resolutions = {};
        el('company-draft-conflicts').querySelectorAll('select[data-field]').forEach(function (select) {
            if (select.value) { resolutions[select.dataset.field] = select.value; }
        });
        return resolutions;
    }

    function renderCompanyConflicts(conflicts) {
        var container = el('company-draft-conflicts');
        clear(container);
        var title = document.createElement('h4');
        title.textContent = '请选择冲突字段的处理方式';
        container.appendChild(title);
        Object.keys(conflicts || {}).forEach(function (field) {
            var row = document.createElement('label');
            row.textContent = fieldLabel(field) + '：已有“' + String(conflicts[field].existing || '') + '”，草稿“' + String(conflicts[field].draft || '') + '”';
            var select = document.createElement('select');
            select.dataset.field = field;
            [['', '请选择'], ['keep_existing', '保留已有资料'], ['use_draft', '采用本次草稿']].forEach(function (choice) {
                option(select, choice[0], choice[1]);
            });
            row.appendChild(select);
            container.appendChild(row);
        });
    }

    async function confirmCompanyDraft(event) {
        event.preventDefault();
        var submit = el('company-draft-confirm');
        setDisabled(submit, true);
        try {
            var confirmed = await post('/api/company_intelligence/confirm_draft', {
                draft_id: el('company-draft-id').value,
                profile: JSON.stringify(collectCompanyDraftProfile()),
                resolutions: JSON.stringify(collectConflictResolutions())
            });
            state.companyDraft = confirmed;
            state.companyKnowledgeItemId = confirmed.knowledge_item_id;
            renderCompanyDraft(confirmed);
            intelligenceStatus('已确认', 'success');
            say('企业画像和来源已写入现有资料与知识素材。');
            await loadProfile();
        } catch (error) {
            var conflicts = error.details && error.details.context ? error.details.context.conflicts : null;
            if (conflicts) { renderCompanyConflicts(conflicts); }
            say(error.message, true);
        } finally {
            setDisabled(submit, false);
        }
    }

    async function generateT19Questions() {
        if (!state.selectedKeyword) { say('请先选择一个关键词。', true); return; }
        var trigger = el('question-ai-generate-btn');
        setDisabled(trigger, true);
        try {
            await post('/api/question/generate', {
                keyword_id: state.selectedKeyword.id,
                knowledge_item_ids: state.companyKnowledgeItemId ? [state.companyKnowledgeItemId] : []
            });
            say('AI 潜在问题已生成，需人工审核后启用。');
            await loadQuestions(1);
        } catch (error) {
            say(error.message, true);
        } finally {
            setDisabled(trigger, false);
        }
    }

    async function reviewQuestion(item, decision) {
        try {
            var reviewed = await post('/api/question/review', {
                id: item.id,
                decision: decision
            });
            say(reviewed.review_status === 'approved' ? '问题审核已通过。' : '问题审核已驳回。');
            await loadQuestions(state.questionPage);
        } catch (error) {
            showError(error);
        }
    }

    async function saveProfile(event) {
        event.preventDefault();
        var submit = el('profile-submit');
        setDisabled(submit, true);
        var products = el('profile-products').value.split(/\r?\n/).map(function (item) {
            return item.trim();
        }).filter(function (item) {
            return item !== '';
        });
        try {
            await post('/api/company/profile', {
                company_name: el('profile-company-name').value,
                brand: el('profile-brand').value,
                industry: el('profile-industry').value,
                products_services: JSON.stringify(products),
                website: el('profile-website').value,
                introduction: el('profile-introduction').value,
                mobile: el('profile-mobile').value,
                email: el('profile-email').value
            });
            say('企业资料已保存。');
            await loadProfile();
        } catch (error) {
            showError(error);
        } finally {
            setDisabled(submit, false);
        }
    }

    function selectedIds(prefix) {
        var nodes = document.querySelectorAll('.' + prefix + '-selection:checked');
        return Array.prototype.map.call(nodes, function (node) {
            return Number(node.value);
        });
    }

    async function loadKeywords(page) {
        state.keywordPage = page || 1;
        var tbody = el('keyword-list');
        clear(tbody);
        loadingRow(tbody, 5);
        var params = new URLSearchParams({
            page: String(state.keywordPage),
            per_page: '20',
            sort: 'createtime',
            direction: 'desc'
        });
        if (el('keyword-search').value) {
            params.set('search', el('keyword-search').value);
        }
        if (el('keyword-status').value) {
            params.set('status', el('keyword-status').value);
        }
        try {
            var data = await api('/api/keyword/index?' + params.toString());
            clear(tbody);
            state.keywordPageItems = data.items || [];
            if (!data.items || data.items.length === 0) {
                emptyRow(tbody, 5, '暂无关键词');
            } else {
                data.items.forEach(function (item) {
                    var row = document.createElement('tr');
                    var td = document.createElement('td');
                    var check = document.createElement('input');
                    check.type = 'checkbox';
                    check.className = 'keyword-selection';
                    check.value = String(item.id);
                    td.appendChild(check);
                    row.appendChild(td);
                    cell(row, item.keyword, 'text-truncate');
                    var statusCell = document.createElement('td');
                    statusCell.appendChild(statusBadge(item.status));
                    row.appendChild(statusCell);
                    cell(row, item.updated_at ? new Date(item.updated_at * 1000).toLocaleString() : '');
                    var actions = cell(row, '');
                    actions.appendChild(button('问题', function () {
                        openQuestions(item);
                    }, false, 'primary'));
                    actions.appendChild(button('编辑', function () {
                        editKeyword(item);
                    }));
                    actions.appendChild(button(item.status === 'normal' ? '停用' : '启用', function () {
                        setKeywordStatus(item);
                    }));
                    actions.appendChild(button('删除', function () {
                        deleteKeyword(item);
                    }, false, 'danger'));
                    tbody.appendChild(row);
                });
            }
            state.keywordPages = Math.max(1, Math.ceil((data.total || 0) / (data.per_page || 20)));
            renderPager(el('keyword-pager'), state.keywordPage, data.total || 0, data.per_page || 20, loadKeywords);
        } catch (error) {
            clear(tbody);
            errorRow(tbody, 5, error.message, function () {
                loadKeywords(state.keywordPage);
            });
        }
    }

    function editKeyword(item) {
        el('keyword-id').value = item ? String(item.id) : '';
        el('keyword-text').value = item ? item.keyword : '';
        el('keyword-form').hidden = false;
        el('keyword-text').focus();
    }

    async function saveKeyword(event) {
        event.preventDefault();
        var id = el('keyword-id').value;
        try {
            await post(id ? '/api/keyword/update' : '/api/keyword/create', id
                ? { id: id, keyword: el('keyword-text').value }
                : { keyword: el('keyword-text').value });
            el('keyword-form').hidden = true;
            say('关键词已保存。');
            await loadKeywords(state.keywordPage);
        } catch (error) {
            showError(error);
        }
    }

    async function setKeywordStatus(item) {
        try {
            await post('/api/keyword/status', {
                id: item.id,
                status: item.status === 'normal' ? 'disabled' : 'normal'
            });
            say('关键词状态已更新。');
            await loadKeywords(state.keywordPage);
        } catch (error) {
            showError(error);
        }
    }

    async function deleteKeyword(item) {
        if (!window.confirm('确认软删除关键词“' + item.keyword + '”？')) {
            return;
        }
        try {
            await post('/api/keyword/delete', { id: item.id });
            say('关键词已软删除。');
            await loadKeywords(state.keywordPage);
        } catch (error) {
            showError(error);
        }
    }

    async function batchKeywords(status) {
        var ids = selectedIds('keyword');
        if (ids.length === 0) {
            say('请先选择关键词。', true);
            return;
        }
        try {
            await post('/api/keyword/batch', { ids: ids, status: status });
            say('关键词批量状态已更新。');
            await loadKeywords(state.keywordPage);
        } catch (error) {
            showError(error);
        }
    }

    function openQuestions(item) {
        state.selectedKeyword = item;
        state.questionPage = 1;
        el('selected-keyword').textContent = '问题：' + item.keyword;
        el('keyword-section').hidden = true;
        el('question-section').hidden = false;
        loadQuestions(1);
    }

    function closeQuestions() {
        state.selectedKeyword = null;
        el('question-section').hidden = true;
        el('keyword-section').hidden = false;
    }

    async function loadQuestions(page) {
        state.questionPage = page || 1;
        if (!state.selectedKeyword) {
            return;
        }
        var tbody = el('question-list');
        clear(tbody);
        loadingRow(tbody, 5);
        var params = new URLSearchParams({
            keyword_id: String(state.selectedKeyword.id),
            page: String(state.questionPage),
            per_page: '20',
            sort: 'createtime',
            direction: 'desc'
        });
        if (el('question-search').value) {
            params.set('search', el('question-search').value);
        }
        if (el('question-status').value) {
            params.set('status', el('question-status').value);
        }
        try {
            var data = await api('/api/question/index?' + params.toString());
            clear(tbody);
            if (!data.items || data.items.length === 0) {
                emptyRow(tbody, 5, '暂无问题');
            } else {
                data.items.forEach(function (item) {
                    var row = document.createElement('tr');
                    var td = document.createElement('td');
                    var check = document.createElement('input');
                    check.type = 'checkbox';
                    check.className = 'question-selection';
                    check.value = String(item.id);
                    td.appendChild(check);
                    row.appendChild(td);
                    cell(row, item.text, 'text-truncate');
                    cell(row, item.source === 'manual' ? '人工录入' : item.source);
                    var statusCell = document.createElement('td');
                    statusCell.appendChild(statusBadge(item.status));
                    row.appendChild(statusCell);
                    var actions = cell(row, '');
                    actions.appendChild(button('编辑', function () {
                        editQuestion(item);
                    }));
                    if (item.source === 'ai' && item.status === 'pending_review') {
                        actions.appendChild(button('通过', function () {
                            reviewQuestion(item, 'approved');
                        }, false, 'primary'));
                        actions.appendChild(button('驳回', function () {
                            reviewQuestion(item, 'rejected');
                        }, false, 'danger'));
                    }
                    actions.appendChild(button(item.status === 'normal' ? '停用' : '启用', function () {
                        setQuestionStatus(item);
                    }));
                    actions.appendChild(button('删除', function () {
                        deleteQuestion(item);
                    }, false, 'danger'));
                    actions.appendChild(button('用于 AI 生成', function () {
                        fillAiFromQuestion(state.selectedKeyword, item);
                    }, item.status !== 'normal', 'primary'));
                    tbody.appendChild(row);
                });
            }
            state.questionPages = Math.max(1, Math.ceil((data.total || 0) / (data.per_page || 20)));
            renderPager(el('question-pager'), state.questionPage, data.total || 0, data.per_page || 20, loadQuestions);
        } catch (error) {
            clear(tbody);
            errorRow(tbody, 5, error.message, function () {
                loadQuestions(state.questionPage);
            });
        }
    }

    function editQuestion(item) {
        el('question-id').value = item ? String(item.id) : '';
        el('question-text').value = item ? item.text : '';
        el('question-form').hidden = false;
        el('question-text').focus();
    }

    async function saveQuestion(event) {
        event.preventDefault();
        var id = el('question-id').value;
        try {
            await post(id ? '/api/question/update' : '/api/question/create', id
                ? { id: id, text: el('question-text').value }
                : { keyword_id: state.selectedKeyword.id, text: el('question-text').value });
            el('question-form').hidden = true;
            say('问题已保存。');
            await loadQuestions(state.questionPage);
        } catch (error) {
            showError(error);
        }
    }

    async function setQuestionStatus(item) {
        try {
            await post('/api/question/status', {
                id: item.id,
                status: item.status === 'normal' ? 'disabled' : 'normal'
            });
            say('问题状态已更新。');
            await loadQuestions(state.questionPage);
        } catch (error) {
            showError(error);
        }
    }

    async function deleteQuestion(item) {
        if (!window.confirm('确认软删除该问题？')) {
            return;
        }
        try {
            await post('/api/question/delete', { id: item.id });
            say('问题已软删除。');
            await loadQuestions(state.questionPage);
        } catch (error) {
            showError(error);
        }
    }

    async function batchQuestions(status) {
        var ids = selectedIds('question');
        if (ids.length === 0) {
            say('请先选择问题。', true);
            return;
        }
        try {
            await post('/api/question/batch', { ids: ids, status: status });
            say('问题批量状态已更新。');
            await loadQuestions(state.questionPage);
        } catch (error) {
            showError(error);
        }
    }

    function selectedOptionValues(select) {
        return Array.prototype.filter.call(select.options, function (option) {
            return option.selected;
        }).map(function (option) {
            return Number(option.value);
        }).filter(function (value) {
            return value > 0;
        });
    }

    function option(select, value, label) {
        var node = document.createElement('option');
        node.value = String(value);
        node.textContent = label;
        select.appendChild(node);
    }

    async function loadKnowledge(page) {
        var tbody = el('knowledge-list');
        clear(tbody);
        loadingRow(tbody, 6);
        var params = new URLSearchParams({
            page: String(page || 1),
            per_page: '20',
            sort: 'updatetime',
            direction: 'desc'
        });
        if (el('knowledge-search').value) {
            params.set('search', el('knowledge-search').value);
        }
        if (el('knowledge-type').value) {
            params.set('type', el('knowledge-type').value);
        }
        if (el('knowledge-status').value) {
            params.set('status', el('knowledge-status').value);
        }
        try {
            var data = await api('/api/knowledge/index?' + params.toString());
            clear(tbody);
            if (!data.items || data.items.length === 0) {
                emptyRow(tbody, 6, '暂无知识素材');
            } else {
                data.items.forEach(function (item) {
                    var row = document.createElement('tr');
                    cell(row, item.title, 'text-truncate');
                    cell(row, item.type === 'text' ? '文本' : (item.type === 'image' ? '图片' : '文档'));
                    cell(row, item.file ? item.file.original_name : '-');
                    cell(row, item.file ? Math.ceil(item.file.size_bytes / 1024) + ' KB' : '-');
                    var statusCell = document.createElement('td');
                    statusCell.appendChild(statusBadge(item.status));
                    row.appendChild(statusCell);
                    var actions = cell(row, '');
                    if (item.type === 'text') {
                        actions.appendChild(button('编辑', function () {
                            el('knowledge-id').value = String(item.id);
                            el('knowledge-title').value = item.title;
                            el('knowledge-content').value = item.content || '';
                            el('knowledge-title').focus();
                        }, false, 'primary'));
                        actions.appendChild(button('预览', function () {
                            say('文本素材：' + item.title);
                        }));
                    } else if (item.file) {
                        actions.appendChild(button('下载', function () {
                            downloadKnowledge(item);
                        }));
                    }
                    actions.appendChild(button(item.status === 'normal' ? '停用' : '启用', function () {
                        setKnowledgeStatus(item);
                    }));
                    actions.appendChild(button('删除', function () {
                        deleteKnowledge(item);
                    }, false, 'danger'));
                    tbody.appendChild(row);
                });
            }
            renderPager(el('knowledge-pager'), page || 1, data.total || 0, data.per_page || 20, loadKnowledge);
        } catch (error) {
            clear(tbody);
            errorRow(tbody, 6, error.message, function () {
                loadKnowledge(page || 1);
            });
        }
    }

    async function saveKnowledge(event) {
        event.preventDefault();
        var id = el('knowledge-id').value;
        try {
            await post(id ? '/api/knowledge/update' : '/api/knowledge/create', id
                ? { id: id, title: el('knowledge-title').value, content: el('knowledge-content').value }
                : { title: el('knowledge-title').value, content: el('knowledge-content').value });
            el('knowledge-text-form').reset();
            el('knowledge-id').value = '';
            say('文本素材已保存。');
            await loadKnowledge(1);
        } catch (error) {
            showError(error);
        }
    }

    async function uploadKnowledge(event) {
        event.preventDefault();
        var data = new FormData();
        data.append('title', el('knowledge-file-title').value);
        data.append('file', el('knowledge-file').files[0]);
        try {
            await api('/api/knowledge/upload', { method: 'POST', body: data });
            el('knowledge-file-form').reset();
            say('文件已上传。');
            await loadKnowledge(1);
        } catch (error) {
            showError(error);
        }
    }

    async function setKnowledgeStatus(item) {
        try {
            await post('/api/knowledge/status', {
                id: item.id,
                status: item.status === 'normal' ? 'disabled' : 'normal'
            });
            say('知识素材状态已更新。');
            await loadKnowledge(1);
        } catch (error) {
            showError(error);
        }
    }

    async function deleteKnowledge(item) {
        if (!window.confirm('确认软删除“' + item.title + '”？')) {
            return;
        }
        try {
            await post('/api/knowledge/delete', { id: item.id });
            say('知识素材已软删除。');
            await loadKnowledge(1);
        } catch (error) {
            showError(error);
        }
    }

    async function downloadKnowledge(item) {
        try {
            var data = await api('/api/knowledge/download?id=' + item.id);
            var binary = atob(data.base64);
            var bytes = new Uint8Array(binary.length);
            for (var i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }
            var link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob([bytes], { type: data.mime }));
            link.download = item.file.safe_name || item.file.original_name;
            document.body.appendChild(link);
            link.click();
            setTimeout(function () {
                URL.revokeObjectURL(link.href);
                document.body.removeChild(link);
            }, 1000);
        } catch (error) {
            showError(error);
        }
    }

    async function loadPrompts(page) {
        var tbody = el('prompt-list');
        clear(tbody);
        loadingRow(tbody, 5);
        var params = new URLSearchParams({
            page: String(page || 1),
            per_page: '20',
            sort: 'updatetime',
            direction: 'desc'
        });
        if (el('prompt-search').value) {
            params.set('search', el('prompt-search').value);
        }
        if (el('prompt-status').value) {
            params.set('status', el('prompt-status').value);
        }
        try {
            var data = await api('/api/prompt/index?' + params.toString());
            clear(tbody);
            if (!data.items || data.items.length === 0) {
                emptyRow(tbody, 5, '暂无提示词');
            } else {
                data.items.forEach(function (item) {
                    var row = document.createElement('tr');
                    cell(row, item.name, 'text-truncate');
                    cell(row, item.scene);
                    cell(row, 'v' + item.version_no);
                    var statusCell = document.createElement('td');
                    statusCell.appendChild(statusBadge(item.status));
                    row.appendChild(statusCell);
                    var actions = cell(row, '');
                    actions.appendChild(button('新建版本', function () {
                        el('prompt-logical-key').value = item.logical_key;
                        el('prompt-name').value = item.name;
                        el('prompt-scene').value = item.scene;
                        el('prompt-content').value = '';
                        el('prompt-content').focus();
                    }, false, 'primary'));
                    actions.appendChild(button('历史', function () {
                        promptHistory(item.logical_key);
                    }));
                    actions.appendChild(button(item.status === 'normal' ? '停用' : '启用', function () {
                        setPromptStatus(item);
                    }));
                    tbody.appendChild(row);
                });
            }
            renderPager(el('prompt-pager'), page || 1, data.total || 0, data.per_page || 20, loadPrompts);
        } catch (error) {
            clear(tbody);
            errorRow(tbody, 5, error.message, function () {
                loadPrompts(page || 1);
            });
        }
    }

    async function savePrompt(event) {
        event.preventDefault();
        var key = el('prompt-logical-key').value;
        var values = {
            name: el('prompt-name').value,
            scene: el('prompt-scene').value,
            content: el('prompt-content').value
        };
        if (key) {
            values.logical_key = key;
        }
        try {
            await post(key ? '/api/prompt/version' : '/api/prompt/create', values);
            el('prompt-form').reset();
            el('prompt-logical-key').value = '';
            el('prompt-scene').value = 'article';
            say('提示词已保存为新版本。');
            await loadPrompts(1);
        } catch (error) {
            showError(error);
        }
    }

    async function setPromptStatus(item) {
        try {
            await post('/api/prompt/status', {
                logical_key: item.logical_key,
                status: item.status === 'normal' ? 'disabled' : 'normal'
            });
            say('提示词状态已更新。');
            await loadPrompts(1);
        } catch (error) {
            showError(error);
        }
    }

    async function promptHistory(logicalKey) {
        try {
            var items = await api('/api/prompt/history?logical_key=' + encodeURIComponent(logicalKey));
            var list = el('prompt-history-list');
            clear(list);
            items.forEach(function (item) {
                var li = document.createElement('li');
                var strong = document.createElement('strong');
                strong.textContent = 'v' + item.version_no + ' · ' + item.name;
                var pre = document.createElement('pre');
                pre.textContent = item.content || '';
                li.appendChild(strong);
                li.appendChild(pre);
                list.appendChild(li);
            });
            el('prompt-history').hidden = false;
        } catch (error) {
            showError(error);
        }
    }

    async function loadArticleSelectors() {
        if (state.articleFormLoaded) {
            return;
        }
        var keywordSelect = el('article-keyword');
        var promptSelect = el('article-prompt');
        var knowledgeSelect = el('article-knowledge');
        clear(keywordSelect);
        clear(promptSelect);
        clear(knowledgeSelect);
        try {
            var keywordData = await api('/api/keyword/index?status=normal&page=1&per_page=100&sort=createtime&direction=desc');
            keywordData.items.forEach(function (item) {
                option(keywordSelect, item.id, item.keyword);
            });
            var promptData = await api('/api/prompt/index?status=normal&page=1&per_page=100&sort=updatetime&direction=desc');
            promptData.items.forEach(function (item) {
                option(promptSelect, item.id, item.name + ' v' + item.version_no);
            });
            var knowledgeData = await api('/api/knowledge/index?status=normal&page=1&per_page=100&sort=updatetime&direction=desc');
            knowledgeData.items.forEach(function (item) {
                option(knowledgeSelect, item.id, item.title + ' · ' + item.type);
            });
            state.articleFormLoaded = true;
        } catch (error) {
            showError(error);
        }
    }

    async function loadArticleQuestions() {
        var select = el('article-question');
        clear(select);
        var keywordId = el('article-keyword').value;
        if (!keywordId) {
            return;
        }
        try {
            var data = await api('/api/question/index?keyword_id=' + encodeURIComponent(keywordId) + '&status=normal&page=1&per_page=100&sort=createtime&direction=desc');
            data.items.forEach(function (item) {
                option(select, item.id, item.text);
            });
        } catch (error) {
            showError(error);
        }
    }

    async function loadArticles(page) {
        var tbody = el('article-list');
        clear(tbody);
        loadingRow(tbody, 4);
        var params = new URLSearchParams({
            page: String(page || 1),
            per_page: '20',
            sort: 'updatetime',
            direction: 'desc'
        });
        if (el('article-search').value) {
            params.set('search', el('article-search').value);
        }
        if (el('article-status').value) {
            params.set('status', el('article-status').value);
        }
        try {
            var data = await api('/api/article/index?' + params.toString());
            clear(tbody);
            if (!data.items || data.items.length === 0) {
                emptyRow(tbody, 4, '暂无文章');
            } else {
                data.items.forEach(function (item) {
                    var row = document.createElement('tr');
                    cell(row, item.title, 'text-truncate');
                    var statusCell = document.createElement('td');
                    statusCell.appendChild(statusBadge(item.status));
                    row.appendChild(statusCell);
                    cell(row, item.current_version ? 'v' + item.current_version.version_no : '-');
                    var actions = cell(row, '');
                    actions.appendChild(button('编辑', function () {
                        openArticleEdit(item.id);
                    }, false, 'primary'));
                    actions.appendChild(button('历史/预览', function () {
                        articleHistory(item);
                    }));
                    actions.appendChild(button(item.status === 'draft' ? '停用' : '恢复草稿', function () {
                        setArticleStatus(item);
                    }));
                    tbody.appendChild(row);
                });
            }
            renderPager(el('article-pager'), page || 1, data.total || 0, data.per_page || 20, loadArticles);
        } catch (error) {
            clear(tbody);
            errorRow(tbody, 4, error.message, function () {
                loadArticles(page || 1);
            });
        }
    }

    async function openArticleEdit(id) {
        try {
            await loadArticleSelectors();
            var detail = await api('/api/article/detail?id=' + id);
            el('article-id').value = String(detail.id);
            el('article-current-version-id').value = String(detail.current_version_id);
            el('article-keyword').value = String(detail.keyword_id);
            await loadArticleQuestions();
            el('article-question').value = String(detail.question_id);
            el('article-prompt').value = String(detail.current_version.prompt.id);
            Array.prototype.forEach.call(el('article-knowledge').options, function (opt) {
                opt.selected = detail.current_version.knowledge.some(function (k) {
                    return k.knowledge_item_id === Number(opt.value);
                });
            });
            el('article-title').value = detail.title;
            el('article-content').value = detail.current_version.content;
            el('article-form-heading').textContent = '编辑文章 · 当前 v' + detail.current_version.version_no;
        } catch (error) {
            showError(error);
        }
    }

    function resetArticleForm() {
        el('article-form').reset();
        el('article-id').value = '';
        el('article-current-version-id').value = '';
        el('article-form-heading').textContent = '新建文章';
        loadArticleSelectors();
    }

    async function saveArticle(event) {
        event.preventDefault();
        var id = el('article-id').value;
        var values = {
            keyword_id: el('article-keyword').value,
            question_id: el('article-question').value,
            prompt_template_id: el('article-prompt').value,
            knowledge_item_ids: selectedOptionValues(el('article-knowledge')),
            title: el('article-title').value,
            content: el('article-content').value
        };
        if (id) {
            delete values.keyword_id;
            delete values.question_id;
            values.id = id;
            values.current_version_id = el('article-current-version-id').value;
        }
        try {
            await post(id ? '/api/article/update' : '/api/article/create', values);
            resetArticleForm();
            say('文章已保存为新版本。');
            await loadArticles(1);
        } catch (error) {
            showError(error);
        }
    }

    async function setArticleStatus(item) {
        try {
            await post('/api/article/status', {
                id: item.id,
                status: item.status === 'draft' ? 'disabled' : 'draft'
            });
            say('文章状态已更新。');
            await loadArticles(1);
        } catch (error) {
            showError(error);
        }
    }

    async function articleHistory(item) {
        try {
            var items = await api('/api/article/history?id=' + item.id);
            var list = el('article-history-list');
            clear(list);
            items.forEach(function (version) {
                var li = document.createElement('li');
                var strong = document.createElement('strong');
                strong.textContent = 'v' + version.version_no + ' · ' + (version.prompt ? version.prompt.name : '') + ' · 素材 ' + (version.knowledge || []).length + ' 项';
                var buttonNode = button('查看', function () {
                    articlePreview(item.id, version.id);
                });
                li.appendChild(strong);
                li.appendChild(buttonNode);
                if (version.id !== item.current_version_id) {
                    li.appendChild(button('恢复为新版本', function () {
                        restoreArticle(item, version);
                    }));
                }
                list.appendChild(li);
            });
            el('article-history').hidden = false;
            await articlePreview(item.id, item.current_version_id);
        } catch (error) {
            showError(error);
        }
    }

    async function articlePreview(id, versionId) {
        try {
            var data = await api('/api/article/preview?id=' + id + '&version_id=' + versionId);
            el('article-preview').textContent = data.title + '\n\n' + data.html;
            el('article-history').hidden = false;
        } catch (error) {
            showError(error);
        }
    }

    async function restoreArticle(item, version) {
        if (!window.confirm('确认将 v' + version.version_no + ' 恢复为新的当前版本？')) {
            return;
        }
        try {
            await post('/api/article/restore', {
                id: item.id,
                version_id: version.id,
                current_version_id: item.current_version_id
            });
            say('旧版本已复制为新版本。');
            await loadArticles(1);
        } catch (error) {
            showError(error);
        }
    }

    async function loadAiFormData(parsed) {
        var keywordSelect = el('ai-keyword');
        var promptSelect = el('ai-prompt');
        var knowledgeSelect = el('ai-knowledge');
        var preferredKeywordId = parsed && parsed.params ? parsed.params.keyword_id : '';
        var preferredQuestionId = parsed && parsed.params ? parsed.params.question_id : '';
        clear(keywordSelect);
        clear(promptSelect);
        clear(knowledgeSelect);
        try {
            var keywordData = await api('/api/keyword/index?status=normal&page=1&per_page=100&sort=createtime&direction=desc');
            keywordData.items.forEach(function (item) {
                option(keywordSelect, item.id, item.keyword);
            });
            if (preferredKeywordId) {
                keywordSelect.value = String(preferredKeywordId);
            }
            var promptData = await api('/api/prompt/index?status=normal&page=1&per_page=100&sort=updatetime&direction=desc');
            promptData.items.forEach(function (item) {
                option(promptSelect, item.id, item.name + ' v' + item.version_no);
            });
            var knowledgeData = await api('/api/knowledge/index?type=text&status=normal&page=1&per_page=100&sort=updatetime&direction=desc');
            knowledgeData.items.forEach(function (item) {
                option(knowledgeSelect, item.id, item.title);
            });
            await loadAiQuestions(preferredQuestionId);
        } catch (error) {
            showError(error);
        }
    }

    async function loadAiQuestions(preferredQuestionId) {
        var select = el('ai-question');
        clear(select);
        var keywordId = el('ai-keyword').value;
        if (!keywordId) {
            await loadAiGeoAnswers();
            return;
        }
        try {
            var data = await api('/api/question/index?keyword_id=' + encodeURIComponent(keywordId) + '&status=normal&page=1&per_page=100&sort=createtime&direction=desc');
            data.items.forEach(function (item) {
                option(select, item.id, item.text);
            });
            if (preferredQuestionId) {
                select.value = String(preferredQuestionId);
            }
            await loadAiGeoAnswers();
        } catch (error) {
            showError(error);
        }
    }

    async function loadAiGeoAnswers() {
        var select = el('ai-geo-answer');
        var previous = select.value;
        clear(select);
        option(select, '', '不使用目标答案');
        var questionId = el('ai-question').value;
        if (!questionId) {
            return;
        }
        try {
            var data = await api('/api/geo_answer/history?question_id=' + encodeURIComponent(questionId) + '&page=1&per_page=100');
            (data.items || []).filter(function (item) {
                return item.status === 'confirmed';
            }).sort(function (a, b) {
                return (b.version_no || 0) - (a.version_no || 0);
            }).forEach(function (item) {
                option(select, item.id, 'v' + item.version_no + ' · 已确认 · ' + (item.created_at || ''));
            });
            if (previous) {
                select.value = previous;
            }
        } catch (error) {
            // keep the empty option; backend still validates when submitted
        }
    }

    function fillAiFromQuestion(keyword, question) {
        navigate('content', {
            tab: 'ai',
            keyword_id: keyword.id,
            question_id: question.id
        });
    }

    function randomIdempotencyKey(prefix) {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return prefix + '-' + window.crypto.randomUUID();
        }
        return prefix + '-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }

    function renderAiTask(task) {
        state.aiTaskId = task.id;
        state.aiTaskStatus = task.status;
        var labels = {
            queued: '排队中',
            running: '生成中',
            succeeded: '生成成功',
            failed: '生成失败',
            cancelled: '已取消'
        };
        el('ai-task-panel').hidden = false;
        el('ai-task-status').textContent = labels[task.status] || task.status;
        el('ai-task-geo-note').textContent = (task.status === 'succeeded' && state.aiGeoAnswerLabel)
            ? '已携带目标答案：' + state.aiGeoAnswerLabel
            : '';
        el('ai-task-error').textContent = task.status === 'failed' && task.error ? (task.error.message || '生成失败') : '';
        el('ai-cancel-btn').hidden = task.status !== 'queued';
        el('ai-retry-btn').hidden = task.status !== 'failed';
        el('ai-open-article-btn').hidden = task.status !== 'succeeded';
        if (task.status === 'queued' || task.status === 'running') {
            stopAiPolling();
            state.aiTimer = window.setTimeout(pollAiTask, 1500);
        } else {
            stopAiPolling();
        }
    }

    async function pollAiTask() {
        if (!state.aiTaskId) {
            return;
        }
        try {
            renderAiTask(await api('/api/ai_task/detail?id=' + state.aiTaskId));
        } catch (error) {
            say(error.message, true);
        }
    }

    function stopAiPolling() {
        if (state.aiTimer) {
            window.clearTimeout(state.aiTimer);
            state.aiTimer = null;
        }
    }

    async function createAiTask(event) {
        event.preventDefault();
        var submit = el('ai-submit');
        setDisabled(submit, true);
        try {
            var values = {
                keyword_id: el('ai-keyword').value,
                question_id: el('ai-question').value,
                prompt_template_id: el('ai-prompt').value,
                knowledge_item_ids: selectedOptionValues(el('ai-knowledge'))
            };
            var geoVersionId = el('ai-geo-answer').value;
            if (geoVersionId) {
                values.geo_answer_version_id = geoVersionId;
                var geoOption = el('ai-geo-answer').selectedOptions && el('ai-geo-answer').selectedOptions[0];
                state.aiGeoAnswerLabel = geoOption ? geoOption.text : '';
            } else {
                state.aiGeoAnswerLabel = '';
            }
            var task = await post('/api/ai_task/create', values, randomIdempotencyKey('ai'));
            renderAiTask(task);
            say('AI 任务已加入队列。');
        } catch (error) {
            showError(error);
        } finally {
            setDisabled(submit, false);
        }
    }

    async function retryAiTask() {
        if (!state.aiTaskId) {
            return;
        }
        try {
            renderAiTask(await post('/api/ai_task/retry', { id: state.aiTaskId }));
            say('AI 任务已重新排队。');
        } catch (error) {
            showError(error);
        }
    }

    async function cancelAiTask() {
        if (!state.aiTaskId) {
            return;
        }
        try {
            renderAiTask(await post('/api/ai_task/cancel', { id: state.aiTaskId }));
            say('AI 任务已取消。');
        } catch (error) {
            showError(error);
        }
    }

    async function openAiArticle() {
        if (!state.aiTaskId) {
            return;
        }
        try {
            var result = await api('/api/ai_task/result?id=' + state.aiTaskId);
            navigate('articles', { article_id: result.article.id });
        } catch (error) {
            showError(error);
        }
    }

    async function loadMedia(page) {
        state.mediaPage = page || 1;
        var seq = ++state.mediaRequestSeq;
        if (state.mediaAbortController) {
            state.mediaAbortController.abort();
        }
        state.mediaAbortController = new AbortController();
        var summaryNode = el('media-filter-summary');
        if (summaryNode) {
            summaryNode.textContent = '当前筛选 ' + mediaActiveFilterCount() + ' 项 · 加载中...';
        }
        var tbody = el('media-list');
        clear(tbody);
        renderMediaTableHeaders();
        loadingRow(tbody, mediaTableColumnCount());
        var params = new URLSearchParams({
            page: String(state.mediaPage),
            per_page: String(state.mediaPerPage || 20)
        });
        var filters = state.mediaFilters;
        if (filters.keyword) {
            params.set('keyword', filters.keyword);
        }
        if (filters.exact) {
            params.set('exact', '1');
        }
        if (filters.channel_id) {
            params.set('channel_id', filters.channel_id);
        }
        if (filters.platform) {
            params.set('platform', filters.platform);
        }
        if (filters.remark) {
            params.set('remark', filters.remark);
        }
        if (filters.start_price) {
            params.set('start_price', filters.start_price);
        }
        if (filters.end_price) {
            params.set('end_price', filters.end_price);
        }
        ['source_catalog', 'region', 'media_category', 'portal_type', 'channel_type', 'inclusion', 'draft_speed', 'industry', 'fans_range', 'read_range', 'occupation', 'special_projects', 'verification', 'distribution_zone', 'contact_type', 'ai_inclusion', 'gender', 'is_collect', 'special_tag'].forEach(function (key) {
            if (filters[key]) {
                params.set(key, filters[key]);
            }
        });
        if (state.mediaFavoriteOnly) {
            params.set('is_collect', '1');
        }
        try {
            var data = await api('/api/media/index?' + params.toString(), {
                signal: state.mediaAbortController.signal
            });
            if (seq !== state.mediaRequestSeq) {
                return;
            }
            state.mediaChannelOptions = data.channels || [];
            state.mediaChannelGroups = data.channel_groups || [];
            renderMediaChannelStatus(data.channels || []);
            renderMediaCatalogStatus(data);
            renderMediaTableHeaders();
            clear(tbody);
            state.mediaItems = {};
            state.mediaLastItems = data.items || [];
            if (!data.items || data.items.length === 0) {
                emptyRow(tbody, mediaTableColumnCount(), '暂无媒体');
            } else {
                data.items.forEach(function (item) {
                    state.mediaItems[item.id] = item;
                    state.selectedMediaNames[item.id] = item.name;
                    var row = document.createElement('tr');
                    var td = document.createElement('td');
                    var check = document.createElement('input');
                    check.type = 'checkbox';
                    check.className = 'media-selection';
                    check.value = String(item.id);
                    check.checked = state.selectedMediaIds.indexOf(item.id) >= 0;
                    check.disabled = !item.can_order;
                    check.addEventListener('change', function () {
                        if (check.checked) {
                            if (state.selectedMediaIds.indexOf(item.id) < 0) {
                                state.selectedMediaIds.push(item.id);
                            }
                        } else {
                            state.selectedMediaIds = state.selectedMediaIds.filter(function (id) {
                                return id !== item.id;
                            });
                        }
                        updateMediaSelection();
                    });
                    td.appendChild(check);
                    row.appendChild(td);
                    renderMediaRowCells(row, item);
                    tbody.appendChild(row);
                });
            }
            renderPager(el('media-pager'), state.mediaPage, data.total || 0, data.per_page || state.mediaPerPage, loadMedia);
            updateMediaSelection();
            renderMediaFilterSummary(data.total || 0);
            loadMediaFilterOptions();
        } catch (error) {
            if (seq !== state.mediaRequestSeq || (error && error.name === 'AbortError')) {
                return;
            }
            clear(tbody);
            errorRow(tbody, mediaTableColumnCount(), error.message, function () {
                loadMedia(state.mediaPage);
            });
        }
    }

    function renderMediaCatalogStatus(data) {
        var node = el('media-catalog-status');
        clear(node);
        var confirmed = (data.channels || []).filter(function (channel) {
            return channel.supplier_confirmed;
        }).length;
        state.mediaCatalogStatus = {
            last_sync_time: data.last_sync_time || '',
            confirmed_channels: confirmed,
            total_channels: (data.channels || []).length
        };
        var text = '目录更新时间：' + (data.last_sync_time || '未知')
            + ' · 可下单渠道：' + confirmed + '/' + (data.channels || []).length
            + ' · 共 ' + (data.total || 0) + ' 条';
        node.textContent = text;
    }

    async function openMediaDetail(id) {
        var drawer = el('media-detail-drawer');
        var container = el('media-detail-content');
        drawer.hidden = false;
        clear(container);
        container.appendChild(document.createTextNode('加载中...'));
        try {
            var detail = await api('/api/media/detail?id=' + id);
            renderMediaDetail(detail);
        } catch (error) {
            clear(container);
            container.appendChild(document.createTextNode(error.message));
            showError(error);
        }
    }

    function closeMediaDetail() {
        el('media-detail-drawer').hidden = true;
    }

    function renderMediaDetail(detail) {
        var container = el('media-detail-content');
        clear(container);
        var fields = [
            ['媒体名称', detail.name],
            ['渠道', detail.channel_name || detail.channel_code || '-'],
            ['平台', detail.platform || '-'],
            ['企业售价', detail.price_unconfirmed ? '价格未确认' : (detail.sale_price !== null && detail.sale_price !== undefined ? '¥' + detail.sale_price : '-')],
            ['状态', detail.can_order ? '可下单' : '暂未开通'],
            ['地区', detail.region || '-'],
            ['媒体分类', detail.media_category || '-'],
            ['门户类型', detail.portal_type || '-'],
            ['频道类型', detail.channel_type || '-'],
            ['收录情况', detail.inclusion || '-'],
            ['出稿速度', detail.draft_speed || '-'],
            ['PC 权重', detail.weighting || '-'],
            ['粉丝量', detail.fans_num || '-'],
            ['销量', detail.sales_volume || '-'],
            ['出稿率', detail.manuscript_rate || '-'],
            ['目录类型', detail.source_catalog || '-'],
            ['行业', detail.industry || '-'],
            ['职业分类', detail.occupation || '-'],
            ['特殊项目', detail.special_projects || detail.media_category || '-'],
            ['认证方式', detail.verification || '-'],
            ['通发专区', detail.distribution_zone || '-'],
            ['联系方式', detail.contact_type || '-'],
            ['AI收录', detail.ai_inclusion || '-'],
            ['性别', detail.gender || '-'],
            ['备注', detail.remark || '-'],
            ['媒体地址', detail.media_link || '-'],
            ['收藏状态', detail.is_collect ? '已收藏' : '未收藏'],
            ['阅读数', detail.read_num !== null && detail.read_num !== undefined ? String(detail.read_num) : '-'],
            ['价格区间', detail.price_range || '-'],
            ['粉丝区间', detail.fans_range || '-'],
            ['阅读区间', detail.read_range || '-'],
            ['短视频报价', detail.short_market_price || '-'],
            ['直播报价', detail.live_market_price || '-'],
            ['短视频能力', detail.short_video_capability || '-'],
            ['直播能力', detail.live_capability || '-'],
            ['最近同步', detail.last_synced_at || '-']
        ];
        fields.forEach(function (pair) {
            var row = document.createElement('div');
            row.className = 'media-detail-row';
            var label = document.createElement('span');
            label.textContent = pair[0];
            var value = document.createElement('strong');
            value.textContent = pair[1];
            row.appendChild(label);
            row.appendChild(value);
            container.appendChild(row);
        });
        if (isDemoText(detail.name)) {
            var demo = document.createElement('p');
            demo.className = 'demo-note';
            demo.appendChild(demoBadge());
            demo.appendChild(document.createTextNode(' 该媒体来自本地 DEMO/TEST 目录。'));
            container.appendChild(demo);
        }
    }

    function mediaSourceCatalogLabel(value) {
        var map = {
            '/media/type/1': '新闻媒体',
            '/media/type/3': '自媒体',
            '/video': '视频直播'
        };
        return map[value] || value || '-';
    }

    function mediaStatusBadge(item) {
        var span = document.createElement('span');
        span.className = 'status-badge';
        if (item.status === 'offline') {
            span.className += ' danger';
            span.textContent = '下架/不可用';
        } else if (item.price_unconfirmed) {
            span.className += ' warning';
            span.textContent = '价格未确认';
        } else if (!item.can_order) {
            span.className += ' warning';
            span.textContent = '暂不可购买';
        } else {
            span.className += ' success';
            span.textContent = '可购买';
        }
        return span;
    }

    var MEDIA_FILTER_MODEL = {
        '': [
            { key: 'channel', label: 'GEO业务渠道' },
            { key: 'platform', label: '平台' },
            { key: 'region', label: '地区' },
            { key: 'price', label: '价格' }
        ],
        '/media/type/1': [
            { key: 'channel', label: 'GEO业务渠道' },
            { key: 'portal_type', label: '门户类型' },
            { key: 'channel_type', label: '频道类型' },
            { key: 'region', label: '覆盖区域' },
            { key: 'price', label: '价格区间' },
            { key: 'inclusion', label: '收录情况' },
            { key: 'contact_type', label: '联系方式' },
            { key: 'draft_speed', label: '出稿速度' },
            { key: 'media_category', label: '特殊项目' },
            { key: 'distribution_zone', label: '通发专区' },
            { key: 'ai_inclusion', label: 'AI收录' }
        ],
        '/media/type/3': [
            { key: 'channel', label: 'GEO业务渠道' },
            { key: 'platform', label: '所属平台' },
            { key: 'industry', label: '行业分类' },
            { key: 'region', label: '覆盖区域' },
            { key: 'price', label: '价格区间' },
            { key: 'inclusion', label: '收录情况' },
            { key: 'verification', label: '认证方式' },
            { key: 'fans_range', label: '粉丝数' },
            { key: 'read_range', label: '平均阅读数' },
            { key: 'media_category', label: '特殊项目' },
            { key: 'distribution_zone', label: '通发专区' }
        ],
        '/video': [
            { key: 'channel', label: 'GEO业务渠道' },
            { key: 'platform', label: '所属平台' },
            { key: 'occupation', label: '职业分类' },
            { key: 'region', label: '所属地域' },
            { key: 'fans_range', label: '粉丝数区间' },
            { key: 'gender', label: '性别' },
            { key: 'special_projects', label: '特殊项目' },
            { key: 'price', label: '短视频价格' }
        ]
    };

    var MEDIA_FILTER_APPLICABILITY = {
        source_catalog: ['common'],
        channel: ['common'],
        region: ['/media/type/1', '/media/type/3', '/video'],
        price: ['common'],
        platform: ['/media/type/3', '/video'],
        industry: ['/media/type/3'],
        occupation: ['/video'],
        portal_type: ['/media/type/1'],
        channel_type: ['/media/type/1'],
        inclusion: ['/media/type/1', '/media/type/3'],
        draft_speed: ['/media/type/1'],
        contact_type: ['/media/type/1'],
        ai_inclusion: ['/media/type/1'],
        verification: ['/media/type/3'],
        distribution_zone: ['/media/type/1', '/media/type/3'],
        fans_range: ['/media/type/3', '/video'],
        read_range: ['/media/type/3'],
        media_category: ['/media/type/1', '/media/type/3'],
        special_projects: ['/video'],
        special_tag: ['/media/type/1', '/media/type/3', '/video'],
        gender: ['/video']
    };

    var MEDIA_TABLE_MODEL = {
        '': [
            { key: 'name', label: '媒体名称' },
            { key: 'source_catalog', label: '资源类型' },
            { key: 'channel_platform', label: '渠道/平台' },
            { key: 'category_industry', label: '分类/行业' },
            { key: 'remark', label: '备注' },
            { key: 'collect', label: '收藏' },
            { key: 'price', label: '企业售价' },
            { key: 'status', label: '状态' },
            { key: 'actions', label: '操作' }
        ],
        '/media/type/1': [
            { key: 'name', label: '媒体名称' },
            { key: 'channel_type', label: '频道类型' },
            { key: 'inclusion', label: '收录情况' },
            { key: 'price', label: '企业售价' },
            { key: 'weighting', label: 'PC权重' },
            { key: 'contact_type', label: '联系方式' },
            { key: 'manuscript_rate', label: '出稿率' },
            { key: 'draft_speed', label: '出稿速度' },
            { key: 'remark', label: '备注' },
            { key: 'collect', label: '收藏' },
            { key: 'status', label: '状态' },
            { key: 'actions', label: '操作' }
        ],
        '/media/type/3': [
            { key: 'name', label: '账号名称' },
            { key: 'platform', label: '所属平台' },
            { key: 'industry', label: '行业' },
            { key: 'fans', label: '粉丝数' },
            { key: 'read', label: '阅读数' },
            { key: 'price', label: '企业报价' },
            { key: 'remark', label: '备注' },
            { key: 'collect', label: '收藏' },
            { key: 'status', label: '状态' },
            { key: 'actions', label: '操作' }
        ],
        '/video': [
            { key: 'name', label: '账号名称' },
            { key: 'region', label: '所属地域' },
            { key: 'fans', label: '粉丝数' },
            { key: 'price', label: '短视频报价' },
            { key: 'live_price', label: '直播报价' },
            { key: 'remark', label: '备注' },
            { key: 'last_sync', label: '更新时间' },
            { key: 'collect', label: '收藏' },
            { key: 'status', label: '状态' },
            { key: 'actions', label: '操作' }
        ]
    };

    var MEDIA_PRICE_RANGES = [
        { label: '不限', min: '', max: '' },
        { label: '20元以内', min: '', max: '20' },
        { label: '20-50元', min: '20', max: '50' },
        { label: '50-100元', min: '50', max: '100' },
        { label: '100-150元', min: '100', max: '150' },
        { label: '150-200元', min: '150', max: '200' },
        { label: '200元以上', min: '200', max: '' }
    ];

    var MEDIA_VIDEO_PRICE_RANGES = [
        { label: '不限', min: '', max: '' },
        { label: '500以内', min: '', max: '500' },
        { label: '500-1000', min: '500', max: '1000' },
        { label: '1000-3000', min: '1000', max: '3000' },
        { label: '3000-5000', min: '3000', max: '5000' },
        { label: '5000-10000', min: '5000', max: '10000' },
        { label: '10000以上', min: '10000', max: '' }
    ];

    var MEDIA_TYPE_SPECIFIC_FILTERS = ['platform', 'portal_type', 'channel_type', 'inclusion', 'draft_speed', 'industry', 'fans_range', 'read_range', 'media_category', 'occupation', 'special_projects', 'verification', 'distribution_zone', 'contact_type', 'ai_inclusion', 'gender', 'special_tag'];

    function renderMediaChannelStatus(channels) {
        var confirmed = (channels || []).filter(function (channel) {
            return channel.supplier_confirmed;
        });
        var status = el('channel-status');
        clear(status);
        if (confirmed.length === 0) {
            status.hidden = false;
            status.textContent = '当前渠道均未确认开通，媒体不可下单。';
        } else {
            status.hidden = true;
        }
    }

    function mediaFilterStateKey(key) {
        return key === 'channel' ? 'channel_id' : key;
    }

    function mediaFilterRowOptions(key) {
        if (key === 'source_catalog') {
            return (state.mediaFilterOptions.source_catalog || []).map(function (item) {
                return { value: item.value, label: mediaSourceCatalogLabel(item.value), count: item.count };
            });
        }
        if (key === 'channel') {
            return (state.mediaChannelOptions || []).map(function (channel) {
                return {
                    value: channel.id,
                    label: channel.name + (channel.supplier_confirmed ? '' : '（未开通）'),
                    count: null
                };
            });
        }
        var catalog = state.mediaFilters.source_catalog || '/media/type/1';
        var applicable = MEDIA_FILTER_APPLICABILITY[key] || [];
        var list = [];
        if (catalog) {
            var catOpts = state.mediaFilterOptionsByCatalog[catalog] || {};
            list = key === 'special_tag'
                ? mergeOptions((catOpts.media_category || []).concat(catOpts.special_projects || []))
                : (catOpts[key] || []);
        } else {
            applicable.forEach(function (cat) {
                var catOpts = state.mediaFilterOptionsByCatalog[cat] || {};
                var source = key === 'special_tag'
                    ? (catOpts.media_category || []).concat(catOpts.special_projects || [])
                    : (catOpts[key] || []);
                list = mergeOptions(list.concat(source));
            });
        }
        return (list || []).map(function (item) {
            return { value: item.value, label: item.value, count: item.count };
        });
    }

    function mergeOptions(list) {
        var byValue = {};
        var order = [];
        (list || []).forEach(function (item) {
            var value = String(item.value);
            if (!byValue[value]) {
                byValue[value] = { value: item.value, count: 0 };
                order.push(value);
            }
            byValue[value].count += Number(item.count) || 0;
        });
        return order.map(function (value) {
            return byValue[value];
        });
    }

    function mediaFilterTag(key, value, label, active, count) {
        var node = document.createElement('button');
        node.type = 'button';
        node.className = 'media-filter-tag' + (active ? ' active' : '');
        node.setAttribute('data-filter-key', key);
        node.setAttribute('data-filter-value', value);
        node.textContent = label + (count ? ' ' + count : '');
        return node;
    }

    function renderMediaFilterRow(row, key) {
        var tags = row.querySelector('.media-filter-tags');
        clear(tags);
        var expanded = !!state.mediaFilterExpanded[key];
        var options = mediaFilterRowOptions(key);
        var stateKey = mediaFilterStateKey(key);
        var activeValue = state.mediaFilters[stateKey] || '';
        tags.appendChild(mediaFilterTag(key, '', '全部', activeValue === '', null));
        var visible = expanded ? options : options.slice(0, 12);
        visible.forEach(function (option) {
            tags.appendChild(mediaFilterTag(key, String(option.value), option.label, String(option.value) === activeValue, option.count));
        });
        var more = row.querySelector('.media-filter-more');
        if (more) {
            more.hidden = options.length <= 12;
            more.textContent = expanded ? '收起' : '更多';
        }
    }

    function renderMediaFilterMatrix() {
        var matrix = el('media-filter-matrix');
        if (!matrix) {
            return;
        }
        var catalog = state.mediaFilters.source_catalog || '/media/type/1';
        var model = MEDIA_FILTER_MODEL[catalog] || MEDIA_FILTER_MODEL['/media/type/1'];
        clear(matrix);
        model.forEach(function (def) {
            var row = document.createElement('div');
            row.className = 'media-filter-row';
            row.setAttribute('data-filter', def.key);
            var label = document.createElement('span');
            label.className = 'media-filter-label';
            label.textContent = def.label;
            row.appendChild(label);
            if (def.key === 'price') {
                var tags = document.createElement('div');
                tags.className = 'media-filter-tags media-filter-price';
                var quick = document.createElement('div');
                quick.className = 'media-price-quick';
                mediaPriceRangesForCatalog().forEach(function (range) {
                    quick.appendChild(mediaPriceQuickTag(range));
                });
                tags.appendChild(quick);
                var minInput = document.createElement('input');
                minInput.id = 'media-price-min';
                minInput.type = 'number';
                minInput.min = '0';
                minInput.step = '0.01';
                minInput.placeholder = '最低售价';
                minInput.value = state.mediaFilters.start_price || '';
                var sep = document.createElement('span');
                sep.className = 'media-price-sep';
                sep.textContent = '—';
                var maxInput = document.createElement('input');
                maxInput.id = 'media-price-max';
                maxInput.type = 'number';
                maxInput.min = '0';
                maxInput.step = '0.01';
                maxInput.placeholder = '最高售价';
                maxInput.value = state.mediaFilters.end_price || '';
                tags.appendChild(minInput);
                tags.appendChild(sep);
                tags.appendChild(maxInput);
                row.appendChild(tags);
            } else {
                var tagsBox = document.createElement('div');
                tagsBox.className = 'media-filter-tags';
                row.appendChild(tagsBox);
                var more = document.createElement('button');
                more.type = 'button';
                more.className = 'media-filter-more';
                more.textContent = '更多';
                row.appendChild(more);
                renderMediaFilterRow(row, def.key);
            }
            matrix.appendChild(row);
        });
        syncMediaDirectoryTabs();
    }

    function mediaPriceQuickTag(range) {
        var node = document.createElement('button');
        node.type = 'button';
        node.className = 'media-filter-tag media-price-tag' + (mediaPriceRangeActive(range) ? ' active' : '');
        node.setAttribute('data-price-min', range.min);
        node.setAttribute('data-price-max', range.max);
        node.textContent = range.label;
        return node;
    }

    function mediaPriceRangesForCatalog() {
        return state.mediaFilters.source_catalog === '/video'
            ? MEDIA_VIDEO_PRICE_RANGES
            : MEDIA_PRICE_RANGES;
    }

    function mediaPriceRangeActive(range) {
        return (state.mediaFilters.start_price || '') === range.min
            && (state.mediaFilters.end_price || '') === range.max;
    }

    function syncPriceChips() {
        Array.prototype.forEach.call(document.querySelectorAll('#media-filter-matrix .media-price-tag'), function (node) {
            var active = (state.mediaFilters.start_price || '') === node.getAttribute('data-price-min')
                && (state.mediaFilters.end_price || '') === node.getAttribute('data-price-max');
            node.classList.toggle('active', active);
        });
    }

    function syncMediaDirectoryTabs() {
        var current = state.mediaFilters.source_catalog || '/media/type/1';
        Array.prototype.forEach.call(document.querySelectorAll('#media-directory-tabs .media-directory-tab'), function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-directory') === current);
        });
    }

    function syncMediaSearchTabs() {
        var favorite = !!state.mediaFavoriteOnly;
        Array.prototype.forEach.call(document.querySelectorAll('#media-search-tabs .media-search-tab'), function (btn) {
            btn.classList.toggle('active', (btn.getAttribute('data-tab') === 'favorite') === favorite);
        });
    }

    function mediaExportCellText(key, item) {
        if (key === 'name') return item.name || '-';
        if (key === 'source_catalog') return mediaSourceCatalogLabel(item.source_catalog);
        if (key === 'channel_platform') {
            return (item.channel_name || item.channel_code || '') + (item.platform ? ' / ' + item.platform : '') || '-';
        }
        if (key === 'category_industry') {
            return (item.media_category || '') + (item.industry ? ' / ' + item.industry : '') || '-';
        }
        if (key === 'price') {
            return item.price_unconfirmed
                ? '价格未确认'
                : (item.sale_price !== null && item.sale_price !== undefined ? '¥' + item.sale_price : '-');
        }
        if (key === 'status') return item.can_order ? '可购买' : '不可购买';
        if (key === 'fans') {
            return item.fans_num !== null && item.fans_num !== undefined ? formatFans(item.fans_num) : '-';
        }
        if (key === 'read') {
            return item.read_num !== null && item.read_num !== undefined
                ? formatFans(item.read_num)
                : (item.read_range || '-');
        }
        if (key === 'remark') return item.remark || '-';
        if (key === 'collect') return item.is_collect ? '已收藏' : '未收藏';
        if (key === 'contact_type') return item.contact_type || '-';
        if (key === 'manuscript_rate') {
            return item.manuscript_rate !== null && item.manuscript_rate !== undefined
                ? String(item.manuscript_rate)
                : '-';
        }
        if (key === 'live_price') {
            return item.live_market_price !== null && item.live_market_price !== undefined
                ? '¥' + item.live_market_price
                : '-';
        }
        if (key === 'last_sync') return item.last_synced_at || '-';
        if (key === 'actions') return '';
        var value = item[key];
        return value === null || value === undefined || value === '' ? '-' : String(value);
    }

    function csvCell(value) {
        var text = String(value === null || value === undefined ? '' : value);
        if (text.indexOf(',') >= 0 || text.indexOf('"') >= 0 || text.indexOf('\n') >= 0) {
            return '"' + text.replace(/"/g, '""') + '"';
        }
        return text;
    }

    function exportMediaCsv() {
        var items = state.mediaLastItems || [];
        if (!items.length) {
            say('暂无可导出的媒体。', true);
            return;
        }
        var catalog = state.mediaFilters.source_catalog || '/media/type/1';
        var model = MEDIA_TABLE_MODEL[catalog] || MEDIA_TABLE_MODEL['/media/type/1'];
        var head = model.map(function (col) {
            return csvCell(col.label);
        });
        var rows = items.map(function (item) {
            return model.map(function (col) {
                return csvCell(mediaExportCellText(col.key, item));
            }).join(',');
        });
        var csv = [head.join(',')].concat(rows).join('\n');
        var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'media-export.csv';
        a.click();
        URL.revokeObjectURL(a.href);
    }

    async function toggleMediaFavorite(item, buttonNode) {
        var next = !item.is_collect;
        if (buttonNode) {
            buttonNode.disabled = true;
        }
        try {
            await post('/api/media/' + (next ? 'favorite' : 'unfavorite'), { media_id: item.id });
            item.is_collect = next ? 1 : 0;
            if (state.mediaItems[item.id]) {
                state.mediaItems[item.id].is_collect = item.is_collect;
            }
            if (buttonNode) {
                buttonNode.className = 'media-collect-btn' + (item.is_collect ? ' active' : '');
                buttonNode.textContent = item.is_collect ? '★ 已收藏' : '☆ 收藏';
                buttonNode.title = item.is_collect ? '取消收藏' : '收藏';
                buttonNode.disabled = false;
            }
            if (state.mediaFavoriteOnly && !item.is_collect) {
                loadMedia(state.mediaPage);
            } else {
                say(item.is_collect ? '已收藏。' : '已取消收藏。');
            }
        } catch (error) {
            if (buttonNode) {
                buttonNode.disabled = false;
            }
            showError(error);
        }
    }

    async function loadMediaFilterOptions() {
        if (state.mediaFilterLoaded || state.mediaFilterLoading) {
            renderMediaFilterMatrix();
            return;
        }
        state.mediaFilterLoading = true;
        try {
            var data = await api('/api/media/filters');
            state.mediaFilterOptions = data.options || {};
            state.mediaFilterOptionsByCatalog = data.by_catalog || {};
            state.mediaFilterLoaded = true;
        } catch (error) {
            state.mediaFilterOptions = {};
            state.mediaFilterOptionsByCatalog = {};
            state.mediaFilterLoaded = false;
        } finally {
            state.mediaFilterLoading = false;
        }
        renderMediaFilterMatrix();
    }

    function toggleMediaFilterMore(button) {
        var row = button.closest('.media-filter-row');
        if (!row) {
            return;
        }
        var key = row.getAttribute('data-filter');
        state.mediaFilterExpanded[key] = !state.mediaFilterExpanded[key];
        renderMediaFilterRow(row, key);
    }

    function mediaActiveFilterCount() {
        var filters = state.mediaFilters;
        var count = 0;
        if (filters.keyword) count++;
        if (filters.exact) count++;
        if (filters.remark) count++;
        if (filters.channel_id) count++;
        if (filters.platform) count++;
        ['source_catalog', 'region', 'media_category', 'portal_type', 'channel_type', 'inclusion', 'draft_speed', 'industry', 'fans_range', 'read_range', 'occupation', 'special_projects', 'verification', 'distribution_zone', 'contact_type', 'ai_inclusion', 'gender', 'is_collect', 'special_tag'].forEach(function (key) {
            if (filters[key]) count++;
        });
        if (filters.start_price || filters.end_price) count++;
        return count;
    }

    function renderMediaFilterSummary(total) {
        el('media-filter-summary').textContent = '当前筛选 ' + mediaActiveFilterCount() + ' 项 · 共 ' + (total || 0) + ' 条';
    }

    function renderMediaTableHeaders() {
        var headRow = document.querySelector('#media-table thead tr');
        if (!headRow) {
            return;
        }
        clear(headRow);
        var checkTh = document.createElement('th');
        checkTh.className = 'media-col-check';
        var sr = document.createElement('span');
        sr.className = 'sr-only';
        sr.textContent = '选择';
        checkTh.appendChild(sr);
        headRow.appendChild(checkTh);
        var catalog = state.mediaFilters.source_catalog || '/media/type/1';
        var columns = MEDIA_TABLE_MODEL[catalog] || MEDIA_TABLE_MODEL['/media/type/1'];
        columns.forEach(function (col) {
            var th = document.createElement('th');
            th.textContent = col.label;
            headRow.appendChild(th);
        });
    }

    function mediaTableColumnCount() {
        var catalog = state.mediaFilters.source_catalog || '/media/type/1';
        var columns = MEDIA_TABLE_MODEL[catalog] || MEDIA_TABLE_MODEL['/media/type/1'];
        return 1 + (columns ? columns.length : 0);
    }

    function renderMediaRowCells(row, item) {
        var catalog = state.mediaFilters.source_catalog || '/media/type/1';
        var columns = MEDIA_TABLE_MODEL[catalog] || MEDIA_TABLE_MODEL['/media/type/1'];
        columns.forEach(function (col) {
            var key = col.key;
            if (key === 'name') {
                var nameCell = document.createElement('td');
                var nameButton = button(item.name || '-', function () {
                    openMediaDetail(item.id);
                }, false, 'primary');
                nameButton.className = 'media-name-btn';
                nameCell.appendChild(nameButton);
                if (isDemoText(item.name) || isDemoText(item.external_id)) {
                    nameCell.appendChild(demoBadge());
                }
                row.appendChild(nameCell);
            } else if (key === 'source_catalog') {
                cell(row, mediaSourceCatalogLabel(item.source_catalog));
            } else if (key === 'channel_platform') {
                cell(row, (item.channel_name || item.channel_code || '-') + (item.platform ? ' / ' + item.platform : ''));
            } else if (key === 'category_industry') {
                cell(row, (item.media_category || '-') + (item.industry ? ' / ' + item.industry : ''));
            } else if (key === 'price') {
                cell(row, item.price_unconfirmed ? '价格未确认' : (item.sale_price !== null && item.sale_price !== undefined ? '¥' + item.sale_price : '-'));
            } else if (key === 'status') {
                var statusCell = document.createElement('td');
                statusCell.appendChild(mediaStatusBadge(item));
                row.appendChild(statusCell);
            } else if (key === 'actions') {
                var actions = cell(row, '');
                actions.appendChild(button('详情', function () {
                    openMediaDetail(item.id);
                }));
            } else if (key === 'fans') {
                cell(row, item.fans_num !== null && item.fans_num !== undefined ? formatFans(item.fans_num) : '-');
            } else if (key === 'read') {
                var readValue = item.read_num !== null && item.read_num !== undefined
                    ? formatFans(item.read_num)
                    : (item.read_range || '-');
                cell(row, readValue);
            } else if (key === 'remark') {
                cell(row, item.remark || '-');
            } else if (key === 'collect') {
                var collectCell = document.createElement('td');
                var collectBtn = document.createElement('button');
                collectBtn.type = 'button';
                collectBtn.className = 'media-collect-btn' + (item.is_collect ? ' active' : '');
                collectBtn.textContent = item.is_collect ? '★ 已收藏' : '☆ 收藏';
                collectBtn.title = item.is_collect ? '取消收藏' : '收藏';
                collectBtn.addEventListener('click', function () {
                    toggleMediaFavorite(item, collectBtn);
                });
                collectCell.appendChild(collectBtn);
                row.appendChild(collectCell);
            } else if (key === 'contact_type') {
                cell(row, item.contact_type || '-');
            } else if (key === 'manuscript_rate') {
                cell(row, item.manuscript_rate !== null && item.manuscript_rate !== undefined ? String(item.manuscript_rate) : '-');
            } else if (key === 'live_price') {
                cell(row, item.live_market_price !== null && item.live_market_price !== undefined ? '¥' + item.live_market_price : '-');
            } else if (key === 'last_sync') {
                cell(row, item.last_synced_at || '-');
            } else {
                var value = item[key];
                cell(row, value === null || value === undefined || value === '' ? '-' : String(value));
            }
        });
    }

    function formatFans(value) {
        var num = Number(value);
        if (!isFinite(num)) {
            return String(value);
        }
        if (num >= 100000000) {
            return (num / 100000000).toFixed(num % 100000000 === 0 ? 0 : 1) + '亿';
        }
        if (num >= 10000) {
            return (num / 10000).toFixed(num % 10000 === 0 ? 0 : 1) + 'w';
        }
        return String(num);
    }

    function setMediaFilter(key, value) {
        if (key === 'source_catalog') {
            MEDIA_TYPE_SPECIFIC_FILTERS.forEach(function (field) {
                state.mediaFilters[field] = '';
            });
            state.mediaFilterExpanded = {};
        }
        state.mediaFilters[mediaFilterStateKey(key)] = value;
        renderMediaFilterMatrix();
        syncMediaDirectoryTabs();
        loadMedia(1);
    }

    function resetMediaFilters() {
        if (state.mediaSearchTimer) {
            window.clearTimeout(state.mediaSearchTimer);
            state.mediaSearchTimer = null;
        }
        state.mediaFilters = {
            keyword: '',
            exact: false,
            remark: '',
            channel_id: '',
            platform: '',
            source_catalog: state.mediaFilters.source_catalog || '/media/type/1',
            region: '',
            media_category: '',
            portal_type: '',
            channel_type: '',
            inclusion: '',
            draft_speed: '',
            industry: '',
            fans_range: '',
            read_range: '',
            occupation: '',
            special_projects: '',
            verification: '',
            distribution_zone: '',
            contact_type: '',
            ai_inclusion: '',
            gender: '',
            is_collect: '',
            special_tag: '',
            start_price: '',
            end_price: ''
        };
        el('media-search').value = '';
        el('media-exact').checked = false;
        el('media-remark').value = '';
        el('media-price-min').value = '';
        el('media-price-max').value = '';
        state.mediaFilterExpanded = {};
        state.mediaFavoriteOnly = false;
        syncMediaSearchTabs();
        syncMediaDirectoryTabs();
        loadMedia(1);
    }

    function updateMediaSelection() {
        el('media-selected-count').textContent = '已选 ' + state.selectedMediaIds.length;
        var bar = el('media-order-bar');
        if (state.selectedMediaIds.length > 0) {
            bar.hidden = false;
            el('media-order-summary').textContent = '已选 ' + state.selectedMediaIds.length + ' 项，最终报价以订单确认为准。';
        } else {
            bar.hidden = true;
            el('media-order-summary').textContent = '';
        }
        syncOrderMaterialField();
    }

    function goToOrderFromMedia() {
        if (state.selectedMediaIds.length === 0) {
            say('请先选择媒体。', true);
            return;
        }
        var names = state.selectedMediaIds.map(function (id) {
            var item = state.mediaItems[id];
            return item ? item.name : (state.selectedMediaNames[id] || ('媒体 #' + id));
        }).join('、');
        el('order-confirm-selection').textContent = '已选媒体：' + names;
        navigate('orders', { tab: 'orders' });
    }

    function selectedRequiresVideoLink() {
        return state.selectedMediaIds.some(function (id) {
            var item = state.mediaItems[id];
            return item && item.source_catalog === '/video';
        });
    }

    function syncOrderMaterialField() {
        var input = el('order-original-link');
        if (!input) {
            return;
        }
        if (selectedRequiresVideoLink()) {
            input.placeholder = '视频链接（必填，可使用网盘分享链接）';
            input.setAttribute('aria-required', 'true');
        } else {
            input.placeholder = '原文链接（可选）';
            input.removeAttribute('aria-required');
        }
    }

    async function loadOrderArticles() {
        var select = el('order-article');
        var previous = select.value;
        clear(select);
        try {
            var data = await api('/api/article/index?status=draft&page=1&per_page=100&sort=updatetime&direction=desc');
            var seen = {};
            data.items.forEach(function (item) {
                var value = item.id + ':' + (item.current_version ? item.current_version.id : '');
                if (!seen[value]) {
                    seen[value] = true;
                    option(select, value, item.title + (item.current_version ? ' v' + item.current_version.version_no : ''));
                }
            });
            if (previous) {
                select.value = previous;
            }
        } catch (error) {
            showError(error);
        }
    }

    async function previewOrder() {
        var articleValue = el('order-article').value;
        if (!articleValue) {
            say('请先选择文章。', true);
            return;
        }
        if (state.selectedMediaIds.length === 0) {
            say('请先在媒体页面选择可下单媒体。', true);
            return;
        }
        var parts = articleValue.split(':');
        var previewButton = el('order-preview-btn');
        setDisabled(previewButton, true);
        try {
            var preview = await postForm('/api/order/preview', {
                article_id: parts[0],
                article_version_id: parts[1],
                media_ids: state.selectedMediaIds
            });
            renderOrderPreview(preview);
            say('报价已生成。');
        } catch (error) {
            clear(el('order-preview'));
            el('order-create-btn').hidden = true;
            showError(error);
        } finally {
            setDisabled(previewButton, false);
        }
    }

    function renderOrderPreview(preview) {
        state.orderPreview = preview;
        var container = el('order-preview');
        clear(container);
        var summary = document.createElement('p');
        summary.textContent = '合计：¥' + preview.total_amount + '，媒体 ' + preview.items.length + ' 项';
        container.appendChild(summary);
        preview.items.forEach(function (item) {
            var line = document.createElement('p');
            line.textContent = item.media_name + ' · ¥' + item.sale_price;
            if (isDemoText(item.media_name) || isDemoText(item.external_id)) {
                line.appendChild(demoBadge());
            }
            container.appendChild(line);
        });
        el('order-create-btn').hidden = false;
    }

    async function createOrder() {
        if (!state.orderPreview) {
            await previewOrder();
            if (!state.orderPreview) {
                return;
            }
        }
        var originalLink = (el('order-original-link').value || '').trim();
        var validLink = /^https?:\/\//i.test(originalLink);
        if (selectedRequiresVideoLink() && !validLink) {
            say('请填写 http/https 视频链接，可使用网盘分享链接。', true);
            return;
        }
        if (originalLink && !validLink) {
            say('原文链接如填写，必须以 http:// 或 https:// 开头。', true);
            return;
        }
        var total = state.orderPreview.total_amount;
        if (!window.confirm('确认下单并冻结 ¥' + total + '？')) {
            return;
        }
        var articleValue = el('order-article').value;
        var parts = articleValue.split(':');
        var createButton = el('order-create-btn');
        setDisabled(createButton, true);
        try {
            var order = await postForm('/api/order/create', {
                article_id: parts[0],
                article_version_id: parts[1],
                media_ids: state.selectedMediaIds,
                price_fingerprint: state.orderPreview.price_fingerprint,
                original_link: originalLink
            }, randomIdempotencyKey('order'));
            el('order-create-btn').hidden = true;
            state.selectedMediaIds = [];
            updateMediaSelection();
            if (order.submission_state === 'queue_failed') {
                say(order.submission_message || '订单已创建并冻结资金，但提交队列不可用，尚未发送供应商，请勿重复下单。', true);
            } else {
                say('订单已创建，已进入供应商提交队列。');
            }
            await showOrderDetail(order.id);
        } catch (error) {
            showError(error);
        } finally {
            setDisabled(createButton, false);
        }
    }

    async function loadOrders(page) {
        state.orderPage = page || 1;
        var tbody = el('order-list');
        clear(tbody);
        loadingRow(tbody, 6);
        var params = new URLSearchParams({
            page: String(state.orderPage),
            per_page: '20'
        });
        if (el('order-status').value) {
            params.set('status', el('order-status').value);
        }
        try {
            var data = await api('/api/order?' + params.toString());
            clear(tbody);
            if (!data.items || data.items.length === 0) {
                emptyRow(tbody, 6, '暂无订单');
            } else {
                data.items.forEach(function (order) {
                    var row = document.createElement('tr');
                    cell(row, order.order_no);
                    cell(row, (order.media_items || []).map(orderMediaDisplayName).join('、') || '-');
                    cell(row, '¥' + order.total_amount);
                    var statusCell = document.createElement('td');
                    statusCell.appendChild(statusBadge(order.status));
                    row.appendChild(statusCell);
                    cell(row, order.created_at);
                    var actions = cell(row, '');
                    actions.appendChild(button('详情', function () {
                        showOrderDetail(order.id);
                    }));
                    if (order.status === 'funds_frozen') {
                        actions.appendChild(button('取消', function () {
                            cancelOrder(order);
                        }, false, 'danger'));
                    }
                    tbody.appendChild(row);
                });
            }
            state.orderPages = Math.max(1, Math.ceil((data.total || 0) / (data.per_page || 20)));
            renderPager(el('order-pager'), state.orderPage, data.total || 0, data.per_page || 20, loadOrders);
        } catch (error) {
            clear(tbody);
            errorRow(tbody, 6, error.message, function () {
                loadOrders(state.orderPage);
            });
        }
    }

    function orderMediaDisplayName(item) {
        if (item.media_display_name) {
            return item.media_display_name;
        }
        if (item.media_name) {
            return item.media_name;
        }
        return '媒体 ID：' + item.media_id;
    }

    function stopOrderPolling() {
        if (state.orderTimer) {
            window.clearTimeout(state.orderTimer);
            state.orderTimer = null;
        }
    }

    async function showOrderDetail(id, background) {
        background = background === true;
        if (state.orderDetailId !== id) {
            state.orderDetailSignature = '';
        }
        state.orderDetailId = id;
        el('order-list-panel').hidden = true;
        el('order-confirm').hidden = true;
        el('order-detail-panel').hidden = false;
        var container = el('order-detail-content');
        if (!background) {
            stopOrderPolling();
            clear(container);
            container.appendChild(document.createTextNode('加载中...'));
        }
        try {
            var detail = await api('/api/order/detail?id=' + id);
            var polling = window.GeoOrderPolling;
            if (!background || !polling || polling.hasChanged(state.orderDetailSignature, detail)) {
                renderOrderDetail(detail);
                state.orderDetailSignature = polling ? polling.signature(detail) : JSON.stringify(detail);
            }
            stopOrderPolling();
            if (polling && polling.shouldPoll(detail.order, Date.now())) {
                state.orderTimer = window.setTimeout(function () {
                    showOrderDetail(id, true);
                }, 3000);
            }
        } catch (error) {
            stopOrderPolling();
            if (!background) {
                clear(container);
                container.appendChild(document.createTextNode(error.message));
            }
            showError(error);
        }
    }

    function renderOrderDetail(detail) {
        var container = el('order-detail-content');
        clear(container);
        var order = detail.order;
        var summary = document.createElement('p');
        summary.textContent = '订单号 ' + order.order_no + ' · 金额 ¥' + order.total_amount + ' · 冻结 ¥' + order.frozen_amount;
        var statusLine = document.createElement('p');
        statusLine.appendChild(document.createTextNode('状态：'));
        statusLine.appendChild(statusBadge(order.status));
        container.appendChild(summary);
        container.appendChild(statusLine);
        var polling = window.GeoOrderPolling;
        if (polling && polling.isStalled(order, Date.now())) {
            var stalledWarning = document.createElement('p');
            stalledWarning.className = 'page-message error';
            stalledWarning.textContent = '提交队列长时间未处理，订单尚未确认发送供应商。请勿重复下单，联系管理员检查发布 Worker。';
            container.appendChild(stalledWarning);
        }
        if (isDemoText(order.order_no) || (detail.items || []).some(function (item) {
            return isDemoText(item.supplier_order_no) || isDemoText(item.published_url);
        })) {
            var demoLine = document.createElement('p');
            demoLine.className = 'demo-note';
            demoLine.appendChild(demoBadge());
            demoLine.appendChild(document.createTextNode(' 当前订单包含本地 DEMO/TEST 发布记录，非真实供应商发布。'));
            container.appendChild(demoLine);
        }

        var itemTitle = document.createElement('h3');
        itemTitle.textContent = '媒体明细';
        container.appendChild(itemTitle);
        var itemTable = document.createElement('table');
        itemTable.appendChild(document.createElement('thead'));
        var itemHeader = document.createElement('tr');
        ['媒体', '售价', '状态', '发布链接'].forEach(function (text) {
            var th = document.createElement('th');
            th.textContent = text;
            itemHeader.appendChild(th);
        });
        itemTable.querySelector('thead').appendChild(itemHeader);
        var itemBody = document.createElement('tbody');
        (detail.items || []).forEach(function (item) {
            var row = document.createElement('tr');
            cell(row, orderMediaDisplayName(item));
            cell(row, '¥' + item.sale_snapshot);
            var statusCell = document.createElement('td');
            statusCell.appendChild(statusBadge(item.status));
            row.appendChild(statusCell);
            var url = item.published_url || '-';
            if (url !== '-') {
                var link = document.createElement('a');
                link.href = url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.className = 'btn btn-secondary';
                link.textContent = isDemoText(url) ? 'DEMO 链接' : '打开';
                cell(row, '').appendChild(link);
            } else {
                cell(row, '-');
            }
            itemBody.appendChild(row);
        });
        itemTable.appendChild(itemBody);
        container.appendChild(itemTable);

        var logTitle = document.createElement('h3');
        logTitle.textContent = '状态历史';
        container.appendChild(logTitle);
        var logTable = document.createElement('table');
        var logHead = document.createElement('thead');
        var logHeaderRow = document.createElement('tr');
        ['时间', '来源', '原状态', '新状态', '原因'].forEach(function (text) {
            var th = document.createElement('th');
            th.textContent = text;
            logHeaderRow.appendChild(th);
        });
        logHead.appendChild(logHeaderRow);
        logTable.appendChild(logHead);
        var logBody = document.createElement('tbody');
        (detail.status_logs || []).forEach(function (log) {
            var row = document.createElement('tr');
            cell(row, log.created_at);
            cell(row, log.source);
            cell(row, log.from_status || '-');
            cell(row, log.to_status);
            cell(row, log.reason || '-');
            logBody.appendChild(row);
        });
        logTable.appendChild(logBody);
        container.appendChild(logTable);

        var actions = document.createElement('div');
        actions.className = 'toolbar';
        actions.appendChild(button('返回列表', function () {
            el('order-detail-panel').hidden = true;
            el('order-confirm').hidden = false;
            el('order-list-panel').hidden = false;
            stopOrderPolling();
            state.orderDetailSignature = '';
            loadOrders(state.orderPage);
        }));
        actions.appendChild(button('GEO 时间线', function () {
            openOrderGeo(order);
        }, false, 'primary'));
        if (order.status === 'funds_frozen') {
            actions.appendChild(button('取消订单', function () {
                cancelOrder(order);
            }, false, 'danger'));
        }
        if (order.status === 'published' || order.status === 'partially_completed') {
            actions.appendChild(button('申请退款', function () {
                refundOrder(order);
            }, false, 'warning'));
        }
        container.appendChild(actions);

        if (detail.notifications && detail.notifications.length) {
            var notificationTitle = document.createElement('h3');
            notificationTitle.textContent = '订单通知';
            container.appendChild(notificationTitle);
            detail.notifications.forEach(function (notification) {
                var p = document.createElement('p');
                p.textContent = notification.title + ' · ' + (notification.is_read ? '已读' : '未读') + ' · ' + notification.created_at;
                container.appendChild(p);
            });
        }
    }

    async function openOrderGeo(order) {
        try {
            var article = await api('/api/article/detail?id=' + order.article_id);
            if (article && article.question_id) {
                navigate('geo', { question_id: article.question_id });
            } else {
                say('该订单文章未关联问题，无法打开 GEO 时间线。', true);
            }
        } catch (error) {
            showError(error);
        }
    }

    async function cancelOrder(order) {
        if (!window.confirm('确认取消订单 ' + order.order_no + '？')) {
            return;
        }
        try {
            await post('/api/order/cancel?id=' + order.id, {});
            say('订单已取消。');
            await showOrderDetail(order.id);
            await loadOrders(state.orderPage);
        } catch (error) {
            showError(error);
        }
    }

    async function refundOrder(order) {
        var reason = window.prompt('请输入退款原因', '');
        if (reason === null) {
            return;
        }
        if (!window.confirm('确认申请退款？')) {
            return;
        }
        try {
            await post('/api/order/refund?id=' + order.id, { reason: reason });
            say('退款申请已提交。');
            await showOrderDetail(order.id);
        } catch (error) {
            showError(error);
        }
    }

    async function loadWallet() {
        try {
            var balance = await api('/api/wallet/balance');
            el('wallet-available').textContent = balance.available_balance;
            el('wallet-frozen').textContent = balance.frozen_balance;
        } catch (error) {
            showError(error);
        }
        await loadLedger(state.walletPage);
    }

    async function loadLedger(page) {
        state.walletPage = page || 1;
        var tbody = el('ledger-list');
        clear(tbody);
        loadingRow(tbody, 6);
        var params = new URLSearchParams({
            page: String(state.walletPage),
            per_page: '20'
        });
        if (el('ledger-type').value) {
            params.set('entry_type', el('ledger-type').value);
        }
        try {
            var data = await api('/api/wallet/ledger?' + params.toString());
            clear(tbody);
            if (!data.items || data.items.length === 0) {
                emptyRow(tbody, 6, '暂无流水');
            } else {
                data.items.forEach(function (entry) {
                    var row = document.createElement('tr');
                    var typeCell = document.createElement('td');
                    typeCell.appendChild(ledgerBadge(entry.entry_type));
                    row.appendChild(typeCell);
                    cell(row, (entry.biz_type || '-') + (entry.biz_id ? '/' + entry.biz_id : ''));
                    cell(row, '¥' + entry.amount);
                    cell(row, formatDelta(entry.available_delta));
                    cell(row, formatDelta(entry.frozen_delta));
                    cell(row, entry.created_at);
                    tbody.appendChild(row);
                });
            }
            state.walletPages = Math.max(1, Math.ceil((data.total || 0) / (data.per_page || 20)));
            renderPager(el('ledger-pager'), state.walletPage, data.total || 0, data.per_page || 20, loadLedger);
        } catch (error) {
            clear(tbody);
            errorRow(tbody, 6, error.message, function () {
                loadLedger(state.walletPage);
            });
        }
    }

    async function loadNotifications(page) {
        state.notificationPage = page || 1;
        var tbody = el('notification-list');
        clear(tbody);
        loadingRow(tbody, 4);
        var params = new URLSearchParams({
            page: String(state.notificationPage),
            per_page: '20'
        });
        if (state.notificationUnreadOnly) {
            params.set('unread', '1');
        }
        try {
            var data = await api('/api/order/notifications?' + params.toString());
            clear(tbody);
            if (!data.items || data.items.length === 0) {
                emptyRow(tbody, 4, '暂无通知');
            } else {
                data.items.forEach(function (notification) {
                    var row = document.createElement('tr');
                    cell(row, notification.title, 'text-truncate');
                    var statusCell = document.createElement('td');
                    statusCell.appendChild(statusBadge(notification.is_read ? 'read' : 'unread'));
                    row.appendChild(statusCell);
                    cell(row, notification.created_at);
                    var actions = cell(row, '');
                    if (!notification.is_read) {
                        actions.appendChild(button('标记已读', function () {
                            markNotificationRead(notification);
                        }));
                    }
                    tbody.appendChild(row);
                });
            }
            state.notificationPages = Math.max(1, Math.ceil((data.total || 0) / (data.per_page || 20)));
            renderPager(el('notification-pager'), state.notificationPage, data.total || 0, data.per_page || 20, loadNotifications);
        } catch (error) {
            clear(tbody);
            errorRow(tbody, 4, error.message, function () {
                loadNotifications(state.notificationPage);
            });
        }
    }

    async function markNotificationRead(notification) {
        try {
            await post('/api/order/mark_notification_read?id=' + notification.id, {});
            say('通知已标记为已读。');
            await loadNotifications(state.notificationPage);
        } catch (error) {
            showError(error);
        }
    }

    async function loadGeo(questionId) {
        clearGeoPanels('加载中...');
        try {
            await loadGeoSelectors();
            if (questionId) {
                state.geoQuestionId = Number(questionId);
                var questionSelect = el('geo-question');
                var foundQuestion = Array.prototype.some.call(questionSelect.options, function (opt) {
                    return String(opt.value) === String(state.geoQuestionId);
                });
                if (foundQuestion) {
                    questionSelect.value = String(state.geoQuestionId);
                }
                await loadGeoQuestionData();
            } else if (state.geoQuestionId) {
                el('geo-question').value = String(state.geoQuestionId);
                await loadGeoQuestionData();
            } else {
                clearGeoPanels('请选择问题后载入 GEO 时间线。');
            }
        } catch (error) {
            clearGeoPanels(error.message || 'GEO 时间线加载失败。');
            showError(error);
        }
    }

    async function loadGeoSelectors() {
        var keywordSelect = el('geo-keyword');
        var previous = keywordSelect.value;
        clear(keywordSelect);
        option(keywordSelect, '', '请选择关键词');
        var data = await api('/api/keyword/index?status=normal&page=1&per_page=100&sort=createtime&direction=desc');
        data.items.forEach(function (item) {
            option(keywordSelect, item.id, item.keyword);
        });
        if (state.geoKeywordId && previous) {
            keywordSelect.value = String(state.geoKeywordId);
        } else if (previous) {
            keywordSelect.value = previous;
        }
        await loadGeoQuestions();
    }

    async function loadGeoQuestions() {
        var select = el('geo-question');
        var previous = select.value;
        clear(select);
        option(select, '', '请选择问题');
        var keywordId = el('geo-keyword').value;
        state.geoKeywordId = keywordId ? Number(keywordId) : null;
        if (!keywordId) {
            return;
        }
        var data = await api('/api/question/index?keyword_id=' + encodeURIComponent(keywordId) + '&status=normal&page=1&per_page=100&sort=createtime&direction=desc');
        data.items.forEach(function (item) {
            option(select, item.id, item.text);
        });
        if (state.geoQuestionId) {
            var found = Array.prototype.some.call(select.options, function (opt) {
                return String(opt.value) === String(state.geoQuestionId);
            });
            if (found) {
                select.value = String(state.geoQuestionId);
            }
        } else if (previous) {
            select.value = previous;
        }
    }

    async function loadGeoQuestionData() {
        var requestSeq = ++state.geoRequestSeq;
        var questionId = Number(el('geo-question').value || state.geoQuestionId || 0);
        if (!questionId) {
            clearGeoPanels('请选择问题后载入。');
            return;
        }
        state.geoQuestionId = questionId;
        clearGeoPanels('加载中...');
        var answerPromise = api('/api/geo_answer/history?question_id=' + questionId + '&page=1&per_page=100').catch(function () { return null; });
        var plansPromise = api('/api/geo_visibility/plans?question_id=' + questionId + '&page=1&per_page=100').catch(function () { return null; });
        var visibilityPromise = api('/api/geo_visibility/history?question_id=' + questionId + '&page=1&per_page=100').catch(function () { return null; });
        var settled = await Promise.all([answerPromise, plansPromise, visibilityPromise]);
        if (requestSeq !== state.geoRequestSeq) {
            return;
        }
        state.geoAnswerHistory = (settled[0] && settled[0].items) ? settled[0].items.slice().sort(function (a, b) {
            return (b.version_no || 0) - (a.version_no || 0);
        }) : [];
        state.geoPlansData = settled[1];
        state.geoHistoryData = settled[2];
        state.geoSelectedAnswer = state.geoAnswerHistory[0] || null;
        state.geoBaselineData = null;
        state.geoActiveResult = null;
        state.geoTrendData = null;
        state.geoSuggestionData = null;
        await loadGeoKnowledgeOptions(requestSeq);
        if (requestSeq !== state.geoRequestSeq) {
            return;
        }
        renderGeoAnswerSection();
        renderGeoBaselineSection();
        renderGeoPlansSection();
        renderGeoHistorySection();
        updateGeoStages();
        el('geo-empty-note').hidden = true;
    }

    async function loadGeoKnowledgeOptions(requestSeq) {
        var select = el('geo-knowledge');
        var previous = [];
        Array.prototype.forEach.call(select.options, function (opt) {
            if (opt.selected && opt.value) {
                previous.push(opt.value);
            }
        });
        clear(select);
        try {
            var data = await api('/api/knowledge/index?type=text&status=normal&page=1&per_page=100&sort=updatetime&direction=desc');
            if (requestSeq && requestSeq !== state.geoRequestSeq) {
                return;
            }
            data.items.forEach(function (item) {
                option(select, item.id, item.title);
            });
            if (previous.length) {
                Array.prototype.forEach.call(select.options, function (opt) {
                    opt.selected = previous.indexOf(opt.value) >= 0;
                });
            }
        } catch (error) {
            // generation will fail closed when no knowledge options are available
        }
    }

    function clearGeoPanels(message) {
        ['geo-answer-list', 'geo-answer-detail', 'geo-baseline-card', 'geo-plan-list', 'geo-visibility-history', 'geo-visibility-detail', 'geo-trend-card', 'geo-suggestion-card'].forEach(function (id) {
            var node = el(id);
            if (node) {
                clear(node);
            }
        });
        el('geo-answer-status').textContent = '未生成';
        el('geo-baseline-status').textContent = '未检测';
        el('geo-plan-status').textContent = '未创建';
        var note = el('geo-empty-note');
        note.hidden = !message;
        note.textContent = message || '';
        updateGeoStages();
    }

    function renderGeoAnswerSection() {
        var confirmed = state.geoAnswerHistory.filter(function (item) {
            return item.status === 'confirmed';
        });
        el('geo-answer-status').textContent = confirmed.length
            ? ('已确认 v' + confirmed[0].version_no)
            : (state.geoAnswerHistory.length ? '待确认' : '未生成');
        renderGeoAnswerList();
        renderGeoAnswerDetail(state.geoSelectedAnswer);
    }

    function renderGeoAnswerList() {
        var container = el('geo-answer-list');
        clear(container);
        if (!state.geoAnswerHistory.length) {
            var empty = document.createElement('p');
            empty.className = 'notice-empty';
            empty.textContent = '暂无目标答案版本。';
            container.appendChild(empty);
            return;
        }
        state.geoAnswerHistory.forEach(function (answer) {
            var row = document.createElement('div');
            row.className = 'geo-list-row';
            var selected = !!state.geoSelectedAnswer && state.geoSelectedAnswer.id === answer.id;
            if (selected) {
                row.classList.add('is-selected');
                row.setAttribute('aria-current', 'true');
            }
            var summary = document.createElement('span');
            summary.appendChild(document.createTextNode('v' + answer.version_no + ' · ' + (answer.source === 'ai' ? 'AI 生成' : '人工修改') + ' · ' + (answer.created_at || '')));
            summary.appendChild(document.createTextNode(' '));
            summary.appendChild(statusBadge(answer.status));
            row.appendChild(summary);
            var actions = document.createElement('span');
            actions.className = 'toolbar geo-row-actions';
            var viewButton = button(selected ? '正在查看' : '查看', function () {
                selectGeoAnswer(answer.id);
            }, false, selected ? 'primary' : 'secondary');
            viewButton.setAttribute('aria-pressed', selected ? 'true' : 'false');
            actions.appendChild(viewButton);
            if (answer.status === 'pending_review') {
                actions.appendChild(button('确认', function () {
                    confirmGeoAnswer(answer);
                }, false, 'primary'));
                actions.appendChild(button('修改', function () {
                    openGeoRevise(answer);
                }));
            }
            row.appendChild(actions);
            container.appendChild(row);
        });
    }

    async function selectGeoAnswer(id) {
        var answer = state.geoAnswerHistory.filter(function (item) {
            return item.id === Number(id);
        })[0];
        if (!answer) {
            return;
        }
        state.geoSelectedAnswer = answer;
        renderGeoAnswerList();
        renderGeoAnswerDetail(answer);
        say('正在查看目标答案 v' + answer.version_no + '。');
        var detail = el('geo-answer-detail');
        detail.setAttribute('tabindex', '-1');
        detail.focus({ preventScroll: true });
        detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function renderGeoAnswerDetail(answer) {
        var container = el('geo-answer-detail');
        clear(container);
        if (!answer) {
            var empty = document.createElement('p');
            empty.className = 'notice-empty';
            empty.textContent = '请先生成目标答案。';
            container.appendChild(empty);
            return;
        }
        var title = document.createElement('h4');
        title.textContent = '目标答案 v' + answer.version_no;
        container.appendChild(title);
        var meta = document.createElement('p');
        meta.className = 'panel-note';
        meta.appendChild(statusBadge(answer.status));
        meta.appendChild(document.createTextNode(' · ' + (answer.source === 'ai' ? 'AI 生成' : '人工修改') + ' · ' + (answer.created_at || '')));
        container.appendChild(meta);
        var answerText = document.createElement('p');
        answerText.className = 'geo-answer-text';
        answerText.textContent = answer.answer || '';
        container.appendChild(answerText);
        if (answer.recommendation_reason) {
            var reason = document.createElement('p');
            reason.className = 'panel-note';
            reason.textContent = '推荐理由：' + answer.recommendation_reason;
            container.appendChild(reason);
        }
        var factsTitle = document.createElement('h4');
        factsTitle.textContent = '事实证据（' + (answer.facts || []).length + '）';
        container.appendChild(factsTitle);
        (answer.facts || []).forEach(function (fact) {
            var item = document.createElement('div');
            item.className = 'geo-fact';
            var claim = document.createElement('strong');
            claim.textContent = fact.claim || '';
            var meta = document.createElement('span');
            meta.textContent = '来源：' + (fact.source_type === 'profile' ? '企业画像' : '知识素材') + ' · 引用：' + (fact.evidence_quote || '');
            item.appendChild(claim);
            item.appendChild(meta);
            container.appendChild(item);
        });
    }

    async function confirmGeoAnswer(answer) {
        try {
            var confirmed = await post('/api/geo_answer/confirm', { id: answer.id });
            say('目标答案 v' + confirmed.version_no + ' 已确认。');
            state.geoSelectedAnswer = confirmed;
            await loadGeoQuestionData();
        } catch (error) {
            showError(error);
        }
    }

    function openGeoRevise(answer) {
        state.geoSelectedAnswer = answer;
        el('geo-revise-id').value = String(answer.id);
        el('geo-revise-answer').value = answer.answer || '';
        el('geo-revise-reason').value = answer.recommendation_reason || '';
        el('geo-revise-form').hidden = false;
        el('geo-revise-answer').focus();
    }

    async function submitGeoRevise(event) {
        event.preventDefault();
        var id = el('geo-revise-id').value;
        if (!id || !state.geoSelectedAnswer) {
            return;
        }
        var submit = el('geo-revise-submit');
        setDisabled(submit, true);
        try {
            var revised = await post('/api/geo_answer/revise', {
                id: id,
                answer: el('geo-revise-answer').value,
                recommendation_reason: el('geo-revise-reason').value,
                facts: state.geoSelectedAnswer.facts || []
            });
            el('geo-revise-form').hidden = true;
            say('目标答案已保存为 v' + revised.version_no + '。');
            await loadGeoQuestionData();
        } catch (error) {
            showError(error);
        } finally {
            setDisabled(submit, false);
        }
    }

    async function generateGeoAnswer(event) {
        event.preventDefault();
        if (!state.geoQuestionId) {
            say('请先选择问题。', true);
            return;
        }
        var ids = selectedOptionValues(el('geo-knowledge'));
        var submit = el('geo-answer-generate-btn');
        var note = el('geo-empty-note');
        setDisabled(submit, true);
        submit.textContent = '生成中...';
        el('geo-answer-status').textContent = '生成中';
        note.hidden = false;
        note.textContent = '正在调用 AI 生成目标答案，请稍候。';
        try {
            var created = await post('/api/geo_answer/generate', {
                question_id: state.geoQuestionId,
                knowledge_item_ids: ids
            }, randomIdempotencyKey('answer'));
            say('目标答案 v' + created.version_no + ' 已生成，等待确认。');
            await loadGeoQuestionData();
        } catch (error) {
            el('geo-answer-status').textContent = '生成失败';
            note.hidden = false;
            note.textContent = error.message || '目标答案生成失败。';
            showError(error);
        } finally {
            setDisabled(submit, false);
            submit.textContent = '生成目标答案';
        }
    }

    function renderGeoBaselineSection() {
        var baseline = null;
        var latestCheck = state.geoHistoryData ? state.geoHistoryData.latest_baseline_check : null;
        if (state.geoHistoryData && state.geoHistoryData.items) {
            var candidates = state.geoHistoryData.items.filter(function (item) {
                return item.check_type === 'baseline';
            }).sort(function (a, b) {
                return (b.detected_at || '') < (a.detected_at || '') ? -1 : ((b.detected_at || '') > (a.detected_at || '') ? 1 : 0);
            });
            baseline = candidates[0] || null;
        }
        state.geoBaselineData = baseline;
        if (baseline) {
            el('geo-baseline-status').textContent = '已完成';
            renderGeoBaselineCard(baseline);
        } else if (latestCheck && latestCheck.status === 'failed') {
            renderGeoBaselineFailure(latestCheck);
        } else if (latestCheck && latestCheck.status === 'running') {
            renderGeoBaselinePending(latestCheck);
        } else {
            el('geo-baseline-status').textContent = '未检测';
            renderGeoBaselineCard(null);
        }
    }

    function renderGeoBaselineCard(data) {
        var container = el('geo-baseline-card');
        clear(container);
        if (!data) {
            var empty = document.createElement('p');
            empty.className = 'notice-empty';
            empty.textContent = '尚未完成发布前 Baseline。';
            container.appendChild(empty);
            return;
        }
        var title = document.createElement('h4');
        title.textContent = 'Baseline 结果';
        container.appendChild(title);
        var line = document.createElement('p');
        line.appendChild(document.createTextNode('检测时间：' + (data.detected_at || '') + ' · 平台：' + (data.platform || '') + ' · 模型：' + (data.model_name || '') + ' '));
        line.appendChild(statusBadge(data.status));
        container.appendChild(line);
        if (data.raw_answer) {
            var raw = document.createElement('p');
            raw.className = 'geo-raw-answer';
            raw.textContent = data.raw_answer;
            container.appendChild(raw);
        }
        if (data.citations && data.citations.length) {
            var citationTitle = document.createElement('h4');
            citationTitle.textContent = '引用（' + data.citations.length + '）';
            container.appendChild(citationTitle);
            data.citations.forEach(function (citation) {
                if (!citation.url) {
                    return;
                }
                var link = document.createElement('a');
                link.href = citation.url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = citation.url;
                var p = document.createElement('p');
                p.appendChild(link);
                container.appendChild(p);
            });
        }
    }

    function renderGeoBaselineFailure(check) {
        var container = el('geo-baseline-card');
        clear(container);
        var title = document.createElement('h4');
        title.textContent = 'Baseline 不可用';
        container.appendChild(title);
        var code = check.external_error_code || check.error_category || '';
        var message = check.error_message || '能力未开通或不可执行。';
        var p = document.createElement('p');
        p.className = 'error-text';
        p.textContent = code ? code + '：' + message : message;
        container.appendChild(p);
        el('geo-baseline-status').textContent = '不可用';
    }

    function renderGeoBaselinePending(check) {
        var container = el('geo-baseline-card');
        clear(container);
        var title = document.createElement('h4');
        title.textContent = 'Baseline 检测中';
        container.appendChild(title);
        var p = document.createElement('p');
        p.className = 'panel-note';
        p.textContent = '检测请求已受理，尚未产生可保存的检测结果。请稍后刷新，不要重复提交。';
        container.appendChild(p);
        el('geo-baseline-status').textContent = '检测中';
    }

    async function runGeoBaseline(event) {
        event.preventDefault();
        if (!state.geoQuestionId) {
            say('请先选择问题。', true);
            return;
        }
        var submit = el('geo-baseline-btn');
        setDisabled(submit, true);
        try {
            var check = await post('/api/geo_visibility/baseline', {
                question_id: state.geoQuestionId,
                platform: el('geo-baseline-platform').value,
                model_name: el('geo-baseline-model').value
            });
            if (check.status === 'failed') {
                renderGeoBaselineFailure(check);
                say(check.error_message || 'Baseline 执行失败。', true);
            } else if (check.status === 'running') {
                state.geoBaselineData = null;
                renderGeoBaselinePending(check);
                say('Baseline 正在检测，尚未产生结果。');
            } else if (check.status === 'succeeded' && check.result) {
                state.geoBaselineData = check.result || null;
                renderGeoBaselineCard(state.geoBaselineData);
                say('Baseline 已完成：' + check.result.status);
                await loadGeoQuestionData();
            } else {
                renderGeoBaselineFailure({
                    status: 'failed',
                    error_category: 'invalid_response',
                    error_message: 'Baseline 未返回可保存的检测结果。'
                });
                say('Baseline 未返回可保存的检测结果。', true);
            }
        } catch (error) {
            var detail = error.details || {};
            renderGeoBaselineFailure({
                status: 'failed',
                error_category: detail.error_category || '',
                external_error_code: detail.external_code || '',
                error_message: error.message
            });
            showError(error);
        } finally {
            setDisabled(submit, false);
        }
    }

    function renderGeoPlansSection() {
        var container = el('geo-plan-list');
        clear(container);
        var plans = state.geoPlansData && state.geoPlansData.items ? state.geoPlansData.items : [];
        el('geo-plan-status').textContent = plans.length ? (plans.length + ' 个计划') : '未创建';
        if (!plans.length) {
            var empty = document.createElement('p');
            empty.className = 'notice-empty';
            empty.textContent = '暂无复检计划。';
            container.appendChild(empty);
            return;
        }
        plans.forEach(function (plan) {
            var row = document.createElement('div');
            row.className = 'geo-list-row';
            var summary = document.createElement('span');
            summary.appendChild(document.createTextNode('#' + plan.id + ' · 间隔 ' + plan.interval_days + ' 天 · ' + plan.platform + ' / ' + plan.model_name + ' · 下次 ' + plan.next_run_at + ' '));
            summary.appendChild(statusBadge(plan.enabled ? 'normal' : 'disabled'));
            row.appendChild(summary);
            var actions = document.createElement('span');
            actions.className = 'toolbar geo-row-actions';
            var intervalSelect = document.createElement('select');
            intervalSelect.className = 'inline-select';
            [3, 4, 7].forEach(function (value) {
                option(intervalSelect, value, value + ' 天');
            });
            intervalSelect.value = String(plan.interval_days);
            intervalSelect.setAttribute('aria-label', '复检间隔');
            actions.appendChild(intervalSelect);
            actions.appendChild(button('更新间隔', function () {
                updateGeoPlanInterval(plan, intervalSelect.value);
            }));
            actions.appendChild(button(plan.enabled ? '停用' : '启用', function () {
                toggleGeoPlan(plan);
            }));
            row.appendChild(actions);
            container.appendChild(row);
        });
    }

    async function createGeoPlan(event) {
        event.preventDefault();
        if (!state.geoQuestionId) {
            say('请先选择问题。', true);
            return;
        }
        if (!state.geoBaselineData) {
            say('请先完成发布前 Baseline。', true);
            return;
        }
        var submit = el('geo-plan-create-btn');
        setDisabled(submit, true);
        try {
            var plan = await post('/api/geo_visibility/create_plan', {
                question_id: state.geoQuestionId,
                baseline_result_id: state.geoBaselineData.id,
                platform: state.geoBaselineData.platform,
                model_name: state.geoBaselineData.model_name,
                interval_days: el('geo-plan-interval').value
            });
            say('复检计划 #' + plan.id + ' 已创建。');
            await loadGeoQuestionData();
        } catch (error) {
            showError(error);
        } finally {
            setDisabled(submit, false);
        }
    }

    async function toggleGeoPlan(plan) {
        try {
            await post('/api/geo_visibility/update_plan', {
                id: plan.id,
                enabled: plan.enabled ? 0 : 1
            });
            say('复检计划状态已更新。');
            await loadGeoQuestionData();
        } catch (error) {
            showError(error);
        }
    }

    async function updateGeoPlanInterval(plan, intervalDays) {
        try {
            await post('/api/geo_visibility/update_plan', {
                id: plan.id,
                interval_days: intervalDays
            });
            say('复检间隔已更新。');
            await loadGeoQuestionData();
        } catch (error) {
            showError(error);
        }
    }

    function renderGeoHistorySection() {
        var tbody = el('geo-visibility-history');
        clear(tbody);
        var items = state.geoHistoryData && state.geoHistoryData.items ? state.geoHistoryData.items : [];
        if (!items.length) {
            emptyRow(tbody, 6, '暂无可见度检测记录');
            return;
        }
        items.forEach(function (result) {
            var row = document.createElement('tr');
            cell(row, result.detected_at || '');
            var typeCell = document.createElement('td');
            typeCell.appendChild(statusBadge(result.check_type || 'initial'));
            row.appendChild(typeCell);
            cell(row, result.platform || '-');
            cell(row, result.model_name || '-');
            var statusCell = document.createElement('td');
            statusCell.appendChild(statusBadge(result.status));
            row.appendChild(statusCell);
            var actions = cell(row, '');
            actions.appendChild(button('详情', function () {
                loadGeoResultDetail(result.id);
            }));
            if (result.check_type !== 'baseline') {
                actions.appendChild(button('趋势', function () {
                    runGeoTrend(result.id);
                }));
                actions.appendChild(button('建议', function () {
                    runGeoSuggestion(result.id);
                }));
            }
            tbody.appendChild(row);
        });
    }

    async function loadGeoResultDetail(id) {
        var container = el('geo-visibility-detail');
        clear(container);
        container.appendChild(document.createTextNode('加载中...'));
        try {
            var result = await api('/api/geo_visibility/result?id=' + id);
            state.geoActiveResult = result;
            renderGeoResultDetail(result);
        } catch (error) {
            clear(container);
            container.appendChild(document.createTextNode(error.message));
            showError(error);
        }
    }

    function renderGeoResultDetail(result) {
        var container = el('geo-visibility-detail');
        clear(container);
        var title = document.createElement('h4');
        title.textContent = '可见度检测详情 #' + result.id;
        container.appendChild(title);
        var line = document.createElement('p');
        line.appendChild(document.createTextNode('检测时间：' + (result.detected_at || '') + ' · ' + (result.platform || '') + ' / ' + (result.model_name || '') + ' '));
        line.appendChild(statusBadge(result.status));
        container.appendChild(line);
        if (result.raw_answer) {
            var raw = document.createElement('p');
            raw.className = 'geo-raw-answer';
            raw.textContent = result.raw_answer;
            container.appendChild(raw);
        }
        var evidence = result.evidence || {};
        if (typeof evidence.real_network_executed === 'boolean') {
            var mode = document.createElement('p');
            mode.className = 'panel-note';
            mode.textContent = evidence.real_network_executed ? '本次为真实联网检测。' : '本次为测试/未真实联网检测。';
            container.appendChild(mode);
        }
        if (result.citations && result.citations.length) {
            var citationTitle = document.createElement('h4');
            citationTitle.textContent = '引用（' + result.citations.length + '）';
            container.appendChild(citationTitle);
            result.citations.forEach(function (citation) {
                if (!citation.url) {
                    return;
                }
                var link = document.createElement('a');
                link.href = citation.url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = citation.url;
                var p = document.createElement('p');
                p.appendChild(link);
                container.appendChild(p);
            });
        }
    }

    async function runGeoTrend(resultId) {
        var container = el('geo-trend-card');
        clear(container);
        container.appendChild(document.createTextNode('加载中...'));
        try {
            var trend = await post('/api/geo_visibility/trend', { current_result_id: resultId });
            state.geoTrendData = trend;
            renderGeoTrend(trend);
        } catch (error) {
            clear(container);
            container.appendChild(document.createTextNode(error.message));
            showError(error);
        }
    }

    function renderGeoTrend(trend) {
        var container = el('geo-trend-card');
        clear(container);
        var title = document.createElement('h4');
        title.textContent = '趋势对比 #' + trend.id;
        container.appendChild(title);
        var line = document.createElement('p');
        line.appendChild(document.createTextNode('趋势：'));
        var trendBadge = document.createElement('span');
        trendBadge.className = 'status-badge ' + (trend.trend === 'up' ? 'success' : (trend.trend === 'down' ? 'danger' : 'info'));
        trendBadge.textContent = trend.trend === 'up' ? '上升' : (trend.trend === 'down' ? '下降' : '持平');
        line.appendChild(trendBadge);
        line.appendChild(document.createTextNode(' · 答案变化：' + (trend.answer_change_summary === 'changed' ? '已变化' : '未变化')));
        container.appendChild(line);
        var changes = trend.citation_change || {};
        var added = changes.added || [];
        var removed = changes.removed || [];
        var detail = document.createElement('p');
        detail.className = 'panel-note';
        detail.textContent = '引用新增 ' + added.length + ' 条，移除 ' + removed.length + ' 条。';
        container.appendChild(detail);
    }

    async function runGeoSuggestion(resultId) {
        var container = el('geo-suggestion-card');
        clear(container);
        container.appendChild(document.createTextNode('加载中...'));
        try {
            var suggestion = await post('/api/geo_visibility/suggestion', { result_id: resultId });
            state.geoSuggestionData = suggestion;
            renderGeoSuggestion(suggestion);
        } catch (error) {
            clear(container);
            container.appendChild(document.createTextNode(error.message));
            showError(error);
        }
    }

    function renderGeoSuggestion(suggestion) {
        var container = el('geo-suggestion-card');
        clear(container);
        var title = document.createElement('h4');
        title.textContent = '再优化建议 #' + suggestion.id;
        container.appendChild(title);
        renderSuggestionGroup(container, '知识缺口', suggestion.knowledge_gaps || []);
        renderSuggestionGroup(container, '问题方向', suggestion.question_directions || []);
        renderSuggestionGroup(container, '答案方向', suggestion.answer_directions || []);
        renderSuggestionGroup(container, '内容方向', suggestion.content_directions || []);
    }

    function renderSuggestionGroup(container, label, items) {
        var heading = document.createElement('h5');
        heading.textContent = label;
        container.appendChild(heading);
        var list = document.createElement('ul');
        items.forEach(function (item) {
            var li = document.createElement('li');
            li.textContent = item;
            list.appendChild(li);
        });
        container.appendChild(list);
    }

    function updateGeoStages() {
        var answerReady = state.geoAnswerHistory.some(function (item) {
            return item.status === 'confirmed';
        });
        var plansReady = state.geoPlansData && state.geoPlansData.items && state.geoPlansData.items.length > 0;
        var historyReady = state.geoHistoryData && state.geoHistoryData.items && state.geoHistoryData.items.length > 0;
        var completed = {
            answer: answerReady,
            baseline: !!state.geoBaselineData,
            publish: plansReady,
            recheck: plansReady,
            trend: historyReady
        };
        Array.prototype.forEach.call(document.querySelectorAll('.stage-step'), function (node) {
            var stage = node.getAttribute('data-geo-stage');
            node.classList.toggle('done', !!completed[stage]);
        });
    }

    el('login-form').addEventListener('submit', handleLogin);
    el('logout-btn').addEventListener('click', logout);
    window.addEventListener('hashchange', renderView);
    el('profile-form').addEventListener('submit', saveProfile);
    el('company-discovery-form').addEventListener('submit', discoverCompany);
    el('company-draft-form').addEventListener('submit', confirmCompanyDraft);
    el('company-intelligence-next').addEventListener('click', function () {
        navigate('answers');
    });
    el('top-notifications-btn').addEventListener('click', function () {
        navigate('orders', { tab: 'notifications' });
    });

    el('keyword-search-btn').addEventListener('click', function () {
        loadKeywords(1);
    });
    el('keyword-new-btn').addEventListener('click', function () {
        editKeyword(null);
    });
    el('keyword-form').addEventListener('submit', saveKeyword);
    el('keyword-cancel-btn').addEventListener('click', function () {
        el('keyword-form').hidden = true;
    });
    el('keyword-enable-btn').addEventListener('click', function () {
        batchKeywords('normal');
    });
    el('keyword-disable-btn').addEventListener('click', function () {
        batchKeywords('disabled');
    });
    el('question-search-btn').addEventListener('click', function () {
        loadQuestions(1);
    });
    el('question-new-btn').addEventListener('click', function () {
        editQuestion(null);
    });
    el('question-ai-generate-btn').addEventListener('click', generateT19Questions);
    el('question-form').addEventListener('submit', saveQuestion);
    el('question-cancel-btn').addEventListener('click', function () {
        el('question-form').hidden = true;
    });
    el('question-enable-btn').addEventListener('click', function () {
        batchQuestions('normal');
    });
    el('question-disable-btn').addEventListener('click', function () {
        batchQuestions('disabled');
    });
    el('question-back-btn').addEventListener('click', closeQuestions);
    el('question-geo-btn').addEventListener('click', function () {
        if (state.selectedKeyword) {
            state.geoKeywordId = state.selectedKeyword.id;
        }
        navigate('geo');
    });

    Array.prototype.forEach.call(document.querySelectorAll('#content-tabbar [data-content-tab]'), function (node) {
        node.addEventListener('click', function () {
            var tab = node.getAttribute('data-content-tab');
            setContentTab(tab);
            loadContentTab(tab, null);
        });
    });

    Array.prototype.forEach.call(document.querySelectorAll('#orders-tabbar [data-orders-tab]'), function (node) {
        node.addEventListener('click', function () {
            var tab = node.getAttribute('data-orders-tab');
            setOrdersTab(tab);
            loadOrdersTab(tab, null);
        });
    });

    el('knowledge-search-btn').addEventListener('click', function () {
        loadKnowledge(1);
    });
    el('knowledge-text-form').addEventListener('submit', saveKnowledge);
    el('knowledge-cancel-btn').addEventListener('click', function () {
        el('knowledge-text-form').reset();
        el('knowledge-id').value = '';
    });
    el('knowledge-file-form').addEventListener('submit', uploadKnowledge);

    el('prompt-search-btn').addEventListener('click', function () {
        loadPrompts(1);
    });
    el('prompt-form').addEventListener('submit', savePrompt);
    el('prompt-cancel-btn').addEventListener('click', function () {
        el('prompt-form').reset();
        el('prompt-logical-key').value = '';
        el('prompt-scene').value = 'article';
    });

    el('article-search-btn').addEventListener('click', function () {
        loadArticles(1);
    });
    el('article-new-btn').addEventListener('click', function () {
        resetArticleForm();
    });
    el('article-keyword').addEventListener('change', function () {
        loadArticleQuestions();
    });
    el('article-form').addEventListener('submit', saveArticle);
    el('article-cancel-btn').addEventListener('click', function () {
        resetArticleForm();
    });

    el('ai-keyword').addEventListener('change', function () {
        loadAiQuestions();
    });
    el('ai-question').addEventListener('change', function () {
        loadAiGeoAnswers();
    });
    el('ai-form').addEventListener('submit', createAiTask);
    el('ai-retry-btn').addEventListener('click', retryAiTask);
    el('ai-cancel-btn').addEventListener('click', cancelAiTask);
    el('ai-open-article-btn').addEventListener('click', openAiArticle);

    el('media-search-btn').addEventListener('click', function () {
        state.mediaFilters.keyword = el('media-search').value.trim();
        state.mediaFilters.exact = el('media-exact').checked;
        state.mediaFilters.remark = el('media-remark').value.trim();
        loadMedia(1);
    });
    el('media-search').addEventListener('input', function () {
        if (state.mediaSearchTimer) {
            window.clearTimeout(state.mediaSearchTimer);
        }
        state.mediaSearchTimer = window.setTimeout(function () {
            state.mediaFilters.keyword = el('media-search').value.trim();
            state.mediaFilters.exact = el('media-exact').checked;
            state.mediaFilters.remark = el('media-remark').value.trim();
            loadMedia(1);
        }, 400);
    });
    el('media-remark').addEventListener('input', function () {
        if (state.mediaSearchTimer) {
            window.clearTimeout(state.mediaSearchTimer);
        }
        state.mediaSearchTimer = window.setTimeout(function () {
            state.mediaFilters.remark = el('media-remark').value.trim();
            loadMedia(1);
        }, 400);
    });
    el('media-exact').addEventListener('change', function () {
        state.mediaFilters.exact = el('media-exact').checked;
        loadMedia(1);
    });
    el('media-reset-btn').addEventListener('click', resetMediaFilters);

    el('media-directory-tabs').addEventListener('click', function (event) {
        var btn = event.target.closest ? event.target.closest('.media-directory-tab') : null;
        if (btn) {
            setMediaFilter('source_catalog', btn.getAttribute('data-directory') || '');
        }
    });
    el('media-search-tabs').addEventListener('click', function (event) {
        var btn = event.target.closest ? event.target.closest('.media-search-tab') : null;
        if (btn) {
            state.mediaFavoriteOnly = btn.getAttribute('data-tab') === 'favorite';
            syncMediaSearchTabs();
            loadMedia(1);
        }
    });
    el('media-export-btn').addEventListener('click', exportMediaCsv);
    el('media-filter-matrix').addEventListener('click', function (event) {
        var priceTag = event.target.closest ? event.target.closest('.media-price-tag') : null;
        if (priceTag) {
            state.mediaFilters.start_price = priceTag.getAttribute('data-price-min') || '';
            state.mediaFilters.end_price = priceTag.getAttribute('data-price-max') || '';
            var minInput = el('media-price-min');
            var maxInput = el('media-price-max');
            if (minInput) {
                minInput.value = state.mediaFilters.start_price;
            }
            if (maxInput) {
                maxInput.value = state.mediaFilters.end_price;
            }
            syncPriceChips();
            loadMedia(1);
            return;
        }
        var tag = event.target.closest ? event.target.closest('.media-filter-tag') : null;
        if (tag) {
            var key = tag.getAttribute('data-filter-key');
            var value = tag.getAttribute('data-filter-value');
            if (key) {
                setMediaFilter(key, value);
            }
            return;
        }
        var more = event.target.closest ? event.target.closest('.media-filter-more') : null;
        if (more) {
            toggleMediaFilterMore(more);
        }
    });
    el('media-filter-matrix').addEventListener('change', function (event) {
        var target = event.target;
        if (target && target.id === 'media-price-min') {
            state.mediaFilters.start_price = target.value.trim();
            syncPriceChips();
            loadMedia(1);
        } else if (target && target.id === 'media-price-max') {
            state.mediaFilters.end_price = target.value.trim();
            syncPriceChips();
            loadMedia(1);
        }
    });
    el('media-per-page').addEventListener('change', function () {
        state.mediaPerPage = Number(el('media-per-page').value) || 20;
        loadMedia(1);
    });
    el('media-detail-close').addEventListener('click', closeMediaDetail);
    el('media-to-order-btn').addEventListener('click', goToOrderFromMedia);

    el('geo-keyword').addEventListener('change', function () {
        state.geoQuestionId = null;
        loadGeoQuestions();
    });
    el('geo-question').addEventListener('change', function () {
        loadGeoQuestionData();
    });
    el('geo-load-btn').addEventListener('click', function () {
        loadGeoQuestionData();
    });
    el('geo-answer-form').addEventListener('submit', generateGeoAnswer);
    el('geo-revise-form').addEventListener('submit', submitGeoRevise);
    el('geo-revise-cancel').addEventListener('click', function () {
        el('geo-revise-form').hidden = true;
    });
    el('geo-baseline-form').addEventListener('submit', runGeoBaseline);
    el('geo-plan-form').addEventListener('submit', createGeoPlan);
    el('geo-to-media-btn').addEventListener('click', function () {
        navigate('media');
    });
    el('geo-to-orders-btn').addEventListener('click', function () {
        navigate('orders', { tab: 'orders' });
    });

    el('order-article').addEventListener('change', function () {
        el('order-preview').textContent = '';
        el('order-create-btn').hidden = true;
        state.orderPreview = null;
    });
    el('order-preview-btn').addEventListener('click', previewOrder);
    el('order-create-btn').addEventListener('click', createOrder);
    el('order-filter-btn').addEventListener('click', function () {
        loadOrders(1);
    });
    el('order-detail-back').addEventListener('click', function () {
        el('order-detail-panel').hidden = true;
        el('order-confirm').hidden = false;
        el('order-list-panel').hidden = false;
        stopOrderPolling();
        state.orderDetailSignature = '';
        loadOrders(state.orderPage);
    });

    el('ledger-filter-btn').addEventListener('click', function () {
        loadLedger(1);
    });
    el('notification-unread-btn').addEventListener('click', function () {
        state.notificationUnreadOnly = !state.notificationUnreadOnly;
        el('notification-unread-btn').textContent = state.notificationUnreadOnly ? '查看全部' : '只看未读';
        loadNotifications(1);
    });

    if (state.token) {
        enterWorkspace().catch(function () {
            showLogin();
        });
    } else {
        showLogin();
    }
}());
