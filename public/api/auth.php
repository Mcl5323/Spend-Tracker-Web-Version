<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Data directory — stored next to this file's parent folder
define('DATA_DIR', __DIR__ . '/../data/');
define('USERS_FILE', DATA_DIR . 'users.json');

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

function loadUsers(): array {
    if (!file_exists(USERS_FILE)) return [];
    $content = file_get_contents(USERS_FILE);
    return json_decode($content, true) ?? [];
}

function saveUsers(array $users): void {
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
}

function respond(bool $success, string $message, array $data = []): void {
    echo json_encode(['success' => $success, 'message' => $message, ...$data]);
    exit();
}

$body = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';

switch ($action) {

    case 'register':
        $name     = trim($body['name'] ?? '');
        $email    = strtolower(trim($body['email'] ?? ''));
        $password = $body['password'] ?? '';

        if (!$name || !$email || !$password) {
            respond(false, 'All fields are required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(false, 'Invalid email address.');
        }
        if (strlen($password) < 6) {
            respond(false, 'Password must be at least 6 characters.');
        }

        $users = loadUsers();
        foreach ($users as $u) {
            if ($u['email'] === $email) {
                respond(false, 'An account with this email already exists.');
            }
        }

        $userId = uniqid('user_', true);
        $users[] = [
            'id'         => $userId,
            'name'       => $name,
            'email'      => $email,
            'password'   => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        saveUsers($users);

        // Create a personal expenses file for this user
        $expensesFile = DATA_DIR . "expenses_{$userId}.json";
        file_put_contents($expensesFile, json_encode([], JSON_PRETTY_PRINT));

        respond(true, 'Account created successfully!', [
            'user' => ['id' => $userId, 'name' => $name, 'email' => $email]
        ]);
        break;

    case 'login':
        $email    = strtolower(trim($body['email'] ?? ''));
        $password = $body['password'] ?? '';

        if (!$email || !$password) {
            respond(false, 'Email and password are required.');
        }

        $users = loadUsers();
        foreach ($users as $u) {
            if ($u['email'] === $email && password_verify($password, $u['password'])) {
                respond(true, 'Login successful!', [
                    'user' => ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email']]
                ]);
            }
        }
        respond(false, 'Incorrect email or password.');
        break;

    default:
        respond(false, 'Unknown action.');
}
