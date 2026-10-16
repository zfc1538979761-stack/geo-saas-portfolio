(function () {
    'use strict';
    var token = '';
    var moduleName = document.documentElement.getAttribute('data-module');
    function el(id) { return document.getElementById(id); }
    function say(text, failed) { var node = el('message'); node.textContent = text; node.className = failed ? 'error' : 'success'; }
    function encoded(values) { var body = new URLSearchParams(); Object.keys(values).forEach(function (key) { body.set(key, Array.isArray(values[key]) ? JSON.stringify(values[key]) : String(values[key])); }); return body.toString(); }
    function request(path, options) {
        var settings = options || {method: 'GET'}; settings.headers = settings.headers || {}; settings.headers.Accept = 'application/json';
        if (token) { settings.headers.Authorization = 'Bearer ' + token; }
        return fetch(path, settings).then(function (response) { return response.json().then(function (payload) { if (!response.ok || payload.code !== 0) { throw new Error(payload.msg || '请求失败'); } return payload.data; }); });
    }
    function post(path, values) { return request(path, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: encoded(values)}); }
    function cell(row, value) { var node = document.createElement('td'); node.textContent = value === null || value === undefined ? '' : String(value); row.appendChild(node); return node; }
    function button(label, handler) { var node = document.createElement('button'); node.type = 'button'; node.textContent = label; node.addEventListener('click', handler); return node; }
    function option(select, value, label) { var node = document.createElement('option'); node.value = String(value); node.textContent = label; select.appendChild(node); }
    function clear(node) { while (node.firstChild) { node.removeChild(node.firstChild); } }
    function statusLabel(status) { return status === 'normal' ? '启用' : (status === 'draft' ? '草稿' : '停用'); }
    function fail(error) { say(error.message, true); }
    function listQuery(extra) { var params = new URLSearchParams({page: '1', per_page: '100', sort: 'updatetime', direction: 'desc'}); if (el('search') && el('search').value) { params.set('search', el('search').value); } if (el('status-filter') && el('status-filter').value) { params.set('status', el('status-filter').value); } Object.keys(extra || {}).forEach(function (key) { if (extra[key] !== '') { params.set(key, extra[key]); } }); return params.toString(); }

    function knowledgeModule() {
        function load() {
            return request('/api/knowledge/index?' + listQuery({type: el('type-filter').value}), {method: 'GET'}).then(function (data) {
                var body = el('items'); clear(body); data.items.forEach(function (item) {
                    var row = document.createElement('tr'); cell(row, item.title); cell(row, item.type); cell(row, item.file ? item.file.original_name : '—'); cell(row, item.file ? Math.ceil(item.file.size_bytes / 1024) + ' KB' : '—'); cell(row, statusLabel(item.status));
                    var actions = cell(row, '');
                    if (item.type === 'text') { actions.appendChild(button('编辑', function () { el('text-id').value=item.id; el('title').value=item.title; el('content').value=item.content; el('title').focus(); })); }
                    actions.appendChild(button('预览', function () { request('/api/knowledge/preview?id=' + item.id, {method:'GET'}).then(function (result) { say(item.type === 'text' ? result.html.replace(/<br\s*\/?\s*>/gi, '\n').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&') : '文件：'+item.file.original_name, false); }).catch(fail); }));
                    if (item.file) { actions.appendChild(button('下载', function () { request('/api/knowledge/download?id='+item.id,{method:'GET'}).then(function (result) { var binary=atob(result.base64); var bytes=new Uint8Array(binary.length); for(var i=0;i<binary.length;i++){bytes[i]=binary.charCodeAt(i);} var link=document.createElement('a'); link.href=URL.createObjectURL(new Blob([bytes],{type:result.mime})); link.download=item.file.safe_name; link.click(); setTimeout(function(){URL.revokeObjectURL(link.href);},1000); }).catch(fail); })); }
                    actions.appendChild(button(item.status==='normal'?'停用':'启用', function(){post('/api/knowledge/status',{id:item.id,status:item.status==='normal'?'disabled':'normal'}).then(load).catch(fail);}));
                    actions.appendChild(button('软删除', function(){post('/api/knowledge/delete',{id:item.id}).then(load).catch(fail);})); body.appendChild(row);
                });
            });
        }
        el('text-form').addEventListener('submit',function(event){event.preventDefault();var id=el('text-id').value;post(id?'/api/knowledge/update':'/api/knowledge/create',id?{id:id,title:el('title').value,content:el('content').value}:{title:el('title').value,content:el('content').value}).then(function(){el('text-form').reset();el('text-id').value='';say('文本素材已保存',false);return load();}).catch(fail);});
        el('text-cancel').addEventListener('click',function(){el('text-form').reset();el('text-id').value='';});
        el('file-form').addEventListener('submit',function(event){event.preventDefault();var data=new FormData();data.append('title',el('file-title').value);data.append('file',el('file').files[0]);request('/api/knowledge/upload',{method:'POST',body:data}).then(function(){el('file-form').reset();say('文件已通过媒介集市存储适配器上传',false);return load();}).catch(fail);});
        el('refresh').addEventListener('click',function(){load().catch(fail);}); return load;
    }

    function promptModule() {
        function history(key) { return request('/api/prompt/history?logical_key='+encodeURIComponent(key),{method:'GET'}).then(function(items){var list=el('history-items');clear(list);items.forEach(function(item){var li=document.createElement('li');var heading=document.createElement('strong');heading.textContent='v'+item.version_no+' · '+item.name+' · '+statusLabel(item.status);var pre=document.createElement('pre');pre.textContent=item.content;li.appendChild(heading);li.appendChild(pre);list.appendChild(li);});el('history').hidden=false;}); }
        function edit(item){el('logical-key').value=item.logical_key;el('name').value=item.name;el('scene').value=item.scene;el('content').value='';el('form-heading').textContent='为模板创建新版本（当前 v'+item.version_no+'）';el('content').focus();}
        function load(){return request('/api/prompt/index?'+listQuery({}),{method:'GET'}).then(function(data){var body=el('items');clear(body);data.items.forEach(function(item){var row=document.createElement('tr');cell(row,item.name);cell(row,item.scene);cell(row,'v'+item.version_no);cell(row,statusLabel(item.status));var actions=cell(row,'');actions.appendChild(button('新版本',function(){edit(item);}));actions.appendChild(button('历史',function(){history(item.logical_key).catch(fail);}));actions.appendChild(button(item.status==='normal'?'停用':'启用',function(){post('/api/prompt/status',{logical_key:item.logical_key,status:item.status==='normal'?'disabled':'normal'}).then(load).catch(fail);}));body.appendChild(row);});});}
        el('prompt-form').addEventListener('submit',function(event){event.preventDefault();var key=el('logical-key').value;var data={name:el('name').value,scene:el('scene').value,content:el('content').value};if(key){data.logical_key=key;}post(key?'/api/prompt/version':'/api/prompt/create',data).then(function(){el('prompt-form').reset();el('logical-key').value='';el('scene').value='article';el('form-heading').textContent='新建模板';say('提示词已保存为不可覆盖的新版本',false);return load();}).catch(fail);});
        el('prompt-cancel').addEventListener('click',function(){el('prompt-form').reset();el('logical-key').value='';el('scene').value='article';el('form-heading').textContent='新建模板';});el('refresh').addEventListener('click',function(){load().catch(fail);});return load;
    }

    function articleModule() {
        var articlesById={};
        function selectedValues(select){return Array.prototype.filter.call(select.options,function(item){return item.selected;}).map(function(item){return Number(item.value);});}
        function loadQuestions(){var keyword=el('keyword').value;clear(el('question'));if(!keyword){return Promise.resolve();}return request('/api/question/index?keyword_id='+encodeURIComponent(keyword)+'&status=normal&page=1&per_page=100&sort=createtime&direction=desc',{method:'GET'}).then(function(data){data.items.forEach(function(item){option(el('question'),item.id,item.text);});});}
        function loadSelectors(){return Promise.all([
            request('/api/keyword/index?status=normal&page=1&per_page=100&sort=createtime&direction=desc',{method:'GET'}).then(function(data){clear(el('keyword'));data.items.forEach(function(item){option(el('keyword'),item.id,item.keyword);});return loadQuestions();}),
            request('/api/prompt/index?status=normal&page=1&per_page=100&sort=updatetime&direction=desc',{method:'GET'}).then(function(data){clear(el('prompt'));data.items.forEach(function(item){option(el('prompt'),item.id,item.name+' v'+item.version_no);});}),
            request('/api/knowledge/index?status=normal&page=1&per_page=100&sort=updatetime&direction=desc',{method:'GET'}).then(function(data){clear(el('knowledge'));data.items.forEach(function(item){option(el('knowledge'),item.id,item.title+' · '+item.type);});})
        ]);}
        function preview(id,version){var path='/api/article/preview?id='+id+(version?'&version_id='+version:'');return request(path,{method:'GET'}).then(function(data){el('preview').textContent=data.title+'\n\n'+data.html.replace(/<br\s*\/?\s*>/gi,'\n').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&');el('history').hidden=false;});}
        function history(item){return request('/api/article/history?id='+item.id,{method:'GET'}).then(function(items){var list=el('history-items');clear(list);items.forEach(function(version){var li=document.createElement('li');li.appendChild(document.createTextNode('v'+version.version_no+' · '+version.prompt.name+' v'+version.prompt.version_no+' · 素材 '+version.knowledge.length+' 项 '));li.appendChild(button('查看',function(){preview(item.id,version.id).catch(fail);}));if(version.id!==item.current_version_id){li.appendChild(button('恢复为新版本',function(){post('/api/article/restore',{id:item.id,version_id:version.id,current_version_id:item.current_version_id}).then(function(){say('旧版本已复制为新的当前版本',false);return load();}).catch(fail);}));}list.appendChild(li);});return preview(item.id,null);});}
        function edit(item){request('/api/article/detail?id='+item.id,{method:'GET'}).then(function(detail){el('article-id').value=detail.id;el('current-version-id').value=detail.current_version_id;el('keyword').value=detail.keyword_id;return loadQuestions().then(function(){el('question').value=detail.question_id;el('prompt').value=detail.current_version.prompt.id;Array.prototype.forEach.call(el('knowledge').options,function(opt){opt.selected=detail.current_version.knowledge.some(function(k){return k.knowledge_item_id===Number(opt.value);});});el('title').value=detail.title;el('content').value=detail.current_version.content;el('form-heading').textContent='编辑文章并创建 v'+(detail.current_version.version_no+1);});}).catch(fail);}
        function load(){return request('/api/article/index?'+listQuery({}),{method:'GET'}).then(function(data){var body=el('items');clear(body);articlesById={};data.items.forEach(function(item){articlesById[item.id]=item;var row=document.createElement('tr');cell(row,item.title);cell(row,statusLabel(item.status));cell(row,item.current_version?'v'+item.current_version.version_no:'异常');var actions=cell(row,'');actions.appendChild(button('编辑',function(){edit(item);}));actions.appendChild(button('历史/预览',function(){history(item).catch(fail);}));actions.appendChild(button(item.status==='draft'?'停用':'恢复草稿',function(){post('/api/article/status',{id:item.id,status:item.status==='draft'?'disabled':'draft'}).then(load).catch(fail);}));body.appendChild(row);});});}
        el('keyword').addEventListener('change',function(){loadQuestions().catch(fail);});
        el('article-form').addEventListener('submit',function(event){event.preventDefault();var id=el('article-id').value;var data={keyword_id:el('keyword').value,question_id:el('question').value,prompt_template_id:el('prompt').value,knowledge_item_ids:selectedValues(el('knowledge')),title:el('title').value,content:el('content').value};if(id){delete data.keyword_id;delete data.question_id;data.id=id;data.current_version_id=el('current-version-id').value;}post(id?'/api/article/update':'/api/article/create',data).then(function(){el('article-form').reset();el('article-id').value='';el('current-version-id').value='';el('form-heading').textContent='人工创建文章草稿';say('文章已保存为新的人工版本',false);return loadSelectors().then(load);}).catch(fail);});
        el('article-cancel').addEventListener('click',function(){el('article-form').reset();el('article-id').value='';el('current-version-id').value='';el('form-heading').textContent='人工创建文章草稿';loadSelectors().catch(fail);});el('refresh').addEventListener('click',function(){load().catch(fail);});return function(){return loadSelectors().then(load);};
    }

    var start = moduleName === 'knowledge' ? knowledgeModule() : (moduleName === 'prompt' ? promptModule() : articleModule());
    el('login-form').addEventListener('submit',function(event){event.preventDefault();post('/api/auth/login',{account:el('account').value,password:el('password').value}).then(function(data){token=data.token;el('password').value='';el('login').hidden=true;el('workspace').hidden=false;return start();}).then(function(){say('企业内容已加载',false);}).catch(function(error){token='';fail(error);});});
}());
