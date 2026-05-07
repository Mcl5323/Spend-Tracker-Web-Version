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
//  安全配置：从独立的 config.php 读取 API Key，该文件已被 .gitignore 忽略
// =============================================================================
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    echo json_encode([
        'success' => false,
        'message' => '缺少配置文件 api/config.php，请参考文档创建并填入 Gemini API Key。',
    ]);
    exit();
}
$config = require $configFile;

define('GEMINI_API_KEY', $config['GEMINI_API_KEY'] ?? '');
define('GEMINI_API_URL', $config['GEMINI_API_URL'] ?? '');

// =============================================================================
//  数据目录
// =============================================================================
define('DATA_DIR', __DIR__ . '/../data/');

// ── 工具函数 ──────────────────────────────────────────────────────────────────

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
//  核心：调用 Google Gemini API
//  返回模型回复的纯文本；失败时抛出 RuntimeException
// =============================================================================
function callGemini(string $prompt): string {
    if (!GEMINI_API_KEY || GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE') {
        throw new RuntimeException('Gemini API Key 尚未配置，请编辑 api/config.php 并填入真实 Key。');
    }

    $payload = json_encode([
        'contents' => [
            ['parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'temperature'     => 0.2,
            'maxOutputTokens' => 512,
        ],
    ]);

    $url = GEMINI_API_URL . '?key=' . urlencode(GEMINI_API_KEY);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);

    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || $raw === false) {
        throw new RuntimeException("cURL 连接失败 ({$errno}): {$error}");
    }

    $resp = json_decode($raw, true);

    // Gemini 错误响应（Key 无效、超配额等）
    if (isset($resp['error'])) {
        $msg = $resp['error']['message'] ?? '未知错误';
        throw new RuntimeException("Gemini API 错误 (HTTP {$http}): {$msg}");
    }

    // 正常响应路径：candidates[0].content.parts[0].text
    $text = $resp['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if ($text === null) {
        throw new RuntimeException("Gemini 响应格式异常 (HTTP {$http})，原始内容：" . substr($raw, 0, 300));
    }

    return trim($text);
}

// =============================================================================
//  解析请求
// =============================================================================
$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';
$userId = trim($body['userId'] ?? '');

if (!$userId || !validateUserId($userId)) {
    respond(false, 'Invalid or missing user ID.');
}

