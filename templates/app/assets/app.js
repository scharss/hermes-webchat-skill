(function () {
  const body = document.body;
  const state = {
    conversationId: body.dataset.initial || '',
    csrf: body.dataset.csrf || '',
    maxChars: parseInt(body.dataset.maxChars || '8000', 10),
    maxUploadMb: parseInt(body.dataset.maxUploadMb || '25', 10),
    pending: [],
    busy: false,
  };

  const el = {
    messages: document.getElementById('messages'),
    input: document.getElementById('input'),
    send: document.getElementById('sendBtn'),
    attach: document.getElementById('attachBtn'),
    file: document.getElementById('fileInput'),
    pending: document.getElementById('pending'),
    conversations: document.getElementById('conversations'),
    newChat: document.getElementById('newChat'),
    title: document.getElementById('chatTitle'),
    agent: document.getElementById('agentStatus'),
    quota: document.querySelector('.quota strong'),
    menu: document.getElementById('menuBtn'),
    app: document.querySelector('.app'),
  };

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  async function api(path, options) {
    const opts = options || {};
    const headers = { Accept: 'application/json' };
    if (opts.body instanceof FormData) {
      headers['X-CSRF-Token'] = state.csrf;
    } else if (opts.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      headers['X-CSRF-Token'] = state.csrf;
      opts.body = JSON.stringify(opts.body);
    }

    const response = await fetch(path, {
      method: opts.method || 'GET',
      headers,
      body: opts.body,
      credentials: 'same-origin',
      cache: 'no-store',
    });

    let data = null;
    try {
      data = await response.json();
    } catch (error) {
      data = { error: 'Unexpected server response.' };
    }
    if (!response.ok && data && data.csrf) {
      state.csrf = data.csrf;
    }
    if (!response.ok && (!data || !data.error)) {
      data = { error: 'Request failed (' + response.status + ').' };
    }
    data.ok = response.ok && data.ok !== false;
    return data;
  }

  function nearBottom() {
    return el.messages.scrollHeight - el.messages.scrollTop - el.messages.clientHeight < 120;
  }

  function scrollDown(force) {
    if (force || nearBottom()) {
      el.messages.scrollTop = el.messages.scrollHeight;
    }
  }

  function setTitle(title) {
    el.title.textContent = title || 'New conversation';
  }

  function renderWelcome() {
    el.messages.innerHTML =
      '<div class="welcome">' +
      '<h2>How can I help today?</h2>' +
      '<p>Ask about your business, documents, email or reports.</p>' +
      '<div class="chips">' +
      ['Summarize my day', 'Analyze this document', 'Generate a PDF report', 'What can you do?']
        .map(function (label) {
          return '<button class="chip" type="button">' + escapeHtml(label) + '</button>';
        })
        .join('') +
      '</div></div>';

    el.messages.querySelectorAll('.chip').forEach(function (chip) {
      chip.addEventListener('click', function () {
        el.input.value = chip.textContent;
        el.input.focus();
        autosize();
      });
    });
  }

  function addMessage(role, text) {
    const row = document.createElement('div');
    row.className = 'msg ' + role;
    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    const content = document.createElement('span');
    content.className = 'text';
    content.textContent = text || '';
    bubble.appendChild(content);
    row.appendChild(bubble);
    el.messages.appendChild(row);
    scrollDown(true);
    return { row, bubble, content };
  }

  function renderAttachments(message, files) {
    if (!files || !files.length) return;
    let box = message.bubble.querySelector('.attachments');
    if (!box) {
      box = document.createElement('div');
      box.className = 'attachments';
      message.bubble.appendChild(box);
    }
    files.forEach(function (file) {
      if (box.querySelector('[data-id="' + file.id + '"]')) return;
      const link = document.createElement('a');
      link.className = 'attachment';
      link.href = file.url;
      link.target = '_blank';
      link.rel = 'noopener';
      link.dataset.id = file.id;
      link.textContent = file.name + ' · ' + Math.round(file.size / 1024) + ' KB';
      box.appendChild(link);
    });
  }

  function showApproval(message, approval) {
    if (message.bubble.querySelector('.approval')) return;
    const card = document.createElement('div');
    card.className = 'approval';
    const text = document.createElement('p');
    text.textContent = approval.description || 'The agent needs permission to continue.';
    const actions = document.createElement('div');
    actions.className = 'actions';
    const yes = document.createElement('button');
    yes.className = 'yes';
    yes.textContent = 'Approve';
    const no = document.createElement('button');
    no.className = 'no';
    no.textContent = 'Deny';
    actions.appendChild(yes);
    actions.appendChild(no);
    card.appendChild(text);
    card.appendChild(actions);
    message.bubble.appendChild(card);

    function resolve(decision) {
      yes.disabled = true;
      no.disabled = true;
      api('api/approval.php', {
        method: 'POST',
        body: { approval_id: approval.id, decision: decision },
      }).then(function (result) {
        text.textContent = result.ok
          ? (decision === 'approved' ? 'Approved.' : 'Denied.')
          : (result.error || 'Could not resolve.');
        actions.remove();
      });
    }

    yes.addEventListener('click', function () { resolve('approved'); });
    no.addEventListener('click', function () { resolve('denied'); });
  }

  async function loadConversations() {
    const data = await api('api/conversations.php');
    if (!data.ok) return;
    el.conversations.innerHTML = '';
    (data.conversations || []).forEach(function (conversation) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'conv' + (conversation.id === state.conversationId ? ' active' : '');
      button.dataset.id = conversation.id;
      button.textContent = conversation.title;
      el.conversations.appendChild(button);
    });
  }

  function markActive(id) {
    el.conversations.querySelectorAll('.conv').forEach(function (button) {
      button.classList.toggle('active', button.dataset.id === id);
    });
  }

  async function openConversation(id) {
    if (!id) {
      renderWelcome();
      setTitle('New conversation');
      return;
    }
    const data = await api('api/conversations.php?id=' + encodeURIComponent(id));
    if (!data.ok) {
      renderWelcome();
      return;
    }
    state.conversationId = id;
    markActive(id);
    setTitle(data.conversation.title);
    el.messages.innerHTML = '';
    (data.messages || []).forEach(function (message) {
      if (message.role === 'user' || message.role === 'assistant') {
        addMessage(message.role, message.content);
      }
    });
    if (!data.messages || !data.messages.length) {
      renderWelcome();
    }
    scrollDown(true);
    if (window.innerWidth <= 860) el.app.classList.remove('sidebar-open');
  }

  async function newConversation() {
    const data = await api('api/conversations.php', { method: 'POST', body: { title: 'New conversation' } });
    if (!data.ok) return null;
    state.conversationId = data.conversation.id;
    setTitle('New conversation');
    el.messages.innerHTML = '';
    renderWelcome();
    await loadConversations();
    markActive(state.conversationId);
    return data.conversation;
  }

  function autosize() {
    el.input.style.height = 'auto';
    el.input.style.height = Math.min(160, el.input.scrollHeight) + 'px';
  }

  async function pollJob(jobId) {
    let offset = 0;
    let message = null;
    let approvalId = null;

    const tick = async function () {
      const data = await api('api/job.php?job_id=' + jobId + '&offset=' + offset);
      if (!data.ok) {
        addMessage('error', data.error || 'Lost track of the job.');
        el.send.disabled = false;
        state.busy = false;
        return;
      }

      if (data.delta) {
        if (!message) message = addMessage('assistant', '');
        message.content.textContent += data.delta;
        scrollDown();
      }
      offset = data.offset;
      if (!message) message = addMessage('assistant', '');

      if (data.approval && data.approval.id !== approvalId) {
        approvalId = data.approval.id;
        showApproval(message, data.approval);
      }
      if (data.attachments && data.attachments.length) {
        renderAttachments(message, data.attachments);
      }

      if (data.status === 'done') {
        el.send.disabled = false;
        state.busy = false;
        if (!message.content.textContent.trim()) {
          message.content.textContent = '(empty response)';
        }
        scrollDown();
        loadConversations();
        return;
      }

      if (data.status === 'error') {
        addMessage('error', data.error || 'The agent failed to answer.');
        el.send.disabled = false;
        state.busy = false;
        return;
      }

      window.setTimeout(tick, 800);
    };

    tick();
  }

  async function send() {
    if (state.busy) return;
    const text = el.input.value.trim();
    if (text === '') return;
    if (text.length > state.maxChars) {
      addMessage('error', 'Message is too long.');
      return;
    }

    if (!state.conversationId) {
      const conversation = await newConversation();
      if (!conversation) return;
    }

    // Remove the welcome block before the first message.
    if (el.messages.querySelector('.welcome')) {
      el.messages.innerHTML = '';
    }

    addMessage('user', text);
    el.input.value = '';
    autosize();

    const uploadIds = state.pending.map(function (file) { return file.id; });
    state.pending = [];
    renderPending();

    state.busy = true;
    el.send.disabled = true;

    const data = await api('api/chat.php', {
      method: 'POST',
      body: {
        conversation_id: state.conversationId,
        message: text,
        upload_ids: uploadIds,
      },
    });

    if (!data.ok) {
      addMessage('error', data.error || 'Could not send the message.');
      el.send.disabled = false;
      state.busy = false;
      return;
    }

    setTitle(data.title);
    pollJob(data.job_id);
  }

  function renderPending() {
    el.pending.innerHTML = '';
    state.pending.forEach(function (file, index) {
      const chip = document.createElement('span');
      chip.className = 'file';
      chip.textContent = file.name;
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.textContent = '×';
      remove.addEventListener('click', function () {
        state.pending.splice(index, 1);
        renderPending();
      });
      chip.appendChild(remove);
      el.pending.appendChild(chip);
    });
  }

  async function upload(file) {
    if (file.size > state.maxUploadMb * 1024 * 1024) {
      addMessage('error', file.name + ' exceeds ' + state.maxUploadMb + ' MB.');
      return;
    }
    const form = new FormData();
    form.append('file', file);
    const data = await api('api/upload.php', { method: 'POST', body: form });
    if (!data.ok) {
      addMessage('error', data.error || 'Upload failed.');
      return;
    }
    state.pending.push({ id: data.id, name: data.name });
    renderPending();
  }

  async function refreshHealth() {
    const data = await api('api/health.php');
    if (!data.ok) return;
    if (data.csrf) state.csrf = data.csrf;
    if (data.agent) {
      el.agent.className = 'agent ' + (data.agent.connected ? 'ok' : 'off');
      el.agent.innerHTML = '<span class="dot"></span>' + (data.agent.connected ? 'Agent connected' : 'Agent offline');
    }
    if (el.quota && data.quota !== null) {
      el.quota.textContent = data.quota >= 1000 ? Math.round(data.quota / 1000) + 'k' : String(data.quota);
    }
  }

  el.send.addEventListener('click', send);

  el.input.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      send();
    }
  });
  el.input.addEventListener('input', autosize);

  el.newChat.addEventListener('click', newConversation);

  el.conversations.addEventListener('click', function (event) {
    const button = event.target.closest('.conv');
    if (button) openConversation(button.dataset.id);
  });

  el.attach.addEventListener('click', function () { el.file.click(); });
  el.file.addEventListener('change', function () {
    Array.from(el.file.files || []).forEach(upload);
    el.file.value = '';
  });

  el.menu.addEventListener('click', function () {
    el.app.classList.toggle('sidebar-open');
  });

  if (state.conversationId) {
    openConversation(state.conversationId);
  } else {
    renderWelcome();
  }

  loadConversations();
  refreshHealth();
  window.setInterval(refreshHealth, 30000);
  autosize();
})();
