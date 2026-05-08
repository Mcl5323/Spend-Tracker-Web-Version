// ─── Config ──────────────────────────────────────────────────────────────────
const API_BASE = 'api/'; // Relative path — works when served via PHP dev server

// ─── DOM refs ────────────────────────────────────────────────────────────────
const loginTab      = document.getElementById('loginTab');
const signupTab     = document.getElementById('signupTab');
const loginForm     = document.getElementById('loginForm');
const signupForm    = document.getElementById('signupForm');
const switchToSignup = document.getElementById('switchToSignup');
const switchToLogin  = document.getElementById('switchToLogin');
const alertBox      = document.getElementById('alert');
const loginBtn      = document.getElementById('loginBtn');
const signupBtn     = document.getElementById('signupBtn');

// ─── Helpers ─────────────────────────────────────────────────────────────────
function showAlert(message, type = 'error') {
    alertBox.textContent = message;
    alertBox.style.display = 'block';
    alertBox.style.background = type === 'error' ? '#fee2e2' : '#d1fae5';
    alertBox.style.color      = type === 'error' ? '#991b1b' : '#065f46';
    alertBox.style.border     = type === 'error' ? '1px solid #fca5a5' : '1px solid #6ee7b7';
    if (type !== 'success') {
        setTimeout(() => alertBox.style.display = 'none', 4000);
    }
}

function setLoading(btn, loading) {
    btn.disabled = loading;
    btn.textContent = loading ? 'Please wait…' : (btn.id === 'loginBtn' ? 'Login' : 'Sign Up');
}

async function apiPost(endpoint, payload) {
    const res = await fetch(API_BASE + endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    return res.json();
}

// ─── Tab switching ────────────────────────────────────────────────────────────
function setActiveTab(tab) {
    alertBox.style.display = 'none';
    if (tab === 'login') {
        loginTab.classList.add('active');
        signupTab.classList.remove('active');
        loginForm.style.display = 'grid';
        signupForm.style.display = 'none';
    } else {
        signupTab.classList.add('active');
        loginTab.classList.remove('active');
        signupForm.style.display = 'grid';
        loginForm.style.display = 'none';
    }
}

loginTab.addEventListener('click', () => setActiveTab('login'));
signupTab.addEventListener('click', () => setActiveTab('signup'));
switchToSignup.addEventListener('click', e => { e.preventDefault(); setActiveTab('signup'); });
switchToLogin.addEventListener('click',  e => { e.preventDefault(); setActiveTab('login'); });

// ─── Login ────────────────────────────────────────────────────────────────────
loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const email    = document.getElementById('loginEmail').value.trim();
    const password = document.getElementById('loginPassword').value.trim();
    if (!email || !password) { showAlert('Please enter your email and password.'); return; }

    setLoading(loginBtn, true);
    try {
        const data = await apiPost('auth.php', { action: 'login', email, password });
        if (data.success) {
            // Save session info in sessionStorage (cleared when tab closes)
            sessionStorage.setItem('userId',   data.user.id);
            sessionStorage.setItem('userName', data.user.name);
            sessionStorage.setItem('userEmail', data.user.email);
            showAlert('Login successful! Redirecting…', 'success');
            setTimeout(() => window.location.href = 'homepage.html', 800);
        } else {
            showAlert(data.message);
        }
    } catch (err) {
        showAlert('Server error. Make sure PHP server is running.');
    }
    setLoading(loginBtn, false);
});

// ─── Sign up ──────────────────────────────────────────────────────────────────
signupForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const name     = document.getElementById('signupName').value.trim();
    const email    = document.getElementById('signupEmail').value.trim();
    const password = document.getElementById('signupPassword').value.trim();
    if (!name || !email || !password) { showAlert('Please fill in all fields.'); return; }

    setLoading(signupBtn, true);
    try {
        const data = await apiPost('auth.php', { action: 'register', name, email, password });
        if (data.success) {
            showAlert('Account created! Please log in.', 'success');
            setTimeout(() => setActiveTab('login'), 1500);
        } else {
            showAlert(data.message);
        }
    } catch (err) {
        showAlert('Server error. Make sure PHP server is running.');
    }
    setLoading(signupBtn, false);
});
