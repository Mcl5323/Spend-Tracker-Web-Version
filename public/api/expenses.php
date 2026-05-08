<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// =============================================================================
//  Config
// =============================================================================
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    echo json_encode(['success' => false, 'message' => 'Missing api/config.php config file.']);
    exit();
}
$config = require $configFile;

define('AI_URL',   $config['AI_URL']   ?? 'http://localhost:11434/api/generate');
define('AI_MODEL', $config['AI_MODEL'] ?? 'llama3');

// =============================================================================
//  Data directory — auto-created on first run
// =============================================================================
define('DATA_DIR', __DIR__ . '/../data/');

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// =============================================================================
//  Helper functions
// =============================================================================
function getExpensesFile(string $userId): string {
    return DATA_DIR . "expenses_{$userId}.json";
}

function loadExpenses(string $userId): array {
    $file = getExpensesFile($userId);
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?? [];
}

function saveExpenses(string $userId, array $expenses): void {
    file_put_contents(getExpensesFile($userId), json_encode($expenses, JSON_PRETTY_PRINT));
}

function respond(bool $success, string $message, array $data = []): void {
    echo json_encode(['success' => $success, 'message' => $message, ...$data]);
    exit();
}

function validateUserId(string $userId): bool {
    return (bool) preg_match('/^[a-zA-Z0-9_.]+$/', $userId);
}

// =============================================================================
//  Extract JSON from Ollama response (handles extra text, markdown fences, etc.)
// =============================================================================
function extractJson(string $text): ?array {
    // 1. Strip markdown code fences
    $text = preg_replace('/^```(?:json)?\s*/im', '', $text);
    $text = preg_replace('/\s*```\s*$/im', '', $text);
    $text = trim($text);

    // 2. Try parsing the whole text first
    $parsed = json_decode($text, true);
    if (is_array($parsed)) return $parsed;

    // 3. Find the first {...} block in the string (Ollama sometimes adds extra words)
    if (preg_match('/\{[^{}]*\}/s', $text, $matches)) {
        $parsed = json_decode($matches[0], true);
        if (is_array($parsed)) return $parsed;
    }

    return null;
}

// =============================================================================
//  Call Ollama AI
// =============================================================================
function callAI(string $prompt, bool $forceJson = false): string {
    $payload = [
        'model'  => AI_MODEL,
        'prompt' => $prompt,
        'stream' => false,
    ];

    // Only use format:json for structured data requests (ai_add)
    // Do NOT use it for free-text insight responses — it confuses the model
    if ($forceJson) {
        $payload['format'] = 'json';
    }

    $ch = curl_init(AI_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);

    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        throw new RuntimeException(
            "Cannot connect to local Ollama. Make sure you opened CMD and ran `ollama run llama3`. Error: {$error}"
        );
    }

    $resp = json_decode($raw, true);

    // Ollama's text response is in the 'response' field
    $text = $resp['response'] ?? null;

    if ($text === null) {
        throw new RuntimeException("Ollama returned unexpected format. Raw: " . substr($raw, 0, 300));
    }

    return trim($text);
}

// =============================================================================
//  Route request
// =============================================================================
$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';
$userId = trim($body['userId'] ?? '');

if (!$userId || !validateUserId($userId)) {
    respond(false, 'Invalid or missing user ID.');
}