switch ($action) {

    // ── 获取记录 ──────────────────────────────────────────────────────────────
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

    // ── 手动添加 ──────────────────────────────────────────────────────────────
    case 'add':
        $name     = trim($body['name'] ?? '');
        $amount   = floatval($body['amount'] ?? 0);
        $category = trim($body['category'] ?? '');
        $date     = trim($body['date'] ?? '');

        if (!$name || $amount <= 0 || !$category || !$date) {
            respond(false, 'All expense fields are required and amount must be positive.');
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

    // ── ✨ AI 智能记账 ────────────────────────────────────────────────────────
    case 'ai_add':
        $text = trim($body['text'] ?? '');
        if (!$text) {
            respond(false, '请提供消费描述文本。');
        }

        $today = date('Y-m-d');

        $prompt = "你是一个消费记录助手。请从下面的自然语言文本中提取消费信息，并严格以纯 JSON 格式返回，不得包含任何额外说明、markdown 代码块或注释。\n\n"
                . "今天的日期是：{$today}\n\n"
                . "返回格式（字段缺一不可）：\n"
                . '{"name": "消费名称", "amount": 数字, "category": "分类", "date": "YYYY-MM-DD"}' . "\n\n"
                . "规则：\n"
                . "1. name：简洁的中文消费名称，不超过 10 个字。\n"
                . "2. amount：正数，单位元（人民币）。若提到 RM / 令吉，数值原样保留。\n"
                . "3. category：只能从以下选项中选一个：Food、Transport、Entertainment、Utilities、Others。\n"
                . "4. date：若文本未明确日期，默认使用今天（{$today}）；\"昨天\"减一天，\"前天\"减两天，以此类推。\n"
                . "5. 只输出纯 JSON，绝对不要有其他任何文字。\n\n"
                . "用户输入：\n{$text}";

        try {
            $aiText = callGemini($prompt);

            // 容错：清理可能残留的 markdown 代码块
            $aiText = preg_replace('/^```(?:json)?\s*/i', '', $aiText);
            $aiText = preg_replace('/\s*```\s*$/i', '',  $aiText);
            // 提取第一个完整的 {...} 块，防止模型在 JSON 前后多输出文字
            if (preg_match('/\{[\s\S]*\}/u', $aiText, $m)) {
                $aiText = $m[0];
            }
            $aiText = trim($aiText);

            $parsed = json_decode($aiText, true);

            // 严格校验
            if (
                !is_array($parsed)
                || empty($parsed['name'])
                || !isset($parsed['amount'])
                || !is_numeric($parsed['amount'])
                || floatval($parsed['amount']) <= 0
                || empty($parsed['category'])
                || empty($parsed['date'])
            ) {
                respond(false, 'AI 未能正确识别消费信息，请换一种描述方式，或直接手动填写。');
            }

            // 白名单校验 category
            $validCats = ['Food', 'Transport', 'Entertainment', 'Utilities', 'Others'];
            if (!in_array($parsed['category'], $validCats, true)) {
                $parsed['category'] = 'Others';
            }

            // 日期格式校验
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $parsed['date'])) {
                $parsed['date'] = $today;
            }

            $newExpense = [
                'id'         => uniqid('exp_', true),
                'name'       => mb_substr(strip_tags($parsed['name']), 0, 50),
                'amount'     => round(floatval($parsed['amount']), 2),
                'category'   => $parsed['category'],
                'date'       => $parsed['date'],
                'created_at' => date('Y-m-d H:i:s'),
                'source'     => 'ai',
            ];

            $expenses   = loadExpenses($userId);
            $expenses[] = $newExpense;
            saveExpenses($userId, $expenses);

            respond(true, 'AI 记账成功！', ['expense' => $newExpense]);

        } catch (RuntimeException $e) {
            respond(false, 'AI 服务暂时不可用：' . $e->getMessage());
        }
        break;

    // ── 🤖 AI 月度财务诊断 ───────────────────────────────────────────────────
    case 'ai_insight':
        $expenses  = loadExpenses($userId);
        $month     = date('Y-m');
        $thisMonth = array_filter($expenses, fn($e) => str_starts_with($e['date'], $month));

        if (empty($thisMonth)) {
            respond(false, '本月还没有任何消费记录，先记几笔再来诊断吧～');
        }

        // 数据脱敏：只汇总各分类金额，不发送具体商品名
        $summary = [];
        foreach ($thisMonth as $e) {
            $cat = $e['category'];
            $summary[$cat] = ($summary[$cat] ?? 0) + floatval($e['amount']);
        }
        arsort($summary); // 大头支出排前面，让模型更易点评

        $totalAmount = array_sum($summary);
        $entryCount  = count($thisMonth);

        $summaryParts = [];
        foreach ($summary as $cat => $amt) {
            $summaryParts[] = "{$cat}: RM " . number_format($amt, 2);
        }
        $summaryStr = implode('，', $summaryParts);

        $prompt = "你是一位严厉但幽默的私人理财管家，用简短犀利的语言点评用户的消费习惯，并给出至少一条实际可操作的省钱建议。\n\n"
                . "要求：\n"
                . "1. 回复控制在 120 字以内（中文）。\n"
                . "2. 语气可以稍微毒舌，但不伤感情，让用户会心一笑。\n"
                . "3. 必须包含至少一条具体的省钱或理财建议。\n"
                . "4. 只输出诊断正文，不要加标题、前缀或额外格式。\n\n"
                . "用户本月（{$month}）消费数据（共 {$entryCount} 笔，总额 RM " . number_format($totalAmount, 2) . "）：\n"
                . $summaryStr . "\n\n"
                . "请给出你的月度财务诊断：";

        try {
            $insight = callGemini($prompt);
            respond(true, 'OK', [
                'insight' => $insight,
                'meta'    => "基于本月 {$entryCount} 笔记录 · 合计 RM " . number_format($totalAmount, 2),
            ]);
        } catch (RuntimeException $e) {
            respond(false, 'AI 服务暂时不可用：' . $e->getMessage());
        }
        break;

    // ── 编辑 ──────────────────────────────────────────────────────────────────
    case 'edit':
        $expenseId = trim($body['expenseId'] ?? '');
        $name      = trim($body['name'] ?? '');
        $amount    = floatval($body['amount'] ?? 0);
        $category  = trim($body['category'] ?? '');
        $date      = trim($body['date'] ?? '');

        if (!$expenseId || !$name || $amount <= 0 || !$category || !$date) {
            respond(false, 'All fields required.');
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

    // ── 删除 ──────────────────────────────────────────────────────────────────
    case 'delete':
        $expenseId = trim($body['expenseId'] ?? '');
        if (!$expenseId) respond(false, 'Expense ID required.');

        $expenses = loadExpenses($userId);
        $before   = count($expenses);
        $expenses = array_values(
            array_filter($expenses, fn($e) => $e['id'] !== $expenseId)
        );

        if (count($expenses) === $before) respond(false, 'Expense not found.');
        saveExpenses($userId, $expenses);
        respond(true, 'Expense deleted.');
        break;

    // ── CSV 导出 ──────────────────────────────────────────────────────────────
    case 'export':
        $expenses = loadExpenses($userId);
        usort($expenses, fn($a, $b) => strcmp($b['date'], $a['date']));

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="expenses_export.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Name', 'Amount (RM)', 'Category', 'Date']);
        foreach ($expenses as $e) {
            fputcsv($out, [
                $e['name'],
                number_format($e['amount'], 2),
                $e['category'],
                $e['date'],
            ]);
        }
        fclose($out);
        exit();

    default:
        respond(false, 'Unknown action.');
}
