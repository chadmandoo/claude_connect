/* Claude Connect — Web Frontend */
(function () {
    'use strict';

    // ── State ──────────────────────────────────────────────
    const state = {
        token: localStorage.getItem('cc_token') || null,
        userId: localStorage.getItem('cc_user_id') || null,
        ws: null,
        connected: false,
        reconnectDelay: 1000,
        reconnectTimer: null,
        messages: [],
        activeTaskId: null,
        parentTaskId: null,
        conversationId: null,
        agentType: null,
        projectName: null,
        conversations: [],
        showArchived: false,
        projects: [],
        memories: { facts: {}, memories: [], project_memories: [], count: 0 },
        currentTab: 'chat',
        activeProjectId: localStorage.getItem('cc_active_project') || 'general',
        msgIdCounter: 0,
        showCreateProject: false,
        selectedProjectId: null,
        showProjectDetail: false,
        epics: [],
        items: [],
        selectedItemIds: new Set(),
        searchMode: 'local', // 'local' or 'semantic'
        semanticSearchTimer: null,
        semanticSearchResults: null,
        semanticSearching: false,
        analyticsData: null,
        analyticsExpanded: false,
        itemNotes: {},
        // Memory modal
        memoryModalMode: null, // 'edit' or 'create'
        memoryModalId: null,
        memoryModalProjectId: null,
        // Project sub-tabs
        projectSubTab: 'work',
        projectMemories: [],
        projectMemorySearch: '',
        // Background tasks
        backgroundTasks: new Map(), // taskId => {state, prompt_preview, timestamp}
        pendingImages: [], // {data: base64, media_type: string, name: string}
    };

    // ── DOM Refs ───────────────────────────────────────────
    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => document.querySelectorAll(sel);

    const dom = {
        loginOverlay: $('#login-overlay'),
        loginForm: $('#login-form'),
        loginPassword: $('#login-password'),
        loginBtn: $('#login-btn'),
        loginError: $('#login-error'),
        app: $('#app'),
        connDot: $('#conn-dot'),
        connLabel: $('#conn-label'),
        bgTasksIndicator: $('#bg-tasks-indicator'),
        bgTasksCount: $('#bg-tasks-count'),
        projectSwitcher: $('#project-switcher'),
        chatHeader: $('#chat-header'),
        chatMessages: $('#chat-messages'),
        chatInput: $('#chat-input'),
        chatSend: $('#chat-send'),
        chatAttachBtn: $('#chat-attach-btn'),
        chatImageInput: $('#chat-image-input'),
        chatImagePreview: $('#chat-image-preview'),
        chatInputArea: $('#chat-input-area'),
        templateSelect: $('#template-select'),
        continueBadge: $('#continue-badge'),
        newChatBtn: $('#new-chat-btn'),
        conversationsProjectFilter: $('#conversations-project-filter'),
        conversationsList: $('#conversations-list'),
        showArchivedCheckbox: $('#show-archived-checkbox'),
        projectsList: $('#projects-list'),
        createProjectBtn: $('#create-project-btn'),
        projectCreateDialog: $('#project-create-dialog'),
        projectNameInput: $('#project-name-input'),
        projectDescInput: $('#project-desc-input'),
        projectCwdInput: $('#project-cwd-input'),
        projectCancelBtn: $('#project-cancel-btn'),
        projectSaveBtn: $('#project-save-btn'),
        memorySearch: $('#memory-search'),
        memoryProjectFilter: $('#memory-project-filter'),
        memoryList: $('#memory-list'),
        searchModeToggle: $('#search-mode-toggle'),
        memorySearchStatus: $('#memory-search-status'),
        memoryAnalytics: $('#memory-analytics'),
        // Conversation type filter
        conversationsTypeFilter: $('#conversations-type-filter'),
        // Project detail view
        projectsListView: $('#projects-list-view'),
        projectsDetailView: $('#projects-detail-view'),
        projectBackBtn: $('#project-back-btn'),
        projectDetailName: $('#project-detail-name'),
        projectDetailDesc: $('#project-detail-desc'),
        projectDetailStats: $('#project-detail-stats'),
        epicsContainer: $('#epics-container'),
        // Project edit/delete
        projectEditBtn: $('#project-edit-btn'),
        projectDeleteBtn: $('#project-delete-btn'),
        projectEditDialog: $('#project-edit-dialog'),
        projectEditName: $('#project-edit-name'),
        projectEditDesc: $('#project-edit-desc'),
        projectEditCwd: $('#project-edit-cwd'),
        projectEditCancelBtn: $('#project-edit-cancel-btn'),
        projectEditSaveBtn: $('#project-edit-save-btn'),
        // Epic create
        createEpicBtn: $('#create-epic-btn'),
        epicCreateDialog: $('#epic-create-dialog'),
        epicTitleInput: $('#epic-title-input'),
        epicDescInput: $('#epic-desc-input'),
        epicCancelBtn: $('#epic-cancel-btn'),
        epicSaveBtn: $('#epic-save-btn'),
        // Item create
        createItemBtn: $('#create-item-btn'),
        itemCreateDialog: $('#item-create-dialog'),
        itemTitleInput: $('#item-title-input'),
        itemDescInput: $('#item-desc-input'),
        itemEpicSelect: $('#item-epic-select'),
        itemPrioritySelect: $('#item-priority-select'),
        itemCancelBtn: $('#item-cancel-btn'),
        itemSaveBtn: $('#item-save-btn'),
        // Memory modal
        memoryModalOverlay: $('#memory-modal-overlay'),
        memoryModalTitle: $('#memory-modal-title'),
        memoryModalContent: $('#memory-modal-content'),
        memoryModalCategory: $('#memory-modal-category'),
        memoryModalImportance: $('#memory-modal-importance'),
        memoryModalClose: $('#memory-modal-close'),
        memoryModalCancel: $('#memory-modal-cancel'),
        memoryModalSave: $('#memory-modal-save'),
        // Project memory sub-tab
        projectWorkPanel: $('#project-work-panel'),
        projectMemoryPanel: $('#project-memory-panel'),
        projectMemorySearch: $('#project-memory-search'),
        projectMemoryList: $('#project-memory-list'),
        projectCreateMemoryBtn: $('#project-create-memory-btn'),
    };

    // ── Toast Notifications ────────────────────────────────
    const toastContainer = document.getElementById('toast-container');

    function showToast(message, type = 'info', duration = 3000) {
        const el = document.createElement('div');
        el.className = `toast toast-${type}`;
        el.textContent = message;
        toastContainer.appendChild(el);
        setTimeout(() => {
            el.classList.add('toast-out');
            el.addEventListener('animationend', () => el.remove());
        }, duration);
    }

    // ── Markdown Setup ─────────────────────────────────────
    if (typeof marked !== 'undefined') {
        const renderer = new marked.Renderer();
        // Sanitize: escape raw HTML tags to prevent XSS from Claude output
        renderer.html = function (html) { return escapeHtml(typeof html === 'object' ? html.text || '' : html); };
        marked.setOptions({
            breaks: true,
            gfm: true,
            renderer: renderer,
            highlight: function (code, lang) {
                if (typeof hljs !== 'undefined' && lang && hljs.getLanguage(lang)) {
                    try { return hljs.highlight(code, { language: lang }).value; } catch (e) {}
                }
                if (typeof hljs !== 'undefined') {
                    try { return hljs.highlightAuto(code).value; } catch (e) {}
                }
                return code;
            },
        });
    }

    function renderMarkdown(text) {
        if (typeof marked !== 'undefined') {
            try { return marked.parse(text); } catch (e) {}
        }
        return escapeHtml(text).replace(/\n/g, '<br>');
    }

    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    // ── Auth ───────────────────────────────────────────────
    dom.loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const pw = dom.loginPassword.value.trim();
        if (!pw) return;

        dom.loginBtn.disabled = true;
        dom.loginError.textContent = '';

        try {
            const res = await fetch('/api/auth', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: pw }),
            });

            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw new Error(data.error || 'Authentication failed');
            }

            const data = await res.json();
            state.token = data.token;
            state.userId = data.user_id;
            localStorage.setItem('cc_token', data.token);
            localStorage.setItem('cc_user_id', data.user_id);

            showApp();
            connectWebSocket();
        } catch (err) {
            dom.loginError.textContent = err.message;
        } finally {
            dom.loginBtn.disabled = false;
        }
    });

    function showApp() {
        dom.loginOverlay.classList.add('hidden');
        dom.app.classList.add('active');
    }

    function showLogin() {
        dom.loginOverlay.classList.remove('hidden');
        dom.app.classList.remove('active');
        state.token = null;
        state.userId = null;
        localStorage.removeItem('cc_token');
        localStorage.removeItem('cc_user_id');
    }

    // ── WebSocket ──────────────────────────────────────────
    function connectWebSocket() {
        if (state.ws) {
            try { state.ws.close(); } catch (e) {}
        }

        updateConnStatus('connecting');

        const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
        const url = `${proto}//${location.host}/?token=${encodeURIComponent(state.token || '')}`;

        const ws = new WebSocket(url);
        state.ws = ws;

        ws.onopen = () => {
            state.connected = true;
            state.reconnectDelay = 1000;
            updateConnStatus('connected');
            loadProjects();
            restoreChatSession();
        };

        ws.onmessage = (event) => {
            try {
                const msg = JSON.parse(event.data);
                handleServerMessage(msg);
            } catch (e) {
                console.error('WS parse error:', e);
            }
        };

        ws.onclose = () => {
            if (state.connected) {
                showToast('Disconnected — reconnecting...', 'warning');
            }
            state.connected = false;
            updateConnStatus('disconnected');
            scheduleReconnect();
        };

        ws.onerror = () => {};
    }

    function scheduleReconnect() {
        if (state.reconnectTimer) clearTimeout(state.reconnectTimer);
        state.reconnectTimer = setTimeout(() => {
            if (!state.token) return;
            state.reconnectDelay = Math.min(state.reconnectDelay * 2, 30000);
            connectWebSocket();
        }, state.reconnectDelay);
    }

    function sendWs(data) {
        if (state.ws && state.ws.readyState === WebSocket.OPEN) {
            state.ws.send(JSON.stringify(data));
        }
    }

    function updateConnStatus(status) {
        dom.connDot.className = 'conn-dot ' + (status === 'connected' ? 'connected' : status === 'connecting' ? 'connecting' : '');
        dom.connLabel.textContent = status === 'connected' ? 'connected' : status === 'connecting' ? 'connecting...' : 'disconnected';
    }

    // ── Server Message Handler ─────────────────────────────
    function handleServerMessage(msg) {
        switch (msg.type) {
            case 'auth.ok':
                state.userId = msg.user_id;
                if (msg.token) {
                    state.token = msg.token;
                    localStorage.setItem('cc_token', msg.token);
                }
                break;

            case 'auth.required':
            case 'auth.error':
                showLogin();
                break;

            case 'chat.ack':
                state.activeTaskId = msg.task_id;
                if (msg.conversation_id) {
                    state.conversationId = msg.conversation_id;
                    localStorage.setItem('cc_conversation_id', msg.conversation_id);
                }
                if (msg.agent_type) state.agentType = msg.agent_type;
                if (msg.project_name) state.projectName = msg.project_name;
                updateChatHeader();
                addProgressMessage(msg.task_id);
                break;

            case 'chat.progress':
                updateProgressMessage(msg.task_id, msg.elapsed, msg.stderr_lines);
                break;

            case 'chat.result':
                removeProgressMessage(msg.task_id);
                // Background task result: only render if viewing the same conversation
                if (msg.background && msg.conversation_id && msg.conversation_id !== state.conversationId) {
                    showToast('Background task completed — check your conversation for results', 'success', 6000);
                    break;
                }
                if (msg.conversation_id) {
                    state.conversationId = msg.conversation_id;
                    localStorage.setItem('cc_conversation_id', msg.conversation_id);
                }
                addAssistantMessage(msg.result, msg.task_id, {
                    cost: msg.cost_usd,
                    duration: msg.duration,
                    sessionId: msg.claude_session_id,
                    images: msg.images || [],
                });
                state.activeTaskId = null;
                if (msg.claude_session_id) {
                    state.parentTaskId = msg.task_id;
                    localStorage.setItem('cc_parent_task_id', msg.task_id);
                    updateContinueBadge();
                }
                updateSendButton();
                break;

            case 'chat.error':
                removeProgressMessage(msg.task_id);
                addErrorMessage(msg.error, msg.task_id);
                state.activeTaskId = null;
                updateSendButton();
                showToast(msg.error || 'Task failed', 'error');
                break;

            case 'conversations.list':
                state.conversations = msg.conversations || [];
                renderConversations();
                break;

            case 'conversations.detail':
                renderConversationDetail(msg.conversation, msg.turns);
                break;

            case 'conversations.completed':
                loadConversations();
                break;

            case 'conversations.archived':
                // Remove from active list (or update in-place if showing archived)
                if (state.showArchived) {
                    const conv = state.conversations.find(c => c.id === msg.conversation_id);
                    if (conv) conv.state = 'completed';
                    renderConversations();
                } else {
                    state.conversations = state.conversations.filter(c => c.id !== msg.conversation_id);
                    renderConversations();
                }
                showToast('Conversation archived', 'success');
                break;

            case 'projects.list':
                state.projects = msg.projects || [];
                renderProjects();
                updateProjectDropdowns();
                break;

            case 'projects.detail':
                // Update detail view if open for this project
                if (state.showProjectDetail && msg.project && msg.project.id === state.selectedProjectId) {
                    dom.projectDetailName.textContent = msg.project.name || 'Unnamed';
                    const desc = msg.project.description || '';
                    dom.projectDetailDesc.textContent = desc;
                    dom.projectDetailDesc.style.display = desc ? 'block' : 'none';
                }
                break;

            case 'projects.created':
                loadProjects();
                showToast('Project created', 'success');
                break;

            case 'projects.updated':
                loadProjects();
                if (state.showProjectDetail && state.selectedProjectId === msg.project_id) {
                    // Refresh detail view header
                    sendWs({ type: 'projects.get', id: ++state.msgIdCounter, project_id: msg.project_id });
                }
                break;

            case 'projects.deleted':
                if (state.showProjectDetail && state.selectedProjectId === msg.project_id) {
                    closeProjectDetail();
                }
                loadProjects();
                showToast('Project deleted', 'success');
                break;

            case 'epics.list':
                state.epics = msg.epics || [];
                renderProjectDetailEpics();
                break;

            case 'epics.created':
                if (state.selectedProjectId === msg.project_id) {
                    loadEpics(msg.project_id);
                    loadItems(msg.project_id);
                }
                showToast('Epic created', 'success');
                break;

            case 'epics.updated':
                if (state.selectedProjectId) {
                    loadEpics(state.selectedProjectId);
                }
                break;

            case 'epics.reordered':
                if (state.selectedProjectId === msg.project_id) {
                    loadEpics(msg.project_id);
                }
                break;

            case 'epics.deleted':
                if (state.selectedProjectId === msg.project_id) {
                    loadEpics(msg.project_id);
                    loadItems(msg.project_id);
                }
                showToast('Epic deleted', 'success');
                break;

            case 'items.list':
                state.items = msg.items || [];
                renderProjectDetailEpics();
                break;

            case 'items.created':
                if (state.selectedProjectId) {
                    loadEpics(state.selectedProjectId);
                    loadItems(state.selectedProjectId);
                }
                showToast('Item created', 'success');
                break;

            case 'items.updated':
                if (state.selectedProjectId) {
                    loadEpics(state.selectedProjectId);
                    loadItems(state.selectedProjectId);
                }
                break;

            case 'items.moved':
                if (state.selectedProjectId) {
                    loadEpics(state.selectedProjectId);
                    loadItems(state.selectedProjectId);
                }
                break;

            case 'items.reordered':
                if (state.selectedProjectId) {
                    loadEpics(state.selectedProjectId);
                    loadItems(state.selectedProjectId);
                }
                break;

            case 'items.deleted':
                if (state.selectedProjectId) {
                    loadEpics(state.selectedProjectId);
                    loadItems(state.selectedProjectId);
                }
                showToast('Item deleted', 'success');
                break;

            case 'memory.list':
                // Check if this response is for the project detail memory panel
                if (msg.project_id && msg.project_id === state.selectedProjectId && state.showProjectDetail) {
                    state.projectMemories = msg.project_memories || [];
                    renderProjectMemories();
                }
                // Always update main memory tab state
                state.memories = {
                    facts: msg.facts || {},
                    memories: msg.memories || [],
                    project_memories: msg.project_memories || [],
                    count: msg.count || 0,
                    project_id: msg.project_id || null,
                };
                renderMemories();
                break;

            case 'memory.created':
                loadMemories();
                if (state.showProjectDetail && state.projectSubTab === 'memory') {
                    loadProjectMemories(state.selectedProjectId);
                }
                showToast('Memory created', 'success');
                break;

            case 'memory.updated':
                loadMemories();
                if (state.showProjectDetail && state.projectSubTab === 'memory') {
                    loadProjectMemories(state.selectedProjectId);
                }
                showToast('Memory updated', 'success');
                break;

            case 'memory.deleted':
                state.memories.memories = state.memories.memories.filter(m => m && m.id !== msg.memory_id);
                state.memories.project_memories = state.memories.project_memories.filter(m => m && m.id !== msg.memory_id);
                renderMemories();
                // Also update project memory panel
                state.projectMemories = state.projectMemories.filter(m => m && m.id !== msg.memory_id);
                renderProjectMemories();
                break;

            case 'memory.search':
                state.semanticSearching = false;
                state.semanticSearchResults = msg.results || [];
                renderSemanticResults();
                break;

            case 'memory.analytics':
                state.analyticsData = msg;
                renderAnalytics();
                break;

            case 'nightly.status':
                // Handled via analytics render
                if (state.analyticsData) {
                    state.analyticsData.nightly = msg;
                    renderAnalytics();
                }
                break;

            case 'items.notes':
                state.itemNotes[msg.item_id] = msg.notes || [];
                renderProjectDetailEpics();
                break;

            case 'items.noteAdded':
                if (state.itemNotes[msg.item_id]) {
                    loadItemNotes(msg.item_id);
                }
                showToast('Note added', 'success');
                break;

            case 'items.assigned':
                if (state.selectedProjectId) {
                    loadEpics(state.selectedProjectId);
                    loadItems(state.selectedProjectId);
                }
                showToast(`Item assigned to ${msg.assignee}`, 'success');
                break;

            case 'task.state_changed':
                handleTaskStateChanged(msg);
                break;

            case 'task.progress':
                handleTaskProgress(msg);
                break;

            case 'sessions.list':
                break;

            case 'ping':
                sendWs({ type: 'pong' });
                break;

            case 'error':
                console.error('Server error:', msg.error);
                showToast(msg.error || 'Server error', 'error');
                break;
        }
    }

    // ── Background Tasks ────────────────────────────────────
    function handleTaskStateChanged(msg) {
        const taskId = msg.task_id || '';
        const taskState = msg.state || '';
        const preview = msg.prompt_preview || 'Task';

        if (taskState === 'running') {
            state.backgroundTasks.set(taskId, { state: taskState, prompt_preview: preview, timestamp: msg.timestamp });
        } else if (taskState === 'completed') {
            state.backgroundTasks.delete(taskId);
            const resultPreview = msg.result_preview || '';
            showToast(`Task completed: ${preview.substring(0, 60)}`, 'success', 5000);
        } else if (taskState === 'failed') {
            state.backgroundTasks.delete(taskId);
            const error = msg.error || 'Unknown error';
            showToast(`Task failed: ${preview.substring(0, 40)} — ${error}`, 'error', 5000);
        } else {
            state.backgroundTasks.set(taskId, { state: taskState, prompt_preview: preview, timestamp: msg.timestamp });
        }

        updateBgTasksIndicator();
    }

    function handleTaskProgress(msg) {
        // Update the running task's timestamp to show activity
        const taskId = msg.task_id || '';
        if (state.backgroundTasks.has(taskId)) {
            const entry = state.backgroundTasks.get(taskId);
            entry.elapsed = msg.elapsed || 0;
            entry.stderrLines = msg.stderr_lines || 0;
        }
    }

    function updateBgTasksIndicator() {
        const runningCount = [...state.backgroundTasks.values()].filter(t => t.state === 'running').length;
        if (runningCount > 0) {
            dom.bgTasksIndicator.style.display = 'flex';
            dom.bgTasksCount.textContent = runningCount;
            dom.bgTasksIndicator.title = `${runningCount} background task${runningCount > 1 ? 's' : ''} running`;
        } else {
            dom.bgTasksIndicator.style.display = 'none';
        }
    }

    // ── Chat ───────────────────────────────────────────────
    function sendChat() {
        const prompt = dom.chatInput.value.trim();
        if (!prompt || state.activeTaskId) return;

        const template = dom.templateSelect.value || null;
        const parentTaskId = state.parentTaskId || null;
        const conversationId = state.conversationId || null;

        const images = state.pendingImages.map(img => ({ data: img.data, media_type: img.media_type }));
        addUserMessage(prompt, images);
        dom.chatInput.value = '';
        clearPendingImages();
        autoResizeTextarea();
        updateSendButton();

        const msg = {
            type: 'chat.send',
            id: ++state.msgIdCounter,
            prompt: prompt,
            template: template,
            parent_task_id: parentTaskId,
            conversation_id: conversationId,
        };
        if (images.length > 0) {
            msg.images = images;
        }
        sendWs(msg);
    }

    // ── Image Upload Handling ─────────────────────────────
    function handleImageSelect(files) {
        const maxSize = 5 * 1024 * 1024; // 5MB per image
        const maxImages = 5;
        const allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        for (const file of files) {
            if (state.pendingImages.length >= maxImages) {
                showToast('Maximum ' + maxImages + ' images allowed', 'error');
                break;
            }
            if (!allowed.includes(file.type)) {
                showToast('Unsupported image type: ' + file.type, 'error');
                continue;
            }
            if (file.size > maxSize) {
                showToast('Image too large: ' + file.name + ' (max 5MB)', 'error');
                continue;
            }

            const reader = new FileReader();
            reader.onload = (e) => {
                const base64 = e.target.result.split(',')[1];
                state.pendingImages.push({
                    data: base64,
                    media_type: file.type,
                    name: file.name,
                    url: e.target.result,
                });
                renderImagePreview();
            };
            reader.readAsDataURL(file);
        }
    }

    function removeImage(index) {
        state.pendingImages.splice(index, 1);
        renderImagePreview();
    }

    function renderImagePreview() {
        dom.chatImagePreview.innerHTML = '';
        state.pendingImages.forEach((img, i) => {
            const thumb = document.createElement('div');
            thumb.className = 'chat-image-thumb';
            thumb.innerHTML = '<img src="' + img.url + '" alt="' + escapeHtml(img.name) + '">' +
                '<button class="remove-image" title="Remove">&times;</button>';
            thumb.querySelector('.remove-image').addEventListener('click', () => removeImage(i));
            dom.chatImagePreview.appendChild(thumb);
        });
    }

    function clearPendingImages() {
        state.pendingImages = [];
        dom.chatImagePreview.innerHTML = '';
        dom.chatImageInput.value = '';
    }

    function showImageOverlay(src) {
        const overlay = document.createElement('div');
        overlay.className = 'image-overlay';
        overlay.innerHTML = '<img src="' + src + '">';
        overlay.addEventListener('click', () => overlay.remove());
        document.body.appendChild(overlay);
    }

    function newChat() {
        state.parentTaskId = null;
        state.conversationId = null;
        state.agentType = null;
        state.projectName = null;
        state.messages = [];
        dom.chatMessages.innerHTML = '';
        clearPendingImages();
        localStorage.removeItem('cc_conversation_id');
        localStorage.removeItem('cc_parent_task_id');
        updateContinueBadge();
        updateChatHeader();
        dom.chatInput.focus();
    }

    function restoreChatSession() {
        const savedConvId = localStorage.getItem('cc_conversation_id');
        const savedParentId = localStorage.getItem('cc_parent_task_id');
        if (savedConvId && dom.chatMessages.children.length === 0) {
            state.conversationId = savedConvId;
            state.parentTaskId = savedParentId || null;
            updateContinueBadge();
            sendWs({ type: 'conversations.get', id: ++state.msgIdCounter, conversation_id: savedConvId });
        }
    }

    function updateChatHeader() {
        const parts = [];
        if (state.projectName && state.projectName !== 'General') {
            parts.push(`<span class="chat-header-project">${escapeHtml(state.projectName)}</span>`);
        }
        if (state.agentType) {
            const label = state.agentType === 'pm' ? 'PM' : 'Project';
            const cls = state.agentType === 'pm' ? 'agent-pm' : 'agent-project';
            parts.push(`<span class="chat-header-agent ${cls}">${label}</span>`);
        }
        dom.chatHeader.innerHTML = parts.join('');
        dom.chatHeader.style.display = parts.length > 0 ? 'flex' : 'none';
    }

    function addUserMessage(text, images) {
        const el = document.createElement('div');
        el.className = 'msg user';
        if (images && images.length > 0) {
            const imgContainer = document.createElement('div');
            imgContainer.className = 'msg-images';
            images.forEach(img => {
                const imgEl = document.createElement('img');
                imgEl.className = 'msg-image-thumb';
                imgEl.src = 'data:' + img.media_type + ';base64,' + img.data;
                imgEl.addEventListener('click', () => showImageOverlay(imgEl.src));
                imgContainer.appendChild(imgEl);
            });
            el.appendChild(imgContainer);
        }
        const textNode = document.createElement('span');
        textNode.textContent = text;
        el.appendChild(textNode);
        dom.chatMessages.appendChild(el);
        scrollToBottom();
    }

    function addAssistantMessage(text, taskId, meta) {
        const el = document.createElement('div');
        el.className = 'msg assistant';
        el.dataset.taskId = taskId || '';

        // Render response images if present
        if (meta && meta.images && meta.images.length > 0) {
            const imgContainer = document.createElement('div');
            imgContainer.className = 'msg-images';
            meta.images.forEach(img => {
                const imgEl = document.createElement('img');
                imgEl.className = 'msg-image-thumb';
                imgEl.src = 'data:' + (img.media_type || 'image/png') + ';base64,' + img.data;
                imgEl.addEventListener('click', () => showImageOverlay(imgEl.src));
                imgContainer.appendChild(imgEl);
            });
            el.appendChild(imgContainer);
        }

        const contentEl = document.createElement('div');
        contentEl.innerHTML = renderMarkdown(text);
        el.appendChild(contentEl);

        if (meta) {
            const metaEl = document.createElement('div');
            metaEl.className = 'msg-meta';
            if (meta.cost) {
                metaEl.innerHTML += `<span>$${meta.cost.toFixed(4)}</span>`;
            }
            if (meta.duration) {
                metaEl.innerHTML += `<span>${formatElapsed(meta.duration)}</span>`;
            }
            if (meta.sessionId) {
                const btn = document.createElement('button');
                btn.className = 'btn-sm accent';
                btn.textContent = 'Continue';
                btn.style.marginLeft = 'auto';
                btn.addEventListener('click', () => {
                    state.parentTaskId = taskId;
                    updateContinueBadge();
                    dom.chatInput.focus();
                });
                metaEl.appendChild(btn);
            }
            el.appendChild(metaEl);
        }

        dom.chatMessages.appendChild(el);
        scrollToBottom();

        el.querySelectorAll('pre code').forEach((block) => {
            if (typeof hljs !== 'undefined') {
                hljs.highlightElement(block);
            }
        });
    }

    function addErrorMessage(text, taskId) {
        const el = document.createElement('div');
        el.className = 'msg error';
        el.textContent = text;
        dom.chatMessages.appendChild(el);
        scrollToBottom();
    }

    function addProgressMessage(taskId) {
        const el = document.createElement('div');
        el.className = 'msg progress';
        el.id = 'progress-' + taskId;
        el.innerHTML = '<span class="spinner"></span>Working...';
        dom.chatMessages.appendChild(el);
        scrollToBottom();
        updateSendButton();
    }

    function updateProgressMessage(taskId, elapsed, stderrLines) {
        const el = document.getElementById('progress-' + taskId);
        if (el) {
            el.innerHTML = `<span class="spinner"></span>Working... (${formatElapsed(elapsed)})`;
            scrollToBottom();
        }
    }

    function removeProgressMessage(taskId) {
        const el = document.getElementById('progress-' + taskId);
        if (el) el.remove();
    }

    function scrollToBottom() {
        requestAnimationFrame(() => {
            dom.chatMessages.scrollTop = dom.chatMessages.scrollHeight;
        });
    }

    function updateSendButton() {
        dom.chatSend.disabled = !!state.activeTaskId || !dom.chatInput.value.trim();
    }

    function updateContinueBadge() {
        if (state.parentTaskId) {
            dom.continueBadge.classList.add('visible');
        } else {
            dom.continueBadge.classList.remove('visible');
        }
    }

    // ── Conversations ─────────────────────────────────────
    function loadConversations() {
        const projectId = dom.conversationsProjectFilter.value || null;
        const typeFilter = dom.conversationsTypeFilter ? (dom.conversationsTypeFilter.value || null) : null;
        const msg = { type: 'conversations.list', id: ++state.msgIdCounter, project_id: projectId, limit: 50, show_archived: state.showArchived };
        if (typeFilter) msg.conv_type = typeFilter;
        sendWs(msg);
    }

    function renderConversations() {
        const container = dom.conversationsList;
        if (state.conversations.length === 0) {
            container.innerHTML = '<div class="panel-empty">No conversations found</div>';
            return;
        }

        container.innerHTML = '';
        state.conversations.forEach((conv) => {
            const el = document.createElement('div');
            const isArchived = conv.state === 'completed' || conv.state === 'learned';
            el.className = 'conversation-item' + (isArchived ? ' archived' : '');
            el.dataset.conversationId = conv.id;

            const type = conv.type || 'task';
            const stateLabel = conv.state || 'active';
            const summary = conv.summary || '(no summary)';
            const cost = conv.total_cost_usd ? `$${parseFloat(conv.total_cost_usd).toFixed(4)}` : '';
            const time = conv.updated_at ? formatTime(conv.updated_at) : '';
            const turns = conv.turn_count || 0;
            const projectId = conv.project_id || 'general';

            // Find project name
            let projectLabel = projectId === 'general' ? 'General' : projectId;
            const proj = state.projects.find(p => p.id === projectId);
            if (proj) projectLabel = proj.name;

            // Archive button for active conversations, no action button for archived
            const actionBtn = !isArchived
                ? `<button class="conversation-archive-btn" data-archive-conv="${conv.id}" title="Archive conversation">&#10003;</button>`
                : '';

            el.innerHTML = `
                <div class="conversation-item-body">
                    <div class="conversation-item-header">
                        <span class="type-badge type-${type}">${type}</span>
                        <span class="conversation-project-badge">${escapeHtml(projectLabel)}</span>
                        <span class="state-badge ${stateLabel}"><span class="state-dot"></span>${stateLabel}</span>
                    </div>
                    <div class="conversation-item-summary">${escapeHtml(summary)}</div>
                    <div class="conversation-item-meta">
                        <span>${time}</span>
                        <span>${turns} turns</span>
                        ${cost ? `<span>${cost}</span>` : ''}
                    </div>
                </div>
                ${actionBtn}
            `;

            el.addEventListener('click', (e) => {
                if (e.target.closest('[data-archive-conv]')) {
                    e.stopPropagation();
                    const cid = e.target.closest('[data-archive-conv]').dataset.archiveConv;
                    if (cid) {
                        sendWs({ type: 'conversations.archive', id: ++state.msgIdCounter, conversation_id: cid });
                    }
                    return;
                }
                sendWs({ type: 'conversations.get', id: ++state.msgIdCounter, conversation_id: conv.id });
            });

            container.appendChild(el);
        });
    }

    function renderConversationDetail(conversation, turns) {
        // Load conversation into chat view
        state.conversationId = conversation.id;
        state.agentType = null;
        state.projectName = null;

        // Find project name
        const projectId = conversation.project_id || 'general';
        const proj = state.projects.find(p => p.id === projectId);
        if (proj) state.projectName = proj.name;

        dom.chatMessages.innerHTML = '';
        state.messages = [];

        if (turns && turns.length > 0) {
            turns.forEach((turn) => {
                if (turn.role === 'user') {
                    addUserMessage(turn.content || '');
                } else if (turn.role === 'assistant') {
                    addAssistantMessage(turn.content || '', turn.task_id || '', {
                        cost: parseFloat(turn.cost_usd || 0),
                    });
                }
            });

            // Set parentTaskId from last assistant turn
            const lastAssistant = [...turns].reverse().find(t => t.role === 'assistant' && t.task_id);
            if (lastAssistant) {
                state.parentTaskId = lastAssistant.task_id;
                updateContinueBadge();
            }
        }

        // Show key takeaways if present
        const takeaways = conversation.key_takeaways ? JSON.parse(conversation.key_takeaways || '[]') : [];
        if (takeaways.length > 0) {
            const el = document.createElement('div');
            el.className = 'msg takeaways';
            el.innerHTML = '<strong>Key Takeaways</strong><ul>' +
                takeaways.map(t => `<li>${escapeHtml(t)}</li>`).join('') +
                '</ul>';
            dom.chatMessages.appendChild(el);
        }

        updateChatHeader();
        switchTab('chat');
    }

    // ── Projects ──────────────────────────────────────────
    function loadProjects() {
        sendWs({ type: 'projects.list', id: ++state.msgIdCounter });
    }

    function renderProjects() {
        const container = dom.projectsList;
        if (state.projects.length === 0) {
            container.innerHTML = '<div class="panel-empty">No projects. Create one to get started.</div>';
            return;
        }

        container.innerHTML = '';
        state.projects.forEach((project) => {
            const el = document.createElement('div');
            el.className = 'project-item clickable';
            el.dataset.projectId = project.id;

            const name = project.name || 'Unnamed';
            const desc = project.description || '';
            const cwd = project.cwd || '';
            const time = project.created_at ? formatTime(project.created_at) : '';
            const ic = project.item_counts || {};
            const hasItems = (ic.total || 0) > 0;

            let countsHtml = '';
            if (hasItems) {
                const parts = [];
                if (ic.open) parts.push(`<span class="item-count-badge open">${ic.open} open</span>`);
                if (ic.in_progress) parts.push(`<span class="item-count-badge in_progress">${ic.in_progress} active</span>`);
                if (ic.blocked) parts.push(`<span class="item-count-badge blocked">${ic.blocked} blocked</span>`);
                if (ic.done) parts.push(`<span class="item-count-badge done">${ic.done} done</span>`);
                countsHtml = `<div class="project-item-counts">${parts.join('')}</div>`;
            }

            el.innerHTML = `
                <div class="project-item-header">
                    <div class="project-item-name">${escapeHtml(name)}</div>
                    <span class="state-badge workspace"><span class="state-dot"></span>workspace</span>
                </div>
                ${desc ? `<div class="project-item-desc">${escapeHtml(desc)}</div>` : ''}
                ${countsHtml}
                <div class="project-item-meta">
                    <span>${time}</span>
                    ${cwd ? `<span>${escapeHtml(cwd)}</span>` : ''}
                </div>
            `;

            el.addEventListener('click', () => openProjectDetail(project.id));
            container.appendChild(el);
        });
    }

    // ── Project Detail View ─────────────────────────────────
    function openProjectDetail(projectId) {
        state.selectedProjectId = projectId;
        state.showProjectDetail = true;

        const project = state.projects.find(p => p.id === projectId);
        if (project) {
            dom.projectDetailName.textContent = project.name || 'Unnamed';
            dom.projectDetailDesc.textContent = project.description || '';
            dom.projectDetailDesc.style.display = project.description ? 'block' : 'none';
            // Hide delete button for General project
            const isGeneral = (project.name || '').toLowerCase() === 'general';
            dom.projectDeleteBtn.style.display = isGeneral ? 'none' : '';
        }

        dom.projectsListView.style.display = 'none';
        dom.projectsDetailView.style.display = 'block';

        // Reset create/edit dialogs
        dom.epicCreateDialog.style.display = 'none';
        dom.itemCreateDialog.style.display = 'none';
        dom.projectEditDialog.style.display = 'none';

        // Reset to Work sub-tab
        switchProjectSubTab('work');
        state.projectMemories = [];
        state.projectMemorySearch = '';
        if (dom.projectMemorySearch) dom.projectMemorySearch.value = '';

        loadEpics(projectId);
        loadItems(projectId);
    }

    function closeProjectDetail() {
        state.selectedProjectId = null;
        state.showProjectDetail = false;
        state.epics = [];
        state.items = [];

        dom.projectsListView.style.display = 'block';
        dom.projectsDetailView.style.display = 'none';

        loadProjects();
    }

    function loadEpics(projectId) {
        sendWs({ type: 'epics.list', id: ++state.msgIdCounter, project_id: projectId });
    }

    function loadItems(projectId) {
        sendWs({ type: 'items.list', id: ++state.msgIdCounter, project_id: projectId });
    }

    function renderProjectDetailEpics() {
        const container = dom.epicsContainer;
        container.innerHTML = '';

        // Clear invalid selections
        state.selectedItemIds = new Set(
            [...state.selectedItemIds].filter(id => state.items.some(i => i.id === id))
        );

        // Update stats bar
        const allItems = state.items;
        const counts = { open: 0, in_progress: 0, review: 0, blocked: 0, done: 0, cancelled: 0 };
        allItems.forEach(item => {
            const s = item.state || 'open';
            if (counts[s] !== undefined) counts[s]++;
        });
        dom.projectDetailStats.innerHTML = allItems.length > 0
            ? `<span class="item-count-badge open">${counts.open} open</span>` +
              `<span class="item-count-badge in_progress">${counts.in_progress} active</span>` +
              (counts.review ? `<span class="item-count-badge" style="color:var(--orange);background:rgba(219,109,40,0.15)">${counts.review} review</span>` : '') +
              (counts.blocked ? `<span class="item-count-badge blocked">${counts.blocked} blocked</span>` : '') +
              `<span class="item-count-badge done">${counts.done} done</span>` +
              (counts.cancelled ? `<span class="item-count-badge" style="color:var(--text-muted);background:var(--bg-tertiary)">${counts.cancelled} cancelled</span>` : '')
            : '<span class="text-muted">No items yet</span>';

        // Update item epic select
        const epicOptions = state.epics
            .filter(e => e.is_backlog !== '1')
            .map(e => `<option value="${escapeHtml(e.id)}">${escapeHtml(e.title)}</option>`)
            .join('');
        dom.itemEpicSelect.innerHTML = '<option value="">Backlog</option>' + epicOptions;

        // Bulk actions bar
        if (state.selectedItemIds.size > 0) {
            const bar = document.createElement('div');
            bar.className = 'bulk-actions-bar';
            bar.innerHTML = `
                <span class="bulk-count">${state.selectedItemIds.size} selected</span>
                <button class="btn-sm accent" data-bulk-action="in_progress">Start</button>
                <button class="btn-sm accent" data-bulk-action="done">Done</button>
                <button class="btn-sm" data-bulk-action="open">Reopen</button>
                <button class="btn-sm danger" data-bulk-action="cancelled">Cancel</button>
                <button class="btn-sm" data-bulk-action="clear" style="margin-left:auto">Clear</button>
            `;
            bar.addEventListener('click', (e) => {
                const action = e.target.dataset.bulkAction;
                if (!action) return;
                if (action === 'clear') {
                    state.selectedItemIds.clear();
                    renderProjectDetailEpics();
                    return;
                }
                // Bulk state transition
                state.selectedItemIds.forEach((itemId) => {
                    sendWs({
                        type: 'items.update',
                        id: ++state.msgIdCounter,
                        item_id: itemId,
                        state: action,
                    });
                });
                showToast(`${state.selectedItemIds.size} items → ${action.replace('_', ' ')}`, 'info');
                state.selectedItemIds.clear();
            });
            container.appendChild(bar);
        }

        // Sort: non-backlog first (by sort_order), backlog last
        const sortedEpics = [...state.epics].sort((a, b) => {
            if (a.is_backlog === '1') return 1;
            if (b.is_backlog === '1') return -1;
            return (parseInt(a.sort_order) || 0) - (parseInt(b.sort_order) || 0);
        });

        sortedEpics.forEach(epic => {
            const epicItems = state.items.filter(item => item.epic_id === epic.id);
            const section = renderEpicSection(epic, epicItems);
            container.appendChild(section);
        });

        if (sortedEpics.length === 0) {
            container.innerHTML = '<div class="panel-empty">No epics yet. Create one or add items to get started.</div>';
        }
    }

    function renderEpicSection(epic, items) {
        const section = document.createElement('div');
        const isBacklog = epic.is_backlog === '1';
        section.className = 'epic-section' + (isBacklog ? ' backlog' : '');
        section.dataset.epicId = epic.id;

        const stateBadge = isBacklog ? '' : `<span class="state-badge ${epic.state}"><span class="state-dot"></span>${epic.state}</span>`;
        const progress = epic.progress || 0;
        const progressBar = !isBacklog && items.length > 0
            ? `<div class="epic-progress"><div class="epic-progress-bar" style="width:${progress}%"></div><span class="epic-progress-label">${progress}%</span></div>`
            : '';

        // Epic header
        const header = document.createElement('div');
        header.className = 'epic-header';
        header.innerHTML = `
            <div class="epic-header-left">
                ${!isBacklog ? '<span class="drag-handle" title="Drag to reorder">&#9776;</span>' : ''}
                <span class="epic-toggle">&#9660;</span>
                <span class="epic-title">${escapeHtml(epic.title)}</span>
                ${stateBadge}
                <span class="epic-item-count">${items.length}</span>
                ${progressBar}
            </div>
            <div class="epic-header-right">
                ${!isBacklog ? `
                    <select class="epic-state-select" data-epic-id="${epic.id}">
                        <option value="open" ${epic.state === 'open' ? 'selected' : ''}>Open</option>
                        <option value="in_progress" ${epic.state === 'in_progress' ? 'selected' : ''}>In Progress</option>
                        <option value="completed" ${epic.state === 'completed' ? 'selected' : ''}>Completed</option>
                        <option value="cancelled" ${epic.state === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                    </select>
                    <button class="btn-sm danger epic-delete-btn" data-epic-id="${epic.id}" title="Delete epic">&times;</button>
                ` : ''}
            </div>
        `;

        // Drag-and-drop for epic reordering
        if (!isBacklog) {
            section.draggable = true;
            section.addEventListener('dragstart', (e) => {
                e.dataTransfer.setData('text/epic-id', epic.id);
                e.dataTransfer.effectAllowed = 'move';
                section.classList.add('dragging');
            });
            section.addEventListener('dragend', () => {
                section.classList.remove('dragging');
                document.querySelectorAll('.epic-section.drag-over').forEach(el => el.classList.remove('drag-over'));
            });
            section.addEventListener('dragover', (e) => {
                if (!e.dataTransfer.types.includes('text/epic-id')) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                section.classList.add('drag-over');
            });
            section.addEventListener('dragleave', (e) => {
                if (e.relatedTarget && section.contains(e.relatedTarget)) return;
                section.classList.remove('drag-over');
            });
            section.addEventListener('drop', (e) => {
                e.preventDefault();
                section.classList.remove('drag-over');
                const draggedId = e.dataTransfer.getData('text/epic-id');
                if (!draggedId || draggedId === epic.id) return;
                handleEpicDrop(draggedId, epic.id);
            });
        }

        // Toggle collapse
        header.addEventListener('click', (e) => {
            if (e.target.closest('.epic-state-select') || e.target.closest('.epic-delete-btn') || e.target.closest('.drag-handle')) return;
            section.classList.toggle('collapsed');
            const toggle = header.querySelector('.epic-toggle');
            toggle.innerHTML = section.classList.contains('collapsed') ? '&#9654;' : '&#9660;';
        });

        // Epic state change
        const stateSelect = header.querySelector('.epic-state-select');
        if (stateSelect) {
            stateSelect.addEventListener('change', (e) => {
                e.stopPropagation();
                sendWs({
                    type: 'epics.update',
                    id: ++state.msgIdCounter,
                    epic_id: e.target.dataset.epicId,
                    state: e.target.value,
                });
            });
        }

        // Epic delete
        const deleteBtn = header.querySelector('.epic-delete-btn');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (confirm('Delete this epic? Items will be moved to Backlog.')) {
                    sendWs({
                        type: 'epics.delete',
                        id: ++state.msgIdCounter,
                        epic_id: e.currentTarget.dataset.epicId,
                    });
                }
            });
        }

        section.appendChild(header);

        // Items
        const itemsContainer = document.createElement('div');
        itemsContainer.className = 'epic-items';
        itemsContainer.dataset.epicId = epic.id;

        // Item drop zone for drag-and-drop reordering
        itemsContainer.addEventListener('dragover', (e) => {
            if (!e.dataTransfer.types.includes('text/item-id')) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        });

        items.forEach(item => {
            itemsContainer.appendChild(renderItemCard(item));
        });

        if (items.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'epic-items-empty';
            empty.textContent = 'No items';
            itemsContainer.appendChild(empty);
        }

        section.appendChild(itemsContainer);
        return section;
    }

    function handleEpicDrop(draggedId, targetId) {
        const nonBacklog = state.epics.filter(e => e.is_backlog !== '1');
        nonBacklog.sort((a, b) => (parseInt(a.sort_order) || 0) - (parseInt(b.sort_order) || 0));
        const ids = nonBacklog.map(e => e.id);
        const fromIdx = ids.indexOf(draggedId);
        const toIdx = ids.indexOf(targetId);
        if (fromIdx < 0 || toIdx < 0) return;
        ids.splice(fromIdx, 1);
        ids.splice(toIdx, 0, draggedId);
        sendWs({
            type: 'epics.reorder',
            id: ++state.msgIdCounter,
            project_id: state.selectedProjectId,
            epic_ids: ids,
        });
    }

    function handleItemDrop(draggedId, targetId, epicId) {
        const epicItems = state.items.filter(i => i.epic_id === epicId);
        epicItems.sort((a, b) => (parseInt(a.sort_order) || 0) - (parseInt(b.sort_order) || 0));
        const ids = epicItems.map(i => i.id);
        const fromIdx = ids.indexOf(draggedId);
        const toIdx = ids.indexOf(targetId);
        if (fromIdx < 0 || toIdx < 0) return;
        ids.splice(fromIdx, 1);
        ids.splice(toIdx, 0, draggedId);
        sendWs({
            type: 'items.reorder',
            id: ++state.msgIdCounter,
            epic_id: epicId,
            item_ids: ids,
        });
    }

    function renderItemCard(item) {
        const card = document.createElement('div');
        const priority = item.priority || 'normal';
        const isSelected = state.selectedItemIds.has(item.id);
        card.className = `item-card priority-${priority}${isSelected ? ' selected' : ''}`;
        card.dataset.itemId = item.id;
        card.dataset.epicId = item.epic_id || '';

        const stateLabel = item.state || 'open';

        card.innerHTML = `
            <input type="checkbox" class="item-checkbox" data-item-id="${item.id}" ${isSelected ? 'checked' : ''} title="Select for bulk actions">
            <span class="drag-handle" title="Drag to reorder">&#9776;</span>
            <div class="item-card-body">
                <div class="item-card-header">
                    <span class="state-badge ${stateLabel}"><span class="state-dot"></span>${stateLabel.replace('_', ' ')}</span>
                    ${priority !== 'normal' ? `<span class="priority-badge priority-${priority}">${priority}</span>` : ''}
                </div>
                <div class="item-card-title">${escapeHtml(item.title)}</div>
                ${item.description ? `<div class="item-card-desc">${escapeHtml(item.description)}</div>` : ''}
            </div>
            <div class="item-card-actions">
                <select class="item-state-select" data-item-id="${item.id}">
                    <option value="open" ${stateLabel === 'open' ? 'selected' : ''}>Open</option>
                    <option value="in_progress" ${stateLabel === 'in_progress' ? 'selected' : ''}>In Progress</option>
                    <option value="review" ${stateLabel === 'review' ? 'selected' : ''}>Review</option>
                    <option value="blocked" ${stateLabel === 'blocked' ? 'selected' : ''}>Blocked</option>
                    <option value="done" ${stateLabel === 'done' ? 'selected' : ''}>Done</option>
                    <option value="cancelled" ${stateLabel === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                </select>
                <select class="item-move-select" data-item-id="${item.id}" title="Move to epic">
                    ${state.epics.map(e =>
                        `<option value="${e.id}" ${e.id === item.epic_id ? 'selected' : ''}>${escapeHtml(e.title)}</option>`
                    ).join('')}
                </select>
                <button class="btn-sm item-assign-btn" data-item-id="${item.id}" title="Assign to Agent">Agent</button>
                <button class="btn-sm danger item-delete-btn" data-item-id="${item.id}" title="Delete">&times;</button>
            </div>
        `;

        // Assigned-to indicator
        if (item.assigned_to) {
            const assignTag = document.createElement('div');
            assignTag.style.cssText = 'font-size:10px;color:var(--text-muted);margin-top:2px;';
            assignTag.textContent = `Assigned: ${item.assigned_to}`;
            card.querySelector('.item-card-body').appendChild(assignTag);
        }

        // Review actions panel
        if (stateLabel === 'review') {
            const reviewPanel = document.createElement('div');
            reviewPanel.className = 'item-review-actions';
            reviewPanel.innerHTML = `
                <button class="btn-sm accent" data-review-action="done" data-item-id="${item.id}">Approve</button>
                <button class="btn-sm" data-review-action="in_progress" data-item-id="${item.id}">Changes</button>
                <button class="btn-sm" data-review-action="open" data-item-id="${item.id}">Reject</button>
            `;
            reviewPanel.querySelectorAll('[data-review-action]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const action = e.target.dataset.reviewAction;
                    const iid = e.target.dataset.itemId;
                    const comment = prompt('Comment (optional):');
                    sendWs({ type: 'items.update', id: ++state.msgIdCounter, item_id: iid, state: action });
                    if (comment) {
                        sendWs({ type: 'items.addNote', id: ++state.msgIdCounter, item_id: iid, content: `Review: ${action} — ${comment}` });
                    }
                });
            });
            card.appendChild(reviewPanel);
        }

        // Notes toggle
        const notesToggle = document.createElement('button');
        notesToggle.className = 'btn-sm';
        notesToggle.style.cssText = 'font-size:10px;padding:1px 6px;min-height:auto;margin-top:4px;';
        notesToggle.textContent = 'Notes';
        notesToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const existing = card.querySelector('.item-notes-section');
            if (existing) {
                existing.remove();
                return;
            }
            loadItemNotes(item.id);
            const notesSection = document.createElement('div');
            notesSection.className = 'item-notes-section';
            notesSection.innerHTML = '<div class="item-notes-header"><span class="item-notes-header-label">Activity</span></div><div class="item-notes-list"></div>';
            const notesList = notesSection.querySelector('.item-notes-list');
            const notes = state.itemNotes[item.id] || [];
            notes.forEach(n => {
                const noteEl = document.createElement('div');
                noteEl.className = 'item-note';
                noteEl.innerHTML = `${escapeHtml(n.content || '')}<div class="item-note-meta">${escapeHtml(n.author || '')} · ${n.timestamp ? formatTime(n.timestamp) : ''}</div>`;
                notesList.appendChild(noteEl);
            });
            const inputRow = document.createElement('div');
            inputRow.className = 'item-note-input';
            inputRow.innerHTML = `<input type="text" placeholder="Add note..." data-item-id="${item.id}"><button class="btn-sm accent" style="padding:2px 8px;min-height:auto;font-size:11px">Add</button>`;
            inputRow.querySelector('button').addEventListener('click', () => {
                const inp = inputRow.querySelector('input');
                addItemNote(item.id, inp.value);
                inp.value = '';
            });
            inputRow.querySelector('input').addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); const inp = e.target; addItemNote(item.id, inp.value); inp.value = ''; }
            });
            notesSection.appendChild(inputRow);
            card.appendChild(notesSection);
        });
        card.querySelector('.item-card-body').appendChild(notesToggle);

        // Checkbox for bulk selection
        card.querySelector('.item-checkbox').addEventListener('change', (e) => {
            e.stopPropagation();
            const id = e.target.dataset.itemId;
            if (e.target.checked) {
                state.selectedItemIds.add(id);
                card.classList.add('selected');
            } else {
                state.selectedItemIds.delete(id);
                card.classList.remove('selected');
            }
            renderProjectDetailEpics();
        });

        // Drag-and-drop for item reordering
        card.draggable = true;
        card.addEventListener('dragstart', (e) => {
            e.dataTransfer.setData('text/item-id', item.id);
            e.dataTransfer.setData('text/item-epic', item.epic_id || '');
            e.dataTransfer.effectAllowed = 'move';
            card.classList.add('dragging');
        });
        card.addEventListener('dragend', () => {
            card.classList.remove('dragging');
            document.querySelectorAll('.item-card.drag-over-item').forEach(el => el.classList.remove('drag-over-item'));
        });
        card.addEventListener('dragover', (e) => {
            if (!e.dataTransfer.types.includes('text/item-id')) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            card.classList.add('drag-over-item');
        });
        card.addEventListener('dragleave', () => {
            card.classList.remove('drag-over-item');
        });
        card.addEventListener('drop', (e) => {
            e.preventDefault();
            e.stopPropagation();
            card.classList.remove('drag-over-item');
            const draggedId = e.dataTransfer.getData('text/item-id');
            const sourceEpic = e.dataTransfer.getData('text/item-epic');
            if (!draggedId || draggedId === item.id) return;
            if (sourceEpic === item.epic_id) {
                // Same epic — reorder
                handleItemDrop(draggedId, item.id, item.epic_id);
            } else {
                // Cross-epic — move item to this epic first
                sendWs({
                    type: 'items.move',
                    id: ++state.msgIdCounter,
                    item_id: draggedId,
                    epic_id: item.epic_id,
                });
                showToast('Item moved to epic', 'info');
            }
        });

        // State change
        card.querySelector('.item-state-select').addEventListener('change', (e) => {
            sendWs({
                type: 'items.update',
                id: ++state.msgIdCounter,
                item_id: e.target.dataset.itemId,
                state: e.target.value,
            });
        });

        // Move to epic
        card.querySelector('.item-move-select').addEventListener('change', (e) => {
            sendWs({
                type: 'items.move',
                id: ++state.msgIdCounter,
                item_id: e.target.dataset.itemId,
                epic_id: e.target.value,
            });
        });

        // Assign to agent
        card.querySelector('.item-assign-btn').addEventListener('click', (e) => {
            e.stopPropagation();
            assignItemToAgent(e.currentTarget.dataset.itemId);
        });

        // Delete
        card.querySelector('.item-delete-btn').addEventListener('click', (e) => {
            if (confirm('Delete this item?')) {
                sendWs({
                    type: 'items.delete',
                    id: ++state.msgIdCounter,
                    item_id: e.currentTarget.dataset.itemId,
                });
            }
        });

        return card;
    }

    function toggleCreateEpic() {
        const visible = dom.epicCreateDialog.style.display !== 'none';
        dom.epicCreateDialog.style.display = visible ? 'none' : 'flex';
        dom.itemCreateDialog.style.display = 'none';
        if (!visible) {
            dom.epicTitleInput.value = '';
            dom.epicDescInput.value = '';
            dom.epicTitleInput.focus();
        }
    }

    function createEpic() {
        const title = dom.epicTitleInput.value.trim();
        if (!title || !state.selectedProjectId) return;

        sendWs({
            type: 'epics.create',
            id: ++state.msgIdCounter,
            project_id: state.selectedProjectId,
            title: title,
            description: dom.epicDescInput.value.trim(),
        });

        dom.epicCreateDialog.style.display = 'none';
    }

    function toggleCreateItem() {
        const visible = dom.itemCreateDialog.style.display !== 'none';
        dom.itemCreateDialog.style.display = visible ? 'none' : 'flex';
        dom.epicCreateDialog.style.display = 'none';
        if (!visible) {
            dom.itemTitleInput.value = '';
            dom.itemDescInput.value = '';
            dom.itemEpicSelect.value = '';
            dom.itemPrioritySelect.value = 'normal';
            dom.itemTitleInput.focus();
        }
    }

    function createItem() {
        const title = dom.itemTitleInput.value.trim();
        if (!title || !state.selectedProjectId) return;

        sendWs({
            type: 'items.create',
            id: ++state.msgIdCounter,
            project_id: state.selectedProjectId,
            title: title,
            description: dom.itemDescInput.value.trim(),
            epic_id: dom.itemEpicSelect.value || null,
            priority: dom.itemPrioritySelect.value,
        });

        dom.itemCreateDialog.style.display = 'none';
    }

    function updateProjectDropdowns() {
        const options = state.projects.map(p =>
            `<option value="${escapeHtml(p.id)}">${escapeHtml(p.name || 'Unnamed')}</option>`
        ).join('');

        // Project switcher
        dom.projectSwitcher.innerHTML = '<option value="general">General</option>' + options;
        dom.projectSwitcher.value = state.activeProjectId;

        // Conversations filter
        dom.conversationsProjectFilter.innerHTML = '<option value="">All Projects</option><option value="general">General</option>' + options;

        // Memory filter
        dom.memoryProjectFilter.innerHTML = '<option value="">General</option>' + options;
    }

    function toggleCreateProject() {
        state.showCreateProject = !state.showCreateProject;
        dom.projectCreateDialog.style.display = state.showCreateProject ? 'flex' : 'none';
        if (state.showCreateProject) {
            dom.projectNameInput.value = '';
            dom.projectDescInput.value = '';
            dom.projectCwdInput.value = '';
            dom.projectNameInput.focus();
        }
    }

    function createProject() {
        const name = dom.projectNameInput.value.trim();
        if (!name) return;

        sendWs({
            type: 'projects.create',
            id: ++state.msgIdCounter,
            name: name,
            description: dom.projectDescInput.value.trim(),
            cwd: dom.projectCwdInput.value.trim(),
        });

        toggleCreateProject();
    }

    function toggleEditProject() {
        const visible = dom.projectEditDialog.style.display !== 'none';
        dom.projectEditDialog.style.display = visible ? 'none' : 'flex';
        if (!visible) {
            const project = state.projects.find(p => p.id === state.selectedProjectId);
            if (project) {
                dom.projectEditName.value = project.name || '';
                dom.projectEditDesc.value = project.description || '';
                dom.projectEditCwd.value = project.cwd || '';
            }
            dom.projectEditName.focus();
        }
    }

    function saveProjectEdit() {
        const name = dom.projectEditName.value.trim();
        if (!name || !state.selectedProjectId) return;

        sendWs({
            type: 'projects.update',
            id: ++state.msgIdCounter,
            project_id: state.selectedProjectId,
            name: name,
            description: dom.projectEditDesc.value.trim(),
            cwd: dom.projectEditCwd.value.trim(),
        });

        dom.projectEditDialog.style.display = 'none';
        // Update local state immediately
        dom.projectDetailName.textContent = name;
        const desc = dom.projectEditDesc.value.trim();
        dom.projectDetailDesc.textContent = desc;
        dom.projectDetailDesc.style.display = desc ? 'block' : 'none';
    }

    function deleteProject() {
        if (!state.selectedProjectId) return;
        const project = state.projects.find(p => p.id === state.selectedProjectId);
        const name = project ? project.name : 'this project';
        if (!confirm(`Delete "${name}" and all its epics and items? This cannot be undone.`)) return;

        sendWs({
            type: 'projects.delete',
            id: ++state.msgIdCounter,
            project_id: state.selectedProjectId,
        });
    }

    // ── Memory ─────────────────────────────────────────────
    function loadMemories() {
        const projectId = dom.memoryProjectFilter.value || null;
        sendWs({ type: 'memory.list', id: ++state.msgIdCounter, project_id: projectId });
    }

    function renderMemories() {
        const container = dom.memoryList;
        const search = (dom.memorySearch.value || '').toLowerCase();
        const { facts, memories, project_memories } = state.memories;

        container.innerHTML = '';

        // Facts section
        const factKeys = Object.keys(facts);
        if (factKeys.length > 0) {
            const section = document.createElement('div');
            section.className = 'facts-section';
            section.innerHTML = '<div class="memory-category-header">Legacy Facts</div>';
            factKeys.forEach((key) => {
                if (search && !key.toLowerCase().includes(search) && !facts[key].toLowerCase().includes(search)) return;
                const item = document.createElement('div');
                item.className = 'fact-item';
                item.innerHTML = `<span class="fact-key">${escapeHtml(key)}:</span><span class="fact-value">${escapeHtml(facts[key])}</span>`;
                section.appendChild(item);
            });
            if (section.querySelectorAll('.fact-item').length > 0) {
                container.appendChild(section);
            }
        }

        // Project memories section
        if (project_memories && project_memories.length > 0) {
            const section = document.createElement('div');
            section.className = 'memory-category';
            section.innerHTML = '<div class="memory-category-header">Project Memories (' + project_memories.length + ')</div>';

            project_memories.forEach((mem) => {
                if (!mem) return;
                if (search && !(mem.content || '').toLowerCase().includes(search)) return;

                const item = createMemoryItem(mem);
                section.appendChild(item);
            });

            if (section.querySelectorAll('.memory-item').length > 0) {
                container.appendChild(section);
            }
        }

        // Group structured memories by category
        const grouped = {};
        (memories || []).forEach((mem) => {
            if (!mem) return;
            if (search && !(mem.content || '').toLowerCase().includes(search)) return;
            const cat = mem.category || 'uncategorized';
            if (!grouped[cat]) grouped[cat] = [];
            grouped[cat].push(mem);
        });

        const categoryOrder = ['preference', 'project', 'fact', 'context'];
        const allCats = [...new Set([...categoryOrder, ...Object.keys(grouped)])];

        allCats.forEach((cat) => {
            const items = grouped[cat];
            if (!items || items.length === 0) return;

            const section = document.createElement('div');
            section.className = 'memory-category';
            section.innerHTML = `<div class="memory-category-header">${escapeHtml(cat)} (${items.length})</div>`;

            items.forEach((mem) => {
                const item = createMemoryItem(mem);
                section.appendChild(item);
            });

            container.appendChild(section);
        });

        if (container.children.length === 0) {
            container.innerHTML = '<div class="panel-empty">No memories found</div>';
        }
    }

    function createMemoryItem(mem, projectIdOverride) {
        const item = document.createElement('div');
        item.className = 'memory-item';

        const importanceTag = mem.importance === 'high' ? ' ★' : '';
        const date = mem.created_at ? formatTime(mem.created_at) : '';
        const source = mem.source || '';
        const cat = mem.category || '';

        item.innerHTML = `
            <div>
                <div class="memory-item-content">${escapeHtml(mem.content || '')}${importanceTag}</div>
                <div class="memory-item-meta">${date}${source ? ' · ' + escapeHtml(source) : ''}${cat ? ' · ' + escapeHtml(cat) : ''}</div>
            </div>
            <div class="memory-item-actions">
                <button class="memory-item-edit" data-id="${escapeHtml(mem.id || '')}" data-content="${escapeHtml(mem.content || '')}" data-importance="${escapeHtml(mem.importance || 'normal')}" data-category="${escapeHtml(cat)}" title="Edit">&#9998;</button>
                <button class="memory-item-delete" data-id="${escapeHtml(mem.id || '')}" title="Delete">&times;</button>
            </div>
        `;

        item.querySelector('.memory-item-delete').addEventListener('click', (e) => {
            e.stopPropagation();
            const id = e.currentTarget.dataset.id;
            if (id && confirm('Delete this memory?')) {
                sendWs({ type: 'memory.delete', id: ++state.msgIdCounter, memory_id: id });
            }
        });

        item.querySelector('.memory-item-edit').addEventListener('click', (e) => {
            e.stopPropagation();
            const btn = e.currentTarget;
            openMemoryModal('edit', {
                id: btn.dataset.id,
                content: btn.dataset.content,
                category: btn.dataset.category,
                importance: btn.dataset.importance,
                projectId: projectIdOverride || state.memories.project_id || null,
            });
        });

        return item;
    }

    // ── Memory Modal ────────────────────────────────────────
    function openMemoryModal(mode, opts) {
        state.memoryModalMode = mode;
        state.memoryModalId = opts.id || null;
        state.memoryModalProjectId = opts.projectId || null;

        dom.memoryModalTitle.textContent = mode === 'edit' ? 'Edit Memory' : 'Create Memory';
        dom.memoryModalContent.value = opts.content || '';
        dom.memoryModalCategory.value = opts.category || 'context';
        dom.memoryModalImportance.value = opts.importance || 'normal';

        dom.memoryModalOverlay.classList.remove('hidden');
        dom.memoryModalContent.focus();
    }

    function closeMemoryModal() {
        dom.memoryModalOverlay.classList.add('hidden');
        state.memoryModalMode = null;
        state.memoryModalId = null;
        state.memoryModalProjectId = null;
        dom.memoryModalContent.value = '';
    }

    function saveMemoryModal() {
        const content = dom.memoryModalContent.value.trim();
        if (!content) return;

        const category = dom.memoryModalCategory.value;
        const importance = dom.memoryModalImportance.value;
        const projectId = state.memoryModalProjectId;

        if (state.memoryModalMode === 'edit' && state.memoryModalId) {
            sendWs({
                type: 'memory.update',
                id: ++state.msgIdCounter,
                memory_id: state.memoryModalId,
                content,
                category,
                importance,
                project_id: projectId,
            });
        } else {
            sendWs({
                type: 'memory.create',
                id: ++state.msgIdCounter,
                content,
                category,
                importance,
                project_id: projectId,
            });
        }
        closeMemoryModal();
    }

    // ── Project Sub-Tabs ────────────────────────────────────
    function switchProjectSubTab(tab) {
        state.projectSubTab = tab;
        $$('.project-sub-tab').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.subtab === tab);
        });
        dom.projectWorkPanel.classList.toggle('active', tab === 'work');
        dom.projectMemoryPanel.classList.toggle('active', tab === 'memory');

        if (tab === 'memory' && state.selectedProjectId) {
            loadProjectMemories(state.selectedProjectId);
        }
    }

    function loadProjectMemories(projectId) {
        sendWs({ type: 'memory.list', id: ++state.msgIdCounter, project_id: projectId, _source: 'project_detail' });
    }

    function renderProjectMemories() {
        const container = dom.projectMemoryList;
        const search = state.projectMemorySearch.toLowerCase();
        const memories = state.projectMemories || [];

        container.innerHTML = '';

        // Group by category
        const grouped = {};
        memories.forEach(mem => {
            if (!mem) return;
            if (search && !(mem.content || '').toLowerCase().includes(search)) return;
            const cat = mem.category || 'uncategorized';
            if (!grouped[cat]) grouped[cat] = [];
            grouped[cat].push(mem);
        });

        const categoryOrder = ['preference', 'project', 'fact', 'context'];
        const allCats = [...new Set([...categoryOrder, ...Object.keys(grouped)])];

        allCats.forEach(cat => {
            const items = grouped[cat];
            if (!items || items.length === 0) return;

            const section = document.createElement('div');
            section.className = 'memory-category';
            section.innerHTML = `<div class="memory-category-header">${escapeHtml(cat)} (${items.length})</div>`;

            items.forEach(mem => {
                section.appendChild(createMemoryItem(mem, state.selectedProjectId));
            });

            container.appendChild(section);
        });

        if (container.children.length === 0) {
            container.innerHTML = '<div class="panel-empty">No project memories</div>';
        }
    }

    // ── Semantic Search ──────────────────────────────────────
    function setSearchMode(mode) {
        state.searchMode = mode;
        dom.searchModeToggle.querySelectorAll('.search-mode-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.mode === mode);
        });
        state.semanticSearchResults = null;
        dom.memorySearchStatus.style.display = 'none';
        if (mode === 'local') {
            renderMemories();
        } else {
            const query = (dom.memorySearch.value || '').trim();
            if (query.length > 0) {
                triggerSemanticSearch(query);
            } else {
                dom.memoryList.innerHTML = '<div class="panel-empty">Type to search across all memories semantically</div>';
            }
        }
    }

    function triggerSemanticSearch(query) {
        if (!query || query.length < 2) {
            dom.memoryList.innerHTML = '<div class="panel-empty">Type to search across all memories semantically</div>';
            dom.memorySearchStatus.style.display = 'none';
            return;
        }
        state.semanticSearching = true;
        dom.memorySearchStatus.innerHTML = '<span class="spinner"></span>Searching...';
        dom.memorySearchStatus.style.display = 'flex';
        const projectId = dom.memoryProjectFilter.value || null;
        sendWs({
            type: 'memory.search',
            id: ++state.msgIdCounter,
            query: query,
            project_id: projectId,
            limit: 30,
        });
    }

    function handleMemorySearchInput() {
        if (state.searchMode === 'local') {
            renderMemories();
            return;
        }
        // Debounce semantic search
        if (state.semanticSearchTimer) clearTimeout(state.semanticSearchTimer);
        state.semanticSearchTimer = setTimeout(() => {
            const query = (dom.memorySearch.value || '').trim();
            triggerSemanticSearch(query);
        }, 300);
    }

    function renderSemanticResults() {
        const container = dom.memoryList;
        const results = state.semanticSearchResults || [];
        dom.memorySearchStatus.style.display = 'none';

        if (results.length === 0) {
            container.innerHTML = '<div class="panel-empty">No matching memories found</div>';
            return;
        }

        container.innerHTML = '';
        results.forEach(r => {
            const item = document.createElement('div');
            item.className = 'memory-item';
            const score = Math.round((r.score || 0) * 100);
            const scoreClass = score >= 80 ? 'score-high' : score >= 60 ? 'score-medium' : 'score-low';
            const cat = r.category || '';
            const importance = r.importance === 'high' ? ' ★' : '';
            const projectId = r.project_id || '';

            item.innerHTML = `
                <div>
                    <div class="memory-item-content">${escapeHtml(r.content || '')}${importance}</div>
                    <div class="memory-item-meta">
                        <span class="type-badge type-${cat}">${escapeHtml(cat)}</span>
                        ${projectId && projectId !== 'general' ? `<span class="conversation-project-badge">${escapeHtml(projectId)}</span>` : ''}
                    </div>
                </div>
                <span class="score-badge ${scoreClass}">${score}%</span>
            `;
            container.appendChild(item);
        });
    }

    // ── Analytics ──────────────────────────────────────────
    function loadAnalytics() {
        sendWs({ type: 'memory.analytics', id: ++state.msgIdCounter });
    }

    function renderAnalytics() {
        const data = state.analyticsData;
        if (!data) {
            dom.memoryAnalytics.style.display = 'none';
            return;
        }
        dom.memoryAnalytics.style.display = 'block';

        const totalMemories = data.total_memories || 0;
        const embeddedPct = data.embedded_pct || 0;
        const projectCount = data.project_count || 0;
        const categories = data.categories || {};
        const nightlyHistory = data.nightly_history || [];

        const arrow = state.analyticsExpanded ? '&#9660;' : '&#9654;';
        let html = `<div class="analytics-toggle" id="analytics-toggle">
            <span class="analytics-toggle-label">Analytics</span>
            <span class="analytics-toggle-arrow">${arrow}</span>
        </div>`;

        if (state.analyticsExpanded) {
            html += `<div class="analytics-cards">
                <div class="analytics-card"><div class="analytics-card-value">${totalMemories}</div><div class="analytics-card-label">Total Memories</div></div>
                <div class="analytics-card"><div class="analytics-card-value">${embeddedPct}%</div><div class="analytics-card-label">Embedded</div></div>
                <div class="analytics-card"><div class="analytics-card-value">${projectCount}</div><div class="analytics-card-label">Projects</div></div>
            </div>`;

            // Category breakdown bar chart
            const catEntries = Object.entries(categories).sort((a, b) => b[1] - a[1]);
            if (catEntries.length > 0) {
                const maxCount = catEntries[0][1] || 1;
                html += '<div class="analytics-bar-chart">';
                catEntries.forEach(([cat, count]) => {
                    const pct = Math.round((count / maxCount) * 100);
                    html += `<div class="analytics-bar-row">
                        <span class="analytics-bar-label">${escapeHtml(cat)}</span>
                        <div class="analytics-bar-track"><div class="analytics-bar-fill" style="width:${pct}%"></div></div>
                        <span class="analytics-bar-count">${count}</span>
                    </div>`;
                });
                html += '</div>';
            }

            // Nightly history table
            if (nightlyHistory.length > 0) {
                html += '<table class="analytics-nightly-table"><thead><tr><th>Date</th><th>Validated</th><th>Removed</th><th>Merged</th><th>Backfilled</th></tr></thead><tbody>';
                nightlyHistory.slice(0, 5).forEach(run => {
                    const date = run.timestamp ? new Date(run.timestamp * 1000).toLocaleDateString() : '?';
                    html += `<tr><td>${date}</td><td>${run.validated || 0}</td><td>${run.removed_stale || 0}</td><td>${run.merged || 0}</td><td>${run.backfilled || 0}</td></tr>`;
                });
                html += '</tbody></table>';
            }
        }

        dom.memoryAnalytics.innerHTML = html;

        // Attach toggle listener
        const toggle = dom.memoryAnalytics.querySelector('#analytics-toggle');
        if (toggle) {
            toggle.addEventListener('click', () => {
                state.analyticsExpanded = !state.analyticsExpanded;
                renderAnalytics();
            });
        }
    }

    // ── Item Notes ────────────────────────────────────────
    function loadItemNotes(itemId) {
        sendWs({ type: 'items.notes', id: ++state.msgIdCounter, item_id: itemId });
    }

    function addItemNote(itemId, content) {
        if (!content.trim()) return;
        sendWs({ type: 'items.addNote', id: ++state.msgIdCounter, item_id: itemId, content: content.trim() });
    }

    function assignItemToAgent(itemId) {
        sendWs({ type: 'items.assign', id: ++state.msgIdCounter, item_id: itemId, assignee: 'agent' });
    }

    // ── Tab Switching ──────────────────────────────────────
    function switchTab(tab) {
        state.currentTab = tab;
        $$('.tab-bar button[data-tab]').forEach((btn) => {
            btn.classList.toggle('active', btn.dataset.tab === tab);
        });
        $$('.tab-panel').forEach((panel) => {
            panel.classList.toggle('active', panel.id === 'panel-' + tab);
        });

        if (tab === 'conversations') loadConversations();
        if (tab === 'projects') loadProjects();
        if (tab === 'memory') { loadMemories(); loadAnalytics(); }
    }

    $$('.tab-bar button[data-tab]').forEach((btn) => {
        btn.addEventListener('click', () => switchTab(btn.dataset.tab));
    });

    // ── Event Listeners ───────────────────────────────────
    dom.chatInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendChat();
        }
    });

    dom.chatInput.addEventListener('input', () => {
        autoResizeTextarea();
        updateSendButton();
    });

    dom.chatSend.addEventListener('click', sendChat);

    // Image upload events
    dom.chatAttachBtn.addEventListener('click', () => dom.chatImageInput.click());
    dom.chatImageInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) handleImageSelect(e.target.files);
    });

    // Drag-and-drop on input area
    dom.chatInputArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        dom.chatInputArea.classList.add('drag-active');
    });
    dom.chatInputArea.addEventListener('dragleave', () => {
        dom.chatInputArea.classList.remove('drag-active');
    });
    dom.chatInputArea.addEventListener('drop', (e) => {
        e.preventDefault();
        dom.chatInputArea.classList.remove('drag-active');
        const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
        if (files.length > 0) handleImageSelect(files);
    });

    // Paste images from clipboard
    dom.chatInput.addEventListener('paste', (e) => {
        const items = Array.from(e.clipboardData?.items || []);
        const imageFiles = items.filter(item => item.type.startsWith('image/')).map(item => item.getAsFile()).filter(Boolean);
        if (imageFiles.length > 0) {
            e.preventDefault();
            handleImageSelect(imageFiles);
        }
    });

    dom.continueBadge.addEventListener('click', () => {
        state.parentTaskId = null;
        updateContinueBadge();
    });

    dom.newChatBtn.addEventListener('click', newChat);

    dom.conversationsProjectFilter.addEventListener('change', loadConversations);
    if (dom.conversationsTypeFilter) dom.conversationsTypeFilter.addEventListener('change', loadConversations);

    dom.showArchivedCheckbox.addEventListener('change', () => {
        state.showArchived = dom.showArchivedCheckbox.checked;
        loadConversations();
    });

    dom.projectSwitcher.addEventListener('change', () => {
        state.activeProjectId = dom.projectSwitcher.value;
        localStorage.setItem('cc_active_project', state.activeProjectId);
    });

    dom.createProjectBtn.addEventListener('click', toggleCreateProject);
    dom.projectCancelBtn.addEventListener('click', toggleCreateProject);
    dom.projectSaveBtn.addEventListener('click', createProject);

    dom.projectBackBtn.addEventListener('click', closeProjectDetail);
    dom.projectEditBtn.addEventListener('click', toggleEditProject);
    dom.projectEditCancelBtn.addEventListener('click', toggleEditProject);
    dom.projectEditSaveBtn.addEventListener('click', saveProjectEdit);
    dom.projectDeleteBtn.addEventListener('click', deleteProject);
    dom.createEpicBtn.addEventListener('click', toggleCreateEpic);
    dom.epicCancelBtn.addEventListener('click', toggleCreateEpic);
    dom.epicSaveBtn.addEventListener('click', createEpic);
    dom.createItemBtn.addEventListener('click', toggleCreateItem);
    dom.itemCancelBtn.addEventListener('click', toggleCreateItem);
    dom.itemSaveBtn.addEventListener('click', createItem);

    dom.epicTitleInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); createEpic(); }
    });
    dom.itemTitleInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); createItem(); }
    });

    // Memory modal events
    dom.memoryModalClose.addEventListener('click', closeMemoryModal);
    dom.memoryModalCancel.addEventListener('click', closeMemoryModal);
    dom.memoryModalSave.addEventListener('click', saveMemoryModal);
    dom.memoryModalOverlay.addEventListener('click', (e) => {
        if (e.target === dom.memoryModalOverlay) closeMemoryModal();
    });

    // Project sub-tab events
    $$('.project-sub-tab').forEach(btn => {
        btn.addEventListener('click', () => switchProjectSubTab(btn.dataset.subtab));
    });
    dom.projectCreateMemoryBtn.addEventListener('click', () => {
        openMemoryModal('create', { projectId: state.selectedProjectId });
    });
    dom.projectMemorySearch.addEventListener('input', () => {
        state.projectMemorySearch = dom.projectMemorySearch.value;
        renderProjectMemories();
    });

    dom.memorySearch.addEventListener('input', handleMemorySearchInput);
    dom.memoryProjectFilter.addEventListener('change', () => {
        loadMemories();
        if (state.searchMode === 'semantic') {
            const query = (dom.memorySearch.value || '').trim();
            if (query) triggerSemanticSearch(query);
        }
    });

    // Search mode toggle
    dom.searchModeToggle.querySelectorAll('.search-mode-btn').forEach(btn => {
        btn.addEventListener('click', () => setSearchMode(btn.dataset.mode));
    });

    // ── Keyboard Shortcuts ───────────────────────────────
    document.addEventListener('keydown', (e) => {
        const activeEl = document.activeElement;
        const isInput = activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA' || activeEl.tagName === 'SELECT');

        // Escape — close dialogs, clear selection, or go back
        if (e.key === 'Escape') {
            // Close memory modal first
            if (state.memoryModalMode) { closeMemoryModal(); return; }
            // Close any open dialog
            if (dom.projectCreateDialog.style.display === 'flex') { toggleCreateProject(); return; }
            if (dom.projectEditDialog.style.display !== 'none') { toggleEditProject(); return; }
            if (dom.epicCreateDialog.style.display !== 'none') { toggleCreateEpic(); return; }
            if (dom.itemCreateDialog.style.display !== 'none') { toggleCreateItem(); return; }
            // Clear bulk selection
            if (state.selectedItemIds.size > 0) { state.selectedItemIds.clear(); renderProjectDetailEpics(); return; }
            // Go back from project detail
            if (state.showProjectDetail) { closeProjectDetail(); return; }
            // Blur active input
            if (isInput) { activeEl.blur(); return; }
        }

        // Don't intercept when typing in inputs (except Escape above)
        if (isInput) return;

        // Tab switching: 1-4
        if (e.key === '1' && !e.ctrlKey && !e.metaKey) { switchTab('chat'); return; }
        if (e.key === '2' && !e.ctrlKey && !e.metaKey) { switchTab('conversations'); return; }
        if (e.key === '3' && !e.ctrlKey && !e.metaKey) { switchTab('projects'); return; }
        if (e.key === '4' && !e.ctrlKey && !e.metaKey) { switchTab('memory'); return; }

        // N — new chat (when on chat tab)
        if (e.key === 'n' && state.currentTab === 'chat') { newChat(); return; }

        // / — focus chat input
        if (e.key === '/' && state.currentTab === 'chat') {
            e.preventDefault();
            dom.chatInput.focus();
            return;
        }

        // A — select all items (when on projects tab with detail view open)
        if (e.key === 'a' && state.currentTab === 'projects' && state.showProjectDetail) {
            if (state.selectedItemIds.size === state.items.length) {
                state.selectedItemIds.clear();
            } else {
                state.items.forEach(i => state.selectedItemIds.add(i.id));
            }
            renderProjectDetailEpics();
            return;
        }
    });

    function autoResizeTextarea() {
        const ta = dom.chatInput;
        ta.style.height = 'auto';
        ta.style.height = Math.min(ta.scrollHeight, 200) + 'px';
    }

    // ── Helpers ────────────────────────────────────────────
    function formatElapsed(seconds) {
        seconds = Math.floor(seconds);
        if (seconds < 60) return seconds + 's';
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return m + 'm ' + s + 's';
    }

    function formatTime(ts) {
        const d = new Date(ts * 1000);
        const now = new Date();
        const diff = (now - d) / 1000;

        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';

        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) +
            ' ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    }

    // ── Init ───────────────────────────────────────────────
    if (state.token) {
        showApp();
        connectWebSocket();
    }

    updateSendButton();
    updateChatHeader();
})();