switch ($action) {

    // ── Get expenses ──────────────────────────────────────────────────────────
    case 'get':
        $expenses = loadExpenses($userId);
        $category = $body['category'] ?? '';
        if ($category) {
            $expenses = array_values(
                array_filter($expenses, fn($e) => $e['category'] === $category)
            );
        }
        usort($expenses, fn($a, $b) => strcmp($b['date'], $a['date']));
        respond(true, 'OK', ['expenses' => $expenses]);
        break;

    // ── Add expense (manual) ──────────────────────────────────────────────────
    case 'add':
        $name     = trim($body['name'] ?? '');
        $amount   = floatval($body['amount'] ?? 0);
        $category = trim($body['category'] ?? '');
        $date     = trim($body['date'] ?? '');

        if (!$name || $amount <= 0 || !$category || !$date) {
            respond(false, 'All fields are required and amount must be positive.');
        }

        $newExpense = [
            'id'         => uniqid('exp_', true),
            'name'       => $name,
            'amount'     => $amount,
            'category'   => $category,
            'date'       => $date,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $expenses   = loadExpenses($userId);
        $expenses[] = $newExpense;
        saveExpenses($userId, $expenses);
        respond(true, 'Expense added.', ['expense' => $newExpense]);
        break;

    // ── Edit expense ──────────────────────────────────────────────────────────
    case 'edit':
        $expenseId = trim($body['expenseId'] ?? '');
        $name      = trim($body['name'] ?? '');
        $amount    = floatval($body['amount'] ?? 0);
        $category  = trim($body['category'] ?? '');
        $date      = trim($body['date'] ?? '');

        if (!$expenseId || !$name || $amount <= 0 || !$category || !$date) {
            respond(false, 'All fields are required.');
        }

        $expenses = loadExpenses($userId);
        $found    = false;
        foreach ($expenses as &$exp) {
            if ($exp['id'] === $expenseId) {
                $exp['name']       = $name;
                $exp['amount']     = $amount;
                $exp['category']   = $category;
                $exp['date']       = $date;
                $exp['updated_at'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }
        unset($exp);

        if (!$found) respond(false, 'Expense not found.');
        saveExpenses($userId, $expenses);
        respond(true, 'Expense updated.');
        break;

    // ── Delete expense ────────────────────────────────────────────────────────
    case 'delete':
        $expenseId = trim($body['expenseId'] ?? '');
        if (!$expenseId) respond(false, 'Expense ID required.');

        $expenses = loadExpenses($userId);
        $filtered = array_values(array_filter($expenses, fn($e) => $e['id'] !== $expenseId));

        if (count($filtered) === count($expenses)) respond(false, 'Expense not found.');
        saveExpenses($userId, $filtered);
        respond(true, 'Expense deleted.');
        break;

    // ── ✨ AI Smart Add ───────────────────────────────────────────────────────
    case 'ai_add':
        $text = trim($body['text'] ?? '');
        if (!$text) respond(false, 'Please provide an expense description.');

        $today  = date('Y-m-d');
        $prompt = "You are an expense tracking assistant. Extract expense information from the user input.\n"
                . "TODAY = {$today}\n\n"
                . "Return ONLY this JSON object and nothing else:\n"
                . "{\"name\": \"short label\", \"amount\": 12.50, \"category\": \"Food\", \"date\": \"{$today}\"}\n\n"
                . "RULES:\n"
                . "- name: max 30 characters\n"
                . "- amount: a positive number (no currency symbol)\n"
                . "- category: MUST be one of exactly: Food, Transport, Entertainment, Utilities, Others\n"
                . "- date: YYYY-MM-DD format. Use {$today} if not mentioned. 'yesterday' means " . date('Y-m-d', strtotime('-1 day')) . "\n"
                . "- Output ONLY the JSON. No explanation, no markdown.\n\n"
                . "User input: {$text}";

        try {
            // Pass forceJson=true so Ollama is constrained to JSON output
            $aiText = callAI($prompt, true);
            $parsed = extractJson($aiText);

            if (!$parsed || !isset($parsed['name'], $parsed['amount'], $parsed['category'], $parsed['date'])) {
                respond(false, 'AI could not understand your input. Try: "Lunch RM15" or "Grab ride RM8 yesterday".');
            }

            $allowedCats = ['Food', 'Transport', 'Entertainment', 'Utilities', 'Others'];
            $name     = trim(substr((string)($parsed['name'] ?? ''), 0, 100));
            $amount   = floatval($parsed['amount'] ?? 0);
            $category = in_array($parsed['category'] ?? '', $allowedCats) ? $parsed['category'] : 'Others';
            $date     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $parsed['date'] ?? '') ? $parsed['date'] : $today;

            if (!$name || $amount <= 0) {
                respond(false, 'AI returned invalid data. Please describe the expense more clearly.');
            }

            $newExpense = [
                'id'         => uniqid('exp_', true),
                'name'       => $name,
                'amount'     => $amount,
                'category'   => $category,
                'date'       => $date,
                'source'     => 'ai',
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $expenses   = loadExpenses($userId);
            $expenses[] = $newExpense;
            saveExpenses($userId, $expenses);

            respond(true, 'Expense recorded via AI.', ['expense' => $newExpense]);

        } catch (RuntimeException $e) {
            respond(false, 'AI error: ' . $e->getMessage());
        }
        break;

    // ── 🤖 AI Financial Insight ───────────────────────────────────────────────
    case 'ai_insight':
        $expenses = loadExpenses($userId);

        if (empty($expenses)) {
            respond(false, 'No expenses recorded yet. Add some expenses first to get insights!');
        }

        $now        = new DateTime();
        $month      = $now->format('Y-m');
        $totalAll   = 0;
        $totalMonth = 0;
        $monthCount = 0;
        $catTotals  = [];

        foreach ($expenses as $e) {
            $amt             = floatval($e['amount']);
            $totalAll       += $amt;
            $cat             = $e['category'] ?? 'Others';
            $catTotals[$cat] = ($catTotals[$cat] ?? 0) + $amt;
            if (str_starts_with($e['date'], $month)) {
                $totalMonth += $amt;
                $monthCount++;
            }
        }

        arsort($catTotals);
        $catBreakdown = '';
        foreach ($catTotals as $cat => $amt) {
            $pct           = round(($amt / $totalAll) * 100);
            $catBreakdown .= "  - {$cat}: RM " . number_format($amt, 2) . " ({$pct}%)\n";
        }

        $sorted = $expenses;
        usort($sorted, fn($a, $b) => strcmp($b['date'], $a['date']));
        $recentStr = '';
        foreach (array_slice($sorted, 0, 5) as $e) {
            $recentStr .= "  - {$e['date']} | {$e['name']} | RM {$e['amount']} | {$e['category']}\n";
        }

        // NOTE: No forceJson for insight — we want free natural language text
        $prompt = "You are a friendly personal finance advisor. Analyze this user's expense data and give a SHORT practical assessment in 3-5 sentences. Be specific with numbers. Write in English.\n\n"
                . "Data summary:\n"
                . "- Total all-time: RM " . number_format($totalAll, 2) . " across " . count($expenses) . " entries\n"
                . "- This month ({$month}): RM " . number_format($totalMonth, 2) . " ({$monthCount} entries)\n"
                . "- Category breakdown:\n{$catBreakdown}"
                . "- Recent 5 expenses:\n{$recentStr}\n"
                . "Give a brief financial health assessment and 1-2 specific actionable tips.";

        try {
            $t0      = microtime(true);
            $insight = callAI($prompt, false); // free text, no JSON constraint
            $elapsed = round(microtime(true) - $t0, 1);

            respond(true, 'OK', [
                'insight' => $insight,
                'meta'    => "Generated in {$elapsed}s · Based on " . count($expenses) . " expenses",
            ]);
        } catch (RuntimeException $e) {
            respond(false, 'AI error: ' . $e->getMessage());
        }
        break;

    default:
        respond(false, 'Unknown action: ' . htmlspecialchars($action));
}
