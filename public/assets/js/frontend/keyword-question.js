(function () {
    'use strict';

    var token = '';
    var keywordPage = 1;
    var keywordPages = 1;
    var questionPage = 1;
    var questionPages = 1;
    var selectedKeyword = null;
    var currentAiTaskId = null;
    var aiPollTimer = null;
    var message = document.getElementById('message');

    function element(id) { return document.getElementById(id); }
    function say(text, error) { message.textContent = text; message.className = error ? 'error' : 'success'; }
    function formBody(values) {
        var body = new URLSearchParams();
        Object.keys(values).forEach(function (key) {
            var value = values[key];
            body.set(key, Array.isArray(value) ? JSON.stringify(value) : String(value));
        });
        return body.toString();
    }
    function request(path, options) {
        var settings = options || {};
        settings.headers = settings.headers || {};
        settings.headers.Accept = 'application/json';
        if (token) { settings.headers.Authorization = 'Bearer ' + token; }
        return fetch(path, settings).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || payload.code !== 0) { throw new Error(payload.msg || '请求失败'); }
                return payload.data;
            });
        });
    }
    function post(path, values) {
        return request(path, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: formBody(values)});
    }
    function postIdempotent(path, values, key) {
        return request(path, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Idempotency-Key': key}, body: formBody(values)});
    }
    function button(label, handler) {
        var control = document.createElement('button');
        control.type = 'button'; control.textContent = label; control.addEventListener('click', handler); return control;
    }
    function cell(row, value) { var td = document.createElement('td'); td.textContent = value; row.appendChild(td); return td; }
    function selection(row, type, id) {
        var td = document.createElement('td'); var input = document.createElement('input');
        input.type = 'checkbox'; input.className = type + '-selection'; input.value = String(id);
        td.appendChild(input); row.appendChild(td);
    }
    function selected(type) {
        return Array.prototype.map.call(document.querySelectorAll('.' + type + '-selection:checked'), function (item) { return Number(item.value); });
    }
    function statusLabel(status) { return status === 'normal' ? '启用' : '停用'; }

    function loadKeywords() {
        var params = new URLSearchParams({page: String(keywordPage), per_page: '20', sort: 'createtime', direction: 'desc'});
        if (element('keyword-search').value) { params.set('search', element('keyword-search').value); }
        if (element('keyword-status').value) { params.set('status', element('keyword-status').value); }
        return request('/api/keyword/index?' + params.toString(), {method: 'GET'}).then(function (data) {
            var body = element('keyword-list'); body.textContent = '';
            data.items.forEach(function (item) {
                var row = document.createElement('tr'); selection(row, 'keyword', item.id);
                cell(row, item.keyword); cell(row, statusLabel(item.status));
                cell(row, new Date(item.updated_at * 1000).toLocaleString());
                var actions = cell(row, ''); actions.className = 'actions';
                actions.appendChild(button('详情/问题', function () { openQuestions(item); }));
                actions.appendChild(button('编辑', function () { editKeyword(item); }));
                actions.appendChild(button(item.status === 'normal' ? '停用' : '启用', function () {
                    post('/api/keyword/status', {id: item.id, status: item.status === 'normal' ? 'disabled' : 'normal'})
                        .then(function () { say('关键词状态已更新', false); return loadKeywords(); }).catch(fail);
                }));
                actions.appendChild(button('删除', function () {
                    post('/api/keyword/delete', {id: item.id}).then(function () { say('关键词已软删除', false); return loadKeywords(); }).catch(fail);
                }));
                body.appendChild(row);
            });
            keywordPages = Math.max(1, Math.ceil(data.total / data.per_page));
            element('keyword-page').textContent = keywordPage + ' / ' + keywordPages;
        });
    }
    function editKeyword(item) {
        element('keyword-id').value = item ? String(item.id) : '';
        element('keyword-text').value = item ? item.keyword : '';
        element('keyword-form').hidden = false;
        element('keyword-text').focus();
    }
    function openQuestions(item) {
        selectedKeyword = item; questionPage = 1;
        element('selected-keyword').textContent = item.keyword;
        element('question-panel').hidden = false;
        element('keyword-panel').hidden = true;
        loadQuestions().catch(fail);
    }
    function batchKeywords(status) {
        var ids = selected('keyword'); if (!ids.length) { say('请先选择关键词', true); return; }
        post('/api/keyword/batch', {ids: ids, status: status}).then(function () { say('关键词批量状态已更新', false); return loadKeywords(); }).catch(fail);
    }

    function loadQuestions() {
        var params = new URLSearchParams({keyword_id: String(selectedKeyword.id), page: String(questionPage), per_page: '20', sort: 'createtime', direction: 'desc'});
        if (element('question-search').value) { params.set('search', element('question-search').value); }
        if (element('question-status').value) { params.set('status', element('question-status').value); }
        return request('/api/question/index?' + params.toString(), {method: 'GET'}).then(function (data) {
            var body = element('question-list'); body.textContent = '';
            data.items.forEach(function (item) {
                var row = document.createElement('tr'); selection(row, 'question', item.id);
                cell(row, item.text); cell(row, item.source === 'manual' ? '人工录入' : item.source);
                cell(row, statusLabel(item.status)); cell(row, item.keyword.keyword);
                var actions = cell(row, ''); actions.className = 'actions';
                actions.appendChild(button('编辑', function () { editQuestion(item); }));
                actions.appendChild(button(item.status === 'normal' ? '停用' : '启用', function () {
                    post('/api/question/status', {id: item.id, status: item.status === 'normal' ? 'disabled' : 'normal'})
                        .then(function () { say('问题状态已更新', false); return loadQuestions(); }).catch(fail);
                }));
                actions.appendChild(button('删除', function () {
                    post('/api/question/delete', {id: item.id}).then(function () { say('问题已软删除', false); return loadQuestions(); }).catch(fail);
                }));
                body.appendChild(row);
            });
            questionPages = Math.max(1, Math.ceil(data.total / data.per_page));
            element('question-page').textContent = questionPage + ' / ' + questionPages;
        });
    }
    function editQuestion(item) {
        element('question-id').value = item ? String(item.id) : '';
        element('question-text').value = item ? item.text : '';
        element('question-form').hidden = false; element('question-text').focus();
    }
    function batchQuestions(status) {
        var ids = selected('question'); if (!ids.length) { say('请先选择问题', true); return; }
        post('/api/question/batch', {ids: ids, status: status}).then(function () { say('问题批量状态已更新', false); return loadQuestions(); }).catch(fail);
    }
    function aiKnowledgeIds() {
        var raw = element('ai-knowledge-ids').value.trim();
        if (!raw) { return []; }
        return raw.split(',').map(function (value) { return Number(value.trim()); }).filter(function (value) { return Number.isInteger(value) && value > 0; });
    }
    function aiIdempotencyKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') { return window.crypto.randomUUID(); }
        return 'ai-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }
    function renderAiTask(task) {
        currentAiTaskId = task.id;
        var labels = {queued: '排队中', running: '生成中', succeeded: '生成成功', failed: '生成失败', cancelled: '已取消'};
        element('ai-generation-status').textContent = labels[task.status] || task.status;
        element('ai-generation-cancel-task').hidden = task.status !== 'queued';
        element('ai-generation-retry').hidden = task.status !== 'failed';
        element('ai-generation-open-result').hidden = task.status !== 'succeeded';
        if (task.status === 'queued' || task.status === 'running') {
            clearTimeout(aiPollTimer); aiPollTimer = setTimeout(pollAiTask, 1500);
        }
    }
    function pollAiTask() {
        if (!currentAiTaskId) { return; }
        request('/api/ai_task/detail?id=' + encodeURIComponent(currentAiTaskId), {method: 'GET'}).then(renderAiTask).catch(fail);
    }
    function resetAiGeneration() {
        clearTimeout(aiPollTimer); aiPollTimer = null; currentAiTaskId = null;
        element('ai-generation-status').textContent = '请选择问题并指定精确提示词版本。';
        element('ai-generation-retry').hidden = true; element('ai-generation-cancel-task').hidden = true;
        element('ai-generation-open-result').hidden = true;
    }
    function fail(error) { say(error.message, true); }

    element('login-form').addEventListener('submit', function (event) {
        event.preventDefault();
        post('/api/auth/login', {account: element('account').value, password: element('password').value})
            .then(function (data) {
                token = data.token; element('password').value = ''; element('login-panel').hidden = true;
                element('keyword-panel').hidden = false; return loadKeywords();
            }).then(function () { say('关键词已加载', false); }).catch(function (error) { token = ''; fail(error); });
    });
    element('keyword-form').addEventListener('submit', function (event) {
        event.preventDefault(); var id = element('keyword-id').value;
        post(id ? '/api/keyword/update' : '/api/keyword/create', id ? {id: id, keyword: element('keyword-text').value} : {keyword: element('keyword-text').value})
            .then(function () { element('keyword-form').hidden = true; say('关键词保存成功', false); return loadKeywords(); }).catch(fail);
    });
    element('question-form').addEventListener('submit', function (event) {
        event.preventDefault(); var id = element('question-id').value;
        post(id ? '/api/question/update' : '/api/question/create', id ? {id: id, text: element('question-text').value} : {keyword_id: selectedKeyword.id, text: element('question-text').value})
            .then(function () { element('question-form').hidden = true; say('问题保存成功', false); return loadQuestions(); }).catch(fail);
    });
    element('ai-use-selected-question').addEventListener('click', function () {
        var ids = selected('question');
        if (!selectedKeyword || ids.length !== 1) { say('请在问题列表中只勾选一个问题', true); return; }
        element('ai-keyword-id').value = String(selectedKeyword.id); element('ai-question-id').value = String(ids[0]);
        element('ai-generation-question').textContent = '已选择问题 #' + ids[0];
    });
    element('ai-generation-form').addEventListener('submit', function (event) {
        event.preventDefault();
        postIdempotent('/api/ai_task/create', {
            keyword_id: element('ai-keyword-id').value,
            question_id: element('ai-question-id').value,
            prompt_template_id: element('ai-prompt-version-id').value,
            knowledge_item_ids: aiKnowledgeIds()
        }, aiIdempotencyKey()).then(renderAiTask).catch(fail);
    });
    element('ai-generation-retry').addEventListener('click', function () {
        if (currentAiTaskId) { post('/api/ai_task/retry', {id: currentAiTaskId}).then(renderAiTask).catch(fail); }
    });
    element('ai-generation-cancel-task').addEventListener('click', function () {
        if (currentAiTaskId) { post('/api/ai_task/cancel', {id: currentAiTaskId}).then(renderAiTask).catch(fail); }
    });
    element('ai-generation-open-result').addEventListener('click', function () {
        if (!currentAiTaskId) { return; }
        request('/api/ai_task/result?id=' + encodeURIComponent(currentAiTaskId), {method: 'GET'}).then(function (data) {
            window.location.href = '/index/article/index?article_id=' + encodeURIComponent(data.article.id);
        }).catch(fail);
    });
    element('ai-generation-close').addEventListener('click', resetAiGeneration);
    element('keyword-new-button').addEventListener('click', function () { editKeyword(null); });
    element('keyword-cancel').addEventListener('click', function () { element('keyword-form').hidden = true; });
    element('keyword-search-button').addEventListener('click', function () { keywordPage = 1; loadKeywords().catch(fail); });
    element('keyword-enable-selected').addEventListener('click', function () { batchKeywords('normal'); });
    element('keyword-disable-selected').addEventListener('click', function () { batchKeywords('disabled'); });
    element('keyword-prev').addEventListener('click', function () { if (keywordPage > 1) { keywordPage--; loadKeywords().catch(fail); } });
    element('keyword-next').addEventListener('click', function () { if (keywordPage < keywordPages) { keywordPage++; loadKeywords().catch(fail); } });
    element('question-new-button').addEventListener('click', function () { editQuestion(null); });
    element('question-cancel').addEventListener('click', function () { element('question-form').hidden = true; });
    element('question-search-button').addEventListener('click', function () { questionPage = 1; loadQuestions().catch(fail); });
    element('question-enable-selected').addEventListener('click', function () { batchQuestions('normal'); });
    element('question-disable-selected').addEventListener('click', function () { batchQuestions('disabled'); });
    element('question-prev').addEventListener('click', function () { if (questionPage > 1) { questionPage--; loadQuestions().catch(fail); } });
    element('question-next').addEventListener('click', function () { if (questionPage < questionPages) { questionPage++; loadQuestions().catch(fail); } });
    element('question-close').addEventListener('click', function () { element('question-panel').hidden = true; element('keyword-panel').hidden = false; selectedKeyword = null; });
}());
