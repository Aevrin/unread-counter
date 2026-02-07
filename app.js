document.addEventListener('DOMContentLoaded', init);

const views = {
    setup: document.getElementById('view-setup'),
    auth: document.getElementById('view-auth'),
    mail: document.getElementById('view-mail')
};

function show(view) {
    Object.values(views).forEach(v => v.classList.add('hidden'));
    view.classList.remove('hidden');
}

async function init() {
    initTheme();

    try {
        const res = await fetch('api.php?action=status');
        const status = await res.json();

        if (!status.configured) {
            show(views.setup);
        } else if (!status.authenticated) {
            show(views.auth);
        } else {
            show(views.mail);
            fetchUnread();
        }
    } catch (e) {
        show(views.setup);
    }

    document.getElementById('btn-auth').addEventListener('click', startAuth);
    document.getElementById('btn-refresh').addEventListener('click', fetchUnread);
    document.getElementById('btn-logout').addEventListener('click', logout);
}

async function startAuth() {
    const res = await fetch('api.php?action=auth');
    const data = await res.json();
    if (data.url) {
        window.location.href = data.url;
    }
}

async function fetchUnread() {
    const countEl = document.getElementById('unread-count');
    countEl.textContent = '…';

    try {
        const res = await fetch('api.php?action=unread');
        if (res.status === 401) {
            show(views.auth);
            return;
        }
        const data = await res.json();
        countEl.textContent = data.unread;
        document.getElementById('total-count').textContent = `${data.total} total`;
        document.getElementById('last-checked').textContent = `Checked ${new Date().toLocaleTimeString()}`;
    } catch (e) {
        countEl.textContent = '!';
    }
}

async function logout() {
    await fetch('api.php?action=logout');
    show(views.auth);
}

function initTheme() {
    const saved = localStorage.getItem('theme');
    if (saved === 'light') {
        document.body.classList.remove('dark-mode');
    }
    document.getElementById('theme-toggle').addEventListener('click', () => {
        const isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        document.getElementById('theme-toggle').textContent = isDark ? '☀️' : '🌙';
    });
    document.getElementById('theme-toggle').textContent =
        document.body.classList.contains('dark-mode') ? '☀️' : '🌙';
}
