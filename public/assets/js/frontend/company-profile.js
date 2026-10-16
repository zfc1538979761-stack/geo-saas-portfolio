(function () {
    'use strict';

    var bearerToken = '';
    var message = document.getElementById('message');
    var loginPanel = document.getElementById('login-panel');
    var profilePanel = document.getElementById('profile-panel');
    var loginForm = document.getElementById('login-form');
    var profileForm = document.getElementById('profile-form');

    function setMessage(text, isError) {
        message.textContent = text;
        message.className = isError ? 'error' : 'success';
    }

    function setValue(id, value) {
        document.getElementById(id).value = value === null ? '' : String(value);
    }

    function renderProfile(profile) {
        setValue('company-name', profile.company_name);
        setValue('brand', profile.brand);
        setValue('industry', profile.industry);
        setValue('products-services', (profile.products_services || []).join('\n'));
        setValue('website', profile.website);
        setValue('introduction', profile.introduction);
        setValue('mobile', profile.contacts ? profile.contacts.mobile : null);
        setValue('email', profile.contacts ? profile.contacts.email : null);
        document.getElementById('profile-completeness').textContent =
            String(profile.profile_completeness || 0);
        document.getElementById('missing-fields').textContent =
            (profile.missing_fields || []).join('、') || '无';
        document.getElementById('ai-ready').textContent =
            profile.ai_ready ? '已具备AI写作基础资料' : '尚未具备AI写作基础资料';
        loginPanel.hidden = true;
        profilePanel.hidden = false;
    }

    function request(path, options) {
        var requestOptions = options || {};
        requestOptions.headers = requestOptions.headers || {};
        requestOptions.headers.Accept = 'application/json';
        if (bearerToken) {
            requestOptions.headers.Authorization = 'Bearer ' + bearerToken;
        }
        return fetch(path, requestOptions).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || payload.code !== 0) {
                    throw new Error(payload.msg || '请求失败');
                }
                return payload.data;
            });
        });
    }

    loginForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var body = new URLSearchParams();
        body.set('account', document.getElementById('account').value);
        body.set('password', document.getElementById('password').value);
        request('/api/auth/login', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        }).then(function (data) {
            bearerToken = data.token;
            document.getElementById('password').value = '';
            return request('/api/company/profile', {method: 'GET'});
        }).then(function (profile) {
            renderProfile(profile);
            setMessage('企业资料已加载', false);
        }).catch(function (error) {
            bearerToken = '';
            setMessage(error.message, true);
        });
    });

    profileForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var products = document.getElementById('products-services').value
            .split(/\r?\n/)
            .map(function (item) { return item.trim(); })
            .filter(function (item) { return item !== ''; });
        var body = new URLSearchParams();
        body.set('company_name', document.getElementById('company-name').value);
        body.set('brand', document.getElementById('brand').value);
        body.set('industry', document.getElementById('industry').value);
        body.set('products_services', JSON.stringify(products));
        body.set('website', document.getElementById('website').value);
        body.set('introduction', document.getElementById('introduction').value);
        body.set('mobile', document.getElementById('mobile').value);
        body.set('email', document.getElementById('email').value);
        request('/api/company/profile', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        }).then(function (profile) {
            renderProfile(profile);
            setMessage('企业资料保存成功', false);
        }).catch(function (error) {
            setMessage(error.message, true);
        });
    });
}());
