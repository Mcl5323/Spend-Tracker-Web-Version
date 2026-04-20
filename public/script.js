const loginTab = document.getElementById('loginTab');
const signupTab = document.getElementById('signupTab');
const loginForm = document.getElementById('loginForm');
const signupForm = document.getElementById('signupForm');
const switchToSignup = document.getElementById('switchToSignup');
const switchToLogin = document.getElementById('switchToLogin');
const alertBox = document.getElementById('alert');

function showAlert(message) {
    alertBox.textContent = message;
    alertBox.style.display = 'block';
    setTimeout(() => alertBox.style.display = 'none', 4000);
}

function setActiveTab(tab) {
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
    alertBox.style.display = 'none';
}

loginTab.addEventListener('click', () => setActiveTab('login'));
signupTab.addEventListener('click', () => setActiveTab('signup'));
switchToSignup.addEventListener('click', event => {
    event.preventDefault();
    setActiveTab('signup');
});
switchToLogin.addEventListener('click', event => {
    event.preventDefault();
    setActiveTab('login');
});

loginForm.addEventListener('submit', event => {
    event.preventDefault();
    const email = document.getElementById('loginEmail').value.trim();
    const password = document.getElementById('loginPassword').value.trim();

    if (!email || !password) {
        showAlert('Please enter your email and password.');
        return;
    }
    showAlert('Login submitted. This is a demo page only.');
});

signupForm.addEventListener('submit', event => {
    event.preventDefault();
    const name = document.getElementById('signupName').value.trim();
    const email = document.getElementById('signupEmail').value.trim();
    const password = document.getElementById('signupPassword').value.trim();

    if (!name || !email || !password) {
        showAlert('Please enter your name, email, and password.');
        return;
    }
    showAlert('Sign up submitted. This is a demo page only.');
});
